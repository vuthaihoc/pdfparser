<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Imagick;
use ImagickPixel;
use PrinsFrank\PdfParser\Document\Filter\Decode\ASCII85Decode;
use PrinsFrank\PdfParser\Document\Filter\Decode\ASCIIHexDecode;
use Throwable;

/**
 * Image XObjects and inline images to Imagick (with alpha for SMask, stencil masks and /Mask streams).
 * Raw samples are loaded without per-pixel PHP loops: 8/16-bit grey, RGB and CMYK as raw blobs, other bit depths and
 * Indexed images as a PNG built from the rows. The decoded result says why when an image cannot be decoded.
 *
 * @experimental
 */
final class ImageDecoder {
    private const FILTER_ABBREVIATIONS = ['AHx' => 'ASCIIHexDecode', 'A85' => 'ASCII85Decode', 'LZW' => 'LZWDecode', 'Fl' => 'FlateDecode',
        'RL' => 'RunLengthDecode', 'CCF' => 'CCITTFaxDecode', 'DCT' => 'DCTDecode'];
    private const INLINE_KEYS = ['W' => 'Width', 'H' => 'Height', 'BPC' => 'BitsPerComponent', 'CS' => 'ColorSpace', 'F' => 'Filter',
        'D' => 'Decode', 'DP' => 'DecodeParms', 'IM' => 'ImageMask', 'I' => 'Interpolate'];

    public function __construct(
        private readonly RawObjects $objects,
        private readonly ColorResolver $colors,
        private readonly bool $masks = true,
    ) {}

    /**
     * @param array<string, mixed> $resources
     * @param list<float> $fillRgb colour of stencil masks
     * @return array{image: ?Imagick, reason: ?string, approximate: ?string, masksSkipped?: bool}
     */
    public function xobject(int $objectNumber, array $resources, array $fillRgb, int $targetPixels): array {
        $dictionary = $this->objects->dictionary($this->objects->value($objectNumber)) ?? [];
        // the stored bytes through our own filters when they are not encrypted: PNG predictors are undone by libpng
        $raw = $this->objects->document->fileEncryptionKey === null ? $this->objects->rawStreamBytes($objectNumber) : null;
        $data = $raw === null ? null : $this->applyFilters($raw, $dictionary);
        $data ??= $this->objects->streamData($objectNumber);

        if ($data === null) {
            return ['image' => null, 'reason' => 'filter ' . implode(',', $this->filters($dictionary)), 'approximate' => null];
        }

        return $this->decode($dictionary, $data, $resources, $fillRgb, $targetPixels, true);
    }

