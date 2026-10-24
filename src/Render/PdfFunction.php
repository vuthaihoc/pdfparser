<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Throwable;

/**
 * PDF functions (ISO 32000 7.10): type 0 (sampled, linear interpolation), 2 (exponential), 3 (stitching) and
 * 4 (PostScript calculator). Used for tint transforms and shadings.
 *
 * @experimental
 */
final class PdfFunction {
    /** @var list<mixed>|null tokens of a type 4 program */
    private ?array $program = null;

    private function __construct(
        private readonly RawObjects $objects,
        private readonly array $dictionary,
        private readonly ?int $objectNumber,
    ) {}

    /** @return self|list<self>|null one function, or an array of 1-output functions */
    public static function from(RawObjects $objects, mixed $value): self|array|null {
        $objectNumber = $value instanceof PdfReference ? $value->objectNumber : null;
        $resolved = $objects->resolve($value);
        if (is_array($resolved) && array_is_list($resolved)) {
            return array_values(array_filter(array_map(static fn ($v) => self::from($objects, $v), $resolved), static fn ($f) => $f instanceof self));
        }

        $dictionary = $objects->dictionary($resolved);

        return $dictionary === null ? null : new self($objects, $dictionary, $objectNumber);
    }

    /**
     * @param self|list<self> $function
     * @param list<float> $input
     * @return list<float>
     */
    public static function evaluate(self|array $function, array $input): array {
        if ($function instanceof self) {
            return $function->call($input);
        }

        $out = [];
        foreach ($function as $f) {
            $out[] = $f->call($input)[0] ?? 0.0;
        }

        return $out;
    }

    /**
     * @param list<float> $input
     * @return list<float>
     */
    public function call(array $input): array {
        try {
            $domain = $this->numbers($this->dictionary['Domain'] ?? [0, 1]);
            foreach ($input as $i => $x) {
                $input[$i] = max($domain[2 * $i] ?? 0.0, min($domain[2 * $i + 1] ?? 1.0, $x));
            }

            $out = match ((int) $this->objects->number($this->dictionary['FunctionType'] ?? 2)) {
                0 => $this->sampled($input),
                2 => $this->exponential($input),
                3 => $this->stitching($input),
                4 => $this->postscript($input),
                default => [$input[0] ?? 0.0],
            };
            if (isset($this->dictionary['Range'])) {
                $range = $this->numbers($this->dictionary['Range']);
                foreach ($out as $i => $y) {
                    $out[$i] = max($range[2 * $i] ?? 0.0, min($range[2 * $i + 1] ?? 1.0, $y));
                }
            }

            return $out;
        } catch (Throwable) {
            return [$input[0] ?? 0.0];
        }
    }

    /** @return list<float> */
    private function numbers(mixed $value): array {
        return array_map(fn ($v) => $this->objects->number($v), $this->objects->array($value) ?? []);
    }

    /** @param list<float> $input @return list<float> */
    private function exponential(array $input): array {
        $c0 = isset($this->dictionary['C0']) ? $this->numbers($this->dictionary['C0']) : [0.0];
        $c1 = isset($this->dictionary['C1']) ? $this->numbers($this->dictionary['C1']) : [1.0];
        $n = $this->objects->number($this->dictionary['N'] ?? 1, 1.0);
        $x = $input[0] ?? 0.0;
        $xn = $x <= 0 && $n < 1 ? 0.0 : $x ** $n;
        $out = [];
        foreach ($c0 as $i => $c) {
            $out[] = $c + $xn * (($c1[$i] ?? 1.0) - $c);
        }

        return $out;
    }

    /** @param list<float> $input @return list<float> */
    private function stitching(array $input): array {
        $functions = self::from($this->objects, $this->dictionary['Functions'] ?? []);
        $functions = is_array($functions) ? $functions : ($functions === null ? [] : [$functions]);
        $bounds = $this->numbers($this->dictionary['Bounds'] ?? []);
        $encode = $this->numbers($this->dictionary['Encode'] ?? []);
        $domain = $this->numbers($this->dictionary['Domain'] ?? [0, 1]);
        $x = $input[0] ?? 0.0;
        $k = 0;
        while ($k < count($bounds) && $x >= $bounds[$k]) {
            $k++;
        }

        $low = $k === 0 ? ($domain[0] ?? 0.0) : $bounds[$k - 1];
        $high = $k === count($bounds) ? ($domain[1] ?? 1.0) : $bounds[$k];
        $e0 = $encode[2 * $k] ?? 0.0;
        $e1 = $encode[2 * $k + 1] ?? 1.0;
        $t = $high === $low ? $e0 : $e0 + ($x - $low) * ($e1 - $e0) / ($high - $low);

        return isset($functions[$k]) ? $functions[$k]->call([$t]) : [$t];
    }

