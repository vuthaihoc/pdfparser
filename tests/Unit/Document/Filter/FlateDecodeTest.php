<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Filter\Decode\FlateDecode;
use PrinsFrank\PdfParser\Document\Filter\Decode\LZWFlatePredictorValue;

#[CoversClass(FlateDecode::class)]
class FlateDecodeTest extends TestCase {
    public function testWithoutPredictor(): void {
        static::assertSame('Hello world', FlateDecode::decodeBinary((string) gzcompress('Hello world'), LZWFlatePredictorValue::None));
    }

    /** Two RGB pixels per row: Sub and Paeth take the previous pixel three bytes back, Up and Average the row above */
    public function testPngPredictorsUseTheBytesOfThePreviousPixel(): void {
        $pixels = [[10, 20, 30, 40, 50, 60], [15, 25, 35, 45, 55, 65], [1, 2, 3, 4, 5, 6], [9, 8, 7, 6, 5, 4], [100, 90, 80, 70, 60, 50]];
        $filtered = chr(0) . pack('C*', ...$pixels[0])
            . chr(1) . pack('C*', 15, 25, 35, 45 - 15, 55 - 25, 65 - 35)
            . chr(2) . pack('C*', ...array_map(static fn (int $v, int $above) => ($v - $above) & 0xFF, $pixels[2], $pixels[1]))
            . chr(3) . pack('C*', 9 - (1 >> 1), 8 - (2 >> 1), 7 - (3 >> 1), (6 - ((9 + 4) >> 1)) & 0xFF, (5 - ((8 + 5) >> 1)) & 0xFF, (4 - ((7 + 6) >> 1)) & 0xFF)
            . chr(4) . pack('C*', 100 - 9, 90 - 8, 80 - 7, (70 - 100) & 0xFF, (60 - 90) & 0xFF, (50 - 80) & 0xFF);

        static::assertSame(
            implode('', array_map(static fn (array $row) => pack('C*', ...$row), $pixels)),
            FlateDecode::decodeBinary((string) gzcompress($filtered), LZWFlatePredictorValue::PngOptimum, 2, 3, 8),
        );
    }

    public function testTiffPredictor(): void {
        static::assertSame(
            pack('C*', 10, 20, 30, 40, 5, 6, 7, 8),
            FlateDecode::decodeBinary((string) gzcompress(pack('C*', 10, 20, 20, 20, 5, 6, 2, 2)), LZWFlatePredictorValue::TIFFPredictor2, 2, 2, 8),
        );
    }
}
