<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\PdfParser;

#[CoversNothing]
class ObjectByteRangeTest extends TestCase {
    public function testByteRangeOfAnObjectEndsWhereTheNextOneStarts(): void {
        $file = __DIR__ . '/samples/h3-simple-string.pdf';
        $content = (string) file_get_contents($file);
        $document = (new PdfParser())->parseFile($file, false);

        [$from, $to] = $document->getObjectByteRange(3) ?? self::fail();
        static::assertStringStartsWith('3 0 obj', substr($content, $from, $to - $from));
        static::assertStringStartsWith('4 0 obj', substr($content, $to));
        static::assertNull($document->getObjectByteRange(999));
    }
}
