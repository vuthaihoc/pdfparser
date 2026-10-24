<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\Generic\Marker;
use PrinsFrank\PdfParser\Document\Object\Decorator\DecoratedObject;
use PrinsFrank\PdfParser\Document\Object\Item\UncompressedObject\UncompressedObject;
use Throwable;

/**
 * Untyped access to objects for the renderer: the value of an object as PdfSyntax reads it, its decoded stream data
 * (filters applied by the parser, without converting images), and references resolved on demand.
 *
 * @experimental
 */
final class RawObjects {
    /** @var array<int, mixed> */
    private array $values = [];

    /** @var array<int, string> */
    private array $streams = [];

    public function __construct(
        public readonly Document $document,
    ) {}

    /** The value of object $objectNumber: for a stream object its dictionary */
    public function value(int $objectNumber): mixed {
        if (array_key_exists($objectNumber, $this->values)) {
            return $this->values[$objectNumber];
        }

        $this->values[$objectNumber] = null; // loops
        try {
            $object = $this->document->getObject($objectNumber);
        } catch (Throwable) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        $item = $object->objectItem;
        if ($item instanceof UncompressedObject) {
            $stream = $this->document->stream;
            $length = $item->endOffset - $item->startOffset;
            $head = $stream->read($item->startOffset, max(1, min($length, 65536)));
            $objPos = strpos($head, 'obj');
            $body = $objPos === false ? $head : substr($head, $objPos + 3);
            $streamPos = $this->streamKeyword($body);
            if ($streamPos !== null) {
                $body = substr($body, 0, $streamPos);
            } elseif ($length > 65536) {
                $body = $stream->read($item->startOffset, $length);
                $body = substr($body, (int) strpos($body, 'obj') + 3);
            }

            $body = preg_replace('/endobj\s*$/', '', $body) ?? $body;
        } else {
            try {
                $body = $item->getContent($this->document)->toString();
            } catch (Throwable) {
                return null;
            }
        }

        return $this->values[$objectNumber] = PdfSyntax::value($body);
    }

    /** Position of the "stream" keyword that starts stream data (not inside "endstream") */
    private function streamKeyword(string $body): ?int {
        $offset = 0;
        while (($pos = strpos($body, Marker::STREAM->value, $offset)) !== false) {
            if ($pos < 3 || substr($body, $pos - 3, 3) !== 'end') {
                return $pos;
            }

            $offset = $pos + 1;
        }

        return null;
    }

    /** Follows references */
    public function resolve(mixed $value, int $depth = 0): mixed {
        while ($value instanceof PdfReference && $depth++ < 32) {
            $value = $this->value($value->objectNumber);
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    public function dictionary(mixed $value): ?array {
        $value = $this->resolve($value);

        return is_array($value) && !array_is_list($value) ? $value : (is_array($value) && $value === [] ? [] : null);
    }

    /** @return list<mixed>|null */
    public function array(mixed $value): ?array {
        $value = $this->resolve($value);

        return is_array($value) && array_is_list($value) ? $value : null;
    }

    public function number(mixed $value, float $default = 0.0): float {
        $value = $this->resolve($value);

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    public function name(mixed $value): ?string {
        $value = $this->resolve($value);

        return is_string($value) && str_starts_with($value, '/') ? substr($value, 1) : null;
    }

    /** Decoded data of a stream object (filters applied by the parser; DCT/JPX/JBIG2 data stays encoded) */
    public function streamData(int $objectNumber): ?string {
        if (array_key_exists($objectNumber, $this->streams)) {
            return $this->streams[$objectNumber];
        }

        try {
            $object = $this->document->getObject($objectNumber);

            return $this->streams[$objectNumber] = $object?->objectItem->getContent($this->document)->toString();
        } catch (Throwable) {
            return $this->streams[$objectNumber] = null;
        }
    }

    /** Stream data as stored, before filters (only for objects that are not inside an object stream; not decrypted) */
    public function rawStreamBytes(int $objectNumber): ?string {
        try {
            $item = $this->document->getObject($objectNumber)?->objectItem;
        } catch (Throwable) {
            return null;
        }

        if (!$item instanceof UncompressedObject) {
            return null;
        }

        $text = $this->document->stream->read($item->startOffset, max(1, $item->endOffset - $item->startOffset));
        $start = $this->streamKeyword($text);
        if ($start === null) {
            return null;
        }

        $start += strlen(Marker::STREAM->value);
        $start += substr($text, $start, 2) === "\r\n" ? 2 : 1;
        $end = strrpos($text, 'endstream');
        if ($end === false || $end < $start) {
            return null;
        }

        $length = $this->number($this->dictionary($this->value($objectNumber))['Length'] ?? null, -1);

        return $length >= 0 && $start + $length <= $end ? substr($text, $start, (int) $length) : rtrim(substr($text, $start, $end - $start), "\r\n");
    }

    /**
     * The object as $decoratorFqn. Document caches the first decorator an object got (GenericObject when it was read
     * untyped first), so another decorator is built around the same object item.
     *
     * @template T of DecoratedObject
     * @param class-string<T> $decoratorFqn
     * @return T|null
     */
    public function decorated(int $objectNumber, string $decoratorFqn): ?DecoratedObject {
        try {
            $object = $this->document->getObject($objectNumber);
        } catch (Throwable) {
            return null;
        }

        if ($object === null || $object instanceof $decoratorFqn) {
            return $object;
        }

        return new $decoratorFqn($object->objectNumber, $object->objectItem, $this->document);
    }

    /**
     * The resources of a page or form, following /Parent for pages.
     *
     * @return array<string, mixed>
     */
    public function resources(int $objectNumber): array {
        $visited = [];
        while (!in_array($objectNumber, $visited, true)) {
            $visited[] = $objectNumber;
            $dictionary = $this->dictionary($this->value($objectNumber)) ?? [];
            if (isset($dictionary['Resources'])) {
                return $this->dictionary($dictionary['Resources']) ?? [];
            }

            if (!($dictionary['Parent'] ?? null) instanceof PdfReference) {
                return [];
            }

            $objectNumber = $dictionary['Parent']->objectNumber;
        }

        return [];
    }

    /** An inherited page attribute (MediaBox, CropBox, Rotate) */
    public function inherited(int $objectNumber, string $key): mixed {
        $visited = [];
        while (!in_array($objectNumber, $visited, true)) {
            $visited[] = $objectNumber;
            $dictionary = $this->dictionary($this->value($objectNumber)) ?? [];
            if (array_key_exists($key, $dictionary)) {
                return $this->resolve($dictionary[$key]);
            }

            if (!($dictionary['Parent'] ?? null) instanceof PdfReference) {
                return null;
            }

            $objectNumber = $dictionary['Parent']->objectNumber;
        }

        return null;
    }
}