    /** @param list<float> $input @return list<float> */
    private function sampled(array $input): array {
        $data = $this->objectNumber === null ? null : $this->objects->streamData($this->objectNumber);
        $size = array_map('intval', $this->numbers($this->dictionary['Size'] ?? [2]));
        $bps = (int) $this->objects->number($this->dictionary['BitsPerSample'] ?? 8, 8);
        $range = $this->numbers($this->dictionary['Range'] ?? [0, 1]);
        $outputs = intdiv(count($range), 2);
        $domain = $this->numbers($this->dictionary['Domain'] ?? [0, 1]);
        $encode = isset($this->dictionary['Encode']) ? $this->numbers($this->dictionary['Encode']) : [0, ($size[0] ?? 2) - 1];
        $decode = isset($this->dictionary['Decode']) ? $this->numbers($this->dictionary['Decode']) : $range;
        if ($data === null || $outputs === 0 || ($bps !== 8 && $bps !== 16)) {
            return array_fill(0, max(1, $outputs), $input[0] ?? 0.0);
        }

        $x = $input[0] ?? 0.0;
        $e = ($encode[0] ?? 0.0) + ($x - ($domain[0] ?? 0.0)) * (($encode[1] ?? 1.0) - ($encode[0] ?? 0.0)) / max(1e-9, ($domain[1] ?? 1.0) - ($domain[0] ?? 0.0));
        $e = max(0.0, min(($size[0] ?? 2) - 1, $e));
        $i0 = (int) floor($e);
        $i1 = min(($size[0] ?? 2) - 1, $i0 + 1);
        $f = $e - $i0;
        $bytes = intdiv($bps, 8);
        $max = (1 << $bps) - 1;
        $out = [];
        for ($j = 0; $j < $outputs; $j++) {
            $sample = static function (int $index) use ($data, $bytes, $outputs, $j): int {
                $offset = ($index * $outputs + $j) * $bytes;

                return $bytes === 1 ? ord($data[$offset] ?? "\0") : (ord($data[$offset] ?? "\0") << 8) | ord($data[$offset + 1] ?? "\0");
            };
            $v = $sample($i0) * (1 - $f) + $sample($i1) * $f;
            $out[] = ($decode[2 * $j] ?? 0.0) + $v * (($decode[2 * $j + 1] ?? 1.0) - ($decode[2 * $j] ?? 0.0)) / $max;
        }

        return $out;
    }

    /** @param list<float> $input @return list<float> */
    private function postscript(array $input): array {
        if ($this->program === null) {
            $code = $this->objectNumber === null ? '' : (string) $this->objects->streamData($this->objectNumber);
            preg_match_all('/[{}]|[^\s{}]+/', $code, $matches);
            $this->program = $matches[0];
        }

        $stack = $input;
        $tokens = $this->program;
        $pos = 0;
        if (($tokens[0] ?? '') === '{') {
            $pos = 1;
        }

        $this->run($tokens, $pos, $stack, 0);
        $outputs = intdiv(count($this->numbers($this->dictionary['Range'] ?? [0, 1])), 2);

        return array_map('floatval', array_slice($stack, -max(1, $outputs)));
    }

    /**
     * Runs tokens from $pos until the matching "}"; nested blocks are kept for if/ifelse.
     *
     * @param list<string> $tokens
     * @param list<float|bool> $stack
     */
    private function run(array $tokens, int &$pos, array &$stack, int $depth): void {
        if ($depth > 20) {
            return;
        }

        $blocks = [];
        while ($pos < count($tokens)) {
            $token = $tokens[$pos++];
            if ($token === '}') {
                return;
            }

            if ($token === '{') {
                $start = $pos;
                $level = 1;
                while ($pos < count($tokens) && $level > 0) {
                    $level += $tokens[$pos] === '{' ? 1 : ($tokens[$pos] === '}' ? -1 : 0);
                    $pos++;
                }

                $blocks[] = $start;
                continue;
            }

            if (is_numeric($token)) {
                $stack[] = (float) $token;
                continue;
            }

            if ($token === 'if' || $token === 'ifelse') {
                $else = $token === 'ifelse' ? array_pop($blocks) : null;
                $then = array_pop($blocks);
                $condition = array_pop($stack);
                $block = $condition ? $then : $else;
                if ($block !== null) {
                    $p = $block;
                    $this->run($tokens, $p, $stack, $depth + 1);
                }

                continue;
            }

            $this->operator($token, $stack);
        }
    }

