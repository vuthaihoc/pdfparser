<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\ContentStream\Command\Operator\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\TextShowingOperator;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\ColorState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;

#[CoversClass(TextShowingOperator::class)]
class TextShowingOperatorTest extends TestCase {
    /** @return list<array{string, int|float|null}> */
    private static function segments(TextShowingOperator $operator, string $operands): array {
        $identity = new TransformationMatrix(1, 0, 0, 1, 0, 0);

        return array_map(
            static fn(TextSegment $segment) => [$segment->textString->getBinaryString(), $segment->offset],
            $operator->getPositionedTextElement($operands, $identity, $identity, new TextState(null, 10), new ColorState())->textSegments,
        );
    }

    public function testSegmentsOfShowArray(): void {
        static::assertSame([['A', -120], ['B', null]], self::segments(TextShowingOperator::SHOW_ARRAY, '[(A)-120(B)]'));
        static::assertSame([['A', -120], ['B', null]], self::segments(TextShowingOperator::SHOW_ARRAY, '[ (A) -120 (B) ]'));
        static::assertSame([['A', -0.5], ['B', 30.25]], self::segments(TextShowingOperator::SHOW_ARRAY, '[(A)-.5(B)30.25]'));
        static::assertSame([['', 250], ['A', 3]], self::segments(TextShowingOperator::SHOW_ARRAY, '[250(A)1 2]'));
        static::assertSame([['a(b)c', 5], ['d)', null]], self::segments(TextShowingOperator::SHOW_ARRAY, '[(a(b)c)5(d\))]'));
        static::assertSame([['x\\', null], ['AB', null]], self::segments(TextShowingOperator::SHOW_ARRAY, '[(x\\\\)<4142>]'));
    }

    public function testSegmentsOfShow(): void {
        static::assertSame([['Hello (world)', null]], self::segments(TextShowingOperator::SHOW, '(Hello \(world\))'));
        static::assertSame([['Hello world', null]], self::segments(TextShowingOperator::MOVE_SHOW_SPACING, '2 0.5 (Hello world)'));
    }

    public function testMoveShowSpacingSetsWordAndCharacterSpacing(): void {
        $textState = TextShowingOperator::MOVE_SHOW_SPACING->applyToTextState('2 .5 (Hello world)', new TextState(null, 10));

        static::assertSame(2.0, $textState->wordSpace);
        static::assertSame(0.5, $textState->charSpace);
    }
}
