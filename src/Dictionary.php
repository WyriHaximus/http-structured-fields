<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use Iterator;

/**
 * @implements StructuredFieldContainer<array-key, Item|InnerList>
 */
final class Dictionary implements StructuredFieldContainer
{
    /** @var array<string, Item|InnerList>  */
    private array $elements;

    /**
     * @param iterable<string, Item|InnerList> $elements
     */
    public function __construct(iterable $elements = [])
    {
        $this->elements = [];
        foreach ($elements as $index => $element) {
            $this->set($index, $element);
        }
    }

    public static function fromField(string $field): self
    {
        $instance = new self();
        $field = trim($field, ' ');
        if ('' === $field) {
            return $instance;
        }

        if (1 === preg_match("/[^\x20-\x7E\t]/", $field) || str_starts_with($field, "\t")) {
            throw new SyntaxError("Dictionary field `$field` contains invalid characters.");
        }

        $parser = fn (string $element): Item|InnerList => str_starts_with($element, '(')
            ? InnerList::fromField($element)
            : Item::fromField($element);

        return array_reduce(explode(',', $field), function (self $instance, string $element) use ($parser): self {
            [$key, $value] = self::extractPair($element);

            $instance->set($key, $parser($value));

            return $instance;
        }, $instance);
    }

    /**
     * @throws SyntaxError
     *
     * @return array{0:string, 1:string}
     */
    private static function extractPair(string $element): array
    {
        $element = trim($element);

        if ('' === $element) {
            throw new SyntaxError('Dictionary pair can not be empty.');
        }

        if (1 !== preg_match('/^(?<key>[a-z*][a-z0-9.*_-]*)(=)?(?<value>.*)/', $element, $found)) {
            throw new SyntaxError("Dictionary pair `$element` contains invalid characters.");
        }

        if (rtrim($found['key']) !== $found['key'] || ltrim($found['value']) !== $found['value']) {
            throw new SyntaxError("Dictionary pair `$element` contains invalid characters.");
        }

        $found['value'] = trim($found['value']);
        if ('' === $found['value'] || str_starts_with($found['value'], ';')) {
            $found['value'] = '?1'.$found['value'];
        }

        return [$found['key'], $found['value']];
    }

    public function isEmpty(): bool
    {
        return [] === $this->elements;
    }

    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * @return Iterator<string, Item|InnerList>
     */
    public function getIterator(): Iterator
    {
        foreach ($this->elements as $index => $element) {
            yield $index => $element;
        }
    }

    public function keys(): array
    {
        return array_keys($this->elements);
    }

    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->elements);
    }

    public function getByKey(string $key): Item|InnerList|null
    {
        if (!array_key_exists($key, $this->elements)) {
            throw InvalidOffset::dueToKeyNotFound($key);
        }

        return $this->elements[$key];
    }

    public function hasIndex(int $index): bool
    {
        return null !== $this->filterIndex($index);
    }

    public function getByIndex(int $index): Item|InnerList|null
    {
        $offset = $this->filterIndex($index);
        if (null === $offset) {
            throw InvalidOffset::dueToIndexNotFound($index);
        }

        return array_values($this->elements)[$offset];
    }

    public function toField(): string
    {
        $returnValue = [];
        foreach ($this->elements as $key => $element) {
            $returnValue[] = match (true) {
                $element instanceof Item && true === $element->value() => $key.$element->parameters()->toField(),
                default => $key.'='.$element->toField(),
            };
        }

        return implode(', ', $returnValue);
    }

    public function set(string $key, Item|InnerList $element): void
    {
        $this->filterKey($key);

        $this->elements[$key] = $element;
    }

    public function delete(string ...$indexes): void
    {
        foreach ($indexes as $index) {
            unset($this->elements[$index]);
        }
    }

    public function append(string $key, Item|InnerList $element): void
    {
        $this->filterKey($key);

        unset($this->elements[$key]);

        $this->elements[$key] = $element;
    }

    public function prepend(string $key, Item|InnerList $element): void
    {
        $this->filterKey($key);

        unset($this->elements[$key]);

        $this->elements = [...[$key => $element], ...$this->elements];
    }

    private function filterIndex(int $index): int|null
    {
        $max = count($this->elements);

        return match (true) {
            [] === $this->elements, 0 > $max + $index, 0 > $max - $index - 1 => null,
            0 > $index => $max + $index,
            default => $index,
        };
    }

    private function filterKey(string $key): void
    {
        if (1 !== preg_match('/^[a-z*][a-z0-9.*_-]*$/', $key)) {
            throw new SyntaxError("Key `$key` contains invalid characters.");
        }
    }
}
