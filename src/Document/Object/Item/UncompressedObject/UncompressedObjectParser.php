<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Object\Item\UncompressedObject;

use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\SubSection\Entry\CrossReferenceEntryInUseObject;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\Generic\Character\WhitespaceCharacter;
use PrinsFrank\PdfParser\Document\Generic\Marker;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Stream\RangeStream;

/** @internal */
class UncompressedObjectParser {
    public static function parseObject(CrossReferenceEntryInUseObject $crossReferenceEntry, int $objectNumber, Document $document): UncompressedObject {
        $startObj = $document->stream->firstPos(Marker::OBJ, $crossReferenceEntry->byteOffsetInDecodedStream, $document->stream->getSizeInBytes())
            ?? throw new ParseFailureException('Unable to locate start of object');
        $endObj = $document->stream instanceof RangeStream ? self::endOfObjectByLength($document, $startObj) : null;
        if ($endObj !== null) {
            return self::uncompressedObject($crossReferenceEntry, $objectNumber, $document, $startObj, $endObj);
        }

        $endObj = $document->stream->firstPos(Marker::END_OBJ, $startObj, $document->stream->getSizeInBytes())
            ?? throw new ParseFailureException('Unable to locate end of object');
        if (($startStream = $document->stream->getStartNextLineAfter(Marker::STREAM, $startObj, $endObj)) !== null) {
            $endStream = $document->stream->firstPos(Marker::END_STREAM, $startStream, $document->stream->getSizeInBytes())
                ?? throw new ParseFailureException('Unable to locate end of stream');
            $endObj = $document->stream->firstPos(Marker::END_OBJ, $endStream, $document->stream->getSizeInBytes())
                ?? throw new ParseFailureException('Unable to locate end of object');
        }

        return self::uncompressedObject($crossReferenceEntry, $objectNumber, $document, $startObj, $endObj);
    }

    /** @throws ParseFailureException */
    private static function uncompressedObject(CrossReferenceEntryInUseObject $crossReferenceEntry, int $objectNumber, Document $document, int $startObj, int $endObj): UncompressedObject {
        $objHeader = $document->stream->read($crossReferenceEntry->byteOffsetInDecodedStream, $startObj + Marker::OBJ->length() - $crossReferenceEntry->byteOffsetInDecodedStream);
        $objHeaderParts = explode(WhitespaceCharacter::SPACE->value, str_replace([WhitespaceCharacter::LINE_FEED->value], ' ', trim($objHeader)));
        if (count($objHeaderParts) !== 3 || (int) $objHeaderParts[0] !== $objectNumber || (int) $objHeaderParts[1] !== $crossReferenceEntry->generationNumber || $objHeaderParts[2] !== Marker::OBJ->value) {
            throw new ParseFailureException(sprintf('Expected "%d %d %s" on first line, got "%s"', $objectNumber, $crossReferenceEntry->generationNumber, Marker::OBJ->value, $objHeader));
        }

        return new UncompressedObject(
            $document,
            $objectNumber,
            $crossReferenceEntry->generationNumber,
            $crossReferenceEntry->byteOffsetInDecodedStream,
            $endObj + Marker::END_OBJ->length(),
        );
    }

    /**
     * The offset of "endobj" without reading stream data: the object ends before its stream, or the stream /Length says
     * where it ends (checked against "endstream" there). Null when that cannot be told, so the caller scans for it.
     */
    private static function endOfObjectByLength(Document $document, int $startObj): ?int {
        $stream = $document->stream;
        $size = $stream->getSizeInBytes();
        $streamPos = $endObjPos = null;
        for ($from = $startObj + Marker::OBJ->length(); $from < $size; $from += 4096) {
            $before = min($size, $from + 4096);
            $endObjPos = $stream->firstPos(Marker::END_OBJ, $from, $before);
            $streamPos = $stream->firstPos(Marker::STREAM, $from, $before);
            if ($endObjPos !== null || $streamPos !== null) {
                break;
            }
        }

        if ($streamPos === null || ($endObjPos !== null && $endObjPos < $streamPos)) {
            return $endObjPos;
        }

        $dictionary = $stream->read($startObj, $streamPos - $startObj);
        if (preg_match('~/Length\s+(\d+)(?:\s+(\d+)\s+R)?~', $dictionary, $match) !== 1) {
            return null;
        }

        $length = isset($match[2]) ? self::indirectInteger($document, (int) $match[1]) : (int) $match[1];
        if ($length === null || ($startStream = $stream->getStartOfNextLine($streamPos, $size)) === null || $startStream + $length >= $size) {
            return null;
        }

        $tail = $stream->read($startStream + $length, min(64, $size - $startStream - $length));
        if (preg_match('~^\s*endstream\s*endobj~', $tail, $tailMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $startStream + $length + strlen($tailMatch[0][0]) - Marker::END_OBJ->length();
    }

    /** The value of an object that is only an integer, e.g. the /Length of a stream written after it. */
    private static function indirectInteger(Document $document, int $objectNumber): ?int {
        $entry = $document->crossReferenceSource->getCrossReferenceEntry($objectNumber, $document);
        if (!$entry instanceof CrossReferenceEntryInUseObject) {
            return null;
        }

        $head = $document->stream->read($entry->byteOffsetInDecodedStream, min(64, $document->stream->getSizeInBytes() - $entry->byteOffsetInDecodedStream));

        return preg_match('~^\s*\d+\s+\d+\s+obj\s*(\d+)\s*endobj~', $head, $match) === 1 ? (int) $match[1] : null;
    }
}
