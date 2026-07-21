<?php

declare(strict_types=1);

/**
 *
 * @file Response.php
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
 * @class Response
 *
 * @brief Represents an outgoing HTTP response.
 *
 * Response stores an HTTP status code, response headers and a string body.
 * It provides immutable transformation methods and factories for common
 * response types such as JSON, HTML, text, redirects and empty responses.
 *
 * @since 0.1.0
 */
final class Response
{
    /**
     * HTTP status code.
     */
    private int $statusCode;

    /**
     * Response body.
     */
    private string $body;

    /**
     * Response headers.
     */
    private Headers $headers;

    /**
     * @param string|\Stringable                             $body
     * @param int                                            $statusCode
     * @param Headers|array<string, string|array<int, string>> $headers
     */
    public function __construct(
        string|\Stringable $body = '',
        int $statusCode = 200,
        Headers|array $headers = []
    ) {
        self::assertValidStatusCode($statusCode);

        $body = (string) $body;

        if (!self::statusAllowsBody($statusCode) && $body !== '') {
            throw new \InvalidArgumentException(
                "HTTP status {$statusCode} cannot contain a response body."
            );
        }

        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers instanceof Headers
            ? clone $headers
            : new Headers($headers);
    }

    /**
     * @brief Create a plain-text response.
     *
     * @param string|\Stringable                             $body
     * @param int                                            $statusCode
     * @param Headers|array<string, string|array<int, string>> $headers
     *
     * @return self
     */
    public static function text(
        string|\Stringable $body,
        int $statusCode = 200,
        Headers|array $headers = []
    ): self {
        $response = new self(
            $body,
            $statusCode,
            $headers
        );

        if (!$response->hasHeader('Content-Type')) {
            $response = $response->withHeader(
                'Content-Type',
                'text/plain; charset=UTF-8'
            );
        }

        return $response;
    }

    /**
     * @brief Create an HTML response.
     *
     * @param string|\Stringable                             $body
     * @param int                                            $statusCode
     * @param Headers|array<string, string|array<int, string>> $headers
     *
     * @return self
     */
    public static function html(
        string|\Stringable $body,
        int $statusCode = 200,
        Headers|array $headers = []
    ): self {
        $response = new self(
            $body,
            $statusCode,
            $headers
        );

        if (!$response->hasHeader('Content-Type')) {
            $response = $response->withHeader(
                'Content-Type',
                'text/html; charset=UTF-8'
            );
        }

        return $response;
    }

