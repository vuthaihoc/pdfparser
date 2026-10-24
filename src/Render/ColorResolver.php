<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * Colour spaces (ISO 32000 8.6) as arrays: family Gray|RGB|CMYK|Lab|Indexed|Separation|DeviceN|Pattern|Unknown,
 * n (components), base (Indexed base / alternate space), func (tint transform), hival + lookup (Indexed), none.
 * Colours are converted to sRGB-ish 0..1 triplets; ICC profiles are not applied.
 *
 * @experimental
 */
final class ColorResolver {
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly RawObjects $objects,
    ) {}

    /**
     * @param array<string, mixed> $resources
     * @return array<string, mixed>
     */
    public function colorSpace(mixed $value, array $resources, int $depth = 0): array {
        $cacheKey = $value instanceof PdfReference ? 'r' . $value->objectNumber : (is_string($value) ? $value . '#' . spl_object_id((object) $resources) : null);
        if ($value instanceof PdfReference && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $info = $this->build($value, $resources, $depth);
        if ($value instanceof PdfReference) {
            $this->cache[$cacheKey] = $info;
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $resources
     * @return array<string, mixed>
     */
    private function build(mixed $value, array $resources, int $depth): array {
        if ($depth > 8) {
            return ['family' => 'Unknown', 'n' => 1];
        }

        $value = $this->objects->resolve($value);
        if (is_string($value) && str_starts_with($value, '/')) {
            $name = substr($value, 1);
            switch ($name) {
                case 'DeviceGray': case 'G': case 'CalGray': return ['family' => 'Gray', 'n' => 1];
                case 'DeviceRGB': case 'RGB': case 'CalRGB': return ['family' => 'RGB', 'n' => 3];
                case 'DeviceCMYK': case 'CMYK': return ['family' => 'CMYK', 'n' => 4];
                case 'Pattern': return ['family' => 'Pattern', 'n' => 1, 'base' => null];
            }

            $named = $this->objects->dictionary($resources['ColorSpace'] ?? null)[$name] ?? null;

            return $named === null ? ['family' => 'Unknown', 'n' => 1] : $this->colorSpace($named, $resources, $depth + 1);
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return ['family' => 'Unknown', 'n' => 1];
        }

        $family = is_string($value[0]) ? ltrim($value[0], '/') : '';
        switch ($family) {
            case 'ICCBased':
                $streamDictionary = $this->objects->dictionary($value[1] ?? null) ?? [];
                $n = (int) $this->objects->number($streamDictionary['N'] ?? 3, 3);

                return match ($n) { 1 => ['family' => 'Gray', 'n' => 1], 4 => ['family' => 'CMYK', 'n' => 4], default => ['family' => 'RGB', 'n' => 3] };
            case 'CalGray': case 'DeviceGray': case 'G':
                return ['family' => 'Gray', 'n' => 1];
            case 'CalRGB': case 'DeviceRGB': case 'RGB':
                return ['family' => 'RGB', 'n' => 3];
            case 'DeviceCMYK': case 'CMYK':
                return ['family' => 'CMYK', 'n' => 4];
            case 'Lab':
                return ['family' => 'Lab', 'n' => 3];
            case 'Indexed': case 'I':
                $base = $this->colorSpace($value[1] ?? '/DeviceRGB', $resources, $depth + 1);
                $lookup = $value[3] ?? null;
                $lookupBytes = $lookup instanceof PdfString ? $lookup->bytes
                    : ($lookup instanceof PdfReference ? ($this->objects->streamData($lookup->objectNumber) ?? '') : '');
                if ($lookup instanceof PdfReference && $lookupBytes === '' && ($resolved = $this->objects->resolve($lookup)) instanceof PdfString) {
                    $lookupBytes = $resolved->bytes;
                }

                return ['family' => 'Indexed', 'n' => 1, 'base' => $base, 'hival' => (int) $this->objects->number($value[2] ?? 255, 255), 'lookup' => $lookupBytes];
            case 'Separation':
                $colorant = is_string($value[1] ?? null) ? ltrim($value[1], '/') : '';

                return ['family' => 'Separation', 'n' => 1, 'base' => $this->colorSpace($value[2] ?? '/DeviceGray', $resources, $depth + 1),
                    'func' => PdfFunction::from($this->objects, $value[3] ?? null), 'none' => $colorant === 'None', 'all' => $colorant === 'All'];
            case 'DeviceN':
                $names = $this->objects->array($value[1] ?? []) ?? [];

                return ['family' => 'DeviceN', 'n' => max(1, count($names)), 'base' => $this->colorSpace($value[2] ?? '/DeviceGray', $resources, $depth + 1),
                    'func' => PdfFunction::from($this->objects, $value[3] ?? null)];
            case 'Pattern':
                return ['family' => 'Pattern', 'n' => 1, 'base' => isset($value[1]) ? $this->colorSpace($value[1], $resources, $depth + 1) : null];
        }

        return ['family' => 'Unknown', 'n' => 1];
    }

    /**
     * @param array<string, mixed> $colorSpace
     * @return list<float>
     */
    public function initial(array $colorSpace): array {
        return match ($colorSpace['family']) {
            'CMYK' => [0.0, 0.0, 0.0, 1.0],
            'RGB', 'Lab' => [0.0, 0.0, 0.0],
            'Separation' => [1.0],
            'DeviceN' => array_fill(0, (int) $colorSpace['n'], 1.0),
            default => [0.0],
        };
    }

    /**
     * @param array<string, mixed> $colorSpace
     * @param list<float> $components
     * @return array{0: float, 1: float, 2: float}
     */
    public function toRgb(array $colorSpace, array $components, int $depth = 0): array {
        $c = static fn (int $i, float $default = 0.0): float => max(0.0, min(1.0, (float) ($components[$i] ?? $default)));
        switch ($colorSpace['family']) {
            case 'Gray':
                return [$c(0), $c(0), $c(0)];
            case 'RGB':
                return [$c(0), $c(1), $c(2)];
            case 'CMYK':
                return self::cmykToRgb($c(0), $c(1), $c(2), $c(3));
            case 'Lab':
                return self::labToRgb((float) ($components[0] ?? 0), (float) ($components[1] ?? 0), (float) ($components[2] ?? 0));
            case 'Indexed':
                $index = (int) max(0, min((int) $colorSpace['hival'], (int) round((float) ($components[0] ?? 0))));
                $base = $colorSpace['base'];
                $n = (int) $base['n'];
                $values = [];
                for ($i = 0; $i < $n; $i++) {
                    $values[] = ord($colorSpace['lookup'][$index * $n + $i] ?? "\0") / 255;
                }

                if ($base['family'] === 'Lab') {
                    $values = [$values[0] * 100, $values[1] * 255 - 128, $values[2] * 255 - 128];
                }

                return $depth > 4 ? [0.5, 0.5, 0.5] : $this->toRgb($base, $values, $depth + 1);
            case 'Separation':
            case 'DeviceN':
                if (!empty($colorSpace['none'])) {
                    return [1.0, 1.0, 1.0];
                }

                if (!empty($colorSpace['all'])) {
                    $t = $c(0);

                    return [1 - $t, 1 - $t, 1 - $t];
                }

                if ($colorSpace['func'] !== null && $colorSpace['func'] !== [] && $depth <= 4) {
                    return $this->toRgb($colorSpace['base'], PdfFunction::evaluate($colorSpace['func'], array_map('floatval', $components)), $depth + 1);
                }

                $t = $c(0);

                return [1 - $t, 1 - $t, 1 - $t];
        }

        return [0.5, 0.5, 0.5];
    }

    /**
     * CMYK to RGB by interpolating between the RGB values of the 16 CMYK corners (the table xpdf and poppler use),
     * closer to a press profile than (1 − c)(1 − k).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function cmykToRgb(float $c, float $m, float $y, float $k): array {
        [$c1, $m1, $y1, $k1] = [1 - $c, 1 - $m, 1 - $y, 1 - $k];
        $r = $g = $b = 0.0;
        $x = $c1 * $m1 * $y1 * $k1; $r += $x; $g += $x; $b += $x;
        $x = $c1 * $m1 * $y1 * $k; $r += 0.1373 * $x; $g += 0.1216 * $x; $b += 0.1255 * $x;
        $x = $c1 * $m1 * $y * $k1; $r += $x; $g += 0.9490 * $x;
        $x = $c1 * $m1 * $y * $k; $r += 0.1098 * $x; $g += 0.1020 * $x;
        $x = $c1 * $m * $y1 * $k1; $r += 0.9255 * $x; $b += 0.5490 * $x;
        $x = $c1 * $m * $y1 * $k; $r += 0.1412 * $x;
        $x = $c1 * $m * $y * $k1; $r += 0.9294 * $x; $g += 0.1098 * $x; $b += 0.1412 * $x;
        $x = $c1 * $m * $y * $k; $r += 0.1333 * $x;
        $x = $c * $m1 * $y1 * $k1; $g += 0.6784 * $x; $b += 0.9373 * $x;
        $x = $c * $m1 * $y1 * $k; $g += 0.0588 * $x; $b += 0.1412 * $x;
        $x = $c * $m1 * $y * $k1; $g += 0.6510 * $x; $b += 0.3137 * $x;
        $x = $c * $m1 * $y * $k; $g += 0.0745 * $x;
        $x = $c * $m * $y1 * $k1; $r += 0.1804 * $x; $g += 0.1922 * $x; $b += 0.5725 * $x;
        $x = $c * $m * $y1 * $k; $b += 0.0078 * $x;
        $x = $c * $m * $y * $k1; $r += 0.2118 * $x; $g += 0.2119 * $x; $b += 0.2235 * $x;

        return [max(0.0, min(1.0, $r)), max(0.0, min(1.0, $g)), max(0.0, min(1.0, $b))];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function labToRgb(float $l, float $a, float $b): array {
        $fy = ($l + 16) / 116;
        $fx = $fy + $a / 500;
        $fz = $fy - $b / 200;
        $f = static fn (float $t): float => $t > 6 / 29 ? $t ** 3 : 3 * (6 / 29) ** 2 * ($t - 4 / 29);
        [$x, $y, $z] = [0.9505 * $f($fx), $f($fy), 1.089 * $f($fz)];
        $rgb = [3.2406 * $x - 1.5372 * $y - 0.4986 * $z, -0.9689 * $x + 1.8758 * $y + 0.0415 * $z, 0.0557 * $x - 0.2040 * $y + 1.0570 * $z];

        return array_map(static fn (float $v): float => max(0.0, min(1.0, $v <= 0.0031308 ? 12.92 * $v : 1.055 * $v ** (1 / 2.4) - 0.055)), $rgb);
    }
}