    /**
     * @param string $operands operands of the EI command: "<entries> ID <data>"
     * @param array<string, mixed> $resources
     * @param list<float> $fillRgb
     * @return array{image: ?Imagick, reason: ?string, approximate: ?string, masksSkipped?: bool}
     */
    public function inline(string $operands, array $resources, array $fillRgb, int $targetPixels): array {
        if (preg_match('/(?:^|\s)ID(\r\n|\s)/', $operands, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ['image' => null, 'reason' => 'inline image without ID', 'approximate' => null];
        }

        $entries = substr($operands, 0, $match[0][1]);
        $data = substr($operands, $match[0][1] + strlen($match[0][0]));
        $values = PdfSyntax::values($entries);
        $dictionary = [];
        for ($i = 0; $i + 1 < count($values); $i += 2) {
            if (is_string($values[$i]) && str_starts_with($values[$i], '/')) {
                $key = substr($values[$i], 1);
                $dictionary[self::INLINE_KEYS[$key] ?? $key] = $values[$i + 1];
            }
        }

        if (isset($dictionary['Filter'])) {
            $filters = is_array($dictionary['Filter']) ? $dictionary['Filter'] : [$dictionary['Filter']];
            $dictionary['Filter'] = array_map(static fn($f) => is_string($f) ? '/' . (self::FILTER_ABBREVIATIONS[ltrim($f, '/')] ?? ltrim($f, '/')) : $f, $filters);
        }

        $decoded = $this->applyFilters($data, $dictionary);
        if ($decoded === null) {
            return ['image' => null, 'reason' => 'inline filter ' . implode(',', $this->filters($dictionary)), 'approximate' => null];
        }

        return $this->decode($dictionary, $decoded, $resources, $fillRgb, $targetPixels, false);
    }

    /** @return list<string> */
    private function filters(array $dictionary): array {
        $filter = $this->objects->resolve($dictionary['Filter'] ?? null);
        $filters = is_array($filter) ? $filter : ($filter === null ? [] : [$filter]);

        return array_values(array_map(static fn($f) => is_string($f) ? ltrim($f, '/') : '?', $filters));
    }

    /** All filters except the image codecs (DCT, JPX, JBIG2, CCITT) that end the chain */
    private function applyFilters(string $data, array $dictionary): ?string {
        $parms = $this->objects->resolve($dictionary['DecodeParms'] ?? null);
        foreach ($this->filters($dictionary) as $i => $filter) {
            $decodeParms = is_array($parms) && array_is_list($parms) ? $this->objects->dictionary($parms[$i] ?? null) : $this->objects->dictionary($parms);
            try {
                $data = match ($filter) {
                    'FlateDecode', 'Fl' => $this->predictor((string) @zlib_decode($data), $decodeParms ?? []),
                    'LZWDecode', 'LZW' => $this->predictor(self::lzw($data, (int) $this->objects->number(($decodeParms ?? [])['EarlyChange'] ?? 1, 1)), $decodeParms ?? []),
                    'RunLengthDecode', 'RL' => self::runLength($data),
                    'ASCIIHexDecode', 'AHx' => ASCIIHexDecode::decodeBinary($data),
                    'ASCII85Decode', 'A85' => ASCII85Decode::decodeBinary($data),
                    'DCTDecode', 'DCT', 'JPXDecode', 'JBIG2Decode', 'CCITTFaxDecode', 'CCF' => $data,
                    default => null,
                };
            } catch (Throwable) {
                return null;
            }

            if ($data === null || $data === '') {
                return null;
            }
        }

        return $data;
    }

    /** PNG predictors (10-15) of Flate/LZW data, applied per row */
    private function predictor(string $data, array $decodeParms): string {
        $predictor = (int) $this->objects->number($decodeParms['Predictor'] ?? 1, 1);
        if ($predictor < 10) {
            return $data;
        }

        $colors = (int) $this->objects->number($decodeParms['Colors'] ?? 1, 1);
        $bpc = (int) $this->objects->number($decodeParms['BitsPerComponent'] ?? 8, 8);
        $columns = (int) $this->objects->number($decodeParms['Columns'] ?? 1, 1);
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $rowLength = intdiv($colors * $bpc * $columns + 7, 8);
        if (($bpc === 8 || $bpc === 16) && in_array($colors, [1, 3, 4], true) && ($unpredicted = self::unpredictWithPng($data, $columns, $colors, $bpc, $rowLength)) !== null) {
            return $unpredicted;
        }

        $out = '';
        $previous = str_repeat("\0", $rowLength);
        $length = strlen($data);
        for ($pos = 0; $pos + 1 + $rowLength <= $length; $pos += 1 + $rowLength) {
            $type = ord($data[$pos]);
            $row = substr($data, $pos + 1, $rowLength);
            if ($type !== 0) {
                for ($i = 0; $i < $rowLength; $i++) {
                    $a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                    $b = ord($previous[$i]);
                    $c = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
                    $x = ord($row[$i]);
                    $row[$i] = chr(match ($type) {
                        1 => $x + $a,
                        2 => $x + $b,
                        3 => $x + intdiv($a + $b, 2),
                        4 => $x + self::paeth($a, $b, $c),
                        default => $x,
                    } & 0xFF);
                }
            }

            $out .= $row;
            $previous = $row;
        }

        return $out;
    }

    /**
     * PNG predictors are the row filters of PNG: given the rows as the image data of a PNG (grey, RGB, or RGBA for
     * four components), libpng undoes them instead of a loop over every byte in PHP
     */
    private static function unpredictWithPng(string $data, int $columns, int $colors, int $bpc, int $rowLength): ?string {
        $rows = intdiv(strlen($data), 1 + $rowLength);
        if ($rows < 1) {
            return null;
        }

        $chunk = static fn(string $type, string $body): string => pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $columns, $rows, $bpc, [1 => 0, 3 => 2, 4 => 6][$colors], 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress(substr($data, 0, $rows * (1 + $rowLength)), 0))
            . $chunk('IEND', '');
        try {
            $image = new Imagick();
            $image->readImageBlob($png);
            $image->setImageDepth($bpc);
            $image->setOption('endian', 'MSB');
            $image->setImageFormat([1 => 'gray', 3 => 'rgb', 4 => 'rgba'][$colors]);
            $raw = $image->getImageBlob();
            $image->clear();
        } catch (Throwable) {
            return null;
        }

        return strlen($raw) === $rows * $rowLength ? $raw : null;
    }