    /**
     * @brief Create a JSON response.
     *
     * @param mixed                                          $data
     * @param int                                            $statusCode
     * @param Headers|array<string, string|array<int, string>> $headers
     * @param int                                            $flags
     * @param int                                            $depth
     *
     * @return self
     */
    public static function json(
        mixed $data,
        int $statusCode = 200,
        Headers|array $headers = [],
        int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        int $depth = 512
    ): self {
        if ($depth < 1) {
            throw new \InvalidArgumentException(
                'The JSON encoding depth must be greater than zero.'
            );
        }

        try {
            $body = json_encode(
                $data,
                $flags | JSON_THROW_ON_ERROR,
                $depth
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'Unable to encode the response as JSON: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }

        $response = new self(
            $body,
            $statusCode,
            $headers
        );

        if (!$response->hasHeader('Content-Type')) {
            $response = $response->withHeader(
                'Content-Type',
                'application/json; charset=UTF-8'
            );
        }

        return $response;
    }

    /**
     * @brief Create a redirect response.
     *
     * @param string                                         $location
     * @param int                                            $statusCode
     * @param Headers|array<string, string|array<int, string>> $headers
     *
     * @return self
     */
    public static function redirect(
        string $location,
        int $statusCode = 302,
        Headers|array $headers = []
    ): self {
        self::assertValidRedirectStatus($statusCode);
        self::assertValidLocation($location);

        return (new self('', $statusCode, $headers))
            ->withHeader('Location', $location);
    }

    /**
     * @brief Create an empty response.
     *
     * @param int                                            $statusCode
     * @param Headers|array<string, string|array<int, string>> $headers
     *
     * @return self
     */
    public static function empty(
        int $statusCode = 204,
        Headers|array $headers = []
    ): self {
        return new self(
            '',
            $statusCode,
            $headers
        );
    }

    /**
     * @brief Create a 204 No Content response.
     *
     * @param Headers|array<string, string|array<int, string>> $headers
     *
     * @return self
     */
    public static function noContent(
        Headers|array $headers = []
    ): self {
        return self::empty(
            204,
            $headers
        );
    }

    /**
     * @brief Return the HTTP status code.
     *
     * @return int
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @brief Return the standard reason phrase.
     *
     * An empty string is returned for unregistered status codes.
     *
     * @return string
     */
    public function reasonPhrase(): string
    {
        return self::reasonPhrases()[$this->statusCode] ?? '';
    }

    /**
     * @brief Return the response body.
     *
     * @return string
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * @brief Return the response body length in bytes.
     *
     * @return int
     */
    public function bodyLength(): int
    {
        return strlen($this->body);
    }

    /**
     * @brief Determine whether the response body is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->body === '';
    }

    /**
     * @brief Determine whether the status permits a response body.
     *
     * @return bool
     */
    public function allowsBody(): bool
    {
        return self::statusAllowsBody($this->statusCode);
    }

    /**
     * @brief Return a copy of the response headers.
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
     * @brief Return the first value of a response header.
     *
     * @param string      $name
     * @param string|null $default
     *
     * @return string|null
     */
    public function header(
        string $name,
        ?string $default = null
    ): ?string {
        return $this->headers->get(
            $name,
            $default
        );
    }

    /**
     * @brief Return all values associated with a response header.
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
     * @brief Return the normalized response content type.
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

        return strtolower(
            trim(explode(';', $header, 2)[0])
        );
    }

    /**
     * @brief Determine whether this is an informational response.
     *
     * @return bool
     */
    public function isInformational(): bool
    {
        return $this->statusCode >= 100
            && $this->statusCode < 200;
    }

    /**
     * @brief Determine whether this is a successful response.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200
            && $this->statusCode < 300;
    }

    /**
     * @brief Determine whether this is a redirect response.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300
            && $this->statusCode < 400;
    }

    /**
     * @brief Determine whether this is a client-error response.
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400
            && $this->statusCode < 500;
    }

    /**
     * @brief Determine whether this is a server-error response.
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500
            && $this->statusCode < 600;
    }

    /**
     * @brief Determine whether this is an error response.
     *
     * @return bool
     */
    public function isError(): bool
    {
        return $this->statusCode >= 400;
    }

    /**
     * @brief Create a copy with another status code.
     *
     * A body cannot be retained when the new status forbids one.
     *
     * @param int $statusCode HTTP status code.
     *
     * @return self
     */
    public function withStatus(int $statusCode): self
    {
        self::assertValidStatusCode($statusCode);

        if (
            !self::statusAllowsBody($statusCode)
            && $this->body !== ''
        ) {
            throw new \InvalidArgumentException(
                "HTTP status {$statusCode} cannot contain a response body."
            );
        }

        $clone = clone $this;
        $clone->statusCode = $statusCode;

        return $clone;
    }

    /**
     * @brief Create a copy with another response body.
     *
     * Content-Length is removed because a previously supplied value may
     * no longer be correct.
     *
     * @param string|\Stringable $body Response body.
     *
     * @return self
     */
    public function withBody(
        string|\Stringable $body
    ): self {
        $body = (string) $body;

        if (!$this->allowsBody() && $body !== '') {
            throw new \InvalidArgumentException(
                "HTTP status {$this->statusCode} cannot contain a response body."
            );
        }

        $clone = clone $this;
        $clone->body = $body;
        $clone->headers->remove('Content-Length');

        return $clone;
    }

    /**
     * @brief Create a copy with a replaced header.
     *
     * @param string                   $name
     * @param string|array<int, string> $value
     *
     * @return self
     */
    public function withHeader(
        string $name,
        string|array $value
    ): self {
        $clone = clone $this;
        $clone->headers->set(
            $name,
            $value
        );

        return $clone;
    }

    /**
     * @brief Create a copy with an appended header value.
     *
     * @param string $name
     * @param string $value
     *
     * @return self
     */
    public function withAddedHeader(
        string $name,
        string $value
    ): self {
        $clone = clone $this;
        $clone->headers->add(
            $name,
            $value
        );

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
     * @brief Create a copy with a calculated Content-Length header.
     *
     * Content-Length is not added when the response status forbids a body
     * or when Transfer-Encoding is already present.
     *
     * @return self
     */
    public function withContentLength(): self
    {
        $clone = clone $this;

        if (
            !$clone->allowsBody()
            || $clone->hasHeader('Transfer-Encoding')
        ) {
            $clone->headers->remove('Content-Length');

            return $clone;
        }

        $clone->headers->set(
            'Content-Length',
            (string) strlen($clone->body)
        );

        return $clone;
    }

    /**
     * @brief Send the response through the PHP runtime.
     *
     * Headers and status are sent before the body. Calling this method
     * after output has started throws a runtime exception.
     *
     * @param bool $sendBody Whether the response body should be emitted.
     *
     * @return void
     */
    public function send(bool $sendBody = true): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                "Unable to send HTTP response because output started at {$file}:{$line}."
            );
        }

        http_response_code($this->statusCode);

        foreach ($this->headers->all() as $name => $values) {
            $replace = true;

            foreach ($values as $value) {
                header(
                    "{$name}: {$value}",
                    $replace
                );

                $replace = false;
            }
        }

        if (
            $sendBody
            && $this->allowsBody()
            && $this->body !== ''
        ) {
            echo $this->body;
        }
    }

