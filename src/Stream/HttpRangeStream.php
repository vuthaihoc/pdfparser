<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Stream;

use PrinsFrank\PdfParser\Exception\RuntimeException;

/**
 * Opens a PDF over HTTP(S) with Range requests (needs ext-curl), so the parser downloads only what it reads:
 * the trailer and cross-reference table, then the objects it asks for. A server that ignores Range (200 instead
 * of 206) sends the whole file in the first response, which is then used as it is.
 *
 * Round trips are what reading over a network costs, so the start and the end of the file (header, trailer,
 * cross-reference stream) are requested together, and RangeStream::prefetch() fetches its ranges in parallel.
 *
 * @api
 */
final class HttpRangeStream {
    /**
     * @param int<1, max> $blockSize
     * @param array<string, string> $headers
     * @param int $headBlocks blocks at the start of the file requested first (a linearized file has the first page and its cross-reference data there)
     * @param int $tailBlocks blocks at the end of the file requested with them (trailer, cross-reference stream)
     * @param int $parallel requests at the same time at most
     */
    public static function open(string $url, int $blockSize = 65536, array $headers = [], int $timeoutSeconds = 30, int $headBlocks = 2, int $tailBlocks = 2, int $parallel = 6): RangeStream|InMemoryStream {
        $responses = self::requestMany($url, [[0, max(1, $headBlocks) * $blockSize - 1], [null, max(1, $tailBlocks) * $blockSize]], $headers, $timeoutSeconds, $parallel);
        [$first, $tail] = $responses;
        if ($first['status'] === 200) {
            return new InMemoryStream($first['body']);
        }

        if ($first['status'] !== 206 || preg_match('~/\s*(\d+)\s*$~', $first['content_range'] ?? '', $match) !== 1) {
            throw new RuntimeException(sprintf('Range request to %s failed: HTTP %d', $url, $first['status']));
        }

        $size = (int) $match[1];
        $stream = new RangeStream(
            static function (int $from, int $to) use ($url, $headers, $timeoutSeconds): string {
                return self::body(self::requestMany($url, [[$from, $to]], $headers, $timeoutSeconds, 1)[0], $url, $from, $to);
            },
            $size,
            $blockSize,
            null,
            static function (array $ranges) use ($url, $headers, $timeoutSeconds, $parallel): array {
                $bodies = [];
                foreach (self::requestMany($url, $ranges, $headers, $timeoutSeconds, $parallel) as $i => $response) {
                    $bodies[] = self::body($response, $url, (int) $ranges[$i][0], $ranges[$i][1]);
                }

                return $bodies;
            },
        );
        foreach (str_split($first['body'], $blockSize) as $block => $bytes) {
            $stream->addBlock($block, $bytes);
        }

        $stream->bytesFetched += strlen($first['body']);
        $stream->requests++;
        $stream->roundTrips++;
        if ($tail['status'] === 206 && preg_match('~bytes\s+(\d+)-(\d+)/~', $tail['content_range'] ?? '', $range) === 1) {
            // the suffix range is not aligned to blocks: keep the blocks it covers whole
            $tailStart = (int) $range[1];
            for ($block = intdiv($tailStart + $blockSize - 1, $blockSize); $block * $blockSize < $size; $block++) {
                $stream->addBlock($block, substr($tail['body'], $block * $blockSize - $tailStart, $blockSize));
            }

            $stream->bytesFetched += strlen($tail['body']);
            $stream->requests++;
        }

        return $stream;
    }

    /** @param array{status: int, body: string, content_range: ?string} $response */
    private static function body(array $response, string $url, int $from, int $to): string {
        if ($response['status'] !== 206) {
            throw new RuntimeException(sprintf('Range request %d-%d to %s failed: HTTP %d', $from, $to, $url, $response['status']));
        }

        return $response['body'];
    }

    /**
     * Range requests, $parallel at a time; a range with a null start is a suffix range (its last $to bytes)
     *
     * @param list<array{?int, int}> $ranges
     * @param array<string, string> $headers
     * @return list<array{status: int, body: string, content_range: ?string}>
     */
    private static function requestMany(string $url, array $ranges, array $headers, int $timeoutSeconds, int $parallel): array {
        $responses = [];
        foreach (array_chunk($ranges, max(1, $parallel), true) as $chunk) {
            $multi = curl_multi_init();
            $handles = $contentRanges = [];
            foreach ($chunk as $i => [$from, $to]) {
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new RuntimeException('Unable to initialise curl');
                }

                $contentRanges[$i] = null;
                $requestHeaders = ['Range: bytes=' . ($from === null ? '-' . $to : $from . '-' . $to)];
                foreach ($headers as $name => $value) {
                    $requestHeaders[] = $name . ': ' . $value;
                }

                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => $timeoutSeconds,
                    CURLOPT_HTTPHEADER => $requestHeaders,
                    CURLOPT_ENCODING => 'identity',
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentRanges, $i): int {
                        if (stripos($header, 'Content-Range:') === 0) {
                            $contentRanges[$i] = trim(substr($header, strlen('Content-Range:')));
                        }

                        return strlen($header);
                    },
                ]);
                curl_multi_add_handle($multi, $curl);
                $handles[$i] = $curl;
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $i => $curl) {
                $body = curl_multi_getcontent($curl);
                $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if (!is_string($body) || $code === 0) {
                    throw new RuntimeException(sprintf('Request to %s failed: %s', $url, curl_error($curl)));
                }

                $responses[$i] = ['status' => $code, 'body' => $body, 'content_range' => $contentRanges[$i]];
                curl_multi_remove_handle($multi, $curl);
            }

            curl_multi_close($multi);
        }

        ksort($responses);

        return array_values($responses);
    }
}
