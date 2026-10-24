<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * What the renderer does, from the closest result it can draw to faster or lighter approximations
 *
 * @experimental
 */
final class RenderOptions {
    public function __construct(
        /** drawn at this × the result size and scaled down (anti-aliasing of paths and images) */
        public readonly int $supersample = 2,
        /** draw text with the embedded font programs (by glyph id); false: substitute system fonts only */
        public readonly bool $embeddedFonts = true,
        /**
         * TJ adjustments (1/1000 em) from which the rest of a string is drawn as a separate run at its exact place;
         * smaller ones are absorbed by stretching the run. 0: every adjustment, INF: none (fewest draw calls).
         */
        public readonly float $textSplitThreshold = 100,
    ) {}
}
