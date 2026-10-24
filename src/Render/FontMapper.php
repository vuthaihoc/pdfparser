<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use Imagick;
use ImagickDraw;

/**
 * Substitute system fonts for PDF fonts (embedded font programs are not used), chosen from the base font name:
 * serif / sans / mono, bold, italic, and a CJK font for CJK text. Widths of strings come from the substitute font,
 * per character and cached, so the renderer can stretch each text run to the width the PDF says it has.
 *
 * @experimental
 */
final class FontMapper {
    /** @var array<string, string> query => font file */
    private static array $files = [];

    /** @param ?string $cacheDirectory where rebuilt font programs are kept, null: pdfparser-render-fonts in the temporary directory */
    public function __construct(
        private readonly ?string $cacheDirectory = null,
    ) {}

    /** @var array<string, array<string, float>> font file => character => width at size 100 */
    private array $widths = [];

    private ?Imagick $probe = null;

    /** @var array<string, ImagickDraw> */
    private array $draws = [];

    /** @param ?int $flags FontDescriptor /Flags: 1 fixed pitch, 2 serif, 64 italic, 262144 force bold; the name decides without them */
    public function file(?string $baseFont, bool $bold, bool $italic, string $text = '', ?int $flags = null): string {
        $name = strtolower(preg_replace('/^[A-Z]{6}\+/', '', (string) $baseFont) ?? '');
        $byName = match (true) {
            preg_match('/cour|mono|consol|code|typewriter/', $name) === 1 => 'Liberation Mono',
            preg_match('/sans|helvet|arial|frutiger|univers|futura|gill|verdana|tahoma|calibri|segoe|myriad|roboto|open ?sans|lato|din|avenir|optima|trebuchet/', $name) === 1 => 'Liberation Sans',
            preg_match('/times|serif|roman|georgia|garamond|cambria|book|minion|palatino|century|baskerville|caslon|cmr|nimbusrom|bodoni|charter/', $name) === 1 => 'Liberation Serif',
            default => null,
        };
        $family = match (true) {
            $flags !== null && ($flags & 1) !== 0 => 'Liberation Mono',
            $byName !== null => $byName,
            $flags !== null && ($flags & 2) !== 0 => 'Liberation Serif',
            default => 'Liberation Sans',
        };
        $italic = $italic || ($flags !== null && ($flags & 64) !== 0);
        $bold = $bold || ($flags !== null && ($flags & 262144) !== 0);
        if ($text !== '' && preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text) === 1) {
            $family = 'Noto Sans CJK JP';
        }

        $bold = $bold || preg_match('/bold|black|heavy|semibold|demi/', $name) === 1;
        $italic = $italic || preg_match('/italic|oblique|ital/', $name) === 1;
        $query = $family . ($bold ? ':bold' : '') . ($italic ? ':italic' : '');

