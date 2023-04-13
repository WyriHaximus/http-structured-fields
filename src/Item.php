<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Stringable;
use function count;
use function preg_match;
use function str_contains;
use function strlen;
use function substr;
use function trim;

/**
 * @see https://www.rfc-editor.org/rfc/rfc8941.html#section-3.3
 *
 * @phpstan-import-type SfItem from StructuredField
 * @phpstan-import-type SfItemInput from StructuredField
 */
final class Item implements ParameterAccess, ValueAccess
{
    private function __construct(
        private readonly Value $value,
        private readonly Parameters $parameters
    ) {
    }

    /**
     * Returns a new instance from an HTTP Header or Trailer value string
     * in compliance with RFC8941.
     *
     * @see https://www.rfc-editor.org/rfc/rfc8941.html#section-3.3
     *
     * @throws SyntaxError If the HTTP value can not be parsed
     */
    public static function fromHttpValue(Stringable|string $httpValue): self
    {
        $itemString = trim((string) $httpValue, ' ');
        if ('' === $itemString || 1 === preg_match("/[\r\t\n]|[^\x20-\x7E]/", $itemString)) {
            throw new SyntaxError('The HTTP textual representation "'.$httpValue.'" for an item contains invalid characters.');
        }

        [$value, $offset] = Parser::parseBareItem($itemString);
        if (!str_contains($itemString, ';') && $offset !== strlen($itemString)) {
            throw new SyntaxError('The HTTP textual representation "'.$httpValue.'" for an item contains invalid characters.');
        }

        return new self(new Value($value), Parameters::fromHttpValue(substr($itemString, $offset)));
    }

    /**
     * Returns a new instance from a value type and an iterable of key-value parameters.
     *
     * @param iterable<string, SfItemInput> $parameters
     *
     * @throws SyntaxError If the value or the parameters are not valid
     */
    public static function fromAssociative(DateTimeInterface|ByteSequence|Token|string|int|float|bool $value, iterable $parameters): self
    {
        if (!$parameters instanceof Parameters) {
            $parameters = Parameters::fromAssociative($parameters);
        }

        return new self(new Value($value), $parameters);
    }

    /**
     * @param array{
     *     0:DateTimeInterface|ByteSequence|Token|string|int|float|bool,
     *     1:MemberOrderedMap<string, SfItem>|iterable<array{0:string, 1:SfItemInput}>
     * } $pair
     *
     * @throws SyntaxError If the pair or its content is not valid.
     */
    public static function fromPair(array $pair): self
    {
        return match (true) {
            !array_is_list($pair) => throw new SyntaxError('The pair must be represented by an array as a list.'), /* @phpstan-ignore-line */
            2 !== count($pair) => throw new SyntaxError('The pair first member is the item value; its second member is the item parameters.'), /* @phpstan-ignore-line */
            default => new self(new Value($pair[0]), $pair[1] instanceof Parameters ? $pair[1] : Parameters::fromPairs($pair[1])),
        };
    }

    /**
     * Returns a new bare instance from value.
     *
     * @throws SyntaxError If the value is not valid.
     */
    public static function new(DateTimeInterface|ByteSequence|Token|string|int|float|bool $value): self
    {
        return self::fromValue(new Value($value));
    }

    /**
     * Returns a new bare instance from value.
     */
    private static function fromValue(Value $value): self
    {
        return new self($value, Parameters::new());
    }

    /**
     * Returns a new instance from a string.
     *
     * @throws SyntaxError if the string is invalid
     */
    public static function fromString(Stringable|string $value): self
    {
        return self::fromValue(Value::fromString($value));
    }

    /**
     * Returns a new instance from an encoded byte sequence and an iterable of key-value parameters.
     *
     * @throws SyntaxError if the sequence is invalid
     */
    public static function fromEncodedByteSequence(Stringable|string $value): self
    {
        return self::fromValue(Value::fromEncodedByteSequence($value));
    }

    /**
     * Returns a new instance from a decoded byte sequence and an iterable of key-value parameters.
     *
     * @throws SyntaxError if the sequence is invalid
     */
    public static function fromDecodedByteSequence(Stringable|string $value): self
    {
        return self::fromValue(Value::fromDecodedByteSequence($value));
    }

