<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * An embedded CFF program (FontFile3 /Type1C, /CIDFontType0C, or the CFF table of an /OpenType program), read
 * enough to draw its glyphs by glyph id: glyph count, glyph names (name-keyed) or CIDs (CID-keyed) from the charset,
 * the built-in encoding, units per em and bounding box; wrapped in an OpenType container by self::openType().
 *
 * @experimental
 */
final class CffFont {
    public readonly int $glyphCount;

    public readonly bool $cidKeyed;

    public readonly int $unitsPerEm;

    public readonly string $name;

    /** @var array{int, int, int, int} */
    public readonly array $bbox;

    /** @var array<string, int> glyph name => gid (name-keyed) */
    private array $gidByName = [];

    /** @var array<int, int> CID => gid (CID-keyed) */
    private array $gidByCid = [];

    /** @var array<int, int> code => gid, the built-in encoding (name-keyed) */
    private array $encoding = [];

    private function __construct(
        private readonly string $data,
    ) {
        $position = ord($data[2]);
        [$names, $position] = $this->index($position);
        [$topDicts, $position] = $this->index($position);
        [$strings, $position] = $this->index($position);
        $this->name = $names[0] ?? '';
        $top = $this->dict($topDicts[0] ?? '');
        $this->cidKeyed = isset($top[1230]);
        $charStrings = isset($top[17]) ? $this->index((int) $top[17][0])[0] : [];
        $this->glyphCount = count($charStrings);
        $matrix = $top[1207] ?? null;
        if ($matrix === null && $this->cidKeyed && isset($top[1236])) {
            $fontDicts = $this->index((int) $top[1236][0])[0];
            $matrix = $this->dict($fontDicts[0] ?? '')[1207] ?? null;
        }

        $this->unitsPerEm = $matrix !== null && abs($matrix[0]) > 1e-6 ? max(16, min(16384, (int) round(1 / abs($matrix[0])))) : 1000;
        $box = array_map(static fn (float $v): int => (int) round($v), $top[5] ?? [0, -200, 1000, 900]);
        $this->bbox = count($box) === 4 ? [$box[0], $box[1], $box[2], $box[3]] : [0, -200, 1000, 900];

        $string = static fn (int $sid): string => $sid < count(CffStandard::STRINGS) ? CffStandard::STRINGS[$sid] : ($strings[$sid - count(CffStandard::STRINGS)] ?? '');
        $charset = $this->charset((int) ($top[15][0] ?? 0));
        foreach ($charset as $gid => $id) {
            if ($this->cidKeyed) {
                $this->gidByCid[$id] = $gid;
            } else {
                $this->gidByName[$string($id)] ??= $gid;
            }
        }

        if (!$this->cidKeyed) {
            $this->readEncoding((int) ($top[16][0] ?? 0), $charset);
        }
    }

    public static function parse(string $data): ?self {
        if (strlen($data) < 4 || ord($data[0]) !== 1) {
            return null;
        }

        try {
            $font = new self($data);
        } catch (\Throwable) {
            return null;
        }

        return $font->glyphCount > 0 ? $font : null;
    }

    /** The CFF table of an OpenType program (FontFile3 /OpenType), or null when it has none */
    public static function fromOpenType(string $data): ?self {
        if (substr($data, 0, 4) !== 'OTTO') {
            return null;
        }

        $count = (ord($data[4]) << 8) | ord($data[5]);
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            if (substr($data, $record, 4) === 'CFF ') {
                $offset = unpack('N', substr($data, $record + 8, 4))[1] ?? 0;
                $length = unpack('N', substr($data, $record + 12, 4))[1] ?? 0;

                return self::parse(substr($data, $offset, $length));
            }
        }

