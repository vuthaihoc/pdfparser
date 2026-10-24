<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/** @experimental */
final readonly class PdfString {
    public function __construct(
        public string $bytes,
    ) {}
}
