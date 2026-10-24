<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Generic\Character\WhitespaceCharacter;
use PrinsFrank\PdfParser\Document\Generic\Marker;
use PrinsFrank\PdfParser\Stream\InMemoryStream;
use PrinsFrank\PdfParser\Stream\RangeStream;

#[CoversClass(RangeStream::class)]
class RangeStreamTest extends TestCase {
    /** @return iterable<string, array{int<1, max>}> */
    public static function blockSizes(): iterable {
        foreach ([1, 3, 7, 64, 100000] as $blockSize) {
            yield 'block ' . $blockSize => [$blockSize];
        }
    }

    /** @param int<1, max> $blockSize */
    #[DataProvider('blockSizes')]
    public function testBehavesLikeInMemoryStream(int $blockSize): void {
        mt_srand($blockSize);
        $content = '';
        $pieces = ["\n", "\r\n", "\r", 'obj', 'endobj', 'stream', 'endstream', 'xref', 'trailer', 'startxref', '%%EOF', ' ', 'abc', '<<', '>>'];
        for ($i = 0; $i < 400; $i++) {
            $content .= $pieces[mt_rand(0, count($pieces) - 1)];
        }

        $expected = new InMemoryStream($content);
        $actual = new RangeStream(static fn(int $from, int $to): string => substr($content, $from, $to - $from + 1), strlen($content), $blockSize);
        $size = strlen($content);
        static::assertSame($size, $actual->getSizeInBytes());
        static::assertSame($content, $actual->toString());
        for ($i = 0; $i < 300; $i++) {
            $from = mt_rand(1, $size - 2);
            $length = mt_rand(1, $size - $from);
            static::assertSame($expected->read($from, $length), $actual->read($from, $length));
            static::assertSame($expected->slice($from, $from + $length), $actual->slice($from, $from + $length));
            static::assertSame(iterator_to_array($expected->chars($from, $length)), iterator_to_array($actual->chars($from, $length)));
            $before = mt_rand($from, $size);
            foreach ([Marker::OBJ, Marker::END_OBJ, Marker::STREAM, Marker::START_XREF, Marker::EOF, WhitespaceCharacter::LINE_FEED, WhitespaceCharacter::CARRIAGE_RETURN] as $needle) {
                static::assertSame($expected->firstPos($needle, $from, $before), $actual->firstPos($needle, $from, $before), sprintf('firstPos %s from %d before %d', $needle->value, $from, $before));
                $offsetFromEnd = mt_rand(0, $size - 1);
                static::assertSame($expected->lastPos($needle, $offsetFromEnd), $actual->lastPos($needle, $offsetFromEnd), sprintf('lastPos %s offset %d', $needle->value, $offsetFromEnd));
            }
            static::assertSame($expected->getStartOfNextLine($from, $before), $actual->getStartOfNextLine($from, $before));
            static::assertSame($expected->getEndOfCurrentLine($from, $before), $actual->getEndOfCurrentLine($from, $before));
        }
    }

    public function testFetchesMissingNeighbouringBlocksInOneCall(): void {
        $content = str_repeat('0123456789', 10);
        $calls = [];
        $stream = new RangeStream(static function (int $from, int $to) use ($content, &$calls): string {
            $calls[] = [$from, $to];

            return substr($content, $from, $to - $from + 1);
        }, strlen($content), 10);

        static::assertSame('2345', $stream->read(22, 4));
        static::assertSame(substr($content, 15, 30), $stream->read(15, 30));
        static::assertSame(substr($content, 12, 30), $stream->read(12, 30)); // cached blocks only
        static::assertSame([[20, 29], [10, 19], [30, 49]], $calls);
        static::assertSame(3, $stream->requests);
        static::assertSame(40, $stream->bytesFetched);
    }

    public function testKeepsAtMostMaxBlocks(): void {
        $content = str_repeat('0123456789', 10);
        $calls = 0;
        $stream = new RangeStream(static function (int $from, int $to) use ($content, &$calls): string {
            $calls++;

            return substr($content, $from, $to - $from + 1);
        }, strlen($content), 10, 2);

        static::assertSame('01', $stream->read(0, 2));
        static::assertSame('2345678901', $stream->read(12, 10));
        static::assertSame(2, $calls);
        static::assertSame('01', $stream->read(0, 2)); // block 0 was dropped: fetched again
        static::assertSame(3, $calls);
    }

    public function testFile(): void {
        $path = tempnam(sys_get_temp_dir(), 'range');
        static::assertIsString($path);
        file_put_contents($path, str_repeat('abcdefghij', 1000));
        $stream = RangeStream::file($path, 64, 4);

        static::assertSame(10000, $stream->getSizeInBytes());
        static::assertSame('jabc', $stream->read(9989, 4));
        static::assertSame(str_repeat('abcdefghij', 1000), $stream->toString());
        unlink($path);
    }

    public function testPrefetchFetchesMissingRunsInOneCall(): void {
        $content = str_repeat('0123456789', 10);
        $single = $many = [];
        $stream = new RangeStream(
            static function (int $from, int $to) use ($content, &$single): string {
                $single[] = [$from, $to];

                return substr($content, $from, $to - $from + 1);
            },
            strlen($content),
            10,
            null,
            static function (array $ranges) use ($content, &$many): array {
                $many[] = $ranges;

                return array_map(static fn(array $range) => substr($content, $range[0], $range[1] - $range[0] + 1), $ranges);
            },
        );
        $stream->read(30, 5);
        $stream->prefetch([[0, 15], [28, 36], [70, 95]]);

        static::assertSame([[30, 39]], $single);
        static::assertSame([[[0, 29], [70, 99]]], $many);
        static::assertSame(2, $stream->roundTrips);
        static::assertSame('0123456789', $stream->read(70, 10));
        static::assertSame([[30, 39]], $single); // nothing fetched for reads of prefetched blocks
    }
}
