<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Imagick;
use ImagickDraw;

/**
 * Substitute system fonts for PDF fonts (embedded font programs are not used), chosen from the base font name:
 * serif / sans / mono, bold, italic, and a CJK font for CJK text. Widths of strings come from the substitute font,
 * per character and cached, so the renderer can stretch each text run to the width the PDF says it has.
 *
 * @experimental
 */
final class FontMapper {
    /** @var array<string, string> query => font file */
    private static array $files = [];

    /** @var array<string, array<string, float>> font file => character => width at size 100 */
    private array $widths = [];

    private ?Imagick $probe = null;

    /** @var array<string, ImagickDraw> */
    private array $draws = [];

    /** @param ?int $flags FontDescriptor /Flags: 1 fixed pitch, 2 serif, 64 italic, 262144 force bold; the name decides without them */
    public function file(?string $baseFont, bool $bold, bool $italic, string $text = '', ?int $flags = null): string {
        $name = strtolower(preg_replace('/^[A-Z]{6}\+/', '', (string) $baseFont) ?? '');
        $byName = match (true) {
            preg_match('/cour|mono|consol|code|typewriter/', $name) === 1 => 'Liberation Mono',
            preg_match('/sans|helvet|arial|frutiger|univers|futura|gill|verdana|tahoma|calibri|segoe|myriad|roboto|open ?sans|lato|din|avenir|optima|trebuchet/', $name) === 1 => 'Liberation Sans',
            preg_match('/times|serif|roman|georgia|garamond|cambria|book|minion|palatino|century|baskerville|caslon|cmr|nimbusrom|bodoni|charter/', $name) === 1 => 'Liberation Serif',
            default => null,
        };
        $family = match (true) {
            $flags !== null && ($flags & 1) !== 0 => 'Liberation Mono',
            $byName !== null => $byName,
            $flags !== null && ($flags & 2) !== 0 => 'Liberation Serif',
            default => 'Liberation Sans',
        };
        $italic = $italic || ($flags !== null && ($flags & 64) !== 0);
        $bold = $bold || ($flags !== null && ($flags & 262144) !== 0);
        if ($text !== '' && preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text) === 1) {
            $family = 'Noto Sans CJK JP';
        }

        $bold = $bold || preg_match('/bold|black|heavy|semibold|demi/', $name) === 1;
        $italic = $italic || preg_match('/italic|oblique|ital/', $name) === 1;
        $query = $family . ($bold ? ':bold' : '') . ($italic ? ':italic' : '');

