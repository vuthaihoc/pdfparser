<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/** @experimental */
final readonly class PdfReference {
    public function __construct(
        public int $objectNumber,
        public int $generationNumber,
    ) {}
}