        return self::$files[$query] ??= $this->match($query);
    }

    /** The font file fontconfig picks for $query, remembered in the cache directory (fc-match takes ~10 ms) */
    private function match(string $query): string {
        $cache = $this->cacheDirectory() . '/fc-match-' . sha1($query) . '.txt';
        $file = is_file($cache) ? trim((string) file_get_contents($cache)) : '';
        if ($file !== '' && is_file($file)) {
            return $file;
        }

        $file = trim((string) @shell_exec('fc-match -f %{file} ' . escapeshellarg($query) . ' 2>/dev/null'));
        if ($file !== '' && is_file($file)) {
            self::write($cache, $file);

            return $file;
        }

        foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans.ttf'] as $fallback) {
            if (is_file($fallback)) {
                return $fallback;
            }
        }

        return 'DejaVu-Sans';
    }

    /** @var array<int, array{file: string, map: \Closure(int): ?int, space: bool, glyphCount: int}|false> */
    private array $glyphFonts = [];

    /** Changes when what is cached changes */
    private const CACHE_VERSION = 1;

    /**
     * The embedded program of a font rebuilt to be drawn by glyph id (OpenType::text()), with the PDF widths as
     * advances, and the map from character code (simple fonts) or CID (Type0 with Identity-H/V) to glyph id. For
     * TrueType (FontFile2), CFF (FontFile3 Type1C, CIDFontType0C, OpenType) and Type 1 (FontFile) programs.
     * space: U+0020 is the glyph of code 32, so word spacing can be applied to it.
     *
     * The rebuilt program and the map are kept in the cache directory, keyed by the program, the encoding and the
     * widths, so another request for the same font reads them instead of parsing and rebuilding the program.
     *
     * @return array{file: string, map: \Closure(int): ?int, space: bool, glyphCount: int}|null
     */
    public function glyphFont(RawObjects $objects, int $fontObjectNumber): ?array {
        if (array_key_exists($fontObjectNumber, $this->glyphFonts)) {
            return $this->glyphFonts[$fontObjectNumber] === false ? null : $this->glyphFonts[$fontObjectNumber];
        }

        $this->glyphFonts[$fontObjectNumber] = false;
        $top = $objects->dictionary($objects->value($fontObjectNumber)) ?? [];
        $composite = isset($top['DescendantFonts']);
        $font = $composite ? ($objects->dictionary(($objects->array($top['DescendantFonts']) ?? [])[0] ?? null) ?? []) : $top;
        if ($composite && !in_array($objects->name($top['Encoding'] ?? null), ['Identity-H', 'Identity-V'], true)) {
            return null; // codes would have to go through the CMap to become CIDs
        }

        $descriptor = $objects->dictionary($font['FontDescriptor'] ?? null) ?? [];
        $kind = $program = null;
        foreach (['FontFile' => 'type1', 'FontFile2' => 'truetype', 'FontFile3' => 'cff'] as $key => $programKind) {
            if (($reference = $descriptor[$key] ?? null) instanceof PdfReference && ($programKind !== 'type1' || !$composite)) {
                $kind = $programKind;
                $program = $reference->objectNumber;
                break;
            }
        }

        // the stored bytes identify the program without decoding it; decoded only when it is not cached
        $stored = $program === null ? null : ($objects->rawStreamBytes($program) ?? $objects->streamData($program));
        if ($kind === null || $program === null || $stored === null || strlen($stored) < 32) {
            return null;
        }

        [$widths, $default] = $this->pdfWidths($objects, $top, $font, $descriptor, $composite);
        $encoding = $objects->resolve($top['Encoding'] ?? null);
        $cidToGid = $font['CIDToGIDMap'] ?? null;
        $key = sha1(json_encode([
            self::CACHE_VERSION, $kind, sha1($stored), $composite, $widths, $default,
            is_string($encoding) ? $encoding : $objects->name(($objects->dictionary($encoding) ?? [])['BaseEncoding'] ?? null),
            $this->differences($objects, $top),
            $cidToGid instanceof PdfReference && !is_string($objects->resolve($cidToGid)) ? sha1((string) $objects->streamData($cidToGid->objectNumber)) : null,
        ], JSON_THROW_ON_ERROR));
        $cached = $this->readCache($key);
        if ($cached === false) {
            return null;
        }

        if ($cached === null) {
            $data = $objects->streamData($program);
            $cached = $data === null ? null : $this->build($objects, $top, $font, $kind, $data, $composite, $widths, $default);
            if ($cached === null) {
                $this->writeCache($key, ['failed' => true]);

                return null;
            }

            $file = $this->cacheDirectory() . '/' . $key . '.' . $cached['extension'];
            self::write($file, $cached['bytes']);
            try {
                $draw = new ImagickDraw();
                $draw->setFont($file);
                $draw->setFontSize(100);
                $this->probe()->queryFontMetrics($draw, OpenType::text([min(3, $cached['glyphCount'] - 1)]));
            } catch (\Throwable) {
                $this->writeCache($key, ['failed' => true]);

                return null;
            }

            unset($cached['bytes']);
            $cached['file'] = basename($file);
            $this->writeCache($key, $cached);
        }

        $table = $cached['table'];
        $identity = $cached['identity'];

        return $this->glyphFonts[$fontObjectNumber] = [
            'file' => $this->cacheDirectory() . '/' . $cached['file'],
            'map' => static fn (int $code): ?int => $table[$code] ?? ($identity !== null && $code > 0 && $code < $identity ? $code : null),
            'space' => $cached['space'],
            'glyphCount' => $cached['glyphCount'],
        ];
    }

    /**
     * Parses and rebuilds a program: the font file and its code (or CID) => gid map as a table, plus the codes up to
     * $identity that are their own gid (Type0 with an identity CIDToGIDMap)
     *
     * @param array<string, mixed> $top
     * @param array<string, mixed> $font
     * @param array<int, float> $widths
     * @return array{bytes: string, extension: string, glyphCount: int, space: bool, table: array<int, int>, identity: ?int}|null
     */
    private function build(RawObjects $objects, array $top, array $font, string $kind, string $data, bool $composite, array $widths, float $default): ?array {
        $ttf = $cff = $type1 = null;
        if ($kind === 'type1') {
            $type1 = Type1Font::parse($data);
        } elseif ($kind === 'truetype') {
            $ttf = TrueTypeFont::parse($data);
        } else {
            $cff = CffFont::parse($data) ?? CffFont::fromOpenType($data);
            $ttf = $cff === null ? TrueTypeFont::parse($data) : null;
        }

        if ($ttf === null && $cff === null && $type1 === null) {
            return null;
        }

        $glyphCount = $ttf->glyphCount ?? $cff->glyphCount ?? $type1?->glyphCount() ?? 0;
        $identity = null;
        $table = [];
        if ($composite) {
            [$table, $identity] = $this->cidTable($objects, $font, $glyphCount, $cff);
        } else {
            $map = match (true) {
                $ttf !== null => $this->trueTypeCodeMap($objects, $top, $ttf),
                $cff !== null => $this->namedCodeMap($objects, $top, $cff->glyphNames(), $cff->gidForCode(...)),
                default => $this->namedCodeMap($objects, $top, $type1->glyphNames(), static fn (int $code): ?int => $type1->gidForName($type1->builtInName($code) ?? '')),
            };
            for ($code = 0; $code < 256; $code++) {
                if (($gid = $map($code)) !== null && $gid > 0 && $gid < $glyphCount) {
                    $table[$code] = $gid;
                }
            }
        }

        $gidOf = static fn (int $code): ?int => $table[$code] ?? ($identity !== null && $code > 0 && $code < $identity ? $code : null);
        // a glyph gets the width of the first code that shows it; codes named by Differences go first, an unused
        // code can reach the same glyph through the built-in encoding with a placeholder width
        $advances = [];
        $differences = $composite ? [] : $this->differences($objects, $top);
        foreach (array_replace(array_intersect_key($widths, $differences), $widths) as $code => $width) {
            if (($gid = $gidOf($code)) !== null && !isset($advances[$gid])) {
                $advances[$gid] = $width;
            }
        }

        $spaceGid = $composite || $type1 !== null ? null : $gidOf(32);
        $extra = $spaceGid !== null ? [0x20 => $spaceGid] : [];

        return [
            'bytes' => match (true) {
                $ttf !== null => $ttf->rebuilt($composite ? $advances + array_fill(0, $glyphCount, $default) : $advances, $extra),
                $cff !== null => $cff->openType($advances, $default, $extra),
                default => $type1->rebuilt($advances),
            },
            'extension' => $ttf !== null ? 'ttf' : ($cff !== null ? 'otf' : 'pfa'),
            'glyphCount' => $glyphCount,
            'space' => $spaceGid !== null,
            'table' => $table,
            'identity' => $identity,
        ];
    }

    /**
     * Widths in 1/1000 em by code (simple fonts) or CID (Type0), and the width of the others
     *
     * @param array<string, mixed> $top
     * @param array<string, mixed> $font
     * @param array<string, mixed> $descriptor
     * @return array{array<int, float>, float}
     */
    private function pdfWidths(RawObjects $objects, array $top, array $font, array $descriptor, bool $composite): array {
        $widths = [];
        if (!$composite) {
            $first = (int) ($objects->number($top['FirstChar'] ?? 0) ?? 0);
            foreach ($objects->array($top['Widths'] ?? null) ?? [] as $i => $width) {
                $widths[$first + $i] = (float) ($objects->number($width) ?? 0);
            }

            return [$widths, (float) ($objects->number($descriptor['MissingWidth'] ?? 0) ?? 0)];
        }

        $entries = $objects->array($font['W'] ?? null) ?? [];
        for ($i = 0; $i < count($entries); $i++) {
            $first = (int) ($objects->number($entries[$i]) ?? 0);
            $next = $objects->resolve($entries[$i + 1] ?? null);
            if (is_array($next)) {
                foreach (array_values($next) as $j => $width) {
                    $widths[$first + $j] = (float) ($objects->number($width) ?? 0);
                }

                $i++;
            } else {
                $last = (int) ($objects->number($next) ?? $first);
                $width = (float) ($objects->number($entries[$i + 2] ?? 0) ?? 0);
                for ($cid = $first; $cid <= min($last, $first + 65535); $cid++) {
                    $widths[$cid] = $width;
                }

                $i += 2;
            }
        }

        return [$widths, (float) ($objects->number($font['DW'] ?? 1000) ?? 1000)];
    }

    /**
     * CID => gid of a descendant CIDFont: the charset of a CID-keyed CFF program, the CIDToGIDMap stream of a
     * TrueType one, or identity (the returned limit) otherwise
     *
     * @param array<string, mixed> $font the descendant CIDFont
     * @return array{array<int, int>, ?int}
     */
    private function cidTable(RawObjects $objects, array $font, int $glyphCount, ?CffFont $cff): array {
        if ($cff !== null) {
            return $cff->cidKeyed ? [$cff->cidTable(), null] : [[], $glyphCount];
        }

        $map = $font['CIDToGIDMap'] ?? null;
        $bytes = $map instanceof PdfReference && !is_string($objects->resolve($map)) ? (string) $objects->streamData($map->objectNumber) : null;
        if ($bytes === null) {
            return [[], $glyphCount];
        }

        $table = [];
        foreach (array_values(unpack('n*', substr($bytes, 0, strlen($bytes) - strlen($bytes) % 2)) ?: []) as $cid => $gid) {
            if ($gid > 0 && $gid < $glyphCount) {
                $table[$cid] = $gid;
            }
        }

        return [$table, null];
    }

    /**
     * 9.6.6.4: symbolic fonts through the (3,0) cmap, others through the Unicode of the encoding in the (3,1) cmap
     *
     * @param array<string, mixed> $top
     * @return \Closure(int): ?int
     */
    private function trueTypeCodeMap(RawObjects $objects, array $top, TrueTypeFont $ttf): \Closure {
        $unicode = $this->simpleEncoding($objects, $top);

        return static function (int $code) use ($ttf, $unicode): ?int {
            if ($ttf->hasCmap(3, 0)) {
                foreach ([0xF000, 0xF100, 0xF200, 0] as $base) {
                    if (($gid = $ttf->lookup(3, 0, $base + $code)) !== null) {
                        return $gid;
                    }
                }
            }

            if (isset($unicode[$code]) && ($gid = $ttf->lookup(3, 1, $unicode[$code])) !== null) {
                return $gid;
            }

            return $ttf->lookup(1, 0, $code) ?? (isset($unicode[$code]) ? $ttf->lookup(0, 3, $unicode[$code]) : null);
        };
    }

    /**
     * 9.6.6.2 (Type 1 and CFF programs): the glyph name from Differences, else from the base encoding when the font
     * names one, else the program's built-in encoding; names are looked up in the program, and by Unicode when the
     * program names the glyph differently
     *
     * @param array<string, mixed> $top
     * @param array<string, int> $glyphNames glyph name => gid
     * @param \Closure(int): ?int $builtIn code => gid in the built-in encoding
     * @return \Closure(int): ?int
     */
    private function namedCodeMap(RawObjects $objects, array $top, array $glyphNames, \Closure $builtIn): \Closure {
        $differences = $this->differences($objects, $top);
        $encoding = $objects->resolve($top['Encoding'] ?? null);
        $explicitBase = is_string($encoding) || isset(($objects->dictionary($encoding) ?? [])['BaseEncoding']);
        $unicode = $explicitBase ? $this->simpleEncoding($objects, $top) : [];
        $gidByUnicode = [];
        if ($unicode !== [] || $differences !== []) {
            foreach ($glyphNames as $name => $gid) {
                if (($codePoint = self::unicodeOfGlyphName($name)) !== null) {
                    $gidByUnicode[$codePoint] ??= $gid;
                }
            }
        }

        return static function (int $code) use ($glyphNames, $builtIn, $differences, $unicode, $gidByUnicode): ?int {
            if (isset($differences[$code])) {
                $gid = $glyphNames[$differences[$code]] ?? $gidByUnicode[self::unicodeOfGlyphName($differences[$code]) ?? -1] ?? null;
                if ($gid !== null) {
                    return $gid;
                }
            }

            if (isset($unicode[$code], $gidByUnicode[$unicode[$code]])) {
                return $gidByUnicode[$unicode[$code]];
            }

            return $builtIn($code) ?? (isset(CffStandard::STANDARD_ENCODING[$code]) ? $glyphNames[CffStandard::STANDARD_ENCODING[$code]] ?? null : null);
        };
    }

    /**
     * Code => glyph name from /Encoding /Differences
     *
     * @param array<string, mixed> $font
     * @return array<int, string>
     */
    private function differences(RawObjects $objects, array $font): array {
        $names = [];
        $code = 0;
        foreach ($objects->array(($objects->dictionary($objects->resolve($font['Encoding'] ?? null)) ?? [])['Differences'] ?? null) ?? [] as $item) {
            if (is_int($item) || is_float($item)) {
                $code = (int) $item;
            } elseif (is_string($item) && str_starts_with($item, '/')) {
                $names[$code++] = substr($item, 1);
            }
        }

        return $names;
    }

    /**
     * Code => Unicode code point of a simple font from /Encoding (base encoding + Differences)
     *
     * @return array<int, int>
     */
    private function simpleEncoding(RawObjects $objects, array $font): array {
        $encoding = $objects->resolve($font['Encoding'] ?? null);
        $base = is_string($encoding) ? substr($encoding, 1) : ($objects->name(($objects->dictionary($encoding) ?? [])['BaseEncoding'] ?? null) ?? 'StandardEncoding');
        $map = [];
        for ($code = 32; $code < 256; $code++) {
            $char = match ($base) {
                'MacRomanEncoding' => @iconv('MACINTOSH', 'UTF-8', chr($code)),
                default => @mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252'),
            };
            if (is_string($char) && $char !== '' && mb_strlen($char) === 1) {
                $map[$code] = mb_ord($char);
            }
        }

        foreach ($this->differences($objects, $font) as $code => $name) {
            if (($codePoint = self::unicodeOfGlyphName($name)) !== null) {
                $map[$code] = $codePoint;
            }
        }

        return $map;
    }

    /** Code point of a glyph name: the Adobe Glyph List, uniXXXX or uXXXX[XX] */
    private static function unicodeOfGlyphName(string $name): ?int {
        $char = \PrinsFrank\GlyphLists\AGlyphList::tryFrom($name)?->getChar();
        if ($char !== null && $char !== '') {
            return mb_ord($char) ?: null;
        }

        if (preg_match('/^uni([0-9A-F]{4})$|^u([0-9A-F]{4,6})$/', $name, $m) === 1) {
            return (int) hexdec($m[1] !== '' ? $m[1] : $m[2]);
        }

        return null;
    }

    private function cacheDirectory(): string {
        $directory = $this->cacheDirectory ?? sys_get_temp_dir() . '/pdfparser-render-fonts';
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        return $directory;
    }

    /**
     * A cached rebuild: false when the program could not be used, null when it is not cached (or its font file is gone)
     *
     * @return array{file: string, glyphCount: int, space: bool, table: array<int, int>, identity: ?int}|false|null
     */
    private function readCache(string $key): array|false|null {
        $path = $this->cacheDirectory() . '/' . $key . '.json';
        $entry = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($entry)) {
            return null;
        }

        if (($entry['failed'] ?? false) === true) {
            return false;
        }

        if (!isset($entry['file'], $entry['glyphCount'], $entry['space'], $entry['table']) || !is_file($this->cacheDirectory() . '/' . $entry['file'])) {
            return null;
        }

        $table = [];
        foreach ((array) $entry['table'] as $code => $gid) {
            $table[(int) $code] = (int) $gid;
        }

        return ['file' => (string) $entry['file'], 'glyphCount' => (int) $entry['glyphCount'], 'space' => (bool) $entry['space'], 'table' => $table, 'identity' => isset($entry['identity']) ? (int) $entry['identity'] : null];
    }

    /** @param array<string, mixed> $entry */
    private function writeCache(string $key, array $entry): void {
        self::write($this->cacheDirectory() . '/' . $key . '.json', (string) json_encode($entry));
    }

    /** Writes a font file whole or not at all: other processes may read it while it is written */
    private static function write(string $file, string $bytes): void {
        $temporary = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, $bytes) === strlen($bytes)) {
            @rename($temporary, $file);
        } else {
            @unlink($temporary);
        }
    }

    private function probe(): Imagick {
        return $this->probe ??= (static function (): Imagick {
            $image = new Imagick();
            $image->newImage(1, 1, 'white');
            $image->setImageProperty('type:hinting', 'off');

            return $image;
        })();
    }

    /** Width of $text in the substitute font at $size, the sum of cached character widths (no kerning) */
    public function width(string $file, string $text, float $size): float {
        $this->probe();
        if (!isset($this->draws[$file])) {
            $draw = new ImagickDraw();
            $draw->setFont($file);
            $draw->setFontSize(100);
            $this->draws[$file] = $draw;
        }

        $total = 0.0;
        foreach (mb_str_split($text) as $char) {
            if (!isset($this->widths[$file][$char])) {
                $metrics = $this->probe->queryFontMetrics($this->draws[$file], $char === ' ' ? 'a a' : $char);
                $this->widths[$file][$char] = $char === ' '
                    ? $metrics['textWidth'] - 2 * ($this->widths[$file]['a'] ?? $this->probe->queryFontMetrics($this->draws[$file], 'a')['textWidth'])
                    : (float) $metrics['textWidth'];
            }

            $total += $this->widths[$file][$char];
        }

        return $total * $size / 100;
    }
}
