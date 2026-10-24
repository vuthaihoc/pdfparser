<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * An embedded Type 1 program (FontFile: cleartext part, eexec encrypted part, trailer; PFB segments are accepted
 * too), read enough to draw its glyphs by glyph id: the glyph names in CharStrings order (the order is the gid),
 * the built-in encoding and the font matrix.
 *
 * FreeType gives Type 1 fonts a Unicode charmap made from the glyph names, so self::rebuilt() adds a copy of every
 * glyph named uF0000 + gid (see OpenType::text()), with the advance width of its hsbw/sbw set to the PDF width.
 *
 * @experimental
 */
final class Type1Font {
    private const EEXEC_KEY = 55665;

    private const CHARSTRING_KEY = 4330;

    /** @var array<string, int> glyph name => gid */
    private array $gidByName = [];

    /** @var list<array{name: string, data: string}> CharStrings entries, encrypted */
    private array $glyphs = [];

    /** @var array<int, string> code => glyph name */
    private array $encoding = [];

    public readonly float $unitsPerEm;

    private string $before = '';

    private string $after = '';

    private string $rd = 'RD';

    private string $nd = 'ND';

    private int $lenIV = 4;

    private function __construct(
        private readonly string $clearText,
        string $private,
    ) {
        if (preg_match('/\/FontMatrix\s*\[\s*([-+0-9.eE]+)/', $clearText, $m) === 1 && (float) $m[1] != 0.0) {
            $this->unitsPerEm = 1 / abs((float) $m[1]);
        } else {
            $this->unitsPerEm = 1000;
        }

        if (preg_match('/\/Encoding\s+StandardEncoding\s+def/', $clearText) === 1) {
            $this->encoding = CffStandard::STANDARD_ENCODING;
        } else {
            preg_match_all('/dup\s+(\d+)\s*\/([^\s\/\[\]{}()<>]+)\s+put/', $clearText, $entries, PREG_SET_ORDER);
            foreach ($entries as [, $code, $name]) {
                $this->encoding[(int) $code] = $name;
            }
        }

        if (preg_match('/\/lenIV\s+(-?\d+)/', $private, $m) === 1) {
            $this->lenIV = (int) $m[1];
        }

        $start = strpos($private, '/CharStrings');
        if ($start === false || preg_match('/\/CharStrings\s+(\d+)\s+dict\s+dup\s+begin\s*/', $private, $m, PREG_OFFSET_CAPTURE, $start) !== 1) {
            throw new \RuntimeException('No CharStrings');
        }

        $position = $m[0][1] + strlen($m[0][0]);
        $this->before = substr($private, 0, $m[1][1]);
        $length = strlen($private);
        while ($position < $length && preg_match('/\G\s*\/([^\s\/\[\]{}()<>]+)\s+(\d+)\s+(\S+) /', $private, $entry, 0, $position) === 1) {
            $size = (int) $entry[2];
            $dataStart = $position + strlen($entry[0]);
            $this->rd = $entry[3];
            $this->gidByName[$entry[1]] ??= count($this->glyphs);
            $this->glyphs[] = ['name' => $entry[1], 'data' => substr($private, $dataStart, $size)];
            $position = $dataStart + $size;
            if (preg_match('/\G\s*(noaccess\s+def|\S+)/', $private, $nd, 0, $position) === 1 && $nd[1] !== 'end') {
                $this->nd = $nd[1];
                $position += strlen($nd[0]);
            }
        }

        $closefile = strpos($private, 'closefile', $position);
        $this->after = substr($private, $position, $closefile === false ? null : $closefile + 9 - $position);
    }

    public static function parse(string $data): ?self {
        if (str_starts_with($data, "\x80\x01")) {
            $data = self::fromSegments($data);
        }

        $eexec = strpos($data, 'eexec');
        if ($eexec === false || !str_contains(substr($data, 0, 64), 'FontType') && !str_starts_with($data, '%!')) {
            return null;
        }

        $start = $eexec + 5;
        while ($start < strlen($data) && in_array($data[$start], ["\r", "\n", ' ', "\t"], true)) {
            $start++;
        }

        $encrypted = substr($data, $start);
        if (preg_match('/^[0-9A-Fa-f]{4}/', $encrypted) === 1) {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($encrypted, 0, (int) (strpos($encrypted, '0000000000') ?: strlen($encrypted)))) ?? '';
            $encrypted = (string) hex2bin(substr($hex, 0, strlen($hex) - strlen($hex) % 2));
        }

        try {
            $font = new self(substr($data, 0, $start), substr(self::decrypt($encrypted, self::EEXEC_KEY), 4));
        } catch (\Throwable) {
            return null;
        }

