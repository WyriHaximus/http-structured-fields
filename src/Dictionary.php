<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use Countable;
use Iterator;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<array-key, Item|InnerList>
 */
final class Dictionary implements Countable, IteratorAggregate, StructuredField
{
    private function __construct(
        /** @var array<string, Item|InnerList>  */
        private array $elements = []
    ) {
    }

    /**
     * @param iterable<string, InnerList|Item|ByteSequence|Token|bool|int|float|string> $elements
     */
    public static function fromAssociative(iterable $elements = []): self
    {
        $instance = new self();
        foreach ($elements as $index => $element) {
            $instance->set($index, $element);
        }

        return $instance;
    }

    /**
     * @param iterable<array{0:string, 1:InnerList|Item|ByteSequence|Token|bool|int|float|string}> $pairs
     */
    public static function fromPairs(iterable $pairs = []): self
    {
        $instance = new self();
        foreach ($pairs as [$key, $element]) {
            $instance->set($key, $element);
        }

        return $instance;
    }

    public static function fromHttpValue(string $httpValue): self
    {
        $instance = new self();
        $httpValue = trim($httpValue, ' ');
        if ('' === $httpValue) {
            return $instance;
        }

        if (1 === preg_match("/[^\x20-\x7E\t]/", $httpValue) || str_starts_with($httpValue, "\t")) {
            throw new SyntaxError("The HTTP textual representation `$httpValue` for dictionary contains invalid characters.");
        }

        $parser = fn (string $element): Item|InnerList => str_starts_with($element, '(')
            ? InnerList::fromHttpValue($element)
            : Item::fromHttpValue($element);

        return array_reduce(explode(',', $httpValue), function (self $instance, string $element) use ($parser): self {
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
    private static function extractPair(string $pair): array
    {
        $pair = trim($pair);

        if ('' === $pair) {
            throw new SyntaxError('The HTTP textual representation for a dictionary pair can not be empty.');
        }

        if (1 !== preg_match('/^(?<key>[a-z*][a-z0-9.*_-]*)(=)?(?<value>.*)/', $pair, $found)) {
            throw new SyntaxError("The HTTP textual representation `$pair` for a dictionary pair contains invalid characters.");
        }

        if (rtrim($found['key']) !== $found['key'] || ltrim($found['value']) !== $found['value']) {
            throw new SyntaxError("The HTTP textual representation `$pair` for a dictionary pair contains invalid characters.");
        }

        $found['value'] = trim($found['value']);
        if ('' === $found['value'] || str_starts_with($found['value'], ';')) {
            $found['value'] = '?1'.$found['value'];
        }

        return [$found['key'], $found['value']];
    }

    public function toHttpValue(): string
    {
        $returnValue = [];
        foreach ($this->elements as $key => $element) {
            $returnValue[] = match (true) {
                $element instanceof Item && true === $element->value() => $key.$element->parameters()->toHttpValue(),
                default => $key.'='.$element->toHttpValue(),
            };
        }

        return implode(', ', $returnValue);
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function isEmpty(): bool
    {
        return [] === $this->elements;
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

    /**
     * @return Iterator<array{0:string, 1:Item|InnerList}>
     */
    public function toPairs(): Iterator
    {
        foreach ($this->elements as $index => $element) {
            yield [$index, $element];
        }
    }

    /**
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->elements);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->elements);
    }

    public function get(string $key): Item|InnerList
    {
        if (!array_key_exists($key, $this->elements)) {
            throw InvalidOffset::dueToKeyNotFound($key);
        }

        return $this->elements[$key];
    }

    public function hasPair(int $index): bool
    {
        return null !== $this->filterIndex($index);
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

    /**
     * @return array{0:string, 1:Item|InnerList}
     */
    public function pair(int $index): array
    {
        $offset = $this->filterIndex($index);
        if (null === $offset) {
            throw InvalidOffset::dueToIndexNotFound($index);
        }

        return [
            array_keys($this->elements)[$offset],
            array_values($this->elements)[$offset],
        ];
    }

    public function set(string $key, InnerList|Item|ByteSequence|Token|bool|int|float|string $element): void
    {
        self::validateKey($key);

        $this->elements[$key] = self::filterElement($element);
    }

    private static function validateKey(string $key): void
    {
        if (1 !== preg_match('/^[a-z*][a-z0-9.*_-]*$/', $key)) {
            throw new SyntaxError("The dictionary key `$key` contains invalid characters.");
        }
    }

    private static function filterElement(InnerList|Item|ByteSequence|Token|bool|int|float|string $element): InnerList|Item
    {
        return match (true) {
            $element instanceof InnerList, $element instanceof Item => $element,
            default => Item::from($element),
        };
    }

    public function delete(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->elements[$key]);
        }
    }

    public function clear(): void
    {
        $this->elements = [];
    }

    public function append(string $key, InnerList|Item|ByteSequence|Token|bool|int|float|string $element): void
    {
        self::validateKey($key);

        unset($this->elements[$key]);

        $this->elements[$key] = self::filterElement($element);
    }

    public function prepend(string $key, InnerList|Item|ByteSequence|Token|bool|int|float|string $element): void
    {
        self::validateKey($key);

        unset($this->elements[$key]);

        $this->elements = [...[$key => self::filterElement($element)], ...$this->elements];
    }

    public function merge(self ...$others): void
    {
        foreach ($others as $other) {
            $this->elements = [...$this->elements, ...$other->elements];
        }
    }
}
