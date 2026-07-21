<?php

declare(strict_types=1);

/**
 *
 * @file Request.php
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

use Ivi\Support\Arr;

/**
 * @class Request
 *
 * @brief Represents an incoming HTTP request.
 *
 * Request provides access to the HTTP method, URI, headers, query
 * parameters, parsed body, JSON payload, cookies, uploaded files,
 * server values and middleware attributes.
 *
 * @since 0.1.0
 */
final class Request
{
    /**
     * HTTP method.
     */
    private string $method;

    /**
     * Request URI.
     */
    private string $uri;

    /**
     * Request headers.
     */
    private Headers $headers;

    /**
     * Query-string parameters.
     *
     * @var array<string|int, mixed>
     */
    private array $query;

    /**
     * Parsed request body.
     */
    private mixed $parsedBody;

    /**
     * Cookies received with the request.
     *
     * @var array<string, mixed>
     */
    private array $cookies;

    /**
     * Uploaded files.
     *
     * @var array<string|int, UploadedFile|array<mixed>>
     */
    private array $uploadedFiles;

    /**
     * Server and execution environment values.
     *
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * Raw request body.
     */
    private string $rawBody;

    /**
     * Request attributes used by middleware and routing.
     *
     * @var array<string, mixed>
     */
    private array $attributes;

