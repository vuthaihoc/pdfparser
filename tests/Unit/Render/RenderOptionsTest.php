<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Render\RenderOptions;

#[CoversClass(RenderOptions::class)]
class RenderOptionsTest extends TestCase {
    public function testWithChangesOnlyTheGivenOptions(): void {
        $options = RenderOptions::fast()->with(imageResolution: 0.5, fontCacheDirectory: '/tmp/fonts');

        static::assertSame(1, $options->supersample);
        static::assertSame(INF, $options->textSplitThreshold);
        static::assertSame(0.5, $options->imageResolution);
        static::assertSame('/tmp/fonts', $options->fontCacheDirectory);
    }

    public function testPresetByName(): void {
        static::assertEquals(RenderOptions::lowMemory(), RenderOptions::preset('low-memory'));
        static::assertEquals(new RenderOptions(), RenderOptions::preset('balanced'));
    }
}
