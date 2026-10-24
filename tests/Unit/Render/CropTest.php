<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Exception\InvalidArgumentException;
use PrinsFrank\PdfParser\Render\Crop;

#[CoversClass(Crop::class)]
class CropTest extends TestCase {
    public function testFractionsAreKeptWithinThePage(): void {
        static::assertSame([0.1, 0.0, 1.0, 0.5], Crop::fractions(0.1, -0.2, 1.3, 0.5)->resolve(595, 842));
    }

    public function testPixelsAreSharesOfThePageRenderedAtTheReferenceWidth(): void {
        // a 1000 px wide rendering of an A4 page is 1000 x 1415 px
        [$left, $top, $right, $bottom] = Crop::pixels(141, 162, 921, 426, 1000)->resolve(595.28, 841.89);

        static::assertEqualsWithDelta(0.141, $left, 1e-9);
        static::assertEqualsWithDelta(162 / 1414.27, $top, 1e-5);
        static::assertEqualsWithDelta(0.921, $right, 1e-9);
        static::assertEqualsWithDelta(426 / 1414.27, $bottom, 1e-5);
    }

    public function testEmptyCrop(): void {
        $this->expectException(InvalidArgumentException::class);
        Crop::fractions(0.5, 0.1, 0.5, 0.2);
    }

    public function testCropOutsideThePage(): void {
        $this->expectException(InvalidArgumentException::class);
        Crop::pixels(1200, 0, 1300, 100, 1000)->resolve(595, 842);
    }
}
