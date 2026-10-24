<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Imagick;

/** @experimental */
final readonly class RenderResult {
    /**
     * @param float $score 0..1, how close the image probably is to a real renderer (see Fidelity)
     * @param array<string, array{count: int, area: float}> $issues what was skipped or approximated, area = page share
     * @param array<string, mixed> $stats
     * @param array<string, float> $timings seconds
     */
    public function __construct(
        public Imagick $image,
        public float $score,
        public array $issues,
        public array $stats,
        public array $timings,
    ) {}
}
