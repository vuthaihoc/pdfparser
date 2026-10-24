<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State;

/**
 * @internal
 *
 * @specification Table 59 - Path-painting operators
 */
enum PathPaintingOperator: string {
    case STROKE = 'S';
    case CLOSE_STROKE = 's';
    case FILL = 'f';

    /** Identical to FILL */
    case FILL_DEPRECATED = 'F';
    case FILL_EVEN_ODD = 'f*';
    case FILL_STROKE = 'B';
    case FILL_STROKE_EVEN_ODD = 'B*';
    case CLOSE_FILL_STROKE_NON_ZERO = 'b';

    /** Close, fill using the even-odd rule, and stroke */
    case CLOSE_FILL_STROKE = 'b*';
    case END = 'n';

    /** @specification Table 76 - Shading operator: paints the shading named in the operand over the current clipping path */
    case PAINT_SHADING = 'sh';
}