        return $font->glyphs !== [] ? $font : null;
    }

    public function glyphCount(): int {
        return count($this->glyphs);
    }

    public function gidForName(string $name): ?int {
        return $this->gidByName[$name] ?? null;
    }

    /** @return array<string, int> glyph name => gid */
    public function glyphNames(): array {
        return $this->gidByName;
    }

    /** The glyph name of $code in the built-in encoding */
    public function builtInName(int $code): ?string {
        return $this->encoding[$code] ?? null;
    }

    /**
     * The program with a copy of every glyph named after its gid, advance widths from $advances (1/1000 em)
     *
     * @param array<int, float> $advances gid => advance in 1/1000 em
     */
    public function rebuilt(array $advances = []): string {
        $entries = '';
        foreach ($this->glyphs as $gid => $glyph) {
            $entries .= $this->entry($glyph['name'], $glyph['data']);
        }

        foreach ($this->glyphs as $gid => $glyph) {
            if ($gid >= OpenType::GLYPH_CAPACITY) {
                break;
            }

            $data = isset($advances[$gid]) ? $this->withAdvance($glyph['data'], $advances[$gid] * $this->unitsPerEm / 1000) : $glyph['data'];
            $entries .= $this->entry(sprintf('u%X', OpenType::GLYPH_BASE + $gid), $data);
        }

        $count = 2 * count($this->glyphs) + 1;
        $private = $this->before . $count . " dict dup begin\n" . $entries . $this->after;

        // no trailer: FreeType decrypts a binary private part up to the end of the file, the zeros would be garbage
        return $this->clearText . self::encrypt("\0\0\0\0" . $private, self::EEXEC_KEY);
    }

    private function entry(string $name, string $data): string {
        return '/' . $name . ' ' . strlen($data) . ' ' . $this->rd . ' ' . $data . ' ' . $this->nd . "\n";
    }

    /** The encrypted charstring with the advance of its leading hsbw or sbw replaced */
    private function withAdvance(string $data, float $advance): string {
        $plain = $this->lenIV >= 0 ? self::decrypt($data, self::CHARSTRING_KEY) : $data;
        $numbers = [];
        $position = max(0, $this->lenIV);
        $length = strlen($plain);
        while ($position < $length) {
            $v = ord($plain[$position]);
            if ($v >= 32) {
                if ($v <= 246) {
                    $numbers[] = $v - 139;
                    $position++;
                } elseif ($v <= 250) {
                    $numbers[] = ($v - 247) * 256 + ord($plain[$position + 1] ?? "\0") + 108;
                    $position += 2;
                } elseif ($v <= 254) {
                    $numbers[] = -($v - 251) * 256 - ord($plain[$position + 1] ?? "\0") - 108;
                    $position += 2;
                } else {
                    $n = unpack('N', substr($plain . "\0\0\0\0", $position + 1, 4))[1] ?? 0;
                    $numbers[] = $n >= 0x80000000 ? $n - 0x100000000 : $n;
                    $position += 5;
                }

                continue;
            }

            $isHsbw = $v === 13 && count($numbers) === 2;
            $isSbw = $v === 12 && ord($plain[$position + 1] ?? "\0") === 7 && count($numbers) === 4;
            if (!$isHsbw && !$isSbw) {
                return $data;
            }

            $numbers[$isHsbw ? 1 : 2] = (int) round($advance);
            $encoded = substr($plain, 0, max(0, $this->lenIV));
            foreach ($numbers as $number) {
                $encoded .= self::encodeNumber($number);
            }

            $encoded .= substr($plain, $position);

            return $this->lenIV >= 0 ? self::encrypt($encoded, self::CHARSTRING_KEY) : $encoded;
        }

        return $data;
    }

    private static function encodeNumber(int $v): string {
        return match (true) {
            $v >= -107 && $v <= 107 => chr($v + 139),
            $v >= 108 && $v <= 1131 => chr(intdiv($v - 108, 256) + 247) . chr(($v - 108) % 256),
            $v >= -1131 && $v <= -108 => chr(intdiv(-$v - 108, 256) + 251) . chr((-$v - 108) % 256),
            default => "\xFF" . pack('N', $v & 0xFFFFFFFF),
        };
    }

    private static function decrypt(string $data, int $r): string {
        $out = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $c = ord($data[$i]);
            $out .= chr($c ^ ($r >> 8));
            $r = (($c + $r) * 52845 + 22719) & 0xFFFF;
        }

        return $out;
    }

    private static function encrypt(string $data, int $r): string {
        $out = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $c = ord($data[$i]) ^ ($r >> 8);
            $out .= chr($c);
            $r = (($c + $r) * 52845 + 22719) & 0xFFFF;
        }

        return $out;
    }

    /** PFB: segments of type 1 (ASCII) and 2 (binary), each with a 6 byte header */
    private static function fromSegments(string $data): string {
        $out = '';
        $position = 0;
        while ($position + 6 <= strlen($data) && ord($data[$position]) === 0x80 && in_array(ord($data[$position + 1]), [1, 2], true)) {
            $length = unpack('V', substr($data, $position + 2, 4))[1] ?? 0;
            $out .= substr($data, $position + 6, $length);
            $position += 6 + $length;
        }

        return $out;
    }
}
