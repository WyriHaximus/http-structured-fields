<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use ArrayAccess;
use Countable;
use Iterator;
use IteratorAggregate;

/**
 * @template TKey
 * @template TValue of StructuredField
 * @template-extends IteratorAggregate<TKey, TValue>
 * @template-extends ArrayAccess<TKey, TValue>
 */
interface MemberContainer extends Countable, ArrayAccess, IteratorAggregate, StructuredField
{
    /**
     * Tells whether the instance contains no members.
     */
    public function hasNoMembers(): bool;

    /**
     * Tells whether the instance contains members.
     */
    public function hasMembers(): bool;

    /**
     * Removes all members from the instance.
     */
    public function clear(): static;

    /**
     * @return Iterator<TKey, TValue>
     */
    public function getIterator(): Iterator;

    /**
     * @return TValue
     */
    public function get(string|int $offset): StructuredField;

    public function has(string|int $offset): bool;

    /**
     * @param TKey $offset
     */
    public function offsetExists(mixed $offset): bool;

    /**
     * @param TKey $offset
     */
    public function offsetGet(mixed $offset): mixed;

    /**
     * @param TKey|null $offset
     * @param TValue $value
     */
    public function offsetSet(mixed $offset, mixed $value): void;

    /**
     * @param TKey $offset
     */
    public function offsetUnset(mixed $offset): void;
}
