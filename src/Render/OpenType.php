<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * Writes the sfnt container shared by the fonts the renderer rebuilds from embedded programs: the table directory
 * with checksums, a cmap that maps code points to glyph ids, and the tables a CFF program needs around it to load
 * as an OpenType font (head, hhea, hmtx, maxp, name, post).
 *
 * Glyphs are given to FreeType as code points: glyph id N is U+F0000 + N (supplementary private use area A, which
 * holds 65534 glyphs), and a font can map extra code points (U+0020 to the glyph of the space character, so word
 * spacing applies to it).
 *
 * @experimental
 */
final class OpenType {
    public const GLYPH_BASE = 0xF0000;
    public const GLYPH_CAPACITY = 0xFFFFD - 0xF0000 + 1;

    /** The text that draws $gids with a font whose cmap comes from self::cmap() */
    public static function text(array $gids): string {
        $text = '';
        foreach ($gids as $gid) {
            $text .= mb_chr(self::GLYPH_BASE + $gid);
        }

        return $text;
    }

    /**
     * A cmap with one format 12 subtable (3,10): U+F0000 + gid => gid for the glyphs that fit, plus $extra
     *
     * @param array<int, int> $extra code point => gid
     */
    public static function cmap(int $glyphCount, array $extra = []): string {
        $groups = [];
        ksort($extra);
        foreach ($extra as $codePoint => $gid) {
            if ($codePoint < self::GLYPH_BASE && $gid > 0 && $gid < $glyphCount) {
                $groups[] = pack('NNN', $codePoint, $codePoint, $gid);
            }
        }

        $count = min($glyphCount, self::GLYPH_CAPACITY);
        $groups[] = pack('NNN', self::GLYPH_BASE, self::GLYPH_BASE + $count - 1, 0);
        $subtable = pack('nnNNN', 12, 0, 16 + 12 * count($groups), 0, count($groups)) . implode('', $groups);

        return pack('nn', 0, 1) . pack('nnN', 3, 10, 12) . $subtable;
    }

    /**
     * The tables around a CFF program
     *
     * @param list<int> $advances per gid, in font units
     * @param array{int, int, int, int} $bbox
     * @return array<string, string>
     */
    public static function cffTables(string $cff, int $unitsPerEm, array $advances, array $bbox, string $name): array {
        $glyphCount = count($advances);
        [$xMin, $yMin, $xMax, $yMax] = array_map(static fn(int $v): int => max(-32768, min(32767, $v)), $bbox);
        $head = pack('NNNNnn', 0x00010000, 0x00010000, 0, 0x5F0F3CF5, 0x000B, $unitsPerEm)
            . str_repeat("\0", 16)
            . pack('nnnn', $xMin & 0xFFFF, $yMin & 0xFFFF, $xMax & 0xFFFF, $yMax & 0xFFFF)
            . pack('nnnnn', 0, 8, 2, 0, 0);
        $hhea = pack('N', 0x00010000)
            . pack('nnn', $yMax & 0xFFFF, $yMin & 0xFFFF, 0)
            . pack('n', min(65535, max([0, ...$advances])))
            . pack('nnn', 0, 0, $xMax & 0xFFFF)
            . pack('nnn', 1, 0, 0)
            . str_repeat("\0", 10)
            . pack('n', $glyphCount);
        $hmtx = '';
        foreach ($advances as $advance) {
            $hmtx .= pack('nn', max(0, min(65535, $advance)), 0);
        }

        $postScriptName = preg_replace('/[^A-Za-z0-9_.-]/', '', $name) ?: 'Embedded';
        $names = [1 => $postScriptName, 2 => 'Regular', 4 => $postScriptName, 6 => $postScriptName];
        $records = $strings = '';
        foreach ($names as $id => $value) {
            $utf16 = (string) mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
            $records .= pack('nnnnnn', 3, 1, 0x409, $id, strlen($utf16), strlen($strings));
            $strings .= $utf16;
        }

        return [
            'CFF ' => $cff,
            'head' => $head,
            'hhea' => $hhea,
            'hmtx' => $hmtx,
            'maxp' => pack('Nn', 0x00005000, $glyphCount),
            'name' => pack('nnn', 0, count($names), 6 + 12 * count($names)) . $records . $strings,
            'post' => pack('NNnnNNNNN', 0x00030000, 0, 0, 0, 0, 0, 0, 0, 0),
        ];
    }

    /**
     * An sfnt from its tables, with the table checksums and head.checkSumAdjustment
     *
     * @param array<string, string> $tables tag => data
     */
    public static function build(array $tables, bool $cff = false): string {
        ksort($tables, SORT_STRING);
        $numTables = count($tables);
        $searchRange = 16 * (1 << (int) floor(log($numTables, 2)));
        $out = pack('Nnnnn', $cff ? 0x4F54544F : 0x00010000, $numTables, $searchRange, (int) log($searchRange / 16, 2), 16 * $numTables - $searchRange);
        $offset = 12 + 16 * $numTables;
        $body = '';
        $headOffset = null;
        foreach ($tables as $tag => $bytes) {
            if ($tag === 'head') {
                $bytes = substr_replace($bytes, "\0\0\0\0", 8, 4);
                $headOffset = $offset + strlen($body);
            }

            $out .= str_pad($tag, 4) . pack('NNN', self::checksum($bytes), $offset + strlen($body), strlen($bytes));
            $body .= $bytes . str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
        }

        $font = $out . $body;
        if ($headOffset !== null) {
            $font = substr_replace($font, pack('N', (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF), $headOffset + 8, 4);
        }

        return $font;
    }

    private static function checksum(string $bytes): int {
        $bytes .= str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $bytes) ?: [] as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
