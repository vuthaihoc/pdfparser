<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State;

use Override;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithTextState;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithTransformationMatrix;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\ProducesPositionedTextElements;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\ColorState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\HexadecimalStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

/** @internal */
enum TextShowingOperator: string implements InteractsWithTextState, ProducesPositionedTextElements, InteractsWithTransformationMatrix {
    case SHOW = 'Tj';
    case MOVE_SHOW = '\'';
    case MOVE_SHOW_SPACING = '"';
    case SHOW_ARRAY = 'TJ';

    #[Override]
    public function applyToTransformationMatrix(string $operands, TransformationMatrix $transformationMatrix, TextState $textState): TransformationMatrix {
        if ($this === self::MOVE_SHOW || $this === self::MOVE_SHOW_SPACING) {
            return (new TransformationMatrix(1, 0, 0, 1, 0.0, -$textState->leading))
                ->multiplyWith($transformationMatrix);
        }

        return $transformationMatrix;
    }

    /** @throws ParseFailureException */
    #[Override]
    public function applyToTextState(string $operands, TextState $textState): TextState {
        if ($this === self::MOVE_SHOW_SPACING) {
            if (preg_match('/^\s*([-+]?(?:\d+\.?\d*|\.\d+))\s+([-+]?(?:\d+\.?\d*|\.\d+))/', $operands, $spacing) !== 1) {
                throw new ParseFailureException(sprintf('Operands "%s" do not start with word and character spacing', $operands));
            }

            array_shift($spacing);

            return new TextState(
                $textState->fontName,
                $textState->fontSize,
                (float) $spacing[1],
                (float) $spacing[0],
                $textState->scale,
                $textState->leading,
                $textState->render,
                $textState->rise,
            );
        }

        return $textState;
    }

    #[Override]
    public function getPositionedTextElement(
        string $operands,
        TransformationMatrix $textMatrix,
        TransformationMatrix $globalTransformationMatrix,
        TextState $textState,
        ColorState $colorState,
    ): PositionedTextElement {
        if ($this === self::MOVE_SHOW_SPACING) { // aw ac string: the spacing is applied to the text state
            $operands = substr($operands, strcspn($operands, '(<'));
        }

        $segments = self::segments($operands);
        if ($segments === []) {
            throw new ParseFailureException(sprintf('Operands "%s" is not in a recognized format', $operands));
        }

        return new PositionedTextElement(
            array_map(
                fn(array $segment) => new TextSegment(
                    str_starts_with($segment[0], '(')
                        ? LiteralStringValue::fromValue($segment[0]) ?? throw new ParseFailureException()
                        : HexadecimalStringValue::fromValue($segment[0]) ?? throw new ParseFailureException(),
                    $segment[1],
                ),
                $segments,
            ),
            $textMatrix->multiplyWith($globalTransformationMatrix),  // 9.4.4, Note 2 on Trm calculation
            $textState,
        );
    }

    /**
     * The strings of a Tj/'/TJ operand with the adjustment that follows each of them (summed when there are several).
     * Strings can contain balanced or escaped parentheses, numbers can be separated by whitespace or not and be
     * written as .5; an adjustment before the first string is kept on an empty string.
     *
     * @return list<array{string, int|float|null}>
     */
    private static function segments(string $operands): array {
        $segments = [];
        $length = strlen($operands);
        for ($index = 0; $index < $length; $index++) {
            $char = $operands[$index];
            if ($char === '(') {
                $start = $index;
                for ($depth = 0; $index < $length; $index++) {
                    if ($operands[$index] === '\\') {
                        $index++;
                    } elseif ($operands[$index] === '(') {
                        $depth++;
                    } elseif ($operands[$index] === ')' && --$depth === 0) {
                        break;
                    }
                }

                $segments[] = [substr($operands, $start, $index - $start + 1), null];
            } elseif ($char === '<') {
                $end = strpos($operands, '>', $index);
                $segments[] = [substr($operands, $index, ($end === false ? $length : $end + 1) - $index), null];
                $index = $end === false ? $length : $end;
            } elseif (preg_match('/\G[-+]?(?:\d+\.?\d*|\.\d+)/', $operands, $match, 0, $index) === 1) {
                $number = str_contains($match[0], '.') ? (float) $match[0] : (int) $match[0];
                if ($segments === []) {
                    $segments[] = ['()', null];
                }

                $last = count($segments) - 1;
                $segments[$last][1] = $segments[$last][1] === null ? $number : $segments[$last][1] + $number;
                $index += strlen($match[0]) - 1;
            }
        }

        return $segments;
    }
}