    /** @param list<float|bool> $stack */
    private function operator(string $op, array &$stack): void {
        $pop = static function () use (&$stack) { return array_pop($stack) ?? 0.0; };
        switch ($op) {
            case 'add': $b = $pop(); $a = $pop(); $stack[] = $a + $b; break;
            case 'sub': $b = $pop(); $a = $pop(); $stack[] = $a - $b; break;
            case 'mul': $b = $pop(); $a = $pop(); $stack[] = $a * $b; break;
            case 'div': $b = $pop(); $a = $pop(); $stack[] = $b == 0 ? 0.0 : $a / $b; break;
            case 'idiv': $b = (int) $pop(); $a = (int) $pop(); $stack[] = $b === 0 ? 0.0 : (float) intdiv($a, $b); break;
            case 'mod': $b = (int) $pop(); $a = (int) $pop(); $stack[] = $b === 0 ? 0.0 : (float) ($a % $b); break;
            case 'neg': $stack[] = -$pop(); break;
            case 'abs': $stack[] = abs($pop()); break;
            case 'ceiling': $stack[] = ceil($pop()); break;
            case 'floor': $stack[] = floor($pop()); break;
            case 'round': $stack[] = round($pop()); break;
            case 'truncate': $v = $pop(); $stack[] = $v < 0 ? ceil($v) : floor($v); break;
            case 'sqrt': $stack[] = sqrt(max(0, $pop())); break;
            case 'sin': $stack[] = sin(deg2rad($pop())); break;
            case 'cos': $stack[] = cos(deg2rad($pop())); break;
            case 'atan': $b = $pop(); $a = $pop(); $d = rad2deg(atan2($a, $b)); $stack[] = $d < 0 ? $d + 360 : $d; break;
            case 'exp': $b = $pop(); $a = $pop(); $stack[] = $a ** $b; break;
            case 'ln': $stack[] = log(max(1e-12, $pop())); break;
            case 'log': $stack[] = log10(max(1e-12, $pop())); break;
            case 'cvi': $stack[] = (float) (int) $pop(); break;
            case 'cvr': break;
            case 'dup': $v = $pop(); $stack[] = $v; $stack[] = $v; break;
            case 'pop': $pop(); break;
            case 'exch': $b = $pop(); $a = $pop(); $stack[] = $b; $stack[] = $a; break;
            case 'copy': $n = (int) $pop(); if ($n > 0) { $stack = [...$stack, ...array_slice($stack, -$n)]; } break;
            case 'index': $n = (int) $pop(); $stack[] = $stack[count($stack) - 1 - $n] ?? 0.0; break;
            case 'roll':
                $j = (int) $pop(); $n = (int) $pop();
                if ($n > 0 && $n <= count($stack)) {
                    $part = array_splice($stack, -$n);
                    $j = (($j % $n) + $n) % $n;
                    $stack = [...$stack, ...array_slice($part, $n - $j), ...array_slice($part, 0, $n - $j)];
                }
                break;
            case 'eq': $b = $pop(); $a = $pop(); $stack[] = $a == $b; break;
            case 'ne': $b = $pop(); $a = $pop(); $stack[] = $a != $b; break;
            case 'gt': $b = $pop(); $a = $pop(); $stack[] = $a > $b; break;
            case 'ge': $b = $pop(); $a = $pop(); $stack[] = $a >= $b; break;
            case 'lt': $b = $pop(); $a = $pop(); $stack[] = $a < $b; break;
            case 'le': $b = $pop(); $a = $pop(); $stack[] = $a <= $b; break;
            case 'and': $b = $pop(); $a = $pop(); $stack[] = is_bool($a) ? ($a && $b) : (float) ((int) $a & (int) $b); break;
            case 'or': $b = $pop(); $a = $pop(); $stack[] = is_bool($a) ? ($a || $b) : (float) ((int) $a | (int) $b); break;
            case 'xor': $b = $pop(); $a = $pop(); $stack[] = is_bool($a) ? ($a xor $b) : (float) ((int) $a ^ (int) $b); break;
            case 'not': $a = $pop(); $stack[] = is_bool($a) ? !$a : (float) ~(int) $a; break;
            case 'true': $stack[] = true; break;
            case 'false': $stack[] = false; break;
            default: break;
        }
    }
}
