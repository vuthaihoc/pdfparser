<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Stream;

use Closure;
use Override;
use PrinsFrank\PdfParser\Document\CMap\ToUnicode\ToUnicodeCMapOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\Object\TextObjectOperator;
use PrinsFrank\PdfParser\Document\Generic\Character\DelimiterCharacter;
use PrinsFrank\PdfParser\Document\Generic\Character\WhitespaceCharacter;
use PrinsFrank\PdfParser\Document\Generic\Marker;
use PrinsFrank\PdfParser\Exception\InvalidArgumentException;
use PrinsFrank\PdfParser\Exception\RuntimeException;

/**
 * A stream that only fetches the byte ranges the parser reads, in blocks that are kept for the next reads.
 * Missing blocks next to each other are fetched in one call. The fetcher gets an inclusive byte range.
 *
 * @api
 */
class RangeStream extends AbstractStream {
    /** @var array<int, string> block number => bytes */
    private array $blocks = [];

    public int $requests = 0;

    public int $bytesFetched = 0;

    /**
     * @param Closure(int $from, int $to): string $fetch
     * @param int<1, max> $blockSize
     */
    public function __construct(
        private readonly Closure $fetch,
        private readonly int $size,
        private readonly int $blockSize = 65536,
    ) {
        if ($blockSize < 1) {
            throw new InvalidArgumentException('Block size must be at least 1 byte');
        }
    }

    /** Seeds a block fetched by someone else, e.g. the first block of the request that found the size. */
    public function addBlock(int $blockNumber, string $bytes): void {
        $this->blocks[$blockNumber] = $bytes;
    }

    #[Override]
    public function getSizeInBytes(): int {
        return $this->size;
    }

    #[Override]
    public function read(int $from, int $nrOfBytes): string {
        if ($nrOfBytes <= 0) {
            throw new InvalidArgumentException(sprintf('$nrOfBytes must be greater than 0, %d given', $nrOfBytes));
        }

        return $this->bytes($from, $from + $nrOfBytes);
    }

    #[Override]
    public function slice(int $startByteOffset, int $endByteOffset): string {
        if ($startByteOffset <= 0) {
            throw new InvalidArgumentException(sprintf('$startByteOffset must be greater than 0, %d given', $startByteOffset));
        }

        if ($endByteOffset - $startByteOffset < 1) {
            throw new InvalidArgumentException(sprintf('End byte offset %d should be bigger than start byte offset %d', $endByteOffset, $startByteOffset));
        }

        return $this->bytes($startByteOffset, $endByteOffset);
    }

    #[Override]
    public function chars(int $from, int $nrOfBytes): iterable {
        if ($from < 0) {
            throw new InvalidArgumentException(sprintf('$from must be greater than zero, %d given', $from));
        }

        if ($nrOfBytes <= 0) {
            throw new InvalidArgumentException(sprintf('$nrOfBytes to read must be greater than zero, %d given', $nrOfBytes));
        }

        foreach (str_split($this->bytes($from, $from + $nrOfBytes)) as $char) {
            yield $char;
        }
    }

    /** Same result as strpos() on the whole content, reading forward one block at a time. */
    #[Override]
    public function firstPos(WhitespaceCharacter|DelimiterCharacter|ToUnicodeCMapOperator|Marker|TextObjectOperator $needle, int $offsetFromStart, int $before): ?int {
        $needleLength = strlen($needle->value);
        $limit = min($this->size, $before + $needleLength);
        for ($start = $offsetFromStart; $start < $limit; $start += $this->blockSize) {
            $end = min($limit, $start + $this->blockSize + $needleLength - 1);
            $pos = strpos($this->bytes($start, $end), $needle->value);
            if ($pos !== false) {
                return $start + $pos > $before ? null : $start + $pos;
            }
        }

        return null;
    }

    /** Same result as strrpos($content, $needle, -$offsetFromEnd), reading backward one block at a time. */
    #[Override]
    public function lastPos(WhitespaceCharacter|DelimiterCharacter|ToUnicodeCMapOperator|Marker $needle, int $offsetFromEnd): ?int {
        $needleLength = strlen($needle->value);
        $lastStart = $this->size - $offsetFromEnd; // a match may start here at the latest
        if ($lastStart < 0) {
            return null;
        }

        $end = min($this->size, $lastStart + $needleLength);
        while (true) {
            $start = max(0, $end - $this->blockSize - $needleLength + 1);
            $window = $this->bytes($start, $end);
            $pos = strrpos($window, $needle->value);
            while ($pos !== false && $start + $pos > $lastStart) {
                $pos = $pos === 0 ? false : strrpos(substr($window, 0, $pos + $needleLength - 1), $needle->value);
            }

            if ($pos !== false) {
                return $start + $pos;
            }

            if ($start === 0) {
                return null;
            }

            $end = $start + $needleLength - 1;
        }
    }

    /** Bytes [$from, $to), loading the blocks that are missing. */
    private function bytes(int $from, int $to): string {
        $from = max(0, $from);
        $to = min($this->size, $to);
        if ($to <= $from) {
            return '';
        }

        $firstBlock = intdiv($from, $this->blockSize);
        $lastBlock = intdiv($to - 1, $this->blockSize);
        $this->load($firstBlock, $lastBlock);
        $bytes = '';
        for ($block = $firstBlock; $block <= $lastBlock; $block++) {
            $bytes .= $this->blocks[$block];
        }

        return substr($bytes, $from - $firstBlock * $this->blockSize, $to - $from);
    }

    private function load(int $firstBlock, int $lastBlock): void {
        $runStart = null;
        for ($block = $firstBlock; $block <= $lastBlock + 1; $block++) {
            $missing = $block <= $lastBlock && !isset($this->blocks[$block]);
            if ($missing && $runStart === null) {
                $runStart = $block;
            } elseif (!$missing && $runStart !== null) {
                $this->fetchBlocks($runStart, $block - 1);
                $runStart = null;
            }
        }
    }

    private function fetchBlocks(int $firstBlock, int $lastBlock): void {
        $from = $firstBlock * $this->blockSize;
        $to = min($this->size, ($lastBlock + 1) * $this->blockSize) - 1;
        $bytes = ($this->fetch)($from, $to);
        if (strlen($bytes) !== $to - $from + 1) {
            throw new RuntimeException(sprintf('Expected %d bytes for range %d-%d, got %d', $to - $from + 1, $from, $to, strlen($bytes)));
        }

        $this->requests++;
        $this->bytesFetched += strlen($bytes);
        foreach (str_split($bytes, $this->blockSize) as $i => $chunk) {
            $this->blocks[$firstBlock + $i] = $chunk;
        }
    }
}