    private static function paeth(int $a, int $b, int $c): int {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function lzw(string $data, int $earlyChange): string {
        $dictionary = [];
        for ($i = 0; $i < 256; $i++) {
            $dictionary[$i] = chr($i);
        }

        $next = 258;
        $bits = 9;
        $out = '';
        $previous = null;
        $buffer = 0;
        $bufferBits = 0;
        $length = strlen($data);
        for ($pos = 0; $pos < $length; $pos++) {
            $buffer = ($buffer << 8) | ord($data[$pos]);
            $bufferBits += 8;
            while ($bufferBits >= $bits) {
                $code = ($buffer >> ($bufferBits - $bits)) & ((1 << $bits) - 1);
                $bufferBits -= $bits;
                $buffer &= (1 << $bufferBits) - 1;
                if ($code === 256) {
                    $dictionary = array_slice($dictionary, 0, 256);
                    $next = 258;
                    $bits = 9;
                    $previous = null;
                    continue;
                }

                if ($code === 257) {
                    return $out;
                }

                $entry = $dictionary[$code] ?? ($previous !== null && $code === $next ? $previous . $previous[0] : null);
                if ($entry === null) {
                    return $out;
                }

                $out .= $entry;
                if ($previous !== null) {
                    $dictionary[$next++] = $previous . $entry[0];
                }

                $previous = $entry;
                if ($next + $earlyChange >= (1 << $bits) && $bits < 12) {
                    $bits++;
                }
            }
        }

        return $out;
    }

    private static function runLength(string $data): string {
        $out = '';
        $length = strlen($data);
        for ($pos = 0; $pos < $length;) {
            $n = ord($data[$pos++]);
            if ($n === 128) {
                break;
            }

            if ($n < 128) {
                $out .= substr($data, $pos, $n + 1);
                $pos += $n + 1;
            } else {
                $out .= str_repeat($data[$pos] ?? "\0", 257 - $n);
                $pos++;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $dictionary
     * @param array<string, mixed> $resources
     * @param list<float> $fillRgb
     * @return array{image: ?Imagick, reason: ?string, approximate: ?string, masksSkipped?: bool}
     */
    private function decode(array $dictionary, string $data, array $resources, array $fillRgb, int $targetPixels, bool $allowMasks): array {
        $width = (int) $this->objects->number($dictionary['Width'] ?? 0);
        $height = (int) $this->objects->number($dictionary['Height'] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > 200_000_000) {
            return ['image' => null, 'reason' => 'size ' . $width . 'x' . $height, 'approximate' => null];
        }

        $filters = $this->filters($dictionary);
        $codec = end($filters) ?: '';
        $approximate = null;
        $isMask = (bool) $this->objects->resolve($dictionary['ImageMask'] ?? false);
        try {
            if ($codec === 'JBIG2Decode') {
                return ['image' => null, 'reason' => 'JBIG2', 'approximate' => null];
            }

            if ($codec === 'DCTDecode' || $codec === 'DCT' || $codec === 'JPXDecode' || $codec === 'CCITTFaxDecode' || $codec === 'CCF') {
                $image = new Imagick();
                if (($codec === 'DCTDecode' || $codec === 'DCT') && $width * $height > $targetPixels * 4) {
                    $scale = sqrt($targetPixels * 2 / ($width * $height));
                    $image->setOption('jpeg:size', max(1, (int) ($width * $scale)) . 'x' . max(1, (int) ($height * $scale)));
                }

                if (($codec === 'CCITTFaxDecode' || $codec === 'CCF') && !str_starts_with($data, "II*\0") && !str_starts_with($data, "MM\0*")) {
                    $decodeParms = $this->objects->dictionary($dictionary['DecodeParms'] ?? null) ?? [];
                    $data = self::tiffForFax(
                        $data,
                        (int) $this->objects->number($decodeParms['Columns'] ?? 1728, 1728),
                        (int) $this->objects->number($decodeParms['Rows'] ?? $height, $height),
                        (int) $this->objects->number($decodeParms['K'] ?? 0),
                        (bool) $this->objects->resolve($decodeParms['BlackIs1'] ?? false),
                    );
                }

                $image->readImageBlob($data);
                if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                    // Adobe (APP14) CMYK JPEGs store inverted values, which ImageMagick 6 reads as they are;
                    // a /Decode [1 0 ...] in the PDF inverts them back
                    if ((str_contains(substr($data, 0, 4096), 'Adobe') && ($codec === 'DCTDecode' || $codec === 'DCT')) !== $this->decodeInverted($dictionary)) {
                        $image->negateImage(false);
                    }

                    $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                }

                if ($isMask) {
                    $image = $this->stencil($image, $fillRgb, $dictionary);
                } else {
                    $colorSpace = $this->colors->colorSpace($dictionary['ColorSpace'] ?? '/DeviceGray', $resources);
                    if (in_array($colorSpace['family'], ['Separation', 'DeviceN'], true) && (int) $colorSpace['n'] === 1) {
                        $image = $this->tintImage($image, $colorSpace, $this->decodeInverted($dictionary));
                    }
                }
            } elseif ($isMask) {
                $image = $this->stencil($this->rawImage($width, $height, 1, ['family' => 'Gray', 'n' => 1], $data), $fillRgb, $dictionary);
            } else {
                $colorSpace = $this->colors->colorSpace($dictionary['ColorSpace'] ?? '/DeviceGray', $resources);
                $bpc = (int) $this->objects->number($dictionary['BitsPerComponent'] ?? 8, 8);
                if (in_array($colorSpace['family'], ['Separation', 'DeviceN', 'Lab', 'Unknown'], true)) {
                    $approximate = 'colour space ' . $colorSpace['family'];
                }

                $image = $this->rawImage($width, $height, $bpc, $colorSpace, $data);
                if ($this->decodeInverted($dictionary)) {
                    $image->negateImage(false);
                }
            }
        } catch (Throwable $e) {
            return ['image' => null, 'reason' => 'decode: ' . substr($e->getMessage(), 0, 60), 'approximate' => null];
        }

        if ($image->getImageWidth() * $image->getImageHeight() > $targetPixels * 4) { // a smaller copy is enough for where it goes
            $scale = sqrt($targetPixels * 2 / ($image->getImageWidth() * $image->getImageHeight()));
            $image->resizeImage(max(1, (int) round($image->getImageWidth() * $scale)), max(1, (int) round($image->getImageHeight() * $scale)), Imagick::FILTER_TRIANGLE, 1);
        }

        $hasMask = isset($dictionary['SMask']) || ($dictionary['Mask'] ?? null) instanceof PdfReference;
        if ($allowMasks && !$this->masks && $hasMask) {
            return ['image' => $image, 'reason' => null, 'approximate' => $approximate, 'masksSkipped' => true];
        }

        if ($allowMasks && isset($dictionary['SMask'])) {
            $mask = $this->maskImage($dictionary['SMask'], $resources, $targetPixels, false);
            if ($mask !== null) {
                self::applyAlpha($image, $mask);
            }
        } elseif ($allowMasks && ($dictionary['Mask'] ?? null) instanceof PdfReference) {
            $mask = $this->maskImage($dictionary['Mask'], $resources, $targetPixels, true);
            if ($mask !== null) {
                self::applyAlpha($image, $mask);
            }
        } elseif (is_array($this->objects->resolve($dictionary['Mask'] ?? null))) {
            $approximate = 'colour key mask';
        }

        return ['image' => $image, 'reason' => null, 'approximate' => $approximate];
    }

    private function decodeInverted(array $dictionary): bool {
        $decode = $this->objects->array($dictionary['Decode'] ?? null);

        return $decode !== null && count($decode) >= 2 && $this->objects->number($decode[0]) > $this->objects->number($decode[1]);
    }

    /** A soft mask (grey levels) or an explicit stencil mask (1 = masked out) as a grey Imagick where white is opaque */
    private function maskImage(mixed $reference, array $resources, int $targetPixels, bool $stencil): ?Imagick {
        if (!$reference instanceof PdfReference) {
            return null;
        }

        $dictionary = $this->objects->dictionary($this->objects->value($reference->objectNumber)) ?? [];
        $data = $this->objects->streamData($reference->objectNumber);
        if ($data === null) {
            return null;
        }

        $dictionary['ColorSpace'] = '/DeviceGray';
        unset($dictionary['ImageMask'], $dictionary['SMask'], $dictionary['Mask']);
        if ($stencil) {
            $dictionary['BitsPerComponent'] = 1;
        }

        $decoded = $this->decode($dictionary, $data, $resources, [0.0, 0.0, 0.0], $targetPixels, false);
        $mask = $decoded['image'];
        if ($mask !== null && $stencil) {
            $mask->negateImage(false); // stencil sample 1 means masked out
        }

        return $mask;
    }

    private static function applyAlpha(Imagick $image, Imagick $mask): void {
        if ($mask->getImageWidth() !== $image->getImageWidth() || $mask->getImageHeight() !== $image->getImageHeight()) {
            $mask->resizeImage($image->getImageWidth(), $image->getImageHeight(), Imagick::FILTER_TRIANGLE, 1);
        }

        $mask->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $mask->setImageAlphaChannel(Imagick::ALPHACHANNEL_DEACTIVATE);
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $image->compositeImage($mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0);
    }

    /** A stencil mask: samples 0 (or 1 with Decode [1 0]) are painted in the fill colour, the rest is transparent */
    private function stencil(Imagick $bits, array $fillRgb, array $dictionary): Imagick {
        $bits->setImageColorspace(Imagick::COLORSPACE_GRAY);
        if (!$this->decodeInverted($dictionary)) {
            $bits->negateImage(false);
        }

        $paint = new Imagick();
        $paint->newImage($bits->getImageWidth(), $bits->getImageHeight(), new ImagickPixel(sprintf('rgb(%d,%d,%d)', $fillRgb[0] * 255, $fillRgb[1] * 255, $fillRgb[2] * 255)));
        $paint->setImageFormat('png');
        self::applyAlpha($paint, $bits);

        return $paint;
    }

    /** @param array<string, mixed> $colorSpace */
    private function rawImage(int $width, int $height, int $bpc, array $colorSpace, string $data): Imagick {
        $family = $colorSpace['family'];
        $n = (int) $colorSpace['n'];
        if ($family === 'Indexed') {
            $palette = '';
            for ($index = 0; $index <= min(255, (int) $colorSpace['hival']); $index++) {
                [$r, $g, $b] = $this->colors->toRgb($colorSpace, [(float) $index]);
                $palette .= chr((int) round($r * 255)) . chr((int) round($g * 255)) . chr((int) round($b * 255));
            }

            return self::fromPng(self::png($width, $height, min(8, $bpc), 3, 1, $data, $palette));
        }

        if (($family === 'Separation' || $family === 'DeviceN') && $n === 1) {
            return $this->tintImage($this->rawImage($width, $height, $bpc, ['family' => 'Gray', 'n' => 1], $data), $colorSpace, false);
        }

        if (in_array($family, ['Separation', 'DeviceN', 'Lab', 'Unknown'], true)) {
            $data = self::firstChannel($data, $width, $height, $bpc, $n);
            $image = $this->rawImage($width, $height, $bpc, ['family' => 'Gray', 'n' => 1], $data);
            if ($family !== 'Lab') {
                $image->negateImage(false); // tint 1 = full ink
            }

            return $image;
        }

        if ($bpc === 8 || $bpc === 16) {
            $format = match ($family) {
                'RGB' => 'rgb', 'CMYK' => 'cmyk', default => 'gray',
            };
            $expected = $width * $height * ($format === 'rgb' ? 3 : ($format === 'cmyk' ? 4 : 1)) * intdiv($bpc, 8);
            $data = str_pad(substr($data, 0, $expected), $expected, "\0");
            $image = new Imagick();
            $image->setSize($width, $height);
            $image->setOption('depth', (string) $bpc);
            $image->setOption('endian', 'MSB');
            $image->setFormat($format);
            $image->readImageBlob($data);
            if ($format === 'cmyk') {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            return $image;
        }

        if ($family === 'RGB' || $family === 'CMYK') { // 1/2/4 bits per component: expand to 8 bits
            $data = self::expandBits($data, $width * $n, $height, $bpc);

            return $this->rawImage($width, $height, 8, $colorSpace, $data);
        }

        return self::fromPng(self::png($width, $height, $bpc, 0, 1, $data));
    }

    /**
     * A one-channel Separation/DeviceN image: each tint (the grey level) through the tint transform, by a 256-entry
     * colour lookup table built from the colour space.
     *
     * @param array<string, mixed> $colorSpace
     */
    private function tintImage(Imagick $image, array $colorSpace, bool $inverted): Imagick {
        $lookup = '';
        for ($i = 0; $i < 256; $i++) {
            [$r, $g, $b] = $this->colors->toRgb($colorSpace, [($inverted ? 255 - $i : $i) / 255]);
            $lookup .= chr((int) round($r * 255)) . chr((int) round($g * 255)) . chr((int) round($b * 255));
        }

        $table = new Imagick();
        $table->setSize(256, 1);
        $table->setOption('depth', '8');
        $table->setFormat('rgb');
        $table->readImageBlob($lookup);
        $image->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->clutImage($table);

        return $image;
    }

    private static function fromPng(string $png): Imagick {
        $image = new Imagick();
        $image->readImageBlob($png);

        return $image;
    }

    /** A PNG of raw rows: colour type 0 (grey), 2 (RGB) or 3 (palette); each row gets filter byte 0 */
    private static function png(int $width, int $height, int $bpc, int $colorType, int $channels, string $data, ?string $palette = null): string {
        $rowLength = intdiv($width * $channels * $bpc + 7, 8);
        $data = str_pad(substr($data, 0, $rowLength * $height), $rowLength * $height, "\0");
        $chunk = static fn(string $type, string $body): string => pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, $bpc, $colorType, 0, 0, 0))
            . ($palette !== null ? $chunk('PLTE', $palette) : '')
            . $chunk('IDAT', (string) gzcompress("\0" . implode("\0", str_split($data, $rowLength)), 1))
            . $chunk('IEND', '');
    }

    private static function expandBits(string $data, int $samplesPerRow, int $height, int $bpc): string {
        $rowLength = intdiv($samplesPerRow * $bpc + 7, 8);
        $max = (1 << $bpc) - 1;
        $out = '';
        for ($y = 0; $y < $height; $y++) {
            $row = substr($data, $y * $rowLength, $rowLength);
            for ($i = 0; $i < $samplesPerRow; $i++) {
                $bit = $i * $bpc;
                $byte = ord($row[intdiv($bit, 8)] ?? "\0");
                $out .= chr(intdiv((($byte >> (8 - $bpc - $bit % 8)) & $max) * 255, $max));
            }
        }

        return $out;
    }

    private static function firstChannel(string $data, int $width, int $height, int $bpc, int $n): string {
        if ($n <= 1 || $bpc !== 8) {
            return $data;
        }

        $out = '';
        $length = min(strlen($data), $width * $height * $n);
        for ($i = 0; $i < $length; $i += $n) {
            $out .= $data[$i];
        }

        return $out;
    }

    /** A minimal TIFF around CCITT data so Imagick can read it */
    private static function tiffForFax(string $data, int $columns, int $rows, int $k, bool $blackIs1): string {
        $entries = [
            [256, 4, 1, $columns], [257, 4, 1, $rows], [258, 3, 1, 1],
            [259, 3, 1, $k < 0 ? 4 : 3], [262, 3, 1, $blackIs1 ? 1 : 0], [273, 4, 1, 0],
            [277, 3, 1, 1], [278, 4, 1, $rows], [279, 4, 1, strlen($data)],
        ];
        if ($k > 0) {
            $entries[] = [292, 4, 1, 1];
        }

        usort($entries, static fn($a, $b) => $a[0] <=> $b[0]);
        $ifdOffset = 8;
        $dataOffset = $ifdOffset + 2 + count($entries) * 12 + 4;
        $ifd = pack('v', count($entries));
        foreach ($entries as [$tag, $type, $count, $value]) {
            if ($tag === 273) {
                $value = $dataOffset;
            }

            $ifd .= pack('vvV', $tag, $type, $count) . ($type === 3 ? pack('vv', $value, 0) : pack('V', $value));
        }

        return 'II' . pack('vV', 42, $ifdOffset) . $ifd . pack('V', 0) . $data;
    }
}
