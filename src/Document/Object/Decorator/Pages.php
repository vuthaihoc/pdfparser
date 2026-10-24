<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Object\Decorator;

use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Integer\IntegerValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Rectangle\Rectangle;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValueArray;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\PdfParserException;

class Pages extends DecoratedObject {
    /**
     * @param list<int> $visitedObjectNrs
     * @throws PdfParserException
     * @return list<Page>
     */
    public function getPageItems(array $visitedObjectNrs = []): array {
        $kids = [];
        foreach ($this->getDictionary()->getValueForKey($this->document, DictionaryKey::KIDS, ReferenceValueArray::class)->referenceValues ?? [] as $referenceValue) {
            if (in_array($referenceValue->objectNumber, $visitedObjectNrs, true)) {
                continue;
            }

            $visitedObjectNrs[] = $referenceValue->objectNumber;
            $kidObject = $this->document->getObject($referenceValue->objectNumber)
                ?? throw new ParseFailureException(sprintf('Child with number %d could not be found', $referenceValue->objectNumber));

            if ($kidObject instanceof Pages) {
                $kids = [...$kids, ...$kidObject->getPageItems($visitedObjectNrs)];
            } elseif ($kidObject instanceof Page) {
                $kids[] = $kidObject;
            } elseif ($kidObject instanceof GenericObject) {
                $kids[] = new Page($referenceValue->objectNumber, $kidObject->objectItem, $this->document);
            }
        }

        return $kids;
    }

    /** The number of pages in this subtree according to its /Count entry */
    public function getCount(): ?int {
        return $this->getDictionary()->getValueForKey($this->document, DictionaryKey::COUNT, IntegerValue::class)?->value;
    }

    /**
     * Page $pageNumber (1-based) of this subtree, going down the tree by the /Count of each kid, so only the nodes on
     * the way to that page are read. A kid without /Count is read completely.
     *
     * @param list<int> $visitedObjectNrs
     * @throws PdfParserException
     */
    public function getPageItem(int $pageNumber, array $visitedObjectNrs = []): ?Page {
        $remaining = $pageNumber;
        $kids = $this->getDictionary()->getValueForKey($this->document, DictionaryKey::KIDS, ReferenceValueArray::class)->referenceValues ?? [];
        // the /Count of the kids decides which one to go into: a remote document fetches them all in one go
        $this->document->prefetchObjects(array_map(static fn (ReferenceValue $kid) => $kid->objectNumber, $kids), 4096);
        foreach ($kids as $referenceValue) {
            if (in_array($referenceValue->objectNumber, $visitedObjectNrs, true)) {
                continue;
            }

            $visitedObjectNrs[] = $referenceValue->objectNumber;
            $kidObject = $this->document->getObject($referenceValue->objectNumber)
                ?? throw new ParseFailureException(sprintf('Child with number %d could not be found', $referenceValue->objectNumber));
            if ($kidObject instanceof Pages) {
                if (($count = $kidObject->getCount()) === null) {
                    $pageItems = $kidObject->getPageItems($visitedObjectNrs);
                    if ($remaining <= count($pageItems)) {
                        return $pageItems[$remaining - 1];
                    }

                    $remaining -= count($pageItems);
                    continue;
                }

                if ($remaining <= $count) {
                    return $kidObject->getPageItem($remaining, $visitedObjectNrs);
                }

                $remaining -= $count;
            } elseif ($kidObject instanceof Page || $kidObject instanceof GenericObject) {
                if ($remaining === 1) {
                    return $kidObject instanceof Page ? $kidObject : new Page($referenceValue->objectNumber, $kidObject->objectItem, $this->document);
                }

                $remaining--;
            }
        }

        return null;
    }

    /**
     * @param list<int> $visitedObjectNrs
     * @throws PdfParserException
     */
    public function getResourceDictionary(array $visitedObjectNrs): ?Dictionary {
        if (($localValue = $this->getDictionary()->getSubDictionary($this->document, DictionaryKey::RESOURCES)) !== null) {
            return $localValue;
        }

        if (($parentReference = $this->getDictionary()->getValueForKey($this->document, DictionaryKey::PARENT, ReferenceValue::class)) === null) {
            return null;
        }

        if (in_array($parentReference->objectNumber, $visitedObjectNrs, true)) {
            return null; // exit loops in page trees
        }

        return ($this->document->getObject($parentReference->objectNumber, Pages::class) ?? throw new ParseFailureException(sprintf('Parent with object nr %d not found', $parentReference->objectNumber)))
            ->getResourceDictionary([... $visitedObjectNrs, $parentReference->objectNumber]);
    }

    /** @param list<int> $visitedObjectNrs */
    public function getMediaBox(array $visitedObjectNrs): ?Rectangle {
        if (($localValue = $this->getDictionary()->getValueForKey($this->document, DictionaryKey::MEDIA_BOX, Rectangle::class)) !== null) {
            return $localValue;
        }

        if (($parentReference = $this->getDictionary()->getValueForKey($this->document, DictionaryKey::PARENT, ReferenceValue::class)) === null) {
            return null;
        }

        if (in_array($parentReference->objectNumber, $visitedObjectNrs, true)) {
            return null; // exit loops in page trees
        }

        return ($this->document->getObject($parentReference->objectNumber, Pages::class) ?? throw new ParseFailureException(sprintf('Parent with object nr %d not found', $parentReference->objectNumber)))
            ->getCropBox([... $visitedObjectNrs, $parentReference->objectNumber]);
    }

    /** @param list<int> $visitedObjectNrs */
    public function getCropBox(array $visitedObjectNrs): ?Rectangle {
        if (($localValue = $this->getDictionary()->getValueForKey($this->document, DictionaryKey::CROP_BOX, Rectangle::class)) !== null) {
            return $localValue;
        }

        if (($parentReference = $this->getDictionary()->getValueForKey($this->document, DictionaryKey::PARENT, ReferenceValue::class)) === null) {
            return null;
        }

        if (in_array($parentReference->objectNumber, $visitedObjectNrs, true)) {
            return null; // exit loops in page trees
        }

        return ($this->document->getObject($parentReference->objectNumber, Pages::class) ?? throw new ParseFailureException(sprintf('Parent with object nr %d not found', $parentReference->objectNumber)))
            ->getCropBox([... $visitedObjectNrs, $parentReference->objectNumber]);
    }
}
