<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * A small reader for PDF object syntax (ISO 32000 7.3), used for content stream operands and for the raw text of
 * objects the renderer needs (resources, colour spaces, functions, annotations).
 *
 * Values: int|float, bool, null, names as '/Name' strings, strings as PdfString, arrays as lists, dictionaries as
 * arrays keyed by name without the slash, references as PdfReference.
 *
 * @experimental
 */
final class PdfSyntax {
    private int $pos = 0;

    private function __construct(
        private readonly string $s,
    ) {}

    /** @return list<mixed> every value in $text, e.g. the operands of one content stream command */
    public static function values(string $text): array {
        $reader = new self($text);
        $values = [];
        while (($value = $reader->next()) !== self::END) {
            $values[] = $value;
        }

        return $values;
    }

    /** The first value of $text, e.g. the body of an object */
    public static function value(string $text): mixed {
        $value = (new self($text))->next();

        return $value === self::END ? null : $value;
    }
    private const END = "\0end\0";

    private function next(): mixed {
        $this->skipWhitespaceAndComments();
        if ($this->pos >= strlen($this->s)) {
            return self::END;
        }

        $char = $this->s[$this->pos];
        if ($char === '/') {
            return $this->name();
        }

        if ($char === '(') {
            return $this->literalString();
        }

        if ($char === '<') {
            if (($this->s[$this->pos + 1] ?? '') === '<') {
                return $this->dictionary();
            }

            return $this->hexString();
        }

        if ($char === '[') {
            $this->pos++;
            $array = [];
            while (true) {
                $this->skipWhitespaceAndComments();
                if ($this->pos >= strlen($this->s)) {
                    return $array;
                }

                if ($this->s[$this->pos] === ']') {
                    $this->pos++;

                    return $array;
                }

                $value = $this->next();
                if ($value === self::END) {
                    return $array;
                }

                $array[] = $value;
                $this->collapseReference($array);
            }
        }

        if ($char === ']' || $char === '>' || $char === ')' || $char === '{' || $char === '}') {
            $this->pos++;

            return $this->next();
        }

        return $this->token();
    }

    /** "n g R" read as three values becomes one reference */
    private function collapseReference(array &$values): void {
        $count = count($values);
        if ($count >= 3 && $values[$count - 1] === 'R' && is_int($values[$count - 2]) && is_int($values[$count - 3])) {
            $reference = new PdfReference($values[$count - 3], $values[$count - 2]);
            array_splice($values, $count - 3, 3, [$reference]);
        }
    }

    private function token(): mixed {
        $start = $this->pos;
        $length = strlen($this->s);
        while ($this->pos < $length && !ctype_space($this->s[$this->pos]) && !str_contains('/()<>[]{}%', $this->s[$this->pos])) {
            $this->pos++;
        }

        $token = substr($this->s, $start, $this->pos - $start);
        if ($token === '') {
            $this->pos++;

            return $this->next();
        }

        if (is_numeric($token)) {
            return str_contains($token, '.') || stripos($token, 'e') !== false ? (float) $token : (int) $token;
        }

        if (preg_match('/^[+-]?\.?\d/', $token) === 1) { // malformed numbers such as "1.2.3" or "-.5-"
            return (float) $token;
        }

        return match ($token) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $token,
        };
    }

    private function name(): string {
        $start = ++$this->pos;
        $length = strlen($this->s);
        while ($this->pos < $length && !ctype_space($this->s[$this->pos]) && !str_contains('/()<>[]{}%', $this->s[$this->pos])) {
            $this->pos++;
        }

        $name = substr($this->s, $start, $this->pos - $start);

        return '/' . (str_contains($name, '#') ? (preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $m) => chr((int) hexdec($m[1])), $name) ?? $name) : $name);
    }

    private function literalString(): PdfString {
        $this->pos++;
        $depth = 1;
        $out = '';
        $length = strlen($this->s);
        while ($this->pos < $length) {
            $char = $this->s[$this->pos++];
            if ($char === '\\') {
                $next = $this->pos < $length ? $this->s[$this->pos++] : '';
                $out .= match ($next) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                    '(' => '(', ')' => ')', '\\' => '\\',
                    "\r" => ($this->s[$this->pos] ?? '') === "\n" ? (function () {
                        $this->pos++;
                        return '';
                    })() : '',
                    "\n" => '',
                    default => ctype_digit($next) && $next < '8' ? $this->octal($next) : $next,
                };
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                break;
            }

            $out .= $char;
        }

        return new PdfString($out);
    }

    private function octal(string $first): string {
        $digits = $first;
        while (strlen($digits) < 3 && ($c = $this->s[$this->pos] ?? '') !== '' && $c >= '0' && $c <= '7') {
            $digits .= $c;
            $this->pos++;
        }

        return chr(octdec($digits) & 0xFF);
    }

    private function hexString(): PdfString {
        $end = strpos($this->s, '>', $this->pos);
        $end = $end === false ? strlen($this->s) : $end;
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->s, $this->pos + 1, $end - $this->pos - 1)) ?? '';
        $this->pos = $end + 1;
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return new PdfString((string) hex2bin($hex));
    }

    /** @return array<string, mixed> */
    private function dictionary(): array {
        $this->pos += 2;
        $items = [];
        while (true) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= strlen($this->s)) {
                break;
            }

            if ($this->s[$this->pos] === '>' && ($this->s[$this->pos + 1] ?? '') === '>') {
                $this->pos += 2;
                break;
            }

            $value = $this->next();
            if ($value === self::END) {
                break;
            }

            $items[] = $value;
            $this->collapseReference($items);
        }

        $dictionary = [];
        for ($i = 0; $i + 1 < count($items); $i += 2) {
            if (is_string($items[$i]) && str_starts_with($items[$i], '/')) {
                $dictionary[substr($items[$i], 1)] = $items[$i + 1];
            }
        }

        return $dictionary;
    }

    private function skipWhitespaceAndComments(): void {
        $length = strlen($this->s);
        while ($this->pos < $length) {
            $char = $this->s[$this->pos];
            if (ctype_space($char) || $char === "\0") {
                $this->pos++;
            } elseif ($char === '%') {
                while ($this->pos < $length && $this->s[$this->pos] !== "\n" && $this->s[$this->pos] !== "\r") {
                    $this->pos++;
                }
            } else {
                return;
            }
        }
    }
}