        return self::$files[$query] ??= self::match($query);
    }

    private static function match(string $query): string {
        $file = trim((string) @shell_exec('fc-match -f %{file} ' . escapeshellarg($query) . ' 2>/dev/null'));
        if ($file !== '' && is_file($file)) {
            return $file;
        }

        foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans.ttf'] as $fallback) {
            if (is_file($fallback)) {
                return $fallback;
            }
        }

        return 'DejaVu-Sans';
    }

    /** @var array<int, string|false> font object nr => file of its embedded program, false when there is none or it does not load */
    private array $embedded = [];

    /**
     * The embedded font program of a font (FontFile, FontFile2, FontFile3; of the descendant font for Type0) written
     * to a temporary file that FreeType can open, or null.
     */
    public function embedded(RawObjects $objects, int $fontObjectNumber): ?string {
        if (array_key_exists($fontObjectNumber, $this->embedded)) {
            return $this->embedded[$fontObjectNumber] === false ? null : $this->embedded[$fontObjectNumber];
        }

        $this->embedded[$fontObjectNumber] = false;
        $font = $objects->dictionary($objects->value($fontObjectNumber)) ?? [];
        if (isset($font['DescendantFonts'])) {
            $font = $objects->dictionary(($objects->array($font['DescendantFonts']) ?? [])[0] ?? null) ?? [];
        }

        $descriptor = $objects->dictionary($font['FontDescriptor'] ?? null) ?? [];
        foreach (['FontFile2' => 'ttf', 'FontFile3' => null, 'FontFile' => 'pfa'] as $key => $extension) {
            $reference = $descriptor[$key] ?? null;
            if (!$reference instanceof PdfReference) {
                continue;
            }

            $data = $objects->streamData($reference->objectNumber);
            if ($data === null || strlen($data) < 32) {
                continue;
            }

            if ($extension === null) {
                $subtype = $objects->name(($objects->dictionary($objects->value($reference->objectNumber)) ?? [])['Subtype'] ?? null);
                $extension = $subtype === 'OpenType' ? 'otf' : 'cff';
            }

            $directory = sys_get_temp_dir() . '/pdfparser-render-fonts';
            if (!is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }

            $file = $directory . '/' . sha1($data) . '.' . $extension;
            if (!is_file($file)) {
                self::write($file, $data);
            }

            try {
                $draw = new ImagickDraw();
                $draw->setFont($file);
                $draw->setFontSize(100);
                $this->probe()->queryFontMetrics($draw, 'a');
                $this->draws[$file] = $draw;

                return $this->embedded[$fontObjectNumber] = $file;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @var array<int, array{file: string, map: \Closure(int): ?int, space: bool, glyphCount: int}|false> */
    private array $glyphFonts = [];

    /**
     * The embedded program of a font rebuilt to be drawn by glyph id (OpenType::text()), with the PDF widths as
     * advances, and the map from character code (simple fonts) or CID (Type0 with Identity-H/V) to glyph id. For
     * TrueType (FontFile2) and CFF (FontFile3 Type1C, CIDFontType0C, OpenType) programs; null for others.
     * space: U+0020 is the glyph of code 32, so word spacing can be applied to it.
     *
     * @return array{file: string, map: \Closure(int): ?int, space: bool, glyphCount: int}|null
     */
    public function glyphFont(RawObjects $objects, int $fontObjectNumber): ?array {
        if (array_key_exists($fontObjectNumber, $this->glyphFonts)) {
            return $this->glyphFonts[$fontObjectNumber] === false ? null : $this->glyphFonts[$fontObjectNumber];
        }

        $this->glyphFonts[$fontObjectNumber] = false;
        $top = $objects->dictionary($objects->value($fontObjectNumber)) ?? [];
        $composite = isset($top['DescendantFonts']);
        $font = $composite ? ($objects->dictionary(($objects->array($top['DescendantFonts']) ?? [])[0] ?? null) ?? []) : $top;
        if ($composite && !in_array($objects->name($top['Encoding'] ?? null), ['Identity-H', 'Identity-V'], true)) {
            return null; // codes would have to go through the CMap to become CIDs
        }

        $descriptor = $objects->dictionary($font['FontDescriptor'] ?? null) ?? [];
        $ttf = $cff = $type1 = null;
        if (!$composite && ($reference = $descriptor['FontFile'] ?? null) instanceof PdfReference) {
            $data = $objects->streamData($reference->objectNumber);
            $type1 = $data === null ? null : Type1Font::parse($data);
        } elseif (($reference = $descriptor['FontFile2'] ?? null) instanceof PdfReference) {
            $data = $objects->streamData($reference->objectNumber);
            $ttf = $data === null ? null : TrueTypeFont::parse($data);
        } elseif (($reference = $descriptor['FontFile3'] ?? null) instanceof PdfReference) {
            $data = $objects->streamData($reference->objectNumber);
            $cff = $data === null ? null : (CffFont::parse($data) ?? CffFont::fromOpenType($data));
            $ttf = $cff === null && $data !== null ? TrueTypeFont::parse($data) : null;
        }

        if ($ttf === null && $cff === null && $type1 === null) {
            return null;
        }

        [$widths, $default] = $this->pdfWidths($objects, $top, $font, $descriptor, $composite);
        $map = match (true) {
            $composite => $this->cidMap($objects, $font, $ttf, $cff),
            $ttf !== null => $this->trueTypeCodeMap($objects, $top, $ttf),
            $cff !== null => $this->namedCodeMap($objects, $top, $cff->glyphNames(), $cff->gidForCode(...)),
            default => $this->namedCodeMap($objects, $top, $type1->glyphNames(), static fn (int $code): ?int => $type1->gidForName($type1->builtInName($code) ?? '')),
        };
        $glyphCount = $ttf->glyphCount ?? $cff->glyphCount ?? $type1?->glyphCount() ?? 0;
        // a glyph gets the width of the first code that shows it; codes named by Differences go first, an unused
        // code can reach the same glyph through the built-in encoding with a placeholder width
        $advances = [];
        $differences = $composite ? [] : $this->differences($objects, $top);
        foreach (array_replace(array_intersect_key($widths, $differences), $widths) as $code => $width) {
            if (($gid = $map($code)) !== null && !isset($advances[$gid])) {
                $advances[$gid] = $width;
            }
        }

        $spaceGid = $composite || $type1 !== null ? null : $map(32);
        $extra = $spaceGid !== null ? [0x20 => $spaceGid] : [];
        $bytes = match (true) {
            $ttf !== null => $ttf->rebuilt($composite ? $advances + array_fill(0, $glyphCount, $default) : $advances, $extra),
            $cff !== null => $cff->openType($advances, $default, $extra),
            default => $type1->rebuilt($advances),
        };
        $directory = sys_get_temp_dir() . '/pdfparser-render-fonts';
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $file = $directory . '/' . sha1($bytes) . ($ttf !== null ? '.ttf' : ($cff !== null ? '.otf' : '.pfa'));
        if (!is_file($file)) {
            self::write($file, $bytes);
        }

        try {
            $draw = new ImagickDraw();
            $draw->setFont($file);
            $draw->setFontSize(100);
            $this->probe()->queryFontMetrics($draw, OpenType::text([min(3, $glyphCount - 1)]));
        } catch (\Throwable) {
            return null;
        }

        return $this->glyphFonts[$fontObjectNumber] = ['file' => $file, 'map' => $map, 'space' => $spaceGid !== null, 'glyphCount' => $glyphCount];
    }

    /**
     * Widths in 1/1000 em by code (simple fonts) or CID (Type0), and the width of the others
     *
     * @param array<string, mixed> $top
     * @param array<string, mixed> $font
     * @param array<string, mixed> $descriptor
     * @return array{array<int, float>, float}
     */
    private function pdfWidths(RawObjects $objects, array $top, array $font, array $descriptor, bool $composite): array {
        $widths = [];
        if (!$composite) {
            $first = (int) ($objects->number($top['FirstChar'] ?? 0) ?? 0);
            foreach ($objects->array($top['Widths'] ?? null) ?? [] as $i => $width) {
                $widths[$first + $i] = (float) ($objects->number($width) ?? 0);
            }

            return [$widths, (float) ($objects->number($descriptor['MissingWidth'] ?? 0) ?? 0)];
        }

        $entries = $objects->array($font['W'] ?? null) ?? [];
        for ($i = 0; $i < count($entries); $i++) {
            $first = (int) ($objects->number($entries[$i]) ?? 0);
            $next = $objects->resolve($entries[$i + 1] ?? null);
            if (is_array($next)) {
                foreach (array_values($next) as $j => $width) {
                    $widths[$first + $j] = (float) ($objects->number($width) ?? 0);
                }

                $i++;
            } else {
                $last = (int) ($objects->number($next) ?? $first);
                $width = (float) ($objects->number($entries[$i + 2] ?? 0) ?? 0);
                for ($cid = $first; $cid <= min($last, $first + 65535); $cid++) {
                    $widths[$cid] = $width;
                }

                $i += 2;
            }
        }

        return [$widths, (float) ($objects->number($font['DW'] ?? 1000) ?? 1000)];
    }

    /**
     * @param array<string, mixed> $font the descendant CIDFont
     * @return \Closure(int): ?int
     */
    private function cidMap(RawObjects $objects, array $font, ?TrueTypeFont $ttf, ?CffFont $cff): \Closure {
        if ($cff !== null) {
            return static fn (int $cid): ?int => $cff->gidForCid($cid);
        }

        $table = $font['CIDToGIDMap'] ?? null;
        $bytes = $table instanceof PdfReference && !is_string($objects->resolve($table)) ? (string) $objects->streamData($table->objectNumber) : null;
        $count = $ttf->glyphCount ?? 0;

        return $bytes === null
            ? static fn (int $cid): ?int => $cid < $count ? $cid : null
            : static fn (int $cid): ?int => 2 * $cid + 1 < strlen($bytes) ? (ord($bytes[2 * $cid]) << 8) | ord($bytes[2 * $cid + 1]) : null;
    }

    /**
     * 9.6.6.4: symbolic fonts through the (3,0) cmap, others through the Unicode of the encoding in the (3,1) cmap
     *
     * @param array<string, mixed> $top
     * @return \Closure(int): ?int
     */
    private function trueTypeCodeMap(RawObjects $objects, array $top, TrueTypeFont $ttf): \Closure {
        $unicode = $this->simpleEncoding($objects, $top);

        return static function (int $code) use ($ttf, $unicode): ?int {
            if ($ttf->hasCmap(3, 0)) {
                foreach ([0xF000, 0xF100, 0xF200, 0] as $base) {
                    if (($gid = $ttf->lookup(3, 0, $base + $code)) !== null) {
                        return $gid;
                    }
                }
            }

            if (isset($unicode[$code]) && ($gid = $ttf->lookup(3, 1, $unicode[$code])) !== null) {
                return $gid;
            }

            return $ttf->lookup(1, 0, $code) ?? (isset($unicode[$code]) ? $ttf->lookup(0, 3, $unicode[$code]) : null);
        };
    }

    /**
     * 9.6.6.2 (Type 1 and CFF programs): the glyph name from Differences, else from the base encoding when the font
     * names one, else the program's built-in encoding; names are looked up in the program, and by Unicode when the
     * program names the glyph differently
     *
     * @param array<string, mixed> $top
     * @param array<string, int> $glyphNames glyph name => gid
     * @param \Closure(int): ?int $builtIn code => gid in the built-in encoding
     * @return \Closure(int): ?int
     */
    private function namedCodeMap(RawObjects $objects, array $top, array $glyphNames, \Closure $builtIn): \Closure {
        $differences = $this->differences($objects, $top);
        $encoding = $objects->resolve($top['Encoding'] ?? null);
        $explicitBase = is_string($encoding) || isset(($objects->dictionary($encoding) ?? [])['BaseEncoding']);
        $unicode = $explicitBase ? $this->simpleEncoding($objects, $top) : [];
        $gidByUnicode = [];
        if ($unicode !== [] || $differences !== []) {
            foreach ($glyphNames as $name => $gid) {
                if (($codePoint = self::unicodeOfGlyphName($name)) !== null) {
                    $gidByUnicode[$codePoint] ??= $gid;
                }
            }
        }

        return static function (int $code) use ($glyphNames, $builtIn, $differences, $unicode, $gidByUnicode): ?int {
            if (isset($differences[$code])) {
                $gid = $glyphNames[$differences[$code]] ?? $gidByUnicode[self::unicodeOfGlyphName($differences[$code]) ?? -1] ?? null;
                if ($gid !== null) {
                    return $gid;
                }
            }

            if (isset($unicode[$code], $gidByUnicode[$unicode[$code]])) {
                return $gidByUnicode[$unicode[$code]];
            }

            return $builtIn($code) ?? (isset(CffStandard::STANDARD_ENCODING[$code]) ? $glyphNames[CffStandard::STANDARD_ENCODING[$code]] ?? null : null);
        };
    }

    /**
     * Code => glyph name from /Encoding /Differences
     *
     * @param array<string, mixed> $font
     * @return array<int, string>
     */
    private function differences(RawObjects $objects, array $font): array {
        $names = [];
        $code = 0;
        foreach ($objects->array(($objects->dictionary($objects->resolve($font['Encoding'] ?? null)) ?? [])['Differences'] ?? null) ?? [] as $item) {
            if (is_int($item) || is_float($item)) {
                $code = (int) $item;
            } elseif (is_string($item) && str_starts_with($item, '/')) {
                $names[$code++] = substr($item, 1);
            }
        }

        return $names;
    }

    /**
     * Code => Unicode code point of a simple font from /Encoding (base encoding + Differences)
     *
     * @return array<int, int>
     */
    private function simpleEncoding(RawObjects $objects, array $font): array {
        $encoding = $objects->resolve($font['Encoding'] ?? null);
        $base = is_string($encoding) ? substr($encoding, 1) : ($objects->name(($objects->dictionary($encoding) ?? [])['BaseEncoding'] ?? null) ?? 'StandardEncoding');
        $map = [];
        for ($code = 32; $code < 256; $code++) {
            $char = match ($base) {
                'MacRomanEncoding' => @iconv('MACINTOSH', 'UTF-8', chr($code)),
                default => @mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252'),
            };
            if (is_string($char) && $char !== '' && mb_strlen($char) === 1) {
                $map[$code] = mb_ord($char);
            }
        }

        foreach ($this->differences($objects, $font) as $code => $name) {
            if (($codePoint = self::unicodeOfGlyphName($name)) !== null) {
                $map[$code] = $codePoint;
            }
        }

        return $map;
    }

    /** Code point of a glyph name: the Adobe Glyph List, uniXXXX or uXXXX[XX] */
    private static function unicodeOfGlyphName(string $name): ?int {
        $char = \PrinsFrank\GlyphLists\AGlyphList::tryFrom($name)?->getChar();
        if ($char !== null && $char !== '') {
            return mb_ord($char) ?: null;
        }

        if (preg_match('/^uni([0-9A-F]{4})$|^u([0-9A-F]{4,6})$/', $name, $m) === 1) {
            return (int) hexdec($m[1] !== '' ? $m[1] : $m[2]);
        }

        return null;
    }

    /** Writes a font file whole or not at all: other processes may read it while it is written */
    private static function write(string $file, string $bytes): void {
        $temporary = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, $bytes) === strlen($bytes)) {
            @rename($temporary, $file);
        } else {
            @unlink($temporary);
        }
    }

    private function probe(): Imagick {
        return $this->probe ??= (static function (): Imagick {
            $image = new Imagick();
            $image->newImage(1, 1, 'white');
            $image->setImageProperty('type:hinting', 'off');

            return $image;
        })();
    }

    /** Width of $text in the substitute font at $size, the sum of cached character widths (no kerning) */
    public function width(string $file, string $text, float $size): float {
        $this->probe();
        if (!isset($this->draws[$file])) {
            $draw = new ImagickDraw();
            $draw->setFont($file);
            $draw->setFontSize(100);
            $this->draws[$file] = $draw;
        }

        $total = 0.0;
        foreach (mb_str_split($text) as $char) {
            if (!isset($this->widths[$file][$char])) {
                $metrics = $this->probe->queryFontMetrics($this->draws[$file], $char === ' ' ? 'a a' : $char);
                $this->widths[$file][$char] = $char === ' '
                    ? $metrics['textWidth'] - 2 * ($this->widths[$file]['a'] ?? $this->probe->queryFontMetrics($this->draws[$file], 'a')['textWidth'])
                    : (float) $metrics['textWidth'];
            }

            $total += $this->widths[$file][$char];
        }

        return $total * $size / 100;
    }
}
