<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature\Render;

use ImagickException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\PdfParser;
use PrinsFrank\PdfParser\Render\Crop;
use PrinsFrank\PdfParser\Render\PageRenderer;
use PrinsFrank\PdfParser\Render\RenderOptions;

#[CoversClass(PageRenderer::class)]
#[RequiresPhpExtension('imagick')]
class PageRendererTest extends TestCase {
    private string $fontCache;

    #[Override]
    protected function setUp(): void {
        $this->fontCache = sys_get_temp_dir() . '/pdfparser-render-test-' . uniqid();
    }

    #[Override]
    protected function tearDown(): void {
        foreach (self::files($this->fontCache . '/*') as $file) {
            unlink($file);
        }

        if (is_dir($this->fontCache)) {
            rmdir($this->fontCache);
        }
    }

    /** @throws ImagickException|PdfParserException */
    public function testRendersAPageWithItsEmbeddedFonts(): void {
        $document = (new PdfParser())->parseFile(dirname(__DIR__, 2) . '/Samples/files/gdocs-hello-world-simple/file.pdf');
        $result = (new PageRenderer($document, new RenderOptions(fontCacheDirectory: $this->fontCache)))->render($document->getPage(1) ?? self::fail(), 200);

        static::assertSame(200, $result->image->getImageWidth());
        static::assertSame(283, $result->image->getImageHeight());
        static::assertGreaterThan(0, $result->stats['texts_glyph_ids'] ?? 0);
        static::assertGreaterThan(0.9, $result->score);
        static::assertArrayHasKey('total', $result->timings);
    }

    /** @throws ImagickException|PdfParserException */
    public function testRebuiltFontsAreReadFromTheCacheByAnotherRenderer(): void {
        $file = dirname(__DIR__, 2) . '/Samples/files/gdocs-hello-world-simple/file.pdf';
        /** @throws ImagickException|PdfParserException */
        $render = function () use ($file): string {
            $document = (new PdfParser())->parseFile($file);

            return (new PageRenderer($document, new RenderOptions(fontCacheDirectory: $this->fontCache)))->render($document->getPage(1) ?? self::fail(), 200)->image->getImageBlob();
        };

        $first = $render();
        $cached = self::files($this->fontCache . '/*.json');
        static::assertNotSame([], $cached);
        $modified = array_map(filemtime(...), $cached);

        static::assertSame($first, $render());
        clearstatcache();
        static::assertSame($modified, array_map(filemtime(...), $cached));
    }

    /** @throws ImagickException|PdfParserException */
    public function testRendersARegionOfThePage(): void {
        $document = (new PdfParser())->parseFile(dirname(__DIR__, 2) . '/Samples/files/gdocs-hello-world-simple/file.pdf');
        $renderer = new PageRenderer($document, new RenderOptions(fontCacheDirectory: $this->fontCache));
        $result = $renderer->render($document->getPage(1) ?? self::fail(), 300, Crop::fractions(0.1, 0.05, 0.6, 0.25));

        static::assertSame(300, $result->image->getImageWidth());
        static::assertEqualsWithDelta(300 / 0.5 * 0.2 * 842 / 595, $result->image->getImageHeight(), 1.5);
    }

    /** @return list<string> */
    private static function files(string $pattern): array {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }
}
