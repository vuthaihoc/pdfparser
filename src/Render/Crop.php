<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use PrinsFrank\PdfParser\Exception\InvalidArgumentException;

/**
 * A region of a page to render, on the page as it is displayed (after /Rotate), origin top left
 *
 * @experimental
 */
final class Crop {
    private function __construct(
        private readonly float $left,
        private readonly float $top,
        private readonly float $right,
        private readonly float $bottom,
        private readonly ?float $referenceWidth,
    ) {
        if ($right <= $left || $bottom <= $top) {
            throw new InvalidArgumentException(sprintf('Empty crop %s, %s, %s, %s', $left, $top, $right, $bottom));
        }
    }

    /** Shares of the page width and height, 0..1 */
    public static function fractions(float $left, float $top, float $right, float $bottom): self {
        return new self($left, $top, $right, $bottom, null);
    }

    /**
     * Pixels of the page rendered $referenceWidth pixels wide (its height follows from the page), as positions
     * measured on a rendered page are given
     */
    public static function pixels(float $left, float $top, float $right, float $bottom, float $referenceWidth): self {
        if ($referenceWidth <= 0) {
            throw new InvalidArgumentException(sprintf('Reference width should be positive, %s given', $referenceWidth));
        }

        return new self($left, $top, $right, $bottom, $referenceWidth);
    }

    /**
     * The region as shares of a page displayed $pageWidth × $pageHeight (any unit), within the page
     *
     * @return array{float, float, float, float} left, top, right, bottom
     */
    public function resolve(float $pageWidth, float $pageHeight): array {
        [$x, $y] = $this->referenceWidth === null ? [1.0, 1.0] : [$this->referenceWidth, $this->referenceWidth * $pageHeight / max(1e-9, $pageWidth)];
        $box = [
            max(0.0, min(1.0, $this->left / $x)),
            max(0.0, min(1.0, $this->top / $y)),
            max(0.0, min(1.0, $this->right / $x)),
            max(0.0, min(1.0, $this->bottom / $y)),
        ];
        if ($box[2] - $box[0] < 1e-6 || $box[3] - $box[1] < 1e-6) {
            throw new InvalidArgumentException('The crop lies outside the page');
        }

        return $box;
    }
}
