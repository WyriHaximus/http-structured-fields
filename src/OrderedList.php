<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use DateTimeInterface;
use Iterator;
use Stringable;
use function array_filter;
use function array_map;
use function array_splice;
use function array_values;
use function count;
use function implode;
use function is_array;

/**
 * @implements MemberList<int, Value|InnerList<int, Value>>
 * @phpstan-import-type DataType from Item
 */
final class OrderedList implements MemberList
{
    /** @var list<Value|InnerList<int, Value>> */
    private array $members;

    private function __construct(InnerList|Value ...$members)
    {
        $this->members = array_values($members);
    }

    public static function from(InnerList|Value|Token|ByteSequence|DateTimeInterface|Stringable|string|int|float|bool ...$members): self
    {
        return new self(...array_map(self::filterMember(...), $members));
    }

    /**
     * @param iterable<InnerList<int, Value>|Value|DataType> $members
     */
    public static function fromList(iterable $members = []): self
    {
        return new self(...array_map(self::filterMember(...), [...$members]));
    }

    private static function filterMember(StructuredField|Token|ByteSequence|DateTimeInterface|Stringable|string|int|float|bool $member): InnerList|Value
    {
        return match (true) {
            $member instanceof InnerList, $member instanceof Value => $member,
            $member instanceof StructuredField => throw new InvalidArgument('Expecting a "'.Value::class.'" or a "'.InnerList::class.'" instance; received a "'.$member::class.'" instead.'),
            default => Item::from($member),
        };
    }

    /**
     * Returns an instance from an HTTP textual representation.
     *
     * @see https://www.rfc-editor.org/rfc/rfc8941.html#section-3.1
     */
    public static function fromHttpValue(Stringable|string $httpValue): self
    {
        return self::from(...array_map(
            fn ($value) => is_array($value) ? InnerList::fromList(...$value) : $value,
            Parser::parseList($httpValue)
        ));
    }

    public function toHttpValue(): string
    {
        return implode(', ', array_map(fn (StructuredField $member): string => $member->toHttpValue(), $this->members));
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function hasNoMembers(): bool
    {
        return !$this->hasMembers();
    }

    public function hasMembers(): bool
    {
        return [] !== $this->members;
    }

    /**
     * @return Iterator<int, Value|InnerList<int, Value>>
     */
    public function getIterator(): Iterator
    {
        yield from $this->members;
    }

    public function has(string|int $offset): bool
    {
        return null !== $this->filterIndex($offset);
    }

    private function filterIndex(int|string $index): int|null
    {
        $max = count($this->members);
        if (!is_int($index)) {
            return null;
        }

        return match (true) {
            [] === $this->members,
            0 > $max + $index,
            0 > $max - $index - 1 => null,
            0 > $index => $max + $index,
            default => $index,
        };
    }

    public function get(string|int $offset): Value|InnerList
    {
        if (!is_int($offset)) {
            throw InvalidOffset::dueToIndexNotFound($offset);
        }

        $index = $this->filterIndex($offset);
        if (null === $index) {
            throw InvalidOffset::dueToIndexNotFound($offset);
        }

        return $this->members[$index];
    }

    /**
     * Inserts members at the beginning of the list.
     */
    public function unshift(StructuredField|Token|ByteSequence|DateTimeInterface|Stringable|string|int|float|bool ...$members): static
    {
        $this->members = [...array_map(self::filterMember(...), array_values($members)), ...$this->members];

        return $this;
    }

    /**
     * Inserts members at the end of the list.
     */
    public function push(StructuredField|Token|ByteSequence|DateTimeInterface|Stringable|string|int|float|bool ...$members): static
    {
        $this->members = [...$this->members, ...array_map(self::filterMember(...), array_values($members))];

        return $this;
    }

    /**
     * Inserts members starting at the given index.
     *
     * @throws InvalidOffset If the index does not exist
     */
    public function insert(int $index, StructuredField|Token|ByteSequence|DateTimeInterface|Stringable|string|int|float|bool ...$members): static
    {
        $offset = $this->filterIndex($index);
        match (true) {
            null === $offset => throw InvalidOffset::dueToIndexNotFound($index),
            0 === $offset => $this->unshift(...$members),
            count($this->members) === $offset => $this->push(...$members),
            default => array_splice($this->members, $offset, 0, array_map(self::filterMember(...), $members)),
        };

        return $this;
    }

    /**
     * Replaces the member associated with the index.
     *
     * @throws InvalidOffset If the index does not exist
     */
    public function replace(int $index, StructuredField|Token|ByteSequence|DateTimeInterface|Stringable|string|int|float|bool $member): static
    {
        if (null === ($offset = $this->filterIndex($index))) {
            throw InvalidOffset::dueToIndexNotFound($index);
        }

        $this->members[$offset] = self::filterMember($member);

        return $this;
    }

    /**
     * Deletes members associated with the list of instance indexes.
     */
    public function remove(int ...$indexes): static
    {
        $offsets = array_filter(
            array_map(fn (int $index): int|null => $this->filterIndex($index), $indexes),
            fn (int|null $index): bool => null !== $index
        );

        foreach ($offsets as $offset) {
            unset($this->members[$offset]);
        }

        return $this;
    }

    public function clear(): static
    {
        $this->members = [];

        return $this;
    }

    /**
     * @param int $offset
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * @param int $offset
     *
     * @return Value|InnerList<int, Value>
     */
    public function offsetGet(mixed $offset): Value|InnerList
    {
        return $this->get($offset);
    }

    public function offsetUnset($offset): void
    {
        $this->remove($offset);
    }

    /**
     * @param int|null $offset
     * @param InnerList<int, Value>|Value|DataType $value
     *
     * @see ::push
     * @see ::replace
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null !== $offset) {
            $this->replace($offset, $value);

            return;
        }

        $this->push($value);
    }
}
