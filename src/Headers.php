<?php

declare(strict_types=1);

/**
 *
 * @file Headers.php
 * @author Gaspard Kirira
 *
 * Copyright 2026, Gaspard Kirira.
 * All rights reserved.
 * https://github.com/iviphp/http
 *
 * Use of this source code is governed by an MIT license
 * that can be found in the LICENSE file.
 *
 * IviPHP
 *
 */

namespace Ivi\Http;

/**
 * @class Headers
 *
 * @brief Stores and manages HTTP headers.
 *
 * Header names are handled case-insensitively while preserving a normalized
 * representation for output.
 *
 * @since 0.1.0
 */
final class Headers
{
    /**
     * Stored headers indexed by lowercase name.
     *
     * @var array<string, array{name: string, values: array<int, string>}>
     */
    private array $headers = [];

    /**
     * @param array<string, string|array<int, string>> $headers
     * Initial headers.
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->set($name, $value);
        }
    }

    /**
     * @brief Determine whether a header exists.
     *
     * @param string $name Header name.
     *
     * @return bool True when the header exists.
     */
    public function has(string $name): bool
    {
        return isset($this->headers[$this->normalizeKey($name)]);
    }

    /**
     * @brief Retrieve the first value of a header.
     *
     * @param string $name Header name.
     * @param string|null $default Default value.
     *
     * @return string|null The first header value or the default value.
     */
    public function get(string $name, ?string $default = null): ?string
    {
        $values = $this->getAll($name);

        return $values[0] ?? $default;
    }

    /**
     * @brief Retrieve all values of a header.
     *
     * @param string $name Header name.
     *
     * @return array<int, string>
     */
    public function getAll(string $name): array
    {
        $key = $this->normalizeKey($name);

        return $this->headers[$key]['values'] ?? [];
    }

    /**
     * @brief Set a header value.
     *
     * Existing values are replaced.
     *
     * @param string $name Header name.
     * @param string|array<int, string> $value Header value or values.
     *
     * @return void
     */
    public function set(string $name, string|array $value): void
    {
        $this->assertValidName($name);

        $values = is_array($value) ? $value : [$value];

        foreach ($values as $headerValue) {
            $this->assertValidValue($headerValue);
        }

        $key = $this->normalizeKey($name);

        $this->headers[$key] = [
            'name' => $this->normalizeName($name),
            'values' => array_values($values),
        ];
    }

    /**
     * @brief Append a value to a header.
     *
     * @param string $name Header name.
     * @param string $value Header value.
     *
     * @return void
     */
    public function add(string $name, string $value): void
    {
        $this->assertValidName($name);
        $this->assertValidValue($value);

        $key = $this->normalizeKey($name);

        if (!isset($this->headers[$key])) {
            $this->headers[$key] = [
                'name' => $this->normalizeName($name),
                'values' => [],
            ];
        }

        $this->headers[$key]['values'][] = $value;
    }

    /**
     * @brief Remove a header.
     *
     * @param string $name Header name.
     *
     * @return void
     */
    public function remove(string $name): void
    {
        unset($this->headers[$this->normalizeKey($name)]);
    }

    /**
     * @brief Return all headers.
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        $headers = [];

        foreach ($this->headers as $header) {
            $headers[$header['name']] = $header['values'];
        }

        return $headers;
    }

    /**
     * @brief Return flattened headers.
     *
     * Multiple values are joined using a comma and a space.
     *
     * @return array<string, string>
     */
    public function flatten(): array
    {
        $headers = [];

        foreach ($this->headers as $header) {
            $headers[$header['name']] = implode(', ', $header['values']);
        }

        return $headers;
    }

    /**
     * @brief Remove all headers.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->headers = [];
    }

    /**
     * @brief Create a copy with a replaced header.
     *
     * @param string $name Header name.
     * @param string|array<int, string> $value Header value or values.
     *
     * @return self
     */
    public function with(string $name, string|array $value): self
    {
        $clone = clone $this;
        $clone->set($name, $value);

        return $clone;
    }

    /**
     * @brief Create a copy without a header.
     *
     * @param string $name Header name.
     *
     * @return self
     */
    public function without(string $name): self
    {
        $clone = clone $this;
        $clone->remove($name);

        return $clone;
    }

    /**
     * @param string $name Header name.
     *
     * @return string
     */
    private function normalizeKey(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * @param string $name Header name.
     *
     * @return string
     */
    private function normalizeName(string $name): string
    {
        return implode(
            '-',
            array_map(
                static fn(string $part): string => ucfirst(strtolower($part)),
                explode('-', trim($name))
            )
        );
    }

    /**
     * @param string $name Header name.
     *
     * @return void
     */
    private function assertValidName(string $name): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException(
                'An HTTP header name cannot be empty.'
            );
        }

        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid HTTP header name: {$name}"
            );
        }
    }

    /**
     * @param string $value Header value.
     *
     * @return void
     */
    private function assertValidValue(string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new \InvalidArgumentException(
                'HTTP header values cannot contain line breaks.'
            );
        }
    }
}
