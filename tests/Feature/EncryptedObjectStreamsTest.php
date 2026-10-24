<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\PdfParser;

#[CoversNothing]
class EncryptedObjectStreamsTest extends TestCase {
    /** Object streams of an encrypted document are encrypted too: the offsets of the objects in them as well */
    public function testObjectsInObjectStreamsOfAnEncryptedDocument(): void {
        $document = (new PdfParser())->parseFile(__DIR__ . '/samples/encrypted-object-streams.pdf');

        static::assertSame(1, $document->getNumberOfPages());
        static::assertSame('Hello world', trim((string) $document->getPage(1)?->getText()));
    }
}
