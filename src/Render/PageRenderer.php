<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use PrinsFrank\PdfParser\Document\ContentStream\Command\ContentStreamCommand;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\Object\InlineImageOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\ClippingPathOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\ColorOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\GraphicsStateOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithTextState;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithTransformationMatrix;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\ProducesPositionedTextElements;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\PathConstructionOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\PathPaintingOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\XObjectOperator;
use PrinsFrank\PdfParser\Document\ContentStream\ContentStreamParser;
use PrinsFrank\PdfParser\Document\ContentStream\Object\TextObject;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\ColorState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\Object\Decorator\Font;
use PrinsFrank\PdfParser\Document\Object\Decorator\Page;
use PrinsFrank\PdfParser\Document\Object\Decorator\XObject;
use Throwable;

/**
 * Renders a page from the parser's output with Imagick: paths (Bézier curves, fill rules, dashes, opacity),
 * clipping, images placed by their matrix (rotated or sheared too), inline images, form XObjects, axial and radial
 * shadings sampled into bands, annotation appearances, text placed and stretched to its PDF width in a substitute
 * font, and /Rotate. What is skipped or approximated is recorded in a Fidelity report.
 *
 * @experimental
 */
final class PageRenderer {
    /** PDF blend modes drawn for images */
    private const BLEND_OPERATORS = [
        'Multiply' => Imagick::COMPOSITE_MULTIPLY, 'Screen' => Imagick::COMPOSITE_SCREEN, 'Overlay' => Imagick::COMPOSITE_OVERLAY,
        'Darken' => Imagick::COMPOSITE_DARKEN, 'Lighten' => Imagick::COMPOSITE_LIGHTEN, 'ColorDodge' => Imagick::COMPOSITE_COLORDODGE,
        'ColorBurn' => Imagick::COMPOSITE_COLORBURN, 'HardLight' => Imagick::COMPOSITE_HARDLIGHT, 'SoftLight' => Imagick::COMPOSITE_SOFTLIGHT,
        'Difference' => Imagick::COMPOSITE_DIFFERENCE, 'Exclusion' => Imagick::COMPOSITE_EXCLUSION, 'Hue' => Imagick::COMPOSITE_HUE,
        'Saturation' => Imagick::COMPOSITE_SATURATE, 'Color' => Imagick::COMPOSITE_COLORIZE, 'Luminosity' => Imagick::COMPOSITE_LUMINIZE,
    ];

    private readonly RawObjects $objects;

    private readonly ColorResolver $colors;

    private readonly ImageDecoder $images;

    private readonly FontMapper $fonts;

    private Imagick $canvas;

    private ImagickDraw $draw;

    private int $pending = 0;

    private Fidelity $fidelity;

    /** @var array<string, int> */
    private array $stats = [];

    /** @var list<float> page space → canvas */
    private array $base = [1, 0, 0, 1, 0, 0];

    private float $canvasWidth = 0;

    private float $canvasHeight = 0;

    private int $clipCounter = 0;

    private ?string $activeClip = null;

    /** @var list<int> form object numbers being drawn */
    private array $formStack = [];

    /** @var array<int, ?Font> */
    private array $fontCache = [];

    private ?ColorState $colorState = null;

    public function __construct(
        public readonly Document $document,
        public readonly RenderOptions $options = new RenderOptions(),
    ) {
        $this->objects = new RawObjects($document);
        $this->colors = new ColorResolver($this->objects);
        $this->images = new ImageDecoder($this->objects, $this->colors, $options->imageMasks);
        $this->fonts = new FontMapper($options->fontCacheDirectory);
    }