    /**
     * Clone mutable response values.
     */
    public function __clone()
    {
        $this->headers = clone $this->headers;
    }

    /**
     * @param int $statusCode HTTP status code.
     *
     * @return void
     */
    private static function assertValidStatusCode(
        int $statusCode
    ): void {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new \InvalidArgumentException(
                "Invalid HTTP status code: {$statusCode}"
            );
        }
    }

    /**
     * @param int $statusCode Redirect status code.
     *
     * @return void
     */
    private static function assertValidRedirectStatus(
        int $statusCode
    ): void {
        $validStatuses = [
            300,
            301,
            302,
            303,
            305,
            307,
            308,
        ];

        if (!in_array($statusCode, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                "Invalid HTTP redirect status code: {$statusCode}"
            );
        }
    }

    /**
     * @param string $location Redirect destination.
     *
     * @return void
     */
    private static function assertValidLocation(
        string $location
    ): void {
        if (trim($location) === '') {
            throw new \InvalidArgumentException(
                'The redirect location cannot be empty.'
            );
        }

        if (
            str_contains($location, "\0")
            || str_contains($location, "\r")
            || str_contains($location, "\n")
        ) {
            throw new \InvalidArgumentException(
                'The redirect location contains invalid control characters.'
            );
        }
    }

    /**
     * @param int $statusCode HTTP status code.
     *
     * @return bool
     */
    private static function statusAllowsBody(
        int $statusCode
    ): bool {
        if ($statusCode >= 100 && $statusCode < 200) {
            return false;
        }

        return $statusCode !== 204
            && $statusCode !== 304;
    }

    /**
     * @return array<int, string>
     */
    private static function reasonPhrases(): array
    {
        return [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            103 => 'Early Hints',

            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            208 => 'Already Reported',
            226 => 'IM Used',

            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',

            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Content Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            418 => "I'm a Teapot",
            421 => 'Misdirected Request',
            422 => 'Unprocessable Content',
            423 => 'Locked',
            424 => 'Failed Dependency',
            425 => 'Too Early',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',

            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
        ];
    }
}
