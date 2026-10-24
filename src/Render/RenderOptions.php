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
        /** drawn at this × the result size and scaled down: anti-aliasing of paths, text edges and images */
        public readonly int $supersample = 2,
        /** Imagick::FILTER_* used to scale the supersampled canvas down */
        public readonly int $downscaleFilter = Imagick::FILTER_TRIANGLE,
        public readonly string $text = self::TEXT_EMBEDDED,
        /**
         * TJ adjustments (1/1000 em) from which the rest of a string is drawn as a separate run at its exact place;
         * smaller ones are absorbed by stretching the run. 0: every adjustment, INF: none (fewest draw calls).
         * 250 keeps word gaps exact: as close as 0 on the sample set (SSIM −0.001) for 60 % less time.
         */
        public readonly float $textSplitThreshold = 250,
        public readonly string $images = self::IMAGES_FULL,
        /** image pixels per pixel of the result an image covers (1: as sharp as the result can show; < 1: lighter, blurrier) */
        public readonly float $imageResolution = 1.0,
        /** apply soft masks and stencil/colour key masks of images */
        public readonly bool $imageMasks = true,
        /** fill and stroke paths */
        public readonly bool $paths = true,
        /** apply clipping paths (without: content can spill out of its clip) */
        public readonly bool $clipping = true,
        public readonly string $shadings = self::SHADINGS_BANDS,
        /** colours of an axial/radial shading */
        public readonly int $shadingBands = 64,
        /** draw the appearance streams of annotations */
        public readonly bool $annotations = true,
        /** draw calls queued before they are rasterized */
        public readonly int $batchSize = 400,
        /** bytes of pixel cache ImageMagick keeps in memory (beyond: disk), null: its default; set for the process */
        public readonly ?int $memoryLimit = null,
        /** ImageMagick threads, null: its default; set for the process */
        public readonly ?int $threads = null,
        /** where rebuilt embedded fonts are kept between renders and processes, null: pdfparser-render-fonts in the temporary directory */
        public readonly ?string $fontCacheDirectory = null,
    ) {}

    /** The defaults: embedded fonts, every construct drawn, 2× supersampling */
    public static function balanced(): self {
        return new self();
    }

    /** As close as it gets: 3× supersampling with a Lanczos downscale, runs split at almost every TJ adjustment */
    public static function quality(): self {
        return new self(supersample: 3, downscaleFilter: Imagick::FILTER_LANCZOS, textSplitThreshold: 50, shadingBands: 128);
    }

    /** Everything drawn, without supersampling and with each string as one run: thinner anti-aliasing, glyphs stretched within a run */
    public static function fast(): self {
        return new self(supersample: 1, textSplitThreshold: INF, shadingBands: 32);
    }

    /** Small previews (up to ~200 px wide) where glyph shapes do not show: substitute fonts, one run per string */
    public static function thumbnail(): self {
        return new self(text: self::TEXT_SUBSTITUTE, textSplitThreshold: INF, shadingBands: 32);
    }

    /** Layout only: text in substitute fonts, grey boxes for images, no clipping, flat shadings, no annotations */
    public static function draft(): self {
        return new self(supersample: 1, text: self::TEXT_SUBSTITUTE, textSplitThreshold: INF, images: self::IMAGES_BOX, clipping: false, shadings: self::SHADINGS_FLAT, annotations: false);
    }

    /** For small workers: no supersampling, images at half resolution, ImageMagick's pixel cache capped at 64 MB (the rest on disk) */
    public static function lowMemory(): self {
        return new self(supersample: 1, imageResolution: 0.5, batchSize: 200, memoryLimit: 64 << 20);
    }

    /** A preset by name (balanced, quality, fast, thumbnail, draft, low_memory), e.g. from configuration */
    public static function preset(string $name): self {
        return match (strtolower(str_replace(['-', ' '], '_', $name))) {
            'balanced', 'default' => self::balanced(),
            'quality' => self::quality(),
            'fast' => self::fast(),
            'thumbnail' => self::thumbnail(),
            'draft' => self::draft(),
            'low_memory', 'lowmemory' => self::lowMemory(),
            default => throw new \InvalidArgumentException(sprintf('Unknown render preset "%s"', $name)),
        };
    }
}
