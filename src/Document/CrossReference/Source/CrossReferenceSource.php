<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CrossReference\Source;

use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\CrossReferenceSection;
use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\SubSection\Entry\CrossReferenceEntryCompressed;
use PrinsFrank\PdfParser\Document\CrossReference\Source\Section\SubSection\Entry\CrossReferenceEntryInUseObject;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\ArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\DictionaryValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\NameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\RuntimeException;
use PrinsFrank\PdfParser\Stream\Stream;

/** Can be both from a crossReferenceTable or a crossReferenceStream */
class CrossReferenceSource {
    /** @var list<CrossReferenceSection> Where the first is the newest incremental update and the last one is the oldest */
    private readonly array $crossReferenceSections;

    /** @no-named-arguments */
    public function __construct(
        CrossReferenceSection... $crossReferenceSections,
    ) {
        $this->crossReferenceSections = $crossReferenceSections;
    }

    /** @throws RuntimeException */
    public function getCrossReferenceEntry(int $objNumber, Document $document): CrossReferenceEntryInUseObject|CrossReferenceEntryCompressed|null {
        foreach ($this->crossReferenceSections as $crossReferenceSection) {
            $crossReferenceEntry = $crossReferenceSection->getCrossReferenceEntry($objNumber);
            if ($crossReferenceEntry !== null) {
                return $crossReferenceEntry;
            }
        }

        return null;
    }

    /** The entry as the cross-reference data has it, without the checks a subclass may make (which read the object) */
    final public function getStoredCrossReferenceEntry(int $objNumber): CrossReferenceEntryInUseObject|CrossReferenceEntryCompressed|null {
        foreach ($this->crossReferenceSections as $crossReferenceSection) {
            try {
                $crossReferenceEntry = $crossReferenceSection->getCrossReferenceEntry($objNumber);
            } catch (RuntimeException) {
                return null;
            }

            if ($crossReferenceEntry !== null) {
                return $crossReferenceEntry;
            }
        }

        return null;
    }

    public function getReferenceForKey(DictionaryKey $dictionaryKey): ?ReferenceValue {
        return $this->getValueForKey($dictionaryKey, ReferenceValue::class);
    }

    /**
     * @template T of DictionaryValue|NameValue|Dictionary
     * @param class-string<T> $valueType
     * @return T
     */
    public function getValueForKey(DictionaryKey $dictionaryKey, string $valueType): DictionaryValue|Dictionary|NameValue|null {
        foreach ($this->crossReferenceSections as $crossReferenceSection) {
            $valueForKey = $crossReferenceSection->dictionary->getValueForKey(null, $dictionaryKey, $valueType);
            if ($valueForKey !== null) {
                return $valueForKey;
            }
        }

        return null;
    }

    public function getFirstId(): string {
        $value = $this->getValueForKey(DictionaryKey::ID, ArrayValue::class)->value[0]
            ?? throw new ParseFailureException('Unable to retrieve first id from cross reference source');
        if (!is_string($value)) {
            throw new ParseFailureException('First id is not a string');
        }

        if (!str_starts_with($value, '<') || !str_ends_with($value, '>')) {
            throw new ParseFailureException('Unsupported first id format, expected "<hex>"');
        }

        $firstId = hex2bin(substr($value, 1, -1));
        if ($firstId === false) {
            throw new ParseFailureException('Unable to retrieve binary value from first id');
        }

        return $firstId;
    }

    /** @var list<int> */
    private array $inUseByteOffsets;

    /**
     * Byte offsets of all objects in use, sorted: an object ends where the next one starts, so a byte range can be
     * known (and fetched) before the object is parsed
     *
     * @return list<int>
     */
    public function getInUseByteOffsets(): array {
        if (isset($this->inUseByteOffsets)) {
            return $this->inUseByteOffsets;
        }

        $offsets = [];
        foreach ($this->crossReferenceSections as $crossReferenceSection) {
            foreach ($crossReferenceSection->crossReferenceSubSections as $crossReferenceSubSection) {
                foreach ($crossReferenceSubSection->crossReferenceEntries as $crossReferenceEntry) {
                    if ($crossReferenceEntry instanceof CrossReferenceEntryInUseObject) {
                        $offsets[$crossReferenceEntry->byteOffsetInDecodedStream] = true;
                    }
                }
            }
        }

        $offsets = array_keys($offsets);
        sort($offsets);

        return $this->inUseByteOffsets = $offsets;
    }

    public function hasInvalidByteOffset(Stream $stream): bool {
        foreach ($this->crossReferenceSections as $crossReferenceSection) {
            if ($crossReferenceSection->hasInvalidByteOffset($stream)) {
                return true;
            }
        }

        return false;
    }
}
