<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Filter\Decode;

use PrinsFrank\PdfParser\Exception\InvalidArgumentException;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\Exception\RuntimeException;

/** @internal */
class FlateDecode {
    /**
     * @param int $columns samples per row (DecodeParms /Columns)
     * @param int $colors components per sample (/Colors)
     * @param int $bitsPerComponent (/BitsPerComponent)
     * @throws PdfParserException
     * @return string in binary format
     */
    public static function decodeBinary(string $value, LZWFlatePredictorValue $predictor, int $columns = 1, int $colors = 1, int $bitsPerComponent = 8): string {
        if ($columns < 1 || $colors < 1 || $bitsPerComponent < 1) {
            throw new InvalidArgumentException(sprintf('Columns, colors and bits per component should be 1 or more, %d, %d and %d given', $columns, $colors, $bitsPerComponent));
        }

        $decodedValue = @gzuncompress($value);
        if ($decodedValue === false) {
            if (($tmpFile = tempnam(sys_get_temp_dir(), 'gz')) === false) {
                throw new RuntimeException('Unable to create temporary file');
            }

            file_put_contents($tmpFile, "\x1f\x8b\x08\x00\x00\x00\x00\x00" . $value);
            $decodedValue = file_get_contents('compress.zlib://' . $tmpFile);
            unlink($tmpFile);
            if (in_array($decodedValue, ['', false], true)) {
                throw new ParseFailureException('Unable to gzuncompress value "' . substr(trim($value), 0, 30) . '..."');
            }
        }

        if ($predictor === LZWFlatePredictorValue::None) {
            return $decodedValue;
        }

        $rowLength = intdiv($columns * $colors * $bitsPerComponent + 7, 8);
        if ($predictor === LZWFlatePredictorValue::TIFFPredictor2) {
            if ($bitsPerComponent !== 8) {
                throw new ParseFailureException(sprintf('Unsupported TIFF predictor with %d bits per component', $bitsPerComponent));
            }

            return self::tiffPredictor($decodedValue, $rowLength, $colors);
        }

        return self::pngPredictors($decodedValue, $rowLength, max(1, intdiv($colors * $bitsPerComponent + 7, 8)));
    }

    /**
     * PNG predictors (7.4.4.4): every row starts with the number of its algorithm, which predicts each byte from the
     * byte of the previous pixel ($bytesPerPixel back), the byte above it, or both
     *
     * @throws ParseFailureException
     */
    private static function pngPredictors(string $data, int $rowLength, int $bytesPerPixel): string {
        $rows = [];
        $previous = array_fill(0, $rowLength, 0);
        $length = strlen($data);
        for ($position = 0; $position < $length; $position += $rowLength + 1) {
            $algorithm = PNGPredictorAlgorithm::tryFrom(ord($data[$position]))
                ?? throw new ParseFailureException(sprintf('Unrecognized row algorithm %d', ord($data[$position])));
            $row = self::bytes(str_pad(substr($data, $position + 1, $rowLength), $rowLength, "\0"));
            switch ($algorithm) {
                case PNGPredictorAlgorithm::None:
                    break;
                case PNGPredictorAlgorithm::Sub:
                    for ($i = $bytesPerPixel; $i < $rowLength; $i++) {
                        $row[$i] = ($row[$i] + $row[$i - $bytesPerPixel]) & 0xFF;
                    }

                    break;
                case PNGPredictorAlgorithm::Up:
                    for ($i = 0; $i < $rowLength; $i++) {
                        $row[$i] = ($row[$i] + $previous[$i]) & 0xFF;
                    }

                    break;
                case PNGPredictorAlgorithm::Average:
                    for ($i = 0; $i < $rowLength; $i++) {
                        $row[$i] = ($row[$i] + ((($i >= $bytesPerPixel ? $row[$i - $bytesPerPixel] : 0) + $previous[$i]) >> 1)) & 0xFF;
                    }

                    break;
                case PNGPredictorAlgorithm::Paeth:
                    for ($i = 0; $i < $rowLength; $i++) {
                        $left = $i >= $bytesPerPixel ? $row[$i - $bytesPerPixel] : 0;
                        $above = $previous[$i];
                        $upperLeft = $i >= $bytesPerPixel ? $previous[$i - $bytesPerPixel] : 0;
                        $estimate = $left + $above - $upperLeft;
                        $distanceLeft = abs($estimate - $left);
                        $distanceAbove = abs($estimate - $above);
                        $distanceUpperLeft = abs($estimate - $upperLeft);
                        $row[$i] = ($row[$i] + ($distanceLeft <= $distanceAbove && $distanceLeft <= $distanceUpperLeft ? $left : ($distanceAbove <= $distanceUpperLeft ? $above : $upperLeft))) & 0xFF;
                    }

                    break;
            }

            $rows[] = pack('C*', ...$row);
            $previous = $row;
        }

        return implode('', $rows);
    }

    /** TIFF predictor 2 with 8 bits per component: every component is the difference with the same component of the previous pixel */
    private static function tiffPredictor(string $data, int $rowLength, int $colors): string {
        $rows = [];
        foreach (str_split($data, max(1, $rowLength)) as $rowData) {
            $row = self::bytes($rowData);
            for ($i = $colors; $i < count($row); $i++) {
                $row[$i] = ($row[$i] + $row[$i - $colors]) & 0xFF;
            }

            $rows[] = pack('C*', ...$row);
        }

        return implode('', $rows);
    }

    /** @return list<int> */
    private static function bytes(string $data): array {
        $bytes = unpack('C*', $data);

        return $bytes === false ? [] : array_values(array_map(static fn (mixed $byte): int => is_int($byte) ? $byte : 0, $bytes));
    }
}