    /**
     * @param string                                      $method
     * @param string                                      $uri
     * @param Headers|array<string, string|array<int, string>> $headers
     * @param array<string|int, mixed>                    $query
     * @param mixed                                       $parsedBody
     * @param array<string, mixed>                        $cookies
     * @param array<string|int, UploadedFile|array<mixed>> $uploadedFiles
     * @param array<string, mixed>                        $server
     * @param string                                      $rawBody
     * @param array<string, mixed>                        $attributes
     */
    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        Headers|array $headers = [],
        array $query = [],
        mixed $parsedBody = null,
        array $cookies = [],
        array $uploadedFiles = [],
        array $server = [],
        string $rawBody = '',
        array $attributes = []
    ) {
        $this->method = self::normalizeMethod($method);
        $this->uri = self::validateUri($uri);
        $this->headers = $headers instanceof Headers
            ? clone $headers
            : new Headers($headers);

        $this->query = $query;
        $this->parsedBody = $parsedBody;
        $this->cookies = $cookies;
        $this->uploadedFiles = self::normalizeUploadedFiles($uploadedFiles);
        $this->server = $server;
        $this->rawBody = $rawBody;
        $this->attributes = $attributes;
    }

    /**
     * @brief Create a request from PHP superglobals.
     *
     * Optional parameters make this method testable without modifying
     * the real PHP superglobals.
     *
     * @param array<string, mixed>|null $server
     * @param array<string|int, mixed>|null $query
     * @param array<string|int, mixed>|null $post
     * @param array<string, mixed>|null $cookies
     * @param array<string, mixed>|null $files
     * @param string|null $rawBody
     *
     * @return self
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $query = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $files = null,
        ?string $rawBody = null
    ): self {
        $server ??= $_SERVER;
        $query ??= $_GET;
        $post ??= $_POST;
        $cookies ??= $_COOKIE;
        $files ??= $_FILES;

        if ($rawBody === null) {
            $contents = file_get_contents('php://input');
            $rawBody = $contents === false ? '' : $contents;
        }

        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $uri = $server['REQUEST_URI'] ?? '/';

        if (!is_string($method)) {
            $method = 'GET';
        }

        if (!is_string($uri)) {
            $uri = '/';
        }

        return new self(
            method: $method,
            uri: $uri,
            headers: self::headersFromServer($server),
            query: $query,
            parsedBody: $post === [] ? null : $post,
            cookies: $cookies,
            uploadedFiles: $files,
            server: $server,
            rawBody: $rawBody
        );
    }

    /**
     * @brief Return the HTTP method.
     *
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * @brief Determine whether the request uses a specific method.
     *
     * @param string $method HTTP method.
     *
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return $this->method === self::normalizeMethod($method);
    }

    /**
     * @brief Return the complete request URI.
     *
     * @return string
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * @brief Return the URI path.
     *
     * The path remains encoded exactly as received. Automatic URL
     * decoding is avoided because encoded path separators may have
     * security-sensitive meaning.
     *
     * @return string
     */
    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '/';
        }

        return $path[0] === '/' ? $path : '/' . $path;
    }

    /**
     * @brief Return the query-string portion of the URI.
     *
     * @return string
     */
    public function queryString(): string
    {
        $query = parse_url($this->uri, PHP_URL_QUERY);

        return is_string($query) ? $query : '';
    }

    /**
     * @brief Return a copy of the request headers.
     *
     * @return Headers
     */
    public function headers(): Headers
    {
        return clone $this->headers;
    }

    /**
     * @brief Determine whether a header exists.
     *
     * @param string $name Header name.
     *
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /**
     * @brief Return the first value of a header.
     *
     * @param string $name Header name.
     * @param string|null $default Default value.
     *
     * @return string|null
     */
    public function header(
        string $name,
        ?string $default = null
    ): ?string {
        return $this->headers->get($name, $default);
    }

    /**
     * @brief Return all values associated with a header.
     *
     * @param string $name Header name.
     *
     * @return array<int, string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers->getAll($name);
    }

    /**
     * @brief Return the normalized request content type.
     *
     * Parameters such as charset are removed.
     *
     * @return string|null
     */
    public function contentType(): ?string
    {
        $header = $this->header('Content-Type');

        if ($header === null || trim($header) === '') {
            return null;
        }

        $contentType = explode(';', $header, 2)[0];

        return strtolower(trim($contentType));
    }

    /**
     * @brief Determine whether the request contains JSON.
     *
     * Standard application/json and structured +json media types
     * are supported.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $contentType = $this->contentType();

        if ($contentType === null) {
            return false;
        }

        return $contentType === 'application/json'
            || str_ends_with($contentType, '+json');
    }

    /**
     * @brief Return the request content length.
     *
     * @return int|null
     */
    public function contentLength(): ?int
    {
        $value = $this->header('Content-Length');

        if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $length = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        return is_int($length) ? $length : null;
    }

    /**
     * @brief Return a bearer authentication token.
     *
     * @return string|null
     */
    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization');

        if ($authorization === null) {
            return null;
        }

        if (
            preg_match(
                '/^\s*Bearer\s+([^\s]+)\s*$/i',
                $authorization,
                $matches
            ) !== 1
        ) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @brief Return all query parameters.
     *
     * @return array<string|int, mixed>
     */
    public function queryParameters(): array
    {
        return $this->query;
    }

    /**
     * @brief Retrieve a query parameter using dot notation.
     *
     * @param string|int|null $key Query key.
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    public function query(
        string|int|null $key = null,
        mixed $default = null
    ): mixed {
        return Arr::get($this->query, $key, $default);
    }

    /**
     * @brief Determine whether a query parameter exists.
     *
     * @param string|int $key Query key.
     *
     * @return bool
     */
    public function hasQuery(string|int $key): bool
    {
        return Arr::has($this->query, $key);
    }

    /**
     * @brief Return the parsed request body.
     *
     * @return mixed
     */
    public function parsedBody(): mixed
    {
        return $this->parsedBody;
    }

    /**
     * @brief Retrieve a value from the parsed request body.
     *
     * Dot notation is supported when the parsed body is an array.
     *
     * @param string|int|null $key Body key.
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    public function bodyParameter(
        string|int|null $key = null,
        mixed $default = null
    ): mixed {
        if (!is_array($this->parsedBody)) {
            return $key === null
                ? $this->parsedBody
                : $default;
        }

        return Arr::get($this->parsedBody, $key, $default);
    }

    /**
     * @brief Return the raw request body.
     *
     * @return string
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @brief Decode the JSON request body.
     *
     * @param string|int|null $key Optional key using dot notation.
     * @param mixed $default Default value for an empty body or missing key.
     * @param bool $associative Decode objects as associative arrays.
     * @param int $depth Maximum decoding depth.
     *
     * @return mixed
     */
    public function json(
        string|int|null $key = null,
        mixed $default = null,
        bool $associative = true,
        int $depth = 512
    ): mixed {
        if ($depth < 1) {
            throw new \InvalidArgumentException(
                'The JSON decoding depth must be greater than zero.'
            );
        }

        if (trim($this->rawBody) === '') {
            return $default;
        }

        try {
            $decoded = json_decode(
                $this->rawBody,
                associative: $associative,
                depth: $depth,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'The request body contains invalid JSON: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($key === null) {
            return $decoded;
        }

        if (!is_array($decoded)) {
            return $default;
        }

        return Arr::get($decoded, $key, $default);
    }

    /**
     * @brief Return combined query and body input.
     *
     * Parsed body values override query values. When the parsed body is
     * absent and the request contains JSON, an associative JSON object
     * is used as the body input.
     *
     * @return array<string|int, mixed>
     */
    public function allInput(): array
    {
        $body = $this->bodyInput();

        return array_replace_recursive(
            $this->query,
            $body
        );
    }

    /**
     * @brief Retrieve a combined input value using dot notation.
     *
     * @param string|int|null $key Input key.
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    public function input(
        string|int|null $key = null,
        mixed $default = null
    ): mixed {
        return Arr::get(
            $this->allInput(),
            $key,
            $default
        );
    }

    /**
     * @brief Determine whether an input value exists.
     *
     * @param string|int $key Input key.
     *
     * @return bool
     */
    public function hasInput(string|int $key): bool
    {
        return Arr::has($this->allInput(), $key);
    }

    /**
     * @brief Return all cookies.
     *
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @brief Retrieve a cookie value.
     *
     * @param string $name Cookie name.
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    public function cookie(
        string $name,
        mixed $default = null
    ): mixed {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @brief Determine whether a cookie exists.
     *
     * @param string $name Cookie name.
     *
     * @return bool
     */
    public function hasCookie(string $name): bool
    {
        return array_key_exists($name, $this->cookies);
    }

    /**
     * @brief Return all uploaded files.
     *
     * @return array<string|int, UploadedFile|array<mixed>>
     */
    public function uploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @brief Retrieve an uploaded file using dot notation.
     *
     * @param string|int|null $key File key.
     *
     * @return UploadedFile|array<mixed>|null
     */
    public function file(
        string|int|null $key = null
    ): UploadedFile|array|null {
        $value = Arr::get(
            $this->uploadedFiles,
            $key
        );

        return $value instanceof UploadedFile || is_array($value)
            ? $value
            : null;
    }

    /**
     * @brief Determine whether an uploaded file exists.
     *
     * A file with an upload error is still present but is not considered
     * a valid successful upload.
     *
     * @param string|int $key File key.
     *
     * @return bool
     */
    public function hasFile(string|int $key): bool
    {
        $file = $this->file($key);

        if ($file instanceof UploadedFile) {
            return $file->error() !== UPLOAD_ERR_NO_FILE;
        }

        return is_array($file) && $file !== [];
    }

    /**
     * @brief Return all server values.
     *
     * @return array<string, mixed>
     */
    public function serverParameters(): array
    {
        return $this->server;
    }

    /**
     * @brief Retrieve a server value.
     *
     * @param string $key Server key.
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    public function server(
        string $key,
        mixed $default = null
    ): mixed {
        return $this->server[$key] ?? $default;
    }

    /**
     * @brief Determine whether the direct request connection uses HTTPS.
     *
     * Forwarded proxy headers are intentionally not trusted here.
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? null;

        if (is_string($https)) {
            return $https !== ''
                && strtolower($https) !== 'off'
                && $https !== '0';
        }

        return $https === true || $https === 1;
    }

    /**
     * @brief Return all request attributes.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @brief Retrieve a request attribute.
     *
     * @param string $name Attribute name.
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    public function attribute(
        string $name,
        mixed $default = null
    ): mixed {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * @brief Determine whether an attribute exists.
     *
     * @param string $name Attribute name.
     *
     * @return bool
     */
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * @brief Create a copy with a different method.
     *
     * @param string $method HTTP method.
     *
     * @return self
     */
    public function withMethod(string $method): self
    {
        $clone = clone $this;
        $clone->method = self::normalizeMethod($method);

        return $clone;
    }

    /**
     * @brief Create a copy with a different URI.
     *
     * @param string $uri Request URI.
     *
     * @return self
     */
    public function withUri(string $uri): self
    {
        $clone = clone $this;
        $clone->uri = self::validateUri($uri);

        return $clone;
    }

    /**
     * @brief Create a copy with a replaced header.
     *
     * @param string $name Header name.
     * @param string|array<int, string> $value Header value.
     *
     * @return self
     */
    public function withHeader(
        string $name,
        string|array $value
    ): self {
        $clone = clone $this;
        $clone->headers->set($name, $value);

        return $clone;
    }

    /**
     * @brief Create a copy without a header.
     *
     * @param string $name Header name.
     *
     * @return self
     */
    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        $clone->headers->remove($name);

        return $clone;
    }

    /**
     * @brief Create a copy with a parsed body.
     *
     * @param mixed $parsedBody Parsed body.
     *
     * @return self
     */
    public function withParsedBody(mixed $parsedBody): self
    {
        $clone = clone $this;
        $clone->parsedBody = $parsedBody;

        return $clone;
    }

    /**
     * @brief Create a copy with a request attribute.
     *
     * @param string $name Attribute name.
     * @param mixed $value Attribute value.
     *
     * @return self
     */
    public function withAttribute(
        string $name,
        mixed $value
    ): self {
        if ($name === '') {
            throw new \InvalidArgumentException(
                'The request attribute name cannot be empty.'
            );
        }

        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    /**
     * @brief Create a copy without a request attribute.
     *
     * @param string $name Attribute name.
     *
     * @return self
     */
    public function withoutAttribute(string $name): self
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    /**
     * Clone mutable request values.
     */
    public function __clone()
    {
        $this->headers = clone $this->headers;
    }

    /**
     * @return array<string|int, mixed>
     */
    private function bodyInput(): array
    {
        if (is_array($this->parsedBody)) {
            return $this->parsedBody;
        }

        if (!$this->isJson() || trim($this->rawBody) === '') {
            return [];
        }

        $decoded = $this->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string|array<int, string>>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (
                !is_string($value)
                && !is_int($value)
                && !is_float($value)
            ) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif (
                $key === 'CONTENT_TYPE'
                || $key === 'CONTENT_LENGTH'
                || $key === 'CONTENT_MD5'
            ) {
                $name = $key;
            } else {
                continue;
            }

            $name = str_replace('_', '-', strtolower($name));
            $name = implode(
                '-',
                array_map(
                    static fn(string $part): string => ucfirst($part),
                    explode('-', $name)
                )
            );

            $headers[$name] = (string) $value;
        }

        return $headers;
    }

    /**
     * @param array<string|int, mixed> $files
     *
     * @return array<string|int, UploadedFile|array<mixed>>
     */
    private static function normalizeUploadedFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $normalized[$key] = $file;
                continue;
            }

            if (!is_array($file)) {
                throw new \InvalidArgumentException(
                    "Invalid uploaded file entry: {$key}"
                );
            }

            if (array_key_exists('error', $file)) {
                $normalized[$key] = self::normalizeFileSpecification($file);
                continue;
            }

            $normalized[$key] = self::normalizeUploadedFiles($file);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return UploadedFile|array<mixed>
     */
    private static function normalizeFileSpecification(
        array $file
    ): UploadedFile|array {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if (!is_array($error)) {
            return UploadedFile::fromArray($file);
        }

        $normalized = [];

        foreach (array_keys($error) as $key) {
            $normalized[$key] = self::normalizeFileSpecification([
                'name' => self::nestedFileValue(
                    $file['name'] ?? null,
                    $key
                ),
                'type' => self::nestedFileValue(
                    $file['type'] ?? null,
                    $key
                ),
                'tmp_name' => self::nestedFileValue(
                    $file['tmp_name'] ?? null,
                    $key
                ),
                'error' => self::nestedFileValue(
                    $file['error'] ?? null,
                    $key
                ),
                'size' => self::nestedFileValue(
                    $file['size'] ?? null,
                    $key
                ),
            ]);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @param string|int $key
     *
     * @return mixed
     */
    private static function nestedFileValue(
        mixed $value,
        string|int $key
    ): mixed {
        if (!is_array($value)) {
            return null;
        }

        return $value[$key] ?? null;
    }

    /**
     * @param string $method
     *
     * @return string
     */
    private static function normalizeMethod(string $method): string
    {
        $method = strtoupper(trim($method));

        if ($method === '') {
            throw new \InvalidArgumentException(
                'The HTTP request method cannot be empty.'
            );
        }

        if (
            preg_match(
                "/^[!#$%&'*+.^_`|~0-9A-Z-]+$/",
                $method
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                "Invalid HTTP request method: {$method}"
            );
        }

        return $method;
    }

    /**
     * @param string $uri
     *
     * @return string
     */
    private static function validateUri(string $uri): string
    {
        if ($uri === '') {
            return '/';
        }

        if (
            str_contains($uri, "\0")
            || str_contains($uri, "\r")
            || str_contains($uri, "\n")
        ) {
            throw new \InvalidArgumentException(
                'The request URI contains invalid control characters.'
            );
        }

        if (parse_url($uri) === false) {
            throw new \InvalidArgumentException(
                "Invalid request URI: {$uri}"
            );
        }

        if (parse_url($uri, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException(
                'HTTP request URIs cannot contain fragments.'
            );
        }

        return $uri;
    }
}
