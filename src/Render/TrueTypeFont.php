<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * An embedded TrueType program (FontFile2), read enough to draw its glyphs by glyph id: glyph count, units per em,
 * advance widths and the cmap subtables, plus a copy whose only cmap maps U+E000 + gid to gid, so a renderer that
 * draws Unicode text (FreeType through Imagick) can be given glyph ids as private use characters.
 *
 * @experimental
 */
final class TrueTypeFont {
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];
    public readonly int $glyphCount;
    public readonly int $unitsPerEm;

    /** @var list<int> */
    private array $advances = [];

    /** @var array<string, array<int, int>> "platform/encoding" => code => gid */
    private array $cmaps = [];

    private function __construct(
        private readonly string $data,
    ) {
        $count = self::u16($data, 4);
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $this->tables[substr($data, $record, 4)] = ['offset' => self::u32($data, $record + 8), 'length' => self::u32($data, $record + 12)];
        }

        $this->glyphCount = isset($this->tables['maxp']) ? self::u16($data, $this->tables['maxp']['offset'] + 4) : 0;
        $this->unitsPerEm = isset($this->tables['head']) ? max(16, self::u16($data, $this->tables['head']['offset'] + 18)) : 1000;
        if (isset($this->tables['hhea'], $this->tables['hmtx'])) {
            $metrics = self::u16($data, $this->tables['hhea']['offset'] + 34);
            $hmtx = $this->tables['hmtx']['offset'];
            for ($i = 0; $i < $metrics; $i++) {
                $this->advances[] = self::u16($data, $hmtx + 4 * $i);
            }
        }

        if (isset($this->tables['cmap'])) {
            $this->readCmaps($this->tables['cmap']['offset']);
        }
    }

    public static function parse(string $data): ?self {
        $version = substr($data, 0, 4);
        if (strlen($data) < 12 || !in_array($version, ["\x00\x01\x00\x00", 'true'], true)) {
            return null;
        }

        $font = new self($data);

        return $font->glyphCount > 0 && isset($font->tables['glyf'], $font->tables['loca'], $font->tables['head']) ? $font : null;
    }

    /** Advance width of $gid in 1/1000 em */
    public function advance(int $gid): float {
        $advance = $this->advances[$gid] ?? ($this->advances === [] ? 0 : end($this->advances));

        return $advance * 1000 / $this->unitsPerEm;
    }

    public function lookup(int $platform, int $encoding, int $code): ?int {
        $gid = $this->cmaps[$platform . '/' . $encoding][$code] ?? null;

        return $gid !== null && $gid > 0 ? $gid : null;
    }

    public function hasCmap(int $platform, int $encoding): bool {
        return isset($this->cmaps[$platform . '/' . $encoding]);
    }

    /** Tables that position glyphs by their own metrics (kerning, device advances, layout) instead of the PDF widths */
    private const DROPPED_TABLES = ['cmap', 'hmtx', 'hhea', 'kern', 'hdmx', 'LTSH', 'VDMX', 'GPOS', 'GSUB', 'GDEF', 'JSTF', 'morx', 'mort', 'kerx', 'DSIG'];

    /**
     * The font drawn by glyph id (see OpenType::cmap()), with the advance of each glyph in $advances (1/1000 em)
     * instead of its own; the left side bearings are kept, TrueType places outlines by them.
     *
     * @param array<int, float> $advances gid => advance in 1/1000 em
     * @param array<int, int> $extra code point => gid
     */
    public function rebuilt(array $advances = [], array $extra = []): string {
        $tables = [];
        foreach ($this->tables as $tag => $table) {
            if (!in_array($tag, self::DROPPED_TABLES, true)) {
                $tables[$tag] = substr($this->data, $table['offset'], $table['length']);
            }
        }

        $hmtx = '';
        for ($gid = 0; $gid < $this->glyphCount; $gid++) {
            $advance = isset($advances[$gid]) ? (int) round($advances[$gid] * $this->unitsPerEm / 1000) : $this->advances[min($gid, count($this->advances) - 1)] ?? 0;
            $hmtx .= pack('nn', max(0, min(65535, $advance)), $this->leftSideBearing($gid) & 0xFFFF);
        }

        $hhea = substr($this->data, $this->tables['hhea']['offset'] ?? 0, 36);
        $tables['hhea'] = strlen($hhea) === 36 ? substr_replace($hhea, pack('n', $this->glyphCount), 34, 2) : $hhea;
        $tables['hmtx'] = $hmtx;
        $tables['cmap'] = OpenType::cmap($this->glyphCount, $extra);

        return OpenType::build($tables);
    }

    private function leftSideBearing(int $gid): int {
        if (!isset($this->tables['hmtx'])) {
            return 0;
        }

        $metrics = count($this->advances);
        $offset = $gid < $metrics ? $this->tables['hmtx']['offset'] + 4 * $gid + 2 : $this->tables['hmtx']['offset'] + 4 * $metrics + 2 * ($gid - $metrics);

        return $offset + 2 <= $this->tables['hmtx']['offset'] + $this->tables['hmtx']['length'] ? self::u16($this->data, $offset) : 0;
    }

    private function readCmaps(int $cmap): void {
        $count = self::u16($this->data, $cmap + 2);
        for ($i = 0; $i < $count; $i++) {
            $platform = self::u16($this->data, $cmap + 4 + 8 * $i);
            $encoding = self::u16($this->data, $cmap + 6 + 8 * $i);
            $offset = $cmap + self::u32($this->data, $cmap + 8 + 8 * $i);
            $map = $this->readSubtable($offset);
            if ($map !== null) {
                $this->cmaps[$platform . '/' . $encoding] = $map + ($this->cmaps[$platform . '/' . $encoding] ?? []);
            }
        }
    }

    /** @return array<int, int>|null */
    private function readSubtable(int $offset): ?array {
        $format = self::u16($this->data, $offset);
        $map = [];
        switch ($format) {
            case 0:
                for ($code = 0; $code < 256; $code++) {
                    $map[$code] = ord($this->data[$offset + 6 + $code] ?? "\0");
                }

                return $map;
            case 4:
                $segX2 = self::u16($this->data, $offset + 6);
                $ends = $offset + 14;
                $starts = $ends + $segX2 + 2;
                $deltas = $starts + $segX2;
                $ranges = $deltas + $segX2;
                for ($s = 0; $s < $segX2 / 2; $s++) {
                    $end = self::u16($this->data, $ends + 2 * $s);
                    $start = self::u16($this->data, $starts + 2 * $s);
                    $delta = self::u16($this->data, $deltas + 2 * $s);
                    $rangeOffset = self::u16($this->data, $ranges + 2 * $s);
                    if ($start === 0xFFFF || $end - $start > 20000) {
                        continue;
                    }

                    for ($code = $start; $code <= $end; $code++) {
                        if ($rangeOffset === 0) {
                            $map[$code] = ($code + $delta) & 0xFFFF;
                        } else {
                            $glyph = self::u16($this->data, $ranges + 2 * $s + $rangeOffset + 2 * ($code - $start));
                            $map[$code] = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                        }
                    }
                }

                return $map;
            case 6:
                $first = self::u16($this->data, $offset + 6);
                $entries = self::u16($this->data, $offset + 8);
                for ($i = 0; $i < $entries; $i++) {
                    $map[$first + $i] = self::u16($this->data, $offset + 10 + 2 * $i);
                }

                return $map;
            case 12:
                $groups = self::u32($this->data, $offset + 12);
                for ($g = 0; $g < min($groups, 4096); $g++) {
                    $record = $offset + 16 + 12 * $g;
                    $start = self::u32($this->data, $record);
                    $end = self::u32($this->data, $record + 4);
                    $glyph = self::u32($this->data, $record + 8);
                    for ($code = $start; $code <= min($end, $start + 20000); $code++) {
                        $map[$code] = $glyph + $code - $start;
                    }
                }

                return $map;
        }

        return null;
    }

    private static function u16(string $data, int $offset): int {
        return (ord($data[$offset] ?? "\0") << 8) | ord($data[$offset + 1] ?? "\0");
    }

    private static function u32(string $data, int $offset): int {
        return (self::u16($data, $offset) << 16) | self::u16($data, $offset + 2);
    }
}