    /**
     * @param int $width width of the result in pixels: of the page, or of the region when $crop is given
     * @param ?Crop $crop a region of the page to render instead of all of it; only what reaches it is drawn
     */
    public function render(Page $page, int $width = 300, ?Crop $crop = null): RenderResult {
        $start = hrtime(true);
        memory_reset_peak_usage();
        if ($this->options->memoryLimit !== null) {
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $this->options->memoryLimit);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 2 * $this->options->memoryLimit);
        }

        if ($this->options->threads !== null) {
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, $this->options->threads);
        }

        $supersample = max(1, $this->options->supersample);
        $this->stats = ['commands' => 0, 'paths' => 0, 'texts' => 0, 'invisible_texts' => 0, 'images' => 0, 'inline_images' => 0, 'forms' => 0, 'shadings' => 0, 'annotations' => 0];
        $this->stages = [];
        $this->stageTime = [];
        $this->imagickPeak = 0;
        $this->enter('interpret');
        $pageNumber = $page->objectNumber;
        $box = $this->box($pageNumber);
        $rotate = ((int) $this->objects->number($this->objects->inherited($pageNumber, 'Rotate') ?? 0) % 360 + 360) % 360;
        [$llx, $lly, $urx, $ury] = $box;
        $boxWidth = max(1.0, $urx - $llx);
        $boxHeight = max(1.0, $ury - $lly);
        $sideways = $rotate === 90 || $rotate === 270;
        [$left, $top, $right, $bottom] = $crop?->resolve($sideways ? $boxHeight : $boxWidth, $sideways ? $boxWidth : $boxHeight) ?? [0.0, 0.0, 1.0, 1.0];
        // the region as shares of the page before /Rotate turns it (the canvas is turned at the end)
        [$u0, $v0, $u1, $v1] = match ($rotate) {
            90 => [$top, 1 - $right, $bottom, 1 - $left],
            180 => [1 - $right, 1 - $bottom, 1 - $left, 1 - $top],
            270 => [1 - $bottom, $left, 1 - $top, $right],
            default => [$left, $top, $right, $bottom],
        };
        $scale = $width * $supersample / (($right - $left) * ($sideways ? $boxHeight : $boxWidth));
        $this->canvasWidth = max(1, (int) round(($u1 - $u0) * $boxWidth * $scale));
        $this->canvasHeight = max(1, (int) round(($v1 - $v0) * $boxHeight * $scale));
        $this->base = [$scale, 0, 0, -$scale, -($llx + $u0 * $boxWidth) * $scale, ($ury - $v0 * $boxHeight) * $scale];
        $this->canvas = new Imagick();
        $this->canvas->newImage((int) $this->canvasWidth, (int) $this->canvasHeight, new ImagickPixel('white'));
        $this->canvas->setImageFormat('png');
        $this->canvas->setImageProperty('type:hinting', 'off'); // hinted advances are whole pixels: runs come out wider or narrower than the PDF says
        $this->newDraw();
        $this->fidelity = new Fidelity($this->canvasWidth * $this->canvasHeight);
        $this->formStack = [];
        $resources = $this->objects->resources($pageNumber);
        $state = $this->initialState();
        try {
            $this->enter('parse');
            try {
                $content = $page->getContentStream()?->content ?? [];
            } finally {
                $this->leave();
            }

            $this->interpret($content, $resources, $state, 0);
        } catch (Throwable $e) {
            $this->fidelity->add('content_error', $this->canvasWidth * $this->canvasHeight);
            $this->stats['error'] = substr($e::class . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(), 0, 300);
        }

        $this->annotations($pageNumber, $state);
        $this->flush();
        $this->enter('finish');
        $image = $this->canvas;
        if ($supersample > 1) {
            $image->resizeImage(max(1, (int) round($this->canvasWidth / $supersample)), max(1, (int) round($this->canvasHeight / $supersample)), $this->options->downscaleFilter, 1);
        }

        if ($rotate !== 0) {
            $image->rotateImage(new ImagickPixel('white'), $rotate);
        }

        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $image->setImageFormat('png');
        $this->leave();
        $this->leave();
        $timings = array_map(static fn (int $ns): float => round($ns / 1e9, 4), $this->stageTime);
        arsort($timings);
        $memory = [
            'php_peak_mb' => round(memory_get_peak_usage() / 1048576, 1),
            'imagick_peak_mb' => round($this->imagickPeak / 1048576, 1),
            'canvas_mb' => round($this->canvasWidth * $this->canvasHeight * 8 / 1048576, 1),
        ];

        return new RenderResult($image, $this->fidelity->score(), $this->fidelity->issues(), $this->stats, $timings + ['total' => round((hrtime(true) - $start) / 1e9, 4)], $memory);
    }

    /** @var list<string> */
    private array $stages = [];

    private int $stageMark = 0;

    /** @var array<string, int> stage => nanoseconds spent in it, not counting the stages entered from it */
    private array $stageTime = [];

    private int $imagickPeak = 0;

    /**
     * Time from here to the matching leave() goes to $stage: parse, interpret, paths, text, fonts, image_decode,
     * image_place, shading, rasterize (ImageMagick drawing the queued paths and text), finish
     */
    private function enter(string $stage): void {
        $now = hrtime(true);
        $current = end($this->stages);
        if ($current !== false) {
            $this->stageTime[$current] = ($this->stageTime[$current] ?? 0) + $now - $this->stageMark;
        }

        $this->stages[] = $stage;
        $this->stageMark = $now;
    }

    private function leave(): void {
        $now = hrtime(true);
        $stage = array_pop($this->stages);
        if ($stage !== null) {
            $this->stageTime[$stage] = ($this->stageTime[$stage] ?? 0) + $now - $this->stageMark;
        }

        $this->stageMark = $now;
    }

    private function sampleMemory(): void {
        $this->imagickPeak = max($this->imagickPeak, Imagick::getResource(Imagick::RESOURCETYPE_MEMORY));
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function box(int $pageNumber): array {
        foreach (['CropBox', 'MediaBox'] as $key) {
            $box = $this->objects->array($this->objects->inherited($pageNumber, $key));
            if ($box !== null && count($box) === 4) {
                $values = array_map(fn ($v) => $this->objects->number($v), $box);

                return [min($values[0], $values[2]), min($values[1], $values[3]), max($values[0], $values[2]), max($values[1], $values[3])];
            }
        }

        return [0.0, 0.0, 612.0, 792.0];
    }

    /** @return array<string, mixed> */
    private function initialState(): array {
        $gray = ['family' => 'Gray', 'n' => 1];

        return [
            'ctm' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            'fillCs' => $gray, 'fill' => [0.0], 'strokeCs' => $gray, 'stroke' => [0.0],
            'fillPattern' => null, 'strokePattern' => null,
            'lw' => 1.0, 'cap' => 0, 'join' => 0, 'dash' => [[], 0.0],
            'fa' => 1.0, 'sa' => 1.0, 'ga' => 1.0, 'softMask' => false, 'blend' => false, 'bm' => 'Normal',
            'clip' => null,
            'textState' => new TextState(null, null),
            'resources' => [],
        ];
    }

    // ---------------------------------------------------------------- content

    /**
     * @param list<TextObject|ContentStreamCommand> $content
     * @param array<string, mixed> $resources
     * @param array<string, mixed> $state
     */
    private function interpret(array $content, array $resources, array &$state, int $depth): void {
        $stack = [];
        $path = ['cmds' => [], 'bbox' => null, 'cur' => null, 'start' => null];
        $pendingClip = null;
        $state['resources'] = $resources;
        foreach ($content as $item) {
            if ($item instanceof TextObject) {
                // the line matrix moves with Td/TD/T*/Tm/'/"; the text matrix also advances after every shown string
                $lineMatrix = $textMatrix = new TransformationMatrix(1, 0, 0, 1, 0, 0);
                foreach ($item->contentStreamCommands as $command) {
                    $this->stats['commands']++;
                    $operator = $command->operator;
                    if ($operator instanceof ProducesPositionedTextElements || $operator instanceof InteractsWithTextState || ($operator instanceof InteractsWithTransformationMatrix && !$operator instanceof GraphicsStateOperator)) {
                        try {
                            if ($operator instanceof InteractsWithTextState) {
                                $state['textState'] = $operator->applyToTextState($command->operands, $state['textState']);
                            }

                            if ($operator instanceof InteractsWithTransformationMatrix && !$operator instanceof GraphicsStateOperator) {
                                $moved = $operator->applyToTransformationMatrix($command->operands, $lineMatrix, $state['textState']);
                                if (!$operator instanceof ProducesPositionedTextElements || $moved !== $lineMatrix) {
                                    $lineMatrix = $textMatrix = $moved;
                                }
                            }

                            if ($operator instanceof ProducesPositionedTextElements && $state['textState']->fontName !== null) {
                                $element = $operator->getPositionedTextElement($command->operands, $textMatrix, self::libMatrix($state['ctm']), $state['textState'], $this->colorState ??= new ColorState()); // colours come from $state
                                $advance = $this->text($element, $state);
                                if ($advance != 0.0) {
                                    $textMatrix = (new TransformationMatrix(1, 0, 0, 1, $advance, 0))->multiplyWith($textMatrix);
                                }
                            }
                        } catch (Throwable) {
                            $this->stats['text_errors'] = ($this->stats['text_errors'] ?? 0) + 1;
                        }

                        continue;
                    }

                    $this->command($command, $state, $stack, $path, $pendingClip, $depth);
                }

                continue;
            }

            $this->stats['commands']++;
            $this->command($item, $state, $stack, $path, $pendingClip, $depth);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $stack
     * @param array<string, mixed> $path
     */
    private function command(ContentStreamCommand $command, array &$state, array &$stack, array &$path, ?bool &$pendingClip, int $depth): void {
        $operator = $command->operator;
        $operands = $command->operands;
        $numbers = static function () use ($operands): array {
            $values = PdfSyntax::values($operands);

            return array_values(array_map(static fn ($v) => is_int($v) || is_float($v) ? (float) $v : 0.0, array_filter($values, static fn ($v) => is_int($v) || is_float($v))));
        };
        switch (true) {
            case $operator === GraphicsStateOperator::SaveCurrentStateToStack:
                $stack[] = $state;
                break;
            case $operator === GraphicsStateOperator::RestoreMostRecentStateFromStack:
                if ($stack !== []) {
                    $resources = $state['resources'];
                    $state = array_pop($stack);
                    $state['resources'] = $resources;
                }

                break;
            case $operator === GraphicsStateOperator::ModifyCurrentTransformationMatrix:
                $m = $numbers();
                if (count($m) >= 6) {
                    $state['ctm'] = self::multiply(array_slice($m, 0, 6), $state['ctm']);
                }

                break;
            case $operator === GraphicsStateOperator::SetLineWidth:
                $state['lw'] = $numbers()[0] ?? 1.0;
                break;
            case $operator === GraphicsStateOperator::SetLineCap:
                $state['cap'] = (int) ($numbers()[0] ?? 0);
                break;
            case $operator === GraphicsStateOperator::SetLineJoin:
                $state['join'] = (int) ($numbers()[0] ?? 0);
                break;
            case $operator === GraphicsStateOperator::SetLineDash:
                $values = PdfSyntax::values($operands);
                $state['dash'] = [array_map('floatval', array_filter(is_array($values[0] ?? null) ? $values[0] : [], 'is_numeric')), (float) ($values[1] ?? 0)];
                break;
            case $operator === GraphicsStateOperator::SetDictName:
                $this->extGState(ltrim(trim($operands), '/'), $state);
                break;
            case $operator instanceof ColorOperator:
                $this->color($operator, $operands, $state);
                break;
            case $operator instanceof PathConstructionOperator:
                $this->pathCommand($operator, $numbers(), $state, $path);
                break;
            case $operator === PathPaintingOperator::PAINT_SHADING:
                $this->flushIfManyPending();
                $this->shadingByName(ltrim(trim($operands), '/'), $state, null);
                break;
            case $operator instanceof PathPaintingOperator:
                $this->paint($operator, $state, $path);
                if ($pendingClip !== null) {
                    $this->setClip($state, $path, $pendingClip);
                    $pendingClip = null;
                }

                $path = ['cmds' => [], 'bbox' => null, 'cur' => null, 'start' => null];
                break;
            case $operator instanceof ClippingPathOperator:
                $pendingClip = $operator === ClippingPathOperator::INTERSECT_EVEN_ODD;
                break;
            case $operator === XObjectOperator::Paint:
                $this->xobject(ltrim(trim($operands), '/'), $state, $depth);
                break;
            case $operator === InlineImageOperator::End:
                $this->inlineImage($operands, $state);
                break;
            default:
                break;
        }
    }

    // ---------------------------------------------------------------- state

    private function extGState(string $name, array &$state): void {
        $gs = $this->objects->dictionary($this->objects->dictionary($state['resources']['ExtGState'] ?? null)[$name] ?? null);
        if ($gs === null) {
            return;
        }

        foreach ($gs as $key => $value) {
            $value = $this->objects->resolve($value);
            switch ($key) {
                case 'LW': $state['lw'] = $this->objects->number($value, 1); break;
                case 'LC': $state['cap'] = (int) $this->objects->number($value); break;
                case 'LJ': $state['join'] = (int) $this->objects->number($value); break;
                case 'ca': $state['fa'] = $this->objects->number($value, 1); break;
                case 'CA': $state['sa'] = $this->objects->number($value, 1); break;
                case 'SMask': $state['softMask'] = !($value === '/None' || $value === null); break;
                case 'BM':
                    $mode = is_array($value) ? ($value[0] ?? '/Normal') : $value;
                    $state['bm'] = is_string($mode) ? ltrim($mode, '/') : 'Normal';
                    $state['blend'] = !in_array($state['bm'], ['Normal', 'Compatible'], true);
                    break;
                case 'D':
                    if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                        $state['dash'] = [array_map(fn ($v) => $this->objects->number($v), $value[0]), $this->objects->number($value[1] ?? 0)];
                    }

                    break;
            }
        }
    }

    private function color(ColorOperator $operator, string $operands, array &$state): void {
        $values = PdfSyntax::values($operands);
        $numbers = array_values(array_map('floatval', array_filter($values, static fn ($v) => is_int($v) || is_float($v))));
        $stroke = in_array($operator, [ColorOperator::SetName, ColorOperator::SetStrokingColor, ColorOperator::SetStrokingParams, ColorOperator::SetStrokingColorSpace, ColorOperator::SetStrokingColorDeviceRGB, ColorOperator::SetStrokingColorDeviceCMYK], true);
        $csKey = $stroke ? 'strokeCs' : 'fillCs';
        $colorKey = $stroke ? 'stroke' : 'fill';
        $patternKey = $stroke ? 'strokePattern' : 'fillPattern';
        switch ($operator) {
            case ColorOperator::SetName:
            case ColorOperator::SetNameNonStroking:
                $state[$csKey] = $this->colors->colorSpace($values[0] ?? '/DeviceGray', $state['resources']);
                $state[$colorKey] = $this->colors->initial($state[$csKey]);
                $state[$patternKey] = null;
                break;
            case ColorOperator::SetStrokingColorSpace:
            case ColorOperator::SetColorSpace:
                $state[$csKey] = ['family' => 'Gray', 'n' => 1];
                $state[$colorKey] = [$numbers[0] ?? 0.0];
                $state[$patternKey] = null;
                break;
            case ColorOperator::SetStrokingColorDeviceRGB:
            case ColorOperator::SetColorDeviceRGB:
                $state[$csKey] = ['family' => 'RGB', 'n' => 3];
                $state[$colorKey] = array_slice($numbers, 0, 3);
                $state[$patternKey] = null;
                break;
            case ColorOperator::SetStrokingColorDeviceCMYK:
            case ColorOperator::SetColorDeviceCMYK:
                $state[$csKey] = ['family' => 'CMYK', 'n' => 4];
                $state[$colorKey] = array_slice($numbers, 0, 4);
                $state[$patternKey] = null;
                break;
            default: // SC SCN sc scn
                $last = end($values);
                if ($state[$csKey]['family'] === 'Pattern' && is_string($last) && str_starts_with($last, '/')) {
                    $state[$patternKey] = substr($last, 1);
                    $state[$colorKey] = $numbers;
                } else {
                    $state[$colorKey] = $numbers;
                }
        }
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function rgb(array $state, bool $stroke, float $area): array {
        $cs = $state[$stroke ? 'strokeCs' : 'fillCs'];
        $pattern = $state[$stroke ? 'strokePattern' : 'fillPattern'];
        if ($cs['family'] === 'Pattern') {
            return $pattern !== null ? $this->patternColor($pattern, $state, $area) : [0.5, 0.5, 0.5];
        }

        if ($cs['family'] === 'Unknown') {
            $this->fidelity->add('colour_space', $area);
        }

        return $this->colors->toRgb($cs, $state[$stroke ? 'stroke' : 'fill']);
    }

    private static function css(array $rgb, float $alpha = 1.0): string {
        return sprintf('rgba(%d,%d,%d,%.3F)', (int) round($rgb[0] * 255), (int) round($rgb[1] * 255), (int) round($rgb[2] * 255), max(0, min(1, $alpha)));
    }

    // ---------------------------------------------------------------- geometry

    /** @param list<float> $m1 @param list<float> $m2 @return list<float> $m1 then $m2 */
    private static function multiply(array $m1, array $m2): array {
        return [
            $m1[0] * $m2[0] + $m1[1] * $m2[2], $m1[0] * $m2[1] + $m1[1] * $m2[3],
            $m1[2] * $m2[0] + $m1[3] * $m2[2], $m1[2] * $m2[1] + $m1[3] * $m2[3],
            $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4], $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
        ];
    }

    private static function libMatrix(array $m): TransformationMatrix {
        return new TransformationMatrix($m[0], $m[1], $m[2], $m[3], $m[4], $m[5]);
    }

    /** @return list<float> current user space → canvas */
    private function device(array $state): array {
        return self::multiply($state['ctm'], $this->base);
    }

    /** @return array{0: float, 1: float} */
    private static function apply(array $m, float $x, float $y): array {
        return [$x * $m[0] + $y * $m[2] + $m[4], $x * $m[1] + $y * $m[3] + $m[5]];
    }

    private static function grow(?array $bbox, float $x, float $y): array {
        return $bbox === null ? [$x, $y, $x, $y] : [min($bbox[0], $x), min($bbox[1], $y), max($bbox[2], $x), max($bbox[3], $y)];
    }

    /** Whether a device bounding box lies entirely off the canvas (outside the crop): nothing of it shows */
    private function offCanvas(?array $bbox): bool {
        return $bbox !== null && ($bbox[2] < 0 || $bbox[3] < 0 || $bbox[0] > $this->canvasWidth || $bbox[1] > $this->canvasHeight);
    }

    private function area(?array $bbox): float {
        if ($bbox === null) {
            return 0.0;
        }

        $w = min($this->canvasWidth, $bbox[2]) - max(0, $bbox[0]);
        $h = min($this->canvasHeight, $bbox[3]) - max(0, $bbox[1]);

        return $w > 0 && $h > 0 ? $w * $h : 0.0;
    }

    private function pathCommand(PathConstructionOperator $operator, array $n, array $state, array &$path): void {
        $m = $this->device($state);
        $add = function (string $type, array $points) use (&$path, $m): void {
            $device = [];
            foreach ($points as [$x, $y]) {
                [$dx, $dy] = self::apply($m, $x, $y);
                $device[] = $dx;
                $device[] = $dy;
                $path['bbox'] = self::grow($path['bbox'], $dx, $dy);
            }

            $path['cmds'][] = [$type, ...$device];
        };
        switch ($operator) {
            case PathConstructionOperator::MOVE:
                $add('M', [[$n[0] ?? 0, $n[1] ?? 0]]);
                $path['cur'] = $path['start'] = [$n[0] ?? 0, $n[1] ?? 0];
                break;
            case PathConstructionOperator::LINE:
                $add('L', [[$n[0] ?? 0, $n[1] ?? 0]]);
                $path['cur'] = [$n[0] ?? 0, $n[1] ?? 0];
                break;
            case PathConstructionOperator::CURVE_BEZIER_123:
                $add('C', [[$n[0] ?? 0, $n[1] ?? 0], [$n[2] ?? 0, $n[3] ?? 0], [$n[4] ?? 0, $n[5] ?? 0]]);
                $path['cur'] = [$n[4] ?? 0, $n[5] ?? 0];
                break;
            case PathConstructionOperator::CURVE_BEZIER_23:
                $cur = $path['cur'] ?? [$n[0] ?? 0, $n[1] ?? 0];
                $add('C', [$cur, [$n[0] ?? 0, $n[1] ?? 0], [$n[2] ?? 0, $n[3] ?? 0]]);
                $path['cur'] = [$n[2] ?? 0, $n[3] ?? 0];
                break;
            case PathConstructionOperator::CURVE_BEZIER_13:
                $add('C', [[$n[0] ?? 0, $n[1] ?? 0], [$n[2] ?? 0, $n[3] ?? 0], [$n[2] ?? 0, $n[3] ?? 0]]);
                $path['cur'] = [$n[2] ?? 0, $n[3] ?? 0];
                break;
            case PathConstructionOperator::CLOSE:
                $path['cmds'][] = ['Z'];
                $path['cur'] = $path['start'];
                break;
            case PathConstructionOperator::RECTANGLE:
                [$x, $y, $w, $h] = [$n[0] ?? 0, $n[1] ?? 0, $n[2] ?? 0, $n[3] ?? 0];
                $add('M', [[$x, $y]]);
                $add('L', [[$x + $w, $y]]);
                $add('L', [[$x + $w, $y + $h]]);
                $add('L', [[$x, $y + $h]]);
                $path['cmds'][] = ['Z'];
                $path['cur'] = $path['start'] = [$x, $y];
                break;
        }
    }

    private function emitPath(array $cmds, ?ImagickDraw $draw = null): void {
        $draw ??= $this->draw;
        $draw->pathStart();
        foreach ($cmds as $cmd) {
            match ($cmd[0]) {
                'M' => $draw->pathMoveToAbsolute($cmd[1], $cmd[2]),
                'L' => $draw->pathLineToAbsolute($cmd[1], $cmd[2]),
                'C' => $draw->pathCurveToAbsolute($cmd[1], $cmd[2], $cmd[3], $cmd[4], $cmd[5], $cmd[6]),
                'Z' => $draw->pathClose(),
                default => null,
            };
        }

        $draw->pathFinish();
    }

    // ---------------------------------------------------------------- painting

    private function paint(PathPaintingOperator $operator, array $state, array $path): void {
        if ($path['cmds'] === [] || $operator === PathPaintingOperator::END) {
            return;
        }

        [$fill, $stroke, $evenOdd, $close] = match ($operator) {
            PathPaintingOperator::STROKE => [false, true, false, false],
            PathPaintingOperator::CLOSE_STROKE => [false, true, false, true],
            PathPaintingOperator::FILL, PathPaintingOperator::FILL_DEPRECATED => [true, false, false, false],
            PathPaintingOperator::FILL_EVEN_ODD => [true, false, true, false],
            PathPaintingOperator::FILL_STROKE => [true, true, false, false],
            PathPaintingOperator::FILL_STROKE_EVEN_ODD => [true, true, true, false],
            PathPaintingOperator::CLOSE_FILL_STROKE_NON_ZERO => [true, true, false, true],
            PathPaintingOperator::CLOSE_FILL_STROKE => [true, true, true, true],
            default => [false, false, false, false],
        };
        $cmds = $path['cmds'];
        if ($close) {
            $cmds[] = ['Z'];
        }

        if ($this->offCanvas($path['bbox'])) {
            return;
        }

        $area = $this->area($path['bbox']);
        $this->stats['paths']++;
        if (!$this->options->paths) {
            $this->fidelity->add('path_skipped', $area);

            return;
        }

        $this->enter('paths');
        try {
            $this->drawPath($state, $cmds, $path['bbox'], $area, $fill, $stroke, $evenOdd);
        } finally {
            $this->leave();
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<int|string, mixed>> $cmds
     */
    private function drawPath(array $state, array $cmds, ?array $bbox, float $area, bool $fill, bool $stroke, bool $evenOdd): void {
        $this->noteState($state, $area);
        if ($fill && $state['fillCs']['family'] === 'Pattern' && $state['fillPattern'] !== null && $this->shadingPatternFill($state['fillPattern'], $state, $cmds, $evenOdd, $bbox)) {
            $fill = false;
        }

        if (!$fill && !$stroke) {
            return;
        }

        $m = $this->device($state);
        $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
        $this->useClip($state, $bbox);
        $this->emitText();
        $this->draw->push();
        $this->draw->setFillRule($evenOdd ? Imagick::FILLRULE_EVENODD : Imagick::FILLRULE_NONZERO);
        $this->draw->setFillColor($fill ? self::css($this->rgb($state, false, $area), $state['fa'] * $state['ga']) : 'none');
        if ($stroke) {
            $this->draw->setStrokeColor(self::css($this->rgb($state, true, $area), $state['sa'] * $state['ga']));
            $this->draw->setStrokeWidth(max(1.0, $state['lw'] * $scale));
            $this->draw->setStrokeLineCap([Imagick::LINECAP_BUTT, Imagick::LINECAP_ROUND, Imagick::LINECAP_SQUARE][$state['cap']] ?? Imagick::LINECAP_BUTT);
            $this->draw->setStrokeLineJoin([Imagick::LINEJOIN_MITER, Imagick::LINEJOIN_ROUND, Imagick::LINEJOIN_BEVEL][$state['join']] ?? Imagick::LINEJOIN_MITER);
            if ($state['dash'][0] !== [] && array_sum($state['dash'][0]) > 0) {
                $this->draw->setStrokeDashArray(array_map(static fn ($d) => max(0.5, $d * $scale), $state['dash'][0]));
                $this->draw->setStrokeDashOffset($state['dash'][1] * $scale);
            }
        } else {
            $this->draw->setStrokeColor('none');
        }

        $this->emitPath($cmds);
        $this->draw->pop();
        $this->pending++;
        $this->flushIfManyPending();
    }

    private function noteState(array $state, float $area): void {
        if ($state['softMask']) {
            $this->fidelity->add('soft_mask', $area);
        }

        if ($state['blend']) {
            $this->fidelity->add('blend_mode', $area);
        }
    }

    // ---------------------------------------------------------------- clipping

    private function setClip(array &$state, array $path, bool $evenOdd): void {
        if ($path['cmds'] === []) {
            return;
        }

        $rect = self::rectangle($path['cmds']);
        $current = $state['clip'];
        if ($current !== null) {
            if ($rect !== null && $current['rect'] !== null) {
                $rect = [max($rect[0], $current['rect'][0]), max($rect[1], $current['rect'][1]), min($rect[2], $current['rect'][2]), min($rect[3], $current['rect'][3])];
                if ($rect[2] <= $rect[0] || $rect[3] <= $rect[1]) {
                    $rect = [0, 0, 0, 0];
                }
            } elseif ($rect === null && $current['rect'] !== null && self::inside($path['bbox'], $current['rect'])) {
                // new clip lies inside the current rectangle: the new one alone is exact
            } elseif ($rect !== null && $current['rect'] === null && self::inside($current['bbox'], $rect)) {
                $state['clip']['bbox'] = $current['bbox'];

                return; // the current clip already lies inside the new rectangle
            } else {
                $this->fidelity->add('clip_intersection', $this->area($path['bbox']));
            }
        }

        $cmds = $rect !== null ? [['M', $rect[0], $rect[1]], ['L', $rect[2], $rect[1]], ['L', $rect[2], $rect[3]], ['L', $rect[0], $rect[3]], ['Z']] : $path['cmds'];
        $bbox = $rect ?? $path['bbox'];
        if ($current !== null && $current['bbox'] !== null) {
            $bbox = [max($bbox[0], $current['bbox'][0]), max($bbox[1], $current['bbox'][1]), min($bbox[2], $current['bbox'][2]), min($bbox[3], $current['bbox'][3])];
        }

        $state['clip'] = ['id' => 'clip' . (++$this->clipCounter), 'cmds' => $cmds, 'evenOdd' => $evenOdd, 'rect' => $rect, 'bbox' => $bbox];
    }

    private static function inside(?array $inner, array $outer): bool {
        return $inner !== null && $inner[0] >= $outer[0] - 0.5 && $inner[1] >= $outer[1] - 0.5 && $inner[2] <= $outer[2] + 0.5 && $inner[3] <= $outer[3] + 0.5;
    }

    /** An axis-aligned rectangle path in device space, as [x0, y0, x1, y1] */
    private static function rectangle(array $cmds): ?array {
        $points = [];
        foreach ($cmds as $i => $cmd) {
            if ($cmd[0] === 'C') {
                return null;
            }

            if ($cmd[0] === 'M' && !in_array($cmds[$i + 1][0] ?? 'M', ['L', 'C'], true)) {
                continue; // a moveto without a segment after it adds nothing to the clip
            }

            if ($cmd[0] === 'M' || $cmd[0] === 'L') {
                if ($cmd[0] === 'M' && $points !== []) {
                    return null;
                }

                $points[] = [$cmd[1], $cmd[2]];
            }
        }

        if (count($points) === 5 && abs($points[4][0] - $points[0][0]) < 0.01 && abs($points[4][1] - $points[0][1]) < 0.01) {
            array_pop($points);
        }

        if (count($points) !== 4) {
            return null;
        }

        $xs = array_unique(array_map(static fn ($p) => round($p[0], 2), $points));
        $ys = array_unique(array_map(static fn ($p) => round($p[1], 2), $points));
        if (count($xs) !== 2 || count($ys) !== 2) {
            return null;
        }

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /** @var array<string, mixed>|null the clip of the primitives queued in $this->draw, which are drawn on a layer */
    private ?array $layerClip = null;

    /** @var array{int, int, int, int}|null x, y, width and height of that layer on the canvas; null: nothing of it shows */
    private ?array $layer = null;

    /**
     * Before a primitive with device bounding box $bbox: when its clip differs from the one of the queued primitives,
     * draws those and starts a new group. A clipped group is drawn on a layer the size of the clip's bounding box
     * (a rectangular clip needs nothing more, another one is applied as a mask once for the group) and composited:
     * ImageMagick's clip-path renders a mask of the whole canvas every time it is set.
     * A primitive inside a rectangular clip needs none.
     */
    private function useClip(array $state, ?array $bbox): void {
        $clip = $state['clip'];
        if (!$this->options->clipping && $clip !== null) {
            $this->fidelity->add('clip_skipped', $this->area($bbox ?? $clip['bbox']));
            $clip = null;
        }

        if ($clip !== null && $clip['rect'] !== null && $bbox !== null && self::inside($bbox, $clip['rect'])) {
            $clip = null;
        }

        $id = $clip['id'] ?? null;
        if ($id === $this->activeClip) {
            return;
        }

        $this->flush(); // also draws the queued text, which belongs to the clip group being left
        if ($clip === null) {
            return;
        }

        $box = $clip['bbox'] ?? [0, 0, $this->canvasWidth, $this->canvasHeight];
        $x = (int) max(0, floor($box[0]));
        $y = (int) max(0, floor($box[1]));
        $width = (int) min($this->canvasWidth, ceil($box[2])) - $x;
        $height = (int) min($this->canvasHeight, ceil($box[3])) - $y;
        $this->activeClip = $id;
        $this->layerClip = $clip;
        $this->layer = $width > 0 && $height > 0 ? [$x, $y, $width, $height] : null;
        $this->draw->translate(-$x, -$y);
        $this->stats['clip_groups'] = ($this->stats['clip_groups'] ?? 0) + 1;
    }

    /** the font set on $this->draw */
    private ?string $drawFont = null;

    private function newDraw(): void {
        $this->drawFont = null;
        $this->activeClip = null;
        $this->layerClip = null;
        $this->layer = null;
        $this->draw = new ImagickDraw();
        $this->draw->setStrokeAntialias(true);
        $this->draw->setTextAntialias(true);
        $this->pending = 0;
    }

    private function flush(): void {
        $this->emitText();
        if ($this->pending > 0 && ($this->activeClip === null || $this->layer !== null)) {
            $this->enter('rasterize');
            try {
                if ($this->activeClip === null) {
                    $this->canvas->drawImage($this->draw);
                } else {
                    [$x, $y, $width, $height] = $this->layer;
                    $layer = new Imagick();
                    $layer->newImage($width, $height, new ImagickPixel('none'));
                    $layer->setImageProperty('type:hinting', 'off');
                    $layer->drawImage($this->draw);
                    if ($this->layerClip !== null && $this->layerClip['rect'] === null) {
                        $layer->compositeImage($this->clipMask($this->layerClip, $x, $y, $width, $height), Imagick::COMPOSITE_DSTIN, 0, 0);
                    }

                    $this->canvas->compositeImage($layer, Imagick::COMPOSITE_OVER, $x, $y);
                    $layer->clear();
                }
            } catch (Throwable) {
                $this->stats['draw_errors'] = ($this->stats['draw_errors'] ?? 0) + 1;
            } finally {
                $this->leave();
            }

            $this->sampleMemory();
        }

        $this->newDraw();
    }

    /**
     * The clip path of $clip as an alpha mask of the area $x, $y, $width × $height of the canvas
     *
     * @param array<string, mixed> $clip
     */
    private function clipMask(array $clip, int $x, int $y, int $width, int $height): Imagick {
        $mask = new Imagick();
        $mask->newImage($width, $height, new ImagickPixel('none'));
        $draw = new ImagickDraw();
        $draw->setFillColor('white');
        $draw->setStrokeColor('none');
        $draw->setFillRule($clip['evenOdd'] ? Imagick::FILLRULE_EVENODD : Imagick::FILLRULE_NONZERO);
        $draw->translate(-$x, -$y);
        $this->emitPath($clip['cmds'], $draw);
        $mask->drawImage($draw);

        return $mask;
    }

    private function flushIfManyPending(): void {
        if ($this->pending >= $this->options->batchSize) {
            $this->flush();
        }
    }

    // ---------------------------------------------------------------- text

    private function font(array $state): ?Font {
        $name = $state['textState']->fontName?->value;
        if ($name === null) {
            return null;
        }

        $reference = $this->objects->dictionary($state['resources']['Font'] ?? null)[$name] ?? null;
        if (!$reference instanceof PdfReference) {
            return null;
        }

        if (!array_key_exists($reference->objectNumber, $this->fontCache)) {
            $font = $this->objects->decorated($reference->objectNumber, Font::class);
            $this->fontCache[$reference->objectNumber] = $font instanceof Font ? $font : null;
        }

        return $this->fontCache[$reference->objectNumber];
    }

    /** @var array<int, bool> */
    private array $type3 = [];

    /** @var array<int, ?int> */
    private array $flags = [];

    private function fontFlags(Font $font): ?int {
        if (!array_key_exists($font->objectNumber, $this->flags)) {
            $dictionary = $this->objects->dictionary($this->objects->value($font->objectNumber)) ?? [];
            $descriptor = $this->objects->dictionary($dictionary['FontDescriptor'] ?? null);
            if ($descriptor === null && isset($dictionary['DescendantFonts'])) {
                $descendant = $this->objects->dictionary(($this->objects->array($dictionary['DescendantFonts']) ?? [])[0] ?? null) ?? [];
                $descriptor = $this->objects->dictionary($descendant['FontDescriptor'] ?? null);
            }

            $this->flags[$font->objectNumber] = $descriptor !== null && isset($descriptor['Flags']) ? (int) $this->objects->number($descriptor['Flags']) : null;
        }

        return $this->flags[$font->objectNumber];
    }

    private function isType3(Font $font): bool {
        return $this->type3[$font->objectNumber] ??= $this->objects->name($this->objects->dictionary($this->objects->value($font->objectNumber))['Subtype'] ?? null) === 'Type3';
    }

    /** @var array<int, bool> */
    private array $composite = [];

    /**
     * Draws a shown string; returns how far it moves the text position, in text space units.
     *
     * The string is cut into runs at TJ adjustments of at least RenderOptions::$textSplitThreshold; each run is one
     * draw call at its own position. Embedded TrueType/CFF programs are drawn by glyph id with the PDF widths as
     * advances, character and word spacing as kerning and interword spacing, so a run lands where the PDF puts it;
     * other fonts are drawn with a substitute font stretched to the PDF width of the run.
     */
    private function text(PositionedTextElement $element, array $state): float {
        $this->enter('text');
        try {
            return $this->layoutText($element, $state);
        } finally {
            $this->leave();
        }
    }

    private function layoutText(PositionedTextElement $element, array $state): float {
        $textState = $element->textState;
        $font = $this->font($state);
        $fontSize = (float) $textState->getFontSize();
        $th = $textState->scale / 100;
        $m = self::multiply([$element->absoluteMatrix->scaleX, $element->absoluteMatrix->shearX, $element->absoluteMatrix->shearY, $element->absoluteMatrix->scaleY, $element->absoluteMatrix->offsetX, $element->absoluteMatrix->offsetY], $this->base);
        $xLength = sqrt($m[0] ** 2 + $m[1] ** 2);
        $yLength = sqrt($m[2] ** 2 + $m[3] ** 2);
        $size = abs($fontSize) * $yLength;
        if ($font === null) {
            $this->missingText($m, $size, 0.5 * $size * array_sum(array_map(static fn ($segment) => strlen($segment->textString->getBinaryString()), $element->textSegments)), $state);

            return 0.0;
        }

        $composite = $this->composite[$font->objectNumber] ??= $font->getDescendantFonts() !== [];
        $width = static fn (array $codes): float => $codes === [] ? 0.0 : $font->getWidthForChars($codes, $textState->wordSpace, $textState->charSpace, $fontSize, 1) * $th;
        $runs = [];
        $current = null;
        $tx = $lastShift = 0.0;
        foreach ($element->textSegments as $segment) {
            $bytes = array_values(unpack('C*', $segment->textString->getBinaryString()) ?: []);
            $codes = $bytes;
            if ($composite) {
                $codes = [];
                for ($i = 0; $i + 1 < count($bytes); $i += 2) {
                    $codes[] = ($bytes[$i] << 8) | $bytes[$i + 1];
                }
            }

            $current ??= ['tx' => $tx, 'codes' => [], 'segments' => [], 'extra' => 0.0];
            array_push($current['codes'], ...$codes);
            $current['segments'][] = $segment;
            $tx += $width($codes);
            $lastShift = 0.0;
            if ($segment->offset !== null && $segment->offset != 0) {
                $shift = -$segment->offset / 1000 * $fontSize * $th;
                $tx += $shift;
                if (abs($segment->offset) >= $this->options->textSplitThreshold) {
                    $runs[] = $current;
                    $current = null;
                } else {
                    $current['extra'] += $lastShift = $shift;
                }
            }
        }

        if ($current !== null) {
            $current['extra'] -= $lastShift;
            $runs[] = $current;
        }

        $advance = $tx;
        if ($textState->render === 3 || $textState->render === 7) {
            $this->stats['invisible_texts']++;

            return $advance;
        }

        if ($size < 0.3 || $size > 4 * $this->canvasHeight) {
            return $advance;
        }

        if ($this->options->text === RenderOptions::TEXT_SKIP) {
            $this->fidelity->add('text_skipped', $this->area([$m[4], $m[5] - $size, $m[4] + abs($advance) * $xLength, $m[5] + 0.3 * $size]));

            return $advance;
        }

        $glyphFont = null;
        if ($this->options->text === RenderOptions::TEXT_EMBEDDED && !$this->isType3($font)) {
            $this->enter('fonts');
            try {
                $glyphFont = $this->fonts->glyphFont($this->objects, $font->objectNumber);
            } finally {
                $this->leave();
            }
        }

        $rise = $textState->rise;
        $angle = rad2deg(atan2($m[1], $m[0]));
        $stretch = $yLength > 0 ? $th * $xLength / $yLength : 1.0;
        $kerning = $textState->charSpace * $yLength;
        // ImageMagick draws a space with the interword spacing instead of its advance when that is not 0
        $wordSpace = $composite ? 0.0 : $textState->wordSpace * $yLength;
        $interword = $wordSpace != 0.0 ? $font->getWidthForChars([32], 0, 0, 1, 1) * $size + $wordSpace : 0.0;
        foreach ($runs as $run) {
            if (count($run['codes']) <= 4 && $this->blank($font, $run['segments'])) {
                // spaces in a font of their own (Word: no-break spaces in a Type0 font between the words) draw nothing
                $this->stats['texts_blank'] = ($this->stats['texts_blank'] ?? 0) + 1;
                continue;
            }

            $runWidth = $width($run['codes']);
            if ($glyphFont !== null) {
                // cut where a code has no glyph: the next part starts at its own place
                $part = [];
                $partStart = 0.0;
                $offset = 0.0;
                foreach ([...$run['codes'], null] as $code) {
                    $gid = $code === null ? null : ($glyphFont['map'])($code);
                    if ($gid === null || $gid >= OpenType::GLYPH_CAPACITY) {
                        if ($part !== []) {
                            $partWidth = $width(array_column($part, 0));
                            $extra = $runWidth > 0 ? $run['extra'] * $partWidth / $runWidth : 0.0;
                            $partTx = $run['tx'] + $partStart + ($runWidth > 0 ? $run['extra'] * $partStart / $runWidth : 0.0);
                            $text = '';
                            foreach ($part as [$partCode, $partGid]) {
                                $text .= $glyphFont['space'] && $partCode === 32 ? ' ' : mb_chr(OpenType::GLYPH_BASE + $partGid);
                            }

                            $runStretch = $partWidth > 0 ? $stretch * ($partWidth + $extra) / $partWidth : $stretch;
                            $this->drawRun($glyphFont['file'], $text, $m, $partTx, $rise, $size, $runStretch, $kerning, $glyphFont['space'] ? $interword : 0.0, ($partWidth + $extra) * $xLength, $angle, $state, 'text_embedded');
                            $this->stats['texts_glyph_ids'] = ($this->stats['texts_glyph_ids'] ?? 0) + 1;
                        }

                        if ($code !== null) {
                            $this->stats['glyphs_missing'] = ($this->stats['glyphs_missing'] ?? 0) + 1;
                            $offset += $width([$code]);
                        }

                        $part = [];
                        $partStart = $offset;
                        continue;
                    }

                    $part[] = [$code, $gid];
                    $offset += $width([$code]);
                }

                continue;
            }

            $text = '';
            foreach ($run['segments'] as $segment) {
                $text .= $segment->getText($this->fontInfo($font)['differences'], $this->fontInfo($font)['encoding'], $this->fontInfo($font)['toUnicode']);
            }

            $target = ($runWidth + $run['extra']) * $xLength;
            if (trim($text) === '' || !mb_check_encoding($text, 'UTF-8')) {
                if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
                    $this->missingText(self::translateText($m, $run['tx'], $rise), $size, $target, $state);
                }

                continue;
            }

            $this->enter('fonts');
            try {
                $file = $this->fonts->file($font->getBaseFont(), $font->isBold(), $font->isItalic(), $text, $this->fontFlags($font));
                $spaces = substr_count($text, ' ');
                $spaceWidth = $spaces > 0 ? $this->fonts->width($file, ' ', $size) : 0.0;
                $textWidth = $this->fonts->width($file, $text, $size);
            } finally {
                $this->leave();
            }

            $substituteInterword = $wordSpace != 0.0 && $spaces > 0 ? $spaceWidth + $wordSpace : 0.0;
            $natural = $textWidth + $kerning * (mb_strlen($text) - 1) + ($substituteInterword != 0.0 ? $wordSpace * $spaces : 0.0);
            $runStretch = $natural > 0 && $target > 0 ? max(0.3, min(3.0, $target / $natural)) : $stretch;
            $this->drawRun($file, $text, $m, $run['tx'], $rise, $size, $runStretch, $kerning, $substituteInterword, $target, $angle, $state, $this->isType3($font) ? 'type3_text' : 'text_substitute');
            $this->stats['texts_substitute'] = ($this->stats['texts_substitute'] ?? 0) + 1;
        }

        return $advance;
    }

    /** @param list<\PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment> $segments */
    private function blank(Font $font, array $segments): bool {
        $info = $this->fontInfo($font);
        $text = '';
        foreach ($segments as $segment) {
            try {
                $text .= $segment->getText($info['differences'], $info['encoding'], $info['toUnicode']);
            } catch (Throwable) {
                return false;
            }
        }

        return $text !== '' && preg_match('/^[\s\x{00A0}\x{2000}-\x{200B}\x{3000}]+$/u', $text) === 1;
    }

    /** @param list<float> $m text space → canvas */
    private static function translateText(array $m, float $tx, float $rise): array {
        return [$m[0], $m[1], $m[2], $m[3], $m[0] * $tx + $m[2] * $rise + $m[4], $m[1] * $tx + $m[3] * $rise + $m[5]];
    }

    /**
     * One draw call: $text at $tx along the baseline, $kerning and $interword in font size pixels before the
     * horizontal $stretch
     *
     * @param list<float> $m text space → canvas
     * @param array<string, mixed> $state
     */
    private function drawRun(string $file, string $text, array $m, float $tx, float $rise, float $size, float $stretch, float $kerning, float $interword, float $width, float $angle, array $state, string $issue): void {
        $origin = self::translateText($m, $tx, $rise);
        $bbox = [$origin[4] - 1, $origin[5] - $size, $origin[4] + $width + 1, $origin[5] + 0.3 * $size];
        if ($angle != 0.0) {
            $reach = abs($width) + $size;
            $bbox = [$origin[4] - $reach, $origin[5] - $reach, $origin[4] + $reach, $origin[5] + $reach];
        }

        if ($this->offCanvas($bbox)) {
            return;
        }

        $area = $this->area($bbox);
        $this->stats['texts']++;
        $this->fidelity->add($issue, $area);
        $this->noteState($state, $area);
        $stroke = $state['textState']->render === 1 || $state['textState']->render === 5;
        $this->useClip($state, $bbox);
        $colour = self::css($this->rgb($state, $stroke, $area), ($stroke ? $state['sa'] : $state['fa']) * $state['ga']);
        $this->textQueue[$file][] = [$text, $origin[4], $origin[5], $size, $stretch, $kerning, $interword, $angle, $colour];
        $this->pending++;
        $this->flushIfManyPending();
    }

    /** @var array<string, list<array{string, float, float, float, float, float, float, float, string}>> font file => runs */
    private array $textQueue = [];

    /**
     * Queues the text runs drawn since the last other primitive (or clip change) font by font: ImageMagick 6 leaks
     * ~20 KB every time the font of a text changes, and Word puts the spaces between words in a font of their own.
     * Text over text is rare enough for the order among them not to matter; paths and images keep their place.
     */
    private function emitText(): void {
        foreach ($this->textQueue as $file => $runs) {
            if ($file !== $this->drawFont) {
                try {
                    $this->draw->setFont($file); // outside push/pop, so it is set once per font
                } catch (Throwable) {
                    $this->stats['font_errors'] = ($this->stats['font_errors'] ?? 0) + count($runs);
                    continue;
                }

                $this->drawFont = $file;
                $this->stats['font_switches'] = ($this->stats['font_switches'] ?? 0) + 1;
            }

            foreach ($runs as [$text, $x, $y, $size, $stretch, $kerning, $interword, $angle, $colour]) {
                $this->draw->push();
                $this->draw->setFontSize($size);
                $this->draw->setStrokeColor('none');
                $this->draw->setFillColor($colour);
                if ($kerning != 0.0) {
                    $this->draw->setTextKerning($kerning);
                }

                if ($interword != 0.0) {
                    $this->draw->setTextInterwordSpacing($interword);
                }

                $this->draw->translate($x, $y);
                if ($angle != 0.0) {
                    $this->draw->rotate($angle);
                }

                if (abs($stretch - 1) > 0.001) {
                    $this->draw->scale($stretch, 1);
                }

                $this->draw->annotation(0, 0, $text);
                $this->draw->pop();
            }
        }

        $this->textQueue = [];
    }

    /**
     * Text that is not drawn (no font, no glyphs and no Unicode text): its area counts as missing
     *
     * @param list<float> $m
     * @param array<string, mixed> $state
     */
    private function missingText(array $m, float $size, float $width, array $state): void {
        $this->stats['texts_missing'] = ($this->stats['texts_missing'] ?? 0) + 1;
        $this->fidelity->add('text_missing', $this->area([$m[4], $m[5] - $size, $m[4] + abs($width), $m[5] + 0.3 * $size]));
    }

    /** @var array<int, array{differences: mixed, encoding: mixed, toUnicode: mixed}> */
    private array $fontInfo = [];

    /** @return array{differences: mixed, encoding: mixed, toUnicode: mixed} */
    private function fontInfo(Font $font): array {
        if (!isset($this->fontInfo[$font->objectNumber])) {
            $info = ['differences' => null, 'encoding' => null, 'toUnicode' => null];
            foreach (['differences' => 'getDifferences', 'encoding' => 'getEncoding', 'toUnicode' => 'getToUnicodeCMap'] as $key => $method) {
                try {
                    $info[$key] = $font->$method();
                } catch (Throwable) {
                }
            }

            $this->fontInfo[$font->objectNumber] = $info;
        }

        return $this->fontInfo[$font->objectNumber];
    }

    // ---------------------------------------------------------------- images

    private function xobject(string $name, array &$state, int $depth): void {
        $reference = $this->objects->dictionary($state['resources']['XObject'] ?? null)[$name] ?? null;
        if (!$reference instanceof PdfReference) {
            return;
        }

        $dictionary = $this->objects->dictionary($this->objects->value($reference->objectNumber)) ?? [];
        $subtype = $this->objects->name($dictionary['Subtype'] ?? null);
        if ($subtype === 'Image') {
            $this->stats['images']++;
            $this->placeImage($state, fn (int $target, array $fill) => $this->images->xobject($reference->objectNumber, $state['resources'], $fill, $target));
        } elseif ($subtype === 'Form') {
            $this->form($reference->objectNumber, $dictionary, $state, $depth, null);
        }
    }

    private function inlineImage(string $operands, array $state): void {
        $this->stats['inline_images']++;
        $this->placeImage($state, fn (int $target, array $fill) => $this->images->inline($operands, $state['resources'], $fill, $target));
    }

    /** @param callable(int, array): array{image: ?Imagick, reason: ?string, approximate: ?string, masksSkipped?: bool} $decode */
    private function placeImage(array $state, callable $decode): void {
        $m = $this->device($state);
        $topLeft = self::apply($m, 0, 1);
        $topRight = self::apply($m, 1, 1);
        $bottomLeft = self::apply($m, 0, 0);
        $bottomRight = self::apply($m, 1, 0);
        $bbox = null;
        foreach ([$topLeft, $topRight, $bottomLeft, $bottomRight] as [$x, $y]) {
            $bbox = self::grow($bbox, $x, $y);
        }

        $area = $this->area($bbox);
        if ($area < 0.5) { // also off the canvas: not decoded at all
            return;
        }

        if ($this->options->images === RenderOptions::IMAGES_SKIP) {
            $this->fidelity->add('image_skipped', $area);

            return;
        }

        if ($this->options->images === RenderOptions::IMAGES_BOX) {
            $this->fidelity->add('image_box', $area);
            $this->useClip($state, $bbox);
            $this->emitText();
            $this->draw->push();
            $this->draw->setStrokeColor('none');
            $this->draw->setFillColor('#d6d6d6');
            $this->draw->polygon(array_map(static fn (array $p) => ['x' => $p[0], 'y' => $p[1]], [$topLeft, $topRight, $bottomRight, $bottomLeft]));
            $this->draw->pop();
            $this->pending++;
            $this->flushIfManyPending();

            return;
        }

        if ($state['softMask']) {
            $this->fidelity->add('soft_mask', $area);
        }

        $this->enter('image_decode');
        try {
            // pixels for the whole image as large as it is on the canvas (a crop can show a small part of it big),
            // capped: the decoder does not go above the image's own resolution anyway
            $fullArea = min(abs($m[0] * $m[3] - $m[1] * $m[2]), 64e6);
            $decoded = $decode((int) max(64, $fullArea * 1.5 * ($this->options->imageResolution / max(1, $this->options->supersample)) ** 2), $this->rgb($state, false, $area));
        } finally {
            $this->leave();
        }

        $image = $decoded['image'];
        if ($image === null) {
            $this->fidelity->add('image_undecodable', $area);
            $this->stats['image_failures'][$decoded['reason']] = ($this->stats['image_failures'][$decoded['reason']] ?? 0) + 1;

            return;
        }

        if ($decoded['masksSkipped'] ?? false) {
            $this->fidelity->add('image_mask_skipped', $area);
        }

        if ($decoded['approximate'] !== null) {
            $this->fidelity->add('image_approximate', $area);
        }

        $this->flush();
        $this->enter('image_place');
        try {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $image->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
            $axisAligned = abs($m[1]) < 1e-6 && abs($m[2]) < 1e-6;
            if ($axisAligned) {
                if ($m[0] < 0) {
                    $image->flopImage();
                }

                if ($m[3] > 0) {
                    $image->flipImage();
                }

                // only the part of the image on the canvas is cut out of the source and scaled
                $deviceWidth = abs($m[0]);
                $deviceHeight = abs($m[3]);
                $sourceWidth = $image->getImageWidth();
                $sourceHeight = $image->getImageHeight();
                $sx0 = (int) max(0, floor((max(0.0, $bbox[0]) - $bbox[0]) / $deviceWidth * $sourceWidth));
                $sy0 = (int) max(0, floor((max(0.0, $bbox[1]) - $bbox[1]) / $deviceHeight * $sourceHeight));
                $sx1 = (int) min($sourceWidth, ceil((min($this->canvasWidth, $bbox[2]) - $bbox[0]) / $deviceWidth * $sourceWidth));
                $sy1 = (int) min($sourceHeight, ceil((min($this->canvasHeight, $bbox[3]) - $bbox[1]) / $deviceHeight * $sourceHeight));
                if ($sx1 <= $sx0 || $sy1 <= $sy0) {
                    return;
                }

                if ($sx0 > 0 || $sy0 > 0 || $sx1 < $sourceWidth || $sy1 < $sourceHeight) {
                    $image->cropImage($sx1 - $sx0, $sy1 - $sy0, $sx0, $sy0);
                    $image->setImagePage(0, 0, 0, 0);
                }

                $x = $bbox[0] + $sx0 * $deviceWidth / $sourceWidth;
                $y = $bbox[1] + $sy0 * $deviceHeight / $sourceHeight;
                $width = max(1, (int) round(($sx1 - $sx0) * $deviceWidth / $sourceWidth));
                $height = max(1, (int) round(($sy1 - $sy0) * $deviceHeight / $sourceHeight));
                $image->resizeImage($width, $height, $width * $height < ($sx1 - $sx0) * ($sy1 - $sy0) ? Imagick::FILTER_TRIANGLE : Imagick::FILTER_LANCZOS, 1);
                $x = (int) round($x);
                $y = (int) round($y);
            } else {
                // scaled to at most the canvas resolution, then turned into place within the visible part only
                $targetWidth = max(2, (int) round(sqrt(($topRight[0] - $topLeft[0]) ** 2 + ($topRight[1] - $topLeft[1]) ** 2)));
                $targetHeight = max(2, (int) round(sqrt(($bottomLeft[0] - $topLeft[0]) ** 2 + ($bottomLeft[1] - $topLeft[1]) ** 2)));
                $limit = 4 * max($this->canvasWidth, $this->canvasHeight);
                $shrink = min(1.0, $limit / max($targetWidth, $targetHeight));
                $sourceWidth = max(2, (int) round($targetWidth * $shrink));
                $sourceHeight = max(2, (int) round($targetHeight * $shrink));
                $image->resizeImage($sourceWidth, $sourceHeight, Imagick::FILTER_TRIANGLE, 1);
                $x = (int) max(0, floor($bbox[0]));
                $y = (int) max(0, floor($bbox[1]));
                $viewWidth = (int) min($this->canvasWidth, ceil($bbox[2])) - $x;
                $viewHeight = (int) min($this->canvasHeight, ceil($bbox[3])) - $y;
                if ($viewWidth < 1 || $viewHeight < 1) {
                    return;
                }

                $image->setImageArtifact('distort:viewport', sprintf('%dx%d+%d+%d', $viewWidth, $viewHeight, $x, $y));
                $image->distortImage(Imagick::DISTORTION_AFFINE, [
                    0, 0, $topLeft[0], $topLeft[1],
                    $sourceWidth, 0, $topRight[0], $topRight[1],
                    0, $sourceHeight, $bottomLeft[0], $bottomLeft[1],
                ], false);
                $image->setImagePage(0, 0, 0, 0);
            }

            if ($state['fa'] * $state['ga'] < 1) {
                $image->evaluateImage(Imagick::EVALUATE_MULTIPLY, $state['fa'] * $state['ga'], Imagick::CHANNEL_ALPHA);
            }

            $clip = $this->options->clipping ? $state['clip'] : null;
            if ($clip !== null) {
                $box = $clip['rect'] ?? $clip['bbox'];

                if ($box !== null) {
                    $x0 = (int) max($x, floor($box[0]));
                    $y0 = (int) max($y, floor($box[1]));
                    $x1 = (int) min($x + $image->getImageWidth(), ceil($box[2]));
                    $y1 = (int) min($y + $image->getImageHeight(), ceil($box[3]));
                    if ($x1 <= $x0 || $y1 <= $y0) {
                        return;
                    }

                    $image->cropImage($x1 - $x0, $y1 - $y0, $x0 - $x, $y0 - $y);
                    $image->setImagePage(0, 0, 0, 0);
                    [$x, $y] = [$x0, $y0];
                }

                if ($clip['rect'] === null) {
                    $mask = $this->clipMask($clip, $x, $y, $image->getImageWidth(), $image->getImageHeight());
                    $image->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
                    $mask->clear();
                }
            }

            $this->canvas->compositeImage($image, self::BLEND_OPERATORS[$state['bm']] ?? Imagick::COMPOSITE_OVER, $x, $y);
            $this->sampleMemory();
        } catch (Throwable $e) {
            $this->fidelity->add('image_undecodable', $area);
            $this->stats['image_failures']['place: ' . substr($e->getMessage(), 0, 40)] = 1;
        } finally {
            $image->clear();
            $this->leave();
        }
    }

    // ---------------------------------------------------------------- forms

    /** @param ?array $ctm a matrix to use instead of form Matrix × CTM (annotations) */
    private function form(int $objectNumber, array $dictionary, array $state, int $depth, ?array $ctm): void {
        $bboxValues = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($dictionary['BBox'] ?? null) ?? []);
        $matrix = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($dictionary['Matrix'] ?? null) ?? [1, 0, 0, 1, 0, 0]);
        $state['ctm'] = $ctm ?? self::multiply(count($matrix) === 6 ? $matrix : [1, 0, 0, 1, 0, 0], $state['ctm']);
        if ($depth > 12 || in_array($objectNumber, $this->formStack, true)) {
            if (count($bboxValues) === 4) {
                $this->fidelity->add('form_depth', $this->area($this->deviceBox($state, $bboxValues)));
            }

            return;
        }

        $decorated = $this->objects->decorated($objectNumber, XObject::class);
        if (!$decorated instanceof XObject) {
            return;
        }

        try {
            $content = ContentStreamParser::parse([$decorated])->content;
        } catch (Throwable $e) {
            $this->stats['form_errors'][$objectNumber] = substr($e::class . ': ' . $e->getMessage(), 0, 160);
            if (count($bboxValues) === 4) {
                $this->fidelity->add('content_error', $this->area($this->deviceBox($state, $bboxValues)));
            }

            return;
        }

        $this->stats['forms']++;
        if (count($bboxValues) === 4) {
            $path = ['cmds' => [], 'bbox' => null, 'cur' => null, 'start' => null];
            $this->pathCommand(PathConstructionOperator::RECTANGLE, [$bboxValues[0], $bboxValues[1], $bboxValues[2] - $bboxValues[0], $bboxValues[3] - $bboxValues[1]], $state, $path);
            $this->setClip($state, $path, false);
        }

        $group = $this->objects->dictionary($dictionary['Group'] ?? null);
        if ($group !== null && $this->objects->name($group['S'] ?? null) === 'Transparency') {
            $state['ga'] *= $state['fa']; // the constant alpha applies to the group as a whole
            $state['fa'] = $state['sa'] = 1.0;
        }

        $resources = isset($dictionary['Resources']) ? ($this->objects->dictionary($dictionary['Resources']) ?? $state['resources']) : $state['resources'];
        $this->formStack[] = $objectNumber;
        try {
            $this->interpret($content, $resources, $state, $depth + 1);
        } catch (Throwable $e) {
            $this->stats['form_errors'][$objectNumber] = substr($e::class . ': ' . $e->getMessage(), 0, 160);
            if (count($bboxValues) === 4) {
                $this->fidelity->add('content_error', $this->area($this->deviceBox($state, $bboxValues)));
            }
        } finally {
            array_pop($this->formStack);
        }
    }

    private function deviceBox(array $state, array $box): array {
        $m = $this->device($state);
        $bbox = null;
        foreach ([[$box[0], $box[1]], [$box[2], $box[1]], [$box[0], $box[3]], [$box[2], $box[3]]] as [$x, $y]) {
            [$dx, $dy] = self::apply($m, $x, $y);
            $bbox = self::grow($bbox, $dx, $dy);
        }

        return $bbox;
    }

    // ---------------------------------------------------------------- shadings and patterns

    private function shadingByName(string $name, array $state, ?array $patternMatrix): void {
        $shading = $this->objects->dictionary($state['resources']['Shading'] ?? null)[$name] ?? null;
        $this->shading($shading, $state, $state['clip']['bbox'] ?? [0, 0, $this->canvasWidth, $this->canvasHeight]);
    }

    /** Paints $shading over $region (device space) under the clip of $state; true when drawn as bands (or skipped) */
    private function shading(mixed $shadingValue, array $state, array $region): bool {
        if ($this->options->shadings === RenderOptions::SHADINGS_SKIP) {
            $this->fidelity->add('shading_skipped', $this->area($region));

            return true;
        }

        $this->enter('shading');
        try {
            return $this->drawShading($shadingValue, $state, $region);
        } finally {
            $this->leave();
        }
    }

    private function drawShading(mixed $shadingValue, array $state, array $region): bool {
        $shading = $this->objects->dictionary($shadingValue);
        if ($shading === null) {
            return false;
        }

        $this->stats['shadings']++;
        $type = (int) $this->objects->number($shading['ShadingType'] ?? 0);
        $this->stats['shading_types'][$type] = ($this->stats['shading_types'][$type] ?? 0) + 1;
        $cs = $this->colors->colorSpace($shading['ColorSpace'] ?? '/DeviceRGB', $state['resources']);
        $function = PdfFunction::from($this->objects, $shading['Function'] ?? null);
        $domain = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($shading['Domain'] ?? null) ?? [0, 1]);
        $colorAt = function (float $t) use ($cs, $function): array {
            $components = $function !== null && $function !== [] ? PdfFunction::evaluate($function, [$t]) : [$t];

            return $this->colors->toRgb($cs, $components);
        };
        $area = $this->area($region);
        $coords = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($shading['Coords'] ?? null) ?? []);
        $m = $this->device($state);
        $this->useClip($state, null);
        $this->emitText();
        $this->draw->push();
        $this->draw->setStrokeColor('none');
        $drawn = false;
        $bands = max(2, $this->options->shadingBands);
        $flat = $this->options->shadings === RenderOptions::SHADINGS_FLAT;
        if (!$flat && $type === 2 && count($coords) === 4 && $function !== null) {
            [$x0, $y0] = self::apply($m, $coords[0], $coords[1]);
            [$x1, $y1] = self::apply($m, $coords[2], $coords[3]);
            $region = [max(0.0, $region[0]), max(0.0, $region[1]), min($this->canvasWidth, $region[2]), min($this->canvasHeight, $region[3])];
            if (($x1 - $x0) ** 2 + ($y1 - $y0) ** 2 > 0.0001 && $region[2] - $region[0] >= 1 && $region[3] - $region[1] >= 1) {
                $lut = [];
                for ($i = 0; $i < $bands; $i++) {
                    $rgb = $colorAt($domain[0] + ($i + 0.5) / $bands * (($domain[1] ?? 1) - $domain[0]));
                    $lut[] = pack('C4', (int) round(255 * $rgb[0]), (int) round(255 * $rgb[1]), (int) round(255 * $rgb[2]), 255);
                }

                $extend = $this->objects->array($shading['Extend'] ?? null) ?? [false, false];
                $this->draw->pop();
                $this->flush();
                $this->placeShadingImage($this->axialImage($region, $x0, $y0, $x1, $y1, $lut, ($extend[0] ?? false) === true, ($extend[1] ?? false) === true), $region, $state);
                $this->fidelity->add('shading_gradient', $area * 0.1);

                return true;
            }
        } elseif (!$flat && $type === 3 && count($coords) === 6 && $function !== null) {
            $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
            $region = [max(0.0, $region[0]), max(0.0, $region[1]), min($this->canvasWidth, $region[2]), min($this->canvasHeight, $region[3])];
            if ($region[2] - $region[0] >= 1 && $region[3] - $region[1] >= 1) {
                $lut = [];
                for ($i = 0; $i < $bands; $i++) {
                    $rgb = $colorAt($domain[0] + ($i + 0.5) / $bands * (($domain[1] ?? 1) - $domain[0]));
                    $lut[] = pack('C4', (int) round(255 * $rgb[0]), (int) round(255 * $rgb[1]), (int) round(255 * $rgb[2]), 255);
                }

                $extend = $this->objects->array($shading['Extend'] ?? null) ?? [false, false];
                [$cx0, $cy0] = self::apply($m, $coords[0], $coords[1]);
                [$cx1, $cy1] = self::apply($m, $coords[3], $coords[4]);
                $this->draw->pop();
                $this->flush();
                $this->placeShadingImage($this->radialImage($region, [$cx0, $cy0, $coords[2] * $scale], [$cx1, $cy1, $coords[5] * $scale], $lut, ($extend[0] ?? false) === true, ($extend[1] ?? false) === true), $region, $state);
                $this->fidelity->add('shading_gradient', $area * 0.1);

                return true;
            }
        }

        if (!$drawn) {
            $background = $this->objects->array($shading['Background'] ?? null);
            $rgb = $background !== null ? $this->colors->toRgb($cs, array_map(fn ($v) => $this->objects->number($v), $background)) : $colorAt(($domain[0] + ($domain[1] ?? 1)) / 2);
            $this->draw->setFillColor(self::css($rgb, $state['fa']));
            $this->draw->rectangle($region[0], $region[1], $region[2], $region[3]);
            $this->fidelity->add('shading_flat', $area);
        } else {
            $this->fidelity->add('shading_gradient', $area * 0.4);
        }

        $this->draw->pop();
        $this->pending++;

        return $drawn;
    }

    /**
     * An axial shading over $region (device space) as an image: the colour of pixel x on a row is $lut[floor(t × n)],
     * t its position along the axis (x0, y0) → (x1, y1); a row is built from runs of equal colour
     *
     * @param list<string> $lut RGBA bytes per band
     */
    private function axialImage(array $region, float $x0, float $y0, float $x1, float $y1, array $lut, bool $extend0, bool $extend1): Imagick {
        [$left, $top] = [(int) floor($region[0]), (int) floor($region[1])];
        $width = max(1, (int) ceil($region[2]) - $left);
        $height = max(1, (int) ceil($region[3]) - $top);
        $n = count($lut);
        $before = $extend0 ? $lut[0] : "\0\0\0\0";
        $after = $extend1 ? $lut[$n - 1] : "\0\0\0\0";
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $length2 = $dx * $dx + $dy * $dy;
        $step = $dx / $length2; // t per pixel along a row
        $pixels = '';
        $previous = null;
        for ($row = 0; $row < $height; $row++) {
            $t0 = (($left + 0.5 - $x0) * $dx + ($top + $row + 0.5 - $y0) * $dy) / $length2;
            if ($previous !== null && $dy == 0.0) {
                $pixels .= $previous;
                continue;
            }

            $line = '';
            for ($x = 0; $x < $width;) {
                $k = max(-1, min($n, (int) floor(($t0 + $step * $x) * $n)));
                if (abs($step) < 1e-12) {
                    $next = $width;
                } else {
                    $boundary = $step > 0 ? ($k >= $n ? INF : ($k + 1) / $n) : ($k < 0 ? -INF : $k / $n);
                    $next = is_infinite($boundary) ? $width : (int) max($x + 1, min($width, ceil(($boundary - $t0) / $step - 1e-9)));
                }

                $line .= str_repeat($k < 0 ? $before : ($k >= $n ? $after : $lut[$k]), $next - $x);
                $x = $next;
            }

            $pixels .= $previous = $line;
        }

        $image = new Imagick();
        $image->setSize($width, $height);
        $image->setFormat('rgba');
        $image->setOption('depth', '8');
        $image->readImageBlob($pixels);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    /**
     * A radial shading over $region (device space) as an image (8.7.4.5.4): for each point the largest s with the
     * point on the circle c0 + s(c1 − c0), r0 + s(r1 − r0); computed on a grid of at most ~40000 points and scaled up,
     * the colours change slowly and the clip path is applied at full resolution afterwards
     *
     * @param array{float, float, float} $circle0 centre x, y and radius
     * @param array{float, float, float} $circle1
     * @param list<string> $lut RGBA bytes per band
     */
    private function radialImage(array $region, array $circle0, array $circle1, array $lut, bool $extend0, bool $extend1): Imagick {
        [$left, $top] = [(int) floor($region[0]), (int) floor($region[1])];
        $width = max(1, (int) ceil($region[2]) - $left);
        $height = max(1, (int) ceil($region[3]) - $top);
        $grid = max(1, (int) ceil(sqrt($width * $height / 40000)));
        $columns = (int) ceil($width / $grid);
        $rows = (int) ceil($height / $grid);
        $n = count($lut);
        [$x0, $y0, $r0] = $circle0;
        [$cdx, $cdy, $dr] = [$circle1[0] - $x0, $circle1[1] - $y0, $circle1[2] - $r0];
        $a = $cdx * $cdx + $cdy * $cdy - $dr * $dr;
        $none = "\0\0\0\0";
        $pixels = '';
        for ($row = 0; $row < $rows; $row++) {
            $py = $top + ($row + 0.5) * $grid - $y0;
            for ($column = 0; $column < $columns; $column++) {
                $px = $left + ($column + 0.5) * $grid - $x0;
                $b = $px * $cdx + $py * $cdy + $r0 * $dr;
                $c = $px * $px + $py * $py - $r0 * $r0;
                if (abs($a) < 1e-9) {
                    $roots = abs($b) < 1e-9 ? [] : [$c / (2 * $b)];
                } else {
                    $discriminant = $b * $b - $a * $c;
                    $roots = $discriminant < 0 ? [] : [($b + sqrt($discriminant)) / $a, ($b - sqrt($discriminant)) / $a];
                    rsort($roots);
                }

                $colour = $none;
                foreach ($roots as $s) {
                    if ($r0 + $s * $dr < 0 || ($s > 1 && !$extend1) || ($s < 0 && !$extend0)) {
                        continue;
                    }

                    $colour = $lut[max(0, min($n - 1, (int) floor($s * $n)))];
                    break;
                }

                $pixels .= $colour;
            }
        }

        $image = new Imagick();
        $image->setSize($columns, $rows);
        $image->setFormat('rgba');
        $image->setOption('depth', '8');
        $image->readImageBlob($pixels);
        $image->setImagePage(0, 0, 0, 0);
        if ($grid > 1) {
            $image->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
        }

        return $image;
    }

    /** Composites a shading drawn as an image over $region, masked by the clip path and with the alpha of $state */
    private function placeShadingImage(Imagick $image, array $region, array $state): void {
        $left = (int) floor($region[0]);
        $top = (int) floor($region[1]);
        $clip = $state['clip'];
        if ($clip !== null && $clip['rect'] === null && $this->options->clipping) {
            $mask = $this->clipMask($clip, $left, $top, $image->getImageWidth(), $image->getImageHeight());
            $image->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
            $mask->clear();
        }

        if ($state['fa'] * $state['ga'] < 1) {
            $image->evaluateImage(Imagick::EVALUATE_MULTIPLY, $state['fa'] * $state['ga'], Imagick::CHANNEL_ALPHA);
        }

        $this->canvas->compositeImage($image, self::BLEND_OPERATORS[$state['bm']] ?? Imagick::COMPOSITE_OVER, $left, $top);
        $this->sampleMemory();
        $image->clear();
    }

    /** Fills $cmds with a shading pattern (PatternType 2) drawn in bands; false for other patterns */
    private function shadingPatternFill(string $name, array $state, array $cmds, bool $evenOdd, ?array $bbox): bool {
        $pattern = $this->objects->dictionary($this->objects->dictionary($state['resources']['Pattern'] ?? null)[$name] ?? null);
        if ($pattern === null || (int) $this->objects->number($pattern['PatternType'] ?? 1) !== 2 || $bbox === null) {
            return false;
        }

        $matrix = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($pattern['Matrix'] ?? null) ?? [1, 0, 0, 1, 0, 0]);
        $patternState = $state;
        $patternState['ctm'] = count($matrix) === 6 ? $matrix : [1, 0, 0, 1, 0, 0];
        $patternState['clip'] = $state['clip'];
        $path = ['cmds' => $cmds, 'bbox' => $bbox];
        $this->setClip($patternState, $path, $evenOdd);

        return $this->shading($pattern['Shading'] ?? null, $patternState, $patternState['clip']['bbox'] ?? $bbox);
    }

    /** @return array{0: float, 1: float, 2: float} a single colour for a pattern that is not drawn */
    private function patternColor(string $name, array $state, float $area): array {
        $pattern = $this->objects->dictionary($this->objects->dictionary($state['resources']['Pattern'] ?? null)[$name] ?? null);
        $this->fidelity->add('pattern', $area);
        if ($pattern !== null && (int) $this->objects->number($pattern['PatternType'] ?? 1) === 2) {
            $shading = $this->objects->dictionary($pattern['Shading'] ?? null) ?? [];
            $function = PdfFunction::from($this->objects, $shading['Function'] ?? null);
            if ($function !== null && $function !== []) {
                return $this->colors->toRgb($this->colors->colorSpace($shading['ColorSpace'] ?? '/DeviceRGB', $state['resources']), PdfFunction::evaluate($function, [0.5]));
            }
        }

        return [0.6, 0.6, 0.6];
    }

    // ---------------------------------------------------------------- annotations

    private function annotations(int $pageNumber, array $state): void {
        if (!$this->options->annotations) {
            return;
        }

        $page = $this->objects->dictionary($this->objects->value($pageNumber)) ?? [];
        foreach ($this->objects->array($page['Annots'] ?? null) ?? [] as $annotationValue) {
            $annotation = $this->objects->dictionary($annotationValue);
            if ($annotation === null) {
                continue;
            }

            $flags = (int) $this->objects->number($annotation['F'] ?? 0);
            $subtype = $this->objects->name($annotation['Subtype'] ?? null);
            if (($flags & 0b100010) !== 0 || in_array($subtype, ['Popup', 'Link'], true)) {
                continue;
            }

            $rect = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($annotation['Rect'] ?? null) ?? []);
            $appearance = $this->objects->dictionary($annotation['AP'] ?? null)['N'] ?? null;
            if (count($rect) !== 4 || $appearance === null) {
                continue;
            }

            $appearanceState = $this->objects->name($annotation['AS'] ?? null);
            $normal = $this->objects->resolve($appearance);
            if (is_array($normal) && !array_is_list($normal) && !isset($normal['Subtype']) && !isset($normal['BBox'])) {
                $appearance = $appearanceState !== null ? ($normal[$appearanceState] ?? null) : null;
            }

            if (!$appearance instanceof PdfReference) {
                continue;
            }

            $form = $this->objects->dictionary($this->objects->value($appearance->objectNumber)) ?? [];
            $box = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($form['BBox'] ?? null) ?? []);
            if (count($box) !== 4) {
                continue;
            }

            $matrix = array_map(fn ($v) => $this->objects->number($v), $this->objects->array($form['Matrix'] ?? null) ?? [1, 0, 0, 1, 0, 0]);
            $transformed = null;
            foreach ([[$box[0], $box[1]], [$box[2], $box[1]], [$box[0], $box[3]], [$box[2], $box[3]]] as [$x, $y]) {
                [$tx, $ty] = self::apply($matrix, $x, $y);
                $transformed = self::grow($transformed, $tx, $ty);
            }

            $tw = max(1e-6, $transformed[2] - $transformed[0]);
            $th = max(1e-6, $transformed[3] - $transformed[1]);
            $sx = (max($rect[0], $rect[2]) - min($rect[0], $rect[2])) / $tw;
            $sy = (max($rect[1], $rect[3]) - min($rect[1], $rect[3])) / $th;
            $a = [$sx, 0, 0, $sy, min($rect[0], $rect[2]) - $transformed[0] * $sx, min($rect[1], $rect[3]) - $transformed[1] * $sy];
            $this->stats['annotations']++;
            $annotationState = $this->initialState();
            $annotationState['resources'] = $state['resources'];
            $this->form($appearance->objectNumber, $form, $annotationState, 1, self::multiply($matrix, $a));
        }
    }
}
