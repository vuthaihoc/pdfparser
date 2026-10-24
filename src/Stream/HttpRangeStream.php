<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Stream;

use PrinsFrank\PdfParser\Exception\RuntimeException;

/**
 * Opens a PDF over HTTP(S) with Range requests (needs ext-curl), so the parser downloads only what it reads:
 * the trailer and cross-reference table, then the objects it asks for. A server that ignores Range (200 instead
 * of 206) sends the whole file in the first response, which is then used as it is.
 *
 * @api
 */
final class HttpRangeStream {
    /**
     * @param int<1, max> $blockSize
     * @param array<string, string> $headers
     */
    public static function open(string $url, int $blockSize = 65536, array $headers = [], int $timeoutSeconds = 30): RangeStream|InMemoryStream {
        $first = self::request($url, 0, $blockSize - 1, $headers, $timeoutSeconds);
        if ($first['status'] === 200) {
            return new InMemoryStream($first['body']);
        }

        if ($first['status'] !== 206 || preg_match('~/\s*(\d+)\s*$~', $first['content_range'] ?? '', $match) !== 1) {
            throw new RuntimeException(sprintf('Range request to %s failed: HTTP %d', $url, $first['status']));
        }

        $size = (int) $match[1];
        $stream = new RangeStream(
            static function (int $from, int $to) use ($url, $headers, $timeoutSeconds): string {
                $response = self::request($url, $from, $to, $headers, $timeoutSeconds);
                if ($response['status'] !== 206) {
                    throw new RuntimeException(sprintf('Range request %d-%d to %s failed: HTTP %d', $from, $to, $url, $response['status']));
                }

                return $response['body'];
            },
            $size,
            $blockSize,
        );
        $stream->addBlock(0, $first['body']);
        $stream->requests++;
        $stream->bytesFetched += strlen($first['body']);

        return $stream;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, content_range: ?string}
     */
    private static function request(string $url, int $from, int $to, array $headers, int $timeoutSeconds): array {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise curl');
        }

        $contentRange = null;
        $requestHeaders = ['Range: bytes=' . $from . '-' . $to];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_ENCODING => 'identity',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentRange): int {
                if (stripos($header, 'Content-Range:') === 0) {
                    $contentRange = trim(substr($header, strlen('Content-Range:')));
                }

                return strlen($header);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($body === false || !is_string($body)) {
            throw new RuntimeException(sprintf('Request to %s failed: %s', $url, $error));
        }

        return ['status' => $status, 'body' => $body, 'content_range' => $contentRange];
    }
}
