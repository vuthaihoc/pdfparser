<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CrossReference\Source;

use Override;
use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\CrossReferenceSection;
use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\SubSection\Entry\CrossReferenceEntryCompressed;
use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\SubSection\Entry\CrossReferenceEntryInUseObject;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\CrossReference\RawStream\ObjectPositionsFromRawStreamParser;
use PrinsFrank\PdfParser\Stream\Stream;
use Throwable;

/**
 * Like RecoveredCrossReferenceSource, but the byte offset of an object is only checked when the object is read, and
 * the whole file is only scanned for object positions when an offset turns out to be wrong. Used for streams where
 * reading every object up front is expensive (RangeStream: every object offset is a separate download).
 */
class LazilyRecoveredCrossReferenceSource extends CrossReferenceSource {
    /** @var array<int, int>|null object nr => byte offset, from scanning the whole stream */
    private ?array $recoveredByteOffsetMap = null;

    /** @no-named-arguments */
    public function __construct(
        private readonly Stream $stream,
        CrossReferenceSection... $crossReferenceSections,
    ) {
        parent::__construct(...$crossReferenceSections);
    }

    #[Override]
    public function getCrossReferenceEntry(int $objNumber, Document $document): CrossReferenceEntryInUseObject|CrossReferenceEntryCompressed|null {
        try {
            $crossReferenceEntry = parent::getCrossReferenceEntry($objNumber, $document);
        } catch (Throwable) {
            $crossReferenceEntry = null;
        }

        if (!$crossReferenceEntry instanceof CrossReferenceEntryInUseObject) {
            return $crossReferenceEntry;
        }

        $expectedStartObjMarker = sprintf('%d %d obj', $objNumber, $crossReferenceEntry->generationNumber);
        if ($crossReferenceEntry->byteOffsetInDecodedStream + strlen($expectedStartObjMarker) <= $this->stream->getSizeInBytes()
            && $this->stream->read($crossReferenceEntry->byteOffsetInDecodedStream, strlen($expectedStartObjMarker)) === $expectedStartObjMarker) {
            return $crossReferenceEntry;
        }

        $this->recoveredByteOffsetMap ??= ObjectPositionsFromRawStreamParser::parse($this->stream);
        if (array_key_exists($objNumber, $this->recoveredByteOffsetMap)) {
            return new CrossReferenceEntryInUseObject($this->recoveredByteOffsetMap[$objNumber], 0);
        }

        return $crossReferenceEntry;
    }
}
