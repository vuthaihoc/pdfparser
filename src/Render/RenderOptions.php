<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Imagick;

/**
 * What the renderer draws and how closely: the defaults draw the closest result it can, the other values trade
 * accuracy for time or memory. RenderResult::$timings shows where the time of a page goes.
 *
 * @experimental
 */
final class RenderOptions {
    public const TEXT_EMBEDDED = 'embedded';       // embedded font programs by glyph id, substitutes for the others
    public const TEXT_SUBSTITUTE = 'substitute';   // system fonts only, stretched to the PDF widths
    public const TEXT_SKIP = 'skip';

    public const IMAGES_FULL = 'full';
    public const IMAGES_BOX = 'box';               // a grey box where the image is, nothing decoded
    public const IMAGES_SKIP = 'skip';

    public const SHADINGS_BANDS = 'bands';         // axial/radial shadings as bands of colour
    public const SHADINGS_FLAT = 'flat';           // one colour
    public const SHADINGS_SKIP = 'skip';

    public function __construct(
        /** drawn at this × the result size and scaled down: anti-aliasing of paths and images */
        public readonly int $supersample = 2,
        /** Imagick::FILTER_* used to scale the supersampled canvas down */
        public readonly int $downscaleFilter = Imagick::FILTER_TRIANGLE,
        public readonly string $text = self::TEXT_EMBEDDED,
        /**
         * TJ adjustments (1/1000 em) from which the rest of a string is drawn as a separate run at its exact place;
         * smaller ones are absorbed by stretching the run. 0: every adjustment, INF: none (fewest draw calls).
         */
        public readonly float $textSplitThreshold = 100,
        public readonly string $images = self::IMAGES_FULL,
        /** images are decoded and resampled at this × the device pixels they cover (< 1: faster and lighter, blurrier) */
        public readonly float $imageResolution = 1.0,
        /** apply soft masks and stencil/colour key masks of images */
        public readonly bool $imageMasks = true,
        /** fill and stroke paths */
        public readonly bool $paths = true,
        /** apply clipping paths (without: content can spill out of its clip, fewer masks to compute) */
        public readonly bool $clipping = true,
        public readonly string $shadings = self::SHADINGS_BANDS,
        public readonly int $shadingBands = 48,
        /** draw the appearance streams of annotations */
        public readonly bool $annotations = true,
        /** draw calls queued before they are rasterized */
        public readonly int $batchSize = 400,
        /** bytes of pixel cache ImageMagick keeps in memory (beyond: disk), null: its default; set for the process */
        public readonly ?int $memoryLimit = null,
        /** ImageMagick threads, null: its default; set for the process */
        public readonly ?int $threads = null,
    ) {}

    /** Text and paths as they are, images at half resolution without masks, flat shadings, no supersampling */
    public static function fast(): self {
        return new self(supersample: 1, textSplitThreshold: 250, imageResolution: 0.5, imageMasks: false, shadings: self::SHADINGS_FLAT);
    }

    /** Layout only: text in substitute fonts, grey boxes for images, no shadings, clipping or supersampling */
    public static function draft(): self {
        return new self(supersample: 1, text: self::TEXT_SUBSTITUTE, textSplitThreshold: INF, images: self::IMAGES_BOX, clipping: false, shadings: self::SHADINGS_FLAT, annotations: false);
    }
}