    /**
     * Returns a new instance from a Token and an iterable of key-value parameters.
     *
     * @throws SyntaxError if the token is invalid
     */
    public static function fromToken(Stringable|string $value): self
    {
        return self::fromValue(Value::fromToken($value));
    }

    /**
     * Returns a new instance from a timestamp and an iterable of key-value parameters.
     *
     * @throws SyntaxError if the timestamp value is not supported
     */
    public static function fromTimestamp(int $timestamp): self
    {
        return self::fromValue(Value::fromTimestamp($timestamp));
    }

    /**
     * Returns a new instance from a date format its date string representation and an iterable of key-value parameters.
     *
     * @throws SyntaxError if the format is invalid
     */
    public static function fromDateFormat(string $format, string $datetime): self
    {
        return self::fromValue(Value::fromDateFormat($format, $datetime));
    }

    /**
     * Returns a new instance from a string parsable by DateTimeImmutable constructor, an optional timezone and an iterable of key-value parameters.
     *
     * @throws SyntaxError if the format is invalid
     */
    public static function fromDateString(string $datetime, DateTimeZone|string|null $timezone = null): self
    {
        return self::fromValue(Value::fromDateString($datetime, $timezone));
    }

    /**
     * Returns a new instance from a DateTineInterface implementing object.
     *
     * @throws SyntaxError if the format is invalid
     */
    public static function fromDate(DateTimeInterface $datetime): self
    {
        return self::fromValue(Value::fromDate($datetime));
    }

    /**
     * Returns a new instance from a float value.
     *
     * @throws SyntaxError if the format is invalid
     */
    public static function fromDecimal(int|float $value): self
    {
        return self::fromValue(Value::fromDecimal($value));
    }

    /**
     * Returns a new instance from an integer value.
     *
     * @throws SyntaxError if the format is invalid
     */
    public static function fromInteger(int|float $value): self
    {
        return self::fromValue(Value::fromInteger($value));
    }

    /**
     * Returns a new instance for the boolean true type.
     */
    public static function true(): self
    {
        return self::fromValue(Value::true());
    }

    /**
     * Returns a new instance for the boolean false type.
     */
    public static function false(): self
    {
        return self::fromValue(Value::false());
    }

    public function value(): ByteSequence|Token|DateTimeImmutable|string|int|float|bool
    {
        return $this->value->value;
    }

    public function type(): Type
    {
        return $this->value->type;
    }

    public function parameters(): Parameters
    {
        return $this->parameters;
    }

    public function parameter(string $key): Token|ByteSequence|DateTimeImmutable|int|float|string|bool|null
    {
        try {
            return $this->parameters->get($key)->value();
        } catch (StructuredFieldError) {
            return null;
        }
    }

    /**
     * Serialize the Item value according to RFC8941.
     *
     * @see https://www.rfc-editor.org/rfc/rfc8941.html#section-4.1
     */
    public function toHttpValue(): string
    {
        return $this->value->serialize().$this->parameters->toHttpValue();
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }

    /**
     * @return array{0:SfItemInput, 1:MemberOrderedMap<string, SfItem>}
     */
    public function toPair(): array
    {
        return [$this->value->value, $this->parameters];
    }

    public function withValue(DateTimeInterface|ByteSequence|Token|string|int|float|bool $value): static
    {
        $value = new Value($value);
        if ($value->equals($this->value)) {
            return $this;
        }

        return new self($value, $this->parameters);
    }

    public function withParameters(Parameters $parameters): static
    {
        return $this->parameters->toHttpValue() === $parameters->toHttpValue() ? $this : new self($this->value, $parameters);
    }

    public function addParameter(string $key, StructuredField|Token|ByteSequence|DateTimeInterface|string|int|float|bool $member): static
    {
        return $this->withParameters($this->parameters()->add($key, $member));
    }

    public function prependParameter(string $key, StructuredField|Token|ByteSequence|DateTimeInterface|string|int|float|bool $member): static
    {
        return $this->withParameters($this->parameters()->prepend($key, $member));
    }

    public function appendParameter(string $key, StructuredField|Token|ByteSequence|DateTimeInterface|string|int|float|bool $member): static
    {
        return $this->withParameters($this->parameters()->append($key, $member));
    }

    public function withoutParameter(string ...$keys): static
    {
        return $this->withParameters($this->parameters()->remove(...$keys));
    }

    public function withoutAnyParameter(): static
    {
        return $this->withParameters(Parameters::new());
    }
}