        return null;
    }

    public function gidForName(string $name): ?int {
        return $this->gidByName[$name] ?? null;
    }

    /** @return array<string, int> glyph name => gid (name-keyed) */
    public function glyphNames(): array {
        return $this->gidByName;
    }

    public function gidForCid(int $cid): ?int {
        return $this->cidKeyed ? ($this->gidByCid[$cid] ?? null) : ($cid < $this->glyphCount ? $cid : null);
    }

    public function gidForCode(int $code): ?int {
        return $this->encoding[$code] ?? null;
    }

    /**
     * The program as an OpenType font drawn by glyph id (see OpenType::cmap()), with the advance of each glyph in
     * $advances (1/1000 em) and $default for the others
     *
     * @param array<int, float> $advances gid => advance in 1/1000 em
     * @param array<int, int> $extra code point => gid
     */
    public function openType(array $advances, float $default = 500, array $extra = []): string {
        $list = [];
        for ($gid = 0; $gid < $this->glyphCount; $gid++) {
            $list[] = (int) round(($advances[$gid] ?? $default) * $this->unitsPerEm / 1000);
        }

        return OpenType::build(OpenType::cffTables($this->data, $this->unitsPerEm, $list, $this->bbox, $this->name) + ['cmap' => OpenType::cmap($this->glyphCount, $extra)], true);
    }

    /**
     * gid => SID (name-keyed) or CID (CID-keyed)
     *
     * @return array<int, int>
     */
    private function charset(int $offset): array {
        $charset = [0 => 0];
        if ($offset <= 2) {
            $names = match ($offset) {
                0 => array_slice(CffStandard::STRINGS, 0, 229),
                1 => CffStandard::EXPERT_CHARSET,
                default => CffStandard::EXPERT_SUBSET_CHARSET,
            };
            $sids = array_flip(CffStandard::STRINGS);
            foreach ($names as $gid => $name) {
                if ($gid > 0 && $gid < $this->glyphCount) {
                    $charset[$gid] = $sids[$name] ?? 0;
                }
            }

            return $charset;
        }

        $format = ord($this->data[$offset] ?? "\0");
        $position = $offset + 1;
        $gid = 1;
        while ($gid < $this->glyphCount && $position < strlen($this->data)) {
            if ($format === 0) {
                $charset[$gid++] = $this->u16($position);
                $position += 2;
                continue;
            }

            $first = $this->u16($position);
            $left = $format === 1 ? ord($this->data[$position + 2] ?? "\0") : $this->u16($position + 2);
            $position += $format === 1 ? 3 : 4;
            for ($i = 0; $i <= $left && $gid < $this->glyphCount; $i++) {
                $charset[$gid++] = $first + $i;
            }
        }

        return $charset;
    }

    /** @param array<int, int> $charset */
    private function readEncoding(int $offset, array $charset): void {
        if ($offset === 0) {
            foreach (CffStandard::STANDARD_ENCODING as $code => $name) {
                if (isset($this->gidByName[$name])) {
                    $this->encoding[$code] = $this->gidByName[$name];
                }
            }

            return;
        }

        if ($offset === 1) { // Expert encoding: not used by PDF producers in practice
            return;
        }

        $format = ord($this->data[$offset] ?? "\0");
        $position = $offset + 1;
        if (($format & 0x7F) === 0) {
            $count = ord($this->data[$position++] ?? "\0");
            for ($gid = 1; $gid <= $count; $gid++) {
                $this->encoding[ord($this->data[$position++] ?? "\0")] = $gid;
            }
        } else {
            $ranges = ord($this->data[$position++] ?? "\0");
            $gid = 1;
            for ($r = 0; $r < $ranges; $r++) {
                $first = ord($this->data[$position] ?? "\0");
                $left = ord($this->data[$position + 1] ?? "\0");
                $position += 2;
                for ($i = 0; $i <= $left; $i++) {
                    $this->encoding[$first + $i] = $gid++;
                }
            }
        }

        if (($format & 0x80) !== 0) {
            $gidBySid = array_flip($charset);
            $supplements = ord($this->data[$position++] ?? "\0");
            for ($s = 0; $s < $supplements; $s++) {
                $code = ord($this->data[$position] ?? "\0");
                $sid = $this->u16($position + 1);
                $position += 3;
                if (isset($gidBySid[$sid])) {
                    $this->encoding[$code] = $gidBySid[$sid];
                }
            }
        }
    }

    /** @return array{list<string>, int} the objects and the position after the INDEX */
    private function index(int $position): array {
        $count = $this->u16($position);
        if ($count === 0) {
            return [[], $position + 2];
        }

        $offSize = ord($this->data[$position + 2] ?? "\0");
        if ($offSize < 1 || $offSize > 4) {
            throw new \RuntimeException('Invalid CFF INDEX');
        }

        $offsets = [];
        for ($i = 0; $i <= $count; $i++) {
            $value = 0;
            for ($b = 0; $b < $offSize; $b++) {
                $value = ($value << 8) | ord($this->data[$position + 3 + $i * $offSize + $b] ?? "\0");
            }

            $offsets[] = $value;
        }

        $base = $position + 2 + ($count + 1) * $offSize;
        $objects = [];
        for ($i = 0; $i < $count; $i++) {
            $objects[] = substr($this->data, $base + $offsets[$i], max(0, $offsets[$i + 1] - $offsets[$i]));
        }

        return [$objects, $base + $offsets[$count]];
    }

    /** @return array<int, list<float>> operator (two-byte operators as 1200 + second byte) => operands */
    private function dict(string $data): array {
        $dict = [];
        $operands = [];
        $length = strlen($data);
        for ($i = 0; $i < $length;) {
            $b0 = ord($data[$i]);
            if ($b0 <= 21) {
                $operator = $b0 === 12 ? 1200 + ord($data[$i + 1] ?? "\0") : $b0;
                $i += $b0 === 12 ? 2 : 1;
                $dict[$operator] = $operands;
                $operands = [];
            } elseif ($b0 === 28) {
                $operands[] = (float) (($v = (ord($data[$i + 1] ?? "\0") << 8) | ord($data[$i + 2] ?? "\0")) >= 0x8000 ? $v - 0x10000 : $v);
                $i += 3;
            } elseif ($b0 === 29) {
                $v = unpack('N', substr($data . "\0\0\0\0", $i + 1, 4))[1] ?? 0;
                $operands[] = (float) ($v >= 0x80000000 ? $v - 0x100000000 : $v);
                $i += 5;
            } elseif ($b0 === 30) {
                $number = '';
                for ($i++; $i < $length; $i++) {
                    $byte = ord($data[$i]);
                    foreach ([$byte >> 4, $byte & 0x0F] as $nibble) {
                        if ($nibble === 0xF) {
                            break 2;
                        }

                        $number .= ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', 'E', 'E-', '', '-'][$nibble];
                    }
                }

                $i++;
                $operands[] = (float) $number;
            } elseif ($b0 >= 32 && $b0 <= 246) {
                $operands[] = (float) ($b0 - 139);
                $i++;
            } elseif ($b0 >= 247 && $b0 <= 250) {
                $operands[] = (float) (($b0 - 247) * 256 + ord($data[$i + 1] ?? "\0") + 108);
                $i += 2;
            } elseif ($b0 >= 251 && $b0 <= 254) {
                $operands[] = (float) (-($b0 - 251) * 256 - ord($data[$i + 1] ?? "\0") - 108);
                $i += 2;
            } else {
                $i++;
            }
        }

        return $dict;
    }

    private function u16(int $position): int {
        return (ord($this->data[$position] ?? "\0") << 8) | ord($this->data[$position + 1] ?? "\0");
    }
}
