<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * How close a render probably is to a real renderer. Every construct the renderer skips or approximates adds
 * weight × (share of the page it covers). score = 1 − sum, clamped to 0..1. Weights say how far the result can be
 * from the real thing for that construct (1 = whatever was there is missing).
 *
 * @experimental
 */
final class Fidelity {
    public const WEIGHTS = [
        'image_undecodable' => 1.0,   // image not drawn (JBIG2, unknown filter, broken data)
        'image_approximate' => 0.3,   // drawn with approximate colours or without a colour key mask
        'image_clip' => 0.4,          // image drawn without its non-rectangular clip
        'shading_gradient' => 0.25,   // axial/radial shading drawn as a 2-colour gradient
        'shading_flat' => 0.6,        // other shadings, filled with one colour
        'pattern' => 0.5,             // pattern fill/stroke drawn with one colour
        'soft_mask' => 0.4,           // content under an ExtGState soft mask drawn without it
        'blend_mode' => 0.2,          // non-Normal blend mode drawn as Normal
        'clip_intersection' => 0.15,  // nested clips: only the innermost is applied
        'type3_text' => 0.5,          // Type 3 glyphs drawn with a substitute font
        'text_substitute' => 0.04,    // text drawn with a substitute font, stretched to the right width
        'text_embedded' => 0.01,      // text drawn with the embedded font program
        'text_missing' => 0.8,        // text not drawn: no font, or neither glyphs nor Unicode text for its codes
        'colour_space' => 0.3,        // unknown colour space drawn grey
        'form_depth' => 1.0,          // form nesting too deep or recursive, skipped
        'annotation' => 0.5,          // annotation appearance that could not be drawn
        'content_error' => 1.0,       // the rest of a content stream after an error
        // left out or simplified by RenderOptions
        'image_box' => 0.9,
        'image_skipped' => 1.0,
        'image_mask_skipped' => 0.3,
        'path_skipped' => 1.0,
        'clip_skipped' => 0.2,
        'text_skipped' => 1.0,
        'shading_skipped' => 1.0,
    ];

    /** @var array<string, array{count: int, area: float}> */
    private array $issues = [];

    public function __construct(
        private readonly float $pageArea,
    ) {}

    /** @param float $area device space area of what is affected (canvas pixels²) */
    public function add(string $issue, float $area): void {
        $share = $this->pageArea > 0 ? max(0.0, min(1.0, $area / $this->pageArea)) : 0.0;
        $this->issues[$issue]['count'] = ($this->issues[$issue]['count'] ?? 0) + 1;
        $this->issues[$issue]['area'] = ($this->issues[$issue]['area'] ?? 0.0) + $share;
    }

    public function score(): float {
        $sum = 0.0;
        foreach ($this->issues as $issue => $data) {
            $sum += (self::WEIGHTS[$issue] ?? 0.5) * min(1.0, $data['area']);
        }

        return round(max(0.0, 1.0 - $sum), 3);
    }

    /** @return array<string, array{count: int, area: float}> */
    public function issues(): array {
        return array_map(static fn (array $d) => ['count' => $d['count'], 'area' => round(min(1.0, $d['area']), 3)], $this->issues);
    }
}
