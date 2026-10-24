<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\ContentStream\PositionedText;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\HexadecimalStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Document\Object\Decorator\Font;

#[CoversClass(PositionedTextElement::class)]
class PositionedTextElementTest extends TestCase {
    private static function element(): PositionedTextElement {
        return new PositionedTextElement(
            [new TextSegment(new HexadecimalStringValue('0041 0142'), -120), new TextSegment(new LiteralStringValue('\001C'), null)],
            new TransformationMatrix(1, 0, 0, 1, 0, 0),
            new TextState(null, 10),
        );
    }

    public function testCharacterCodesOfSimpleFontAreBytes(): void {
        $font = static::createStub(Font::class);
        $font->method('getDescendantFonts')->willReturn([]);

        static::assertSame([0x00, 0x41, 0x01, 0x42, 0x01, 0x43], self::element()->getCharacterCodes($font));
    }

    public function testCharacterCodesOfCompositeFontAreTwoBytes(): void {
        $font = static::createStub(Font::class);
        $font->method('getDescendantFonts')->willReturn([new Dictionary()]);

        static::assertSame([0x0041, 0x0142, 0x0143], self::element()->getCharacterCodes($font));
    }
}
