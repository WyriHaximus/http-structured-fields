<?php

declare(strict_types=1);

namespace Bakame\Http\StructuredFields;

final class Item implements StructuredField, SupportsParameters
{
    private function __construct(
        private Token|ByteSequence|int|float|string|bool $value,
        private Parameters $parameters,
    ) {
    }

    /**
     * @param iterable<string,Item|ByteSequence|Token|bool|int|float|string> $parameters
     */
    public static function from(
        Token|ByteSequence|int|float|string|bool $value,
        iterable $parameters = [],
    ): self {
        return new self(match (true) {
            is_integer($value) => self::filterInteger($value),
            is_float($value) => self::filterDecimal($value),
            is_string($value) => self::filterString($value),
            default => $value,
        }, $parameters instanceof Parameters ? $parameters : Parameters::fromAssociative($parameters));
    }

    public static function filterDecimal(float $value): float
    {
        if (abs(floor($value)) > 999_999_999_999) {
            throw new SyntaxError('Integer portion of decimals is limited to 12 digits');
        }

        return $value;
    }

    public static function filterString(string $value): string
    {
        if (1 === preg_match('/[^\x20-\x7E]/i', $value)) {
            throw new SyntaxError('The string `'.$value.'` contains invalid characters.');
        }

        return $value;
    }

    private static function filterInteger(int $value): int
    {
        if ($value > 999_999_999_999_999 || $value < -999_999_999_999_999) {
            throw new SyntaxError('Integers are limited to 15 digits');
        }

        return $value;
    }

    public static function fromHttpValue(string $httpValue): self
    {
        $httpValue = trim($httpValue, ' ');
        [$value, $parameters] = match (true) {
            $httpValue === '',
            1 === preg_match("/[\r\t\n]/", $httpValue),
            1 === preg_match("/[^\x20-\x7E]/", $httpValue) => throw new SyntaxError("The HTTP textual representation `$httpValue` for an item contains invalid characters."),
            1 === preg_match('/^(-?[0-9])/', $httpValue) => self::parseNumber($httpValue),
            $httpValue[0] == '"' => self::parseString($httpValue),
            $httpValue[0] == ':' => self::parseBytesSequence($httpValue),
            $httpValue[0] == '?' => self::parseBoolean($httpValue),
            1 === preg_match('/^([a-z*])/i', $httpValue) => self::parseToken($httpValue),
            default => throw new SyntaxError("The HTTP textual representation `$httpValue` for an item is unknown or unsupported."),
        };

        return new self($value, Parameters::fromHttpValue($parameters));
    }

    /**
     * @return array{0:Token, 1:string}
     */
    private static function parseToken(string $string): array
    {
        $regexp = "^(?<token>[a-z*][a-z0-9:\/\!\#\$%&'\*\+\-\.\^_`\|~]*)";
        if (!str_contains($string, ';')) {
            $regexp .= '$';
        }

        if (1 !== preg_match('/'.$regexp.'/i', $string, $matches)) {
            throw new SyntaxError("The HTTP textual representation `$string` for a token contains invalid characters.");
        }

        return [
            new Token($matches['token']),
            substr($string, strlen($matches['token'])),
        ];
    }

    /**
     * @return array{0:bool, 1:string}
     */
    private static function parseBoolean(string $string): array
    {
        if (1 !== preg_match('/^\?[01]/', $string)) {
            throw new SyntaxError("The HTTP textual representation `$string` for a boolean contains invalid characters.");
        }

        return [$string[1] === '1', substr($string, 2)];
    }

    /**
     * @return array{0:ByteSequence, 1:string}
     */
    private static function parseBytesSequence(string $string): array
    {
        if (1 !== preg_match('/^:(?<bytes>[a-z0-9+\/=]*):/i', $string, $matches)) {
            throw new SyntaxError("The HTTP textual representation `$string` for a byte sequence contains invalid characters.");
        }

        return [ByteSequence::fromEncoded($matches['bytes']), substr($string, strlen($matches[0]))];
    }

    /**
     * @return array{0:int|float, 1:string}
     */
    private static function parseNumber(string $string): array
    {
        if (1 !== preg_match('/^(?<number>-?\d+(?:\.\d+)?)(?:[^\d.]|$)/', $string, $found)) {
            throw new SyntaxError("The HTTP textual representation `$string` for a number contains invalid characters.");
        }

        $number = match (true) {
            1 === preg_match('/^-?\d{1,12}\.\d{1,3}$/', $found['number']) => (float) $found['number'],
            1 === preg_match('/^-?\d{1,15}$/', $found['number']) => (int) $found['number'],
            default => throw new SyntaxError("The HTTP textual representation `$string` for a number contain too many digits."),
        };

        return [$number, substr($string, strlen($found['number']))];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private static function parseString(string $string): array
    {
        $originalString = $string;
        $string = substr($string, 1);
        $returnValue = '';

        while (strlen($string)) {
            $char = $string[0];
            $string = substr($string, 1);

            if ($char === '"') {
                return [$returnValue, $string];
            }

            if ($char !== '\\') {
                $returnValue .= $char;
                continue;
            }

            if ($string === '') {
                throw new SyntaxError("The HTTP textual representation `$originalString` for a string contains an invalid end string.");
            }

            $char = $string[0];
            $string = substr($string, 1);
            if (!in_array($char, ['"', '\\'], true)) {
                throw new SyntaxError("The HTTP textual representation `$originalString` for a string contains invalid characters.");
            }

            $returnValue .= $char;
        }

        throw new SyntaxError("The HTTP textual representation `$originalString` for a string contains an invalid end string.");
    }

    public function toHttpValue(): string
    {
        return $this->serializeValue($this->value).$this->parameters->toHttpValue();
    }

    private function serializeValue(Token|ByteSequence|int|float|string|bool $value): string
    {
        return match (true) {
            $value instanceof Token => $value->toHttpValue(),
            $value instanceof ByteSequence => $value->toHttpValue(),
            is_string($value) => '"'.preg_replace('/(["\\\])/', '\\\$1', $value).'"',
            is_int($value) => (string) $value,
            is_float($value) => $this->serializeDecimal($value),
            default => '?'.($value ? '1' : '0'),
        };
    }

    private function serializeDecimal(float $value): string
    {
        /** @var string $result */
        $result = json_encode(round($value, 3, PHP_ROUND_HALF_EVEN));
        if (str_contains($result, '.')) {
            return $result;
        }

        return $result.'.0';
    }

    public function value(): Token|ByteSequence|int|float|string|bool
    {
        return $this->value;
    }

    public function parameters(): Parameters
    {
        return $this->parameters;
    }

    public function isInteger(): bool
    {
        return is_int($this->value);
    }

    public function isDecimal(): bool
    {
        return is_float($this->value);
    }

    public function isBoolean(): bool
    {
        return is_bool($this->value);
    }

    public function isString(): bool
    {
        return is_string($this->value);
    }

    public function isToken(): bool
    {
        return $this->value instanceof Token;
    }

    public function isByteSequence(): bool
    {
        return $this->value instanceof ByteSequence;
    }
}
