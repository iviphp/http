<?php

declare(strict_types=1);

/**
 *
 * @file HttpException.php
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

namespace Ivi\Http\Exceptions;

use Ivi\Http\Headers;
use Throwable;

/**
 * @class HttpException
 *
 * @brief Represents an HTTP request or response failure.
 *
 * HttpException associates an exception with an HTTP status code,
 * response headers and optional diagnostic context.
 *
 * The context is intended for logging and debugging. It should not be
 * exposed directly to clients without explicit filtering.
 *
 * @since 0.1.0
 */
final class HttpException extends \RuntimeException
{
    /**
     * Response headers associated with the failure.
     */
    private Headers $headers;

    /**
     * Diagnostic context associated with the failure.
     *
     * @var array<string, mixed>
     */
    private array $context;

    /**
     * @param int                                            $statusCode
     * @param string                                         $message
     * @param Headers|array<string, string|array<int, string>> $headers
     * @param array<string, mixed>                           $context
     * @param Throwable|null                                 $previous
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        Headers|array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        self::assertValidStatusCode($statusCode);

        $this->headers = $headers instanceof Headers
            ? clone $headers
            : new Headers($headers);

        $this->context = $context;

        parent::__construct(
            $message !== ''
                ? $message
                : self::defaultMessage($statusCode),
            $statusCode,
            $previous
        );
    }

    /**
     * @brief Create a 400 Bad Request exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function badRequest(
        string $message = 'Bad request.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            400,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 401 Unauthorized exception.
     *
     * @param string|null          $challenge Authentication challenge.
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function unauthorized(
        ?string $challenge = null,
        string $message = 'Authentication is required.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        $headers = [];

        if ($challenge !== null && trim($challenge) !== '') {
            $headers['WWW-Authenticate'] = $challenge;
        }

        return new self(
            401,
            $message,
            $headers,
            $context,
            $previous
        );
    }

    /**
     * @brief Create a 403 Forbidden exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function forbidden(
        string $message = 'Access is forbidden.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            403,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 404 Not Found exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function notFound(
        string $message = 'The requested resource was not found.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            404,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 405 Method Not Allowed exception.
     *
     * @param array<int, string>   $allowedMethods
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function methodNotAllowed(
        array $allowedMethods,
        string $message = 'The HTTP method is not allowed.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        $allowedMethods = array_values(
            array_unique(
                array_map(
                    static function (string $method): string {
                        $method = strtoupper(trim($method));

                        if (
                            $method === ''
                            || preg_match(
                                "/^[!#$%&'*+.^_`|~0-9A-Z-]+$/",
                                $method
                            ) !== 1
                        ) {
                            throw new \InvalidArgumentException(
                                "Invalid allowed HTTP method: {$method}"
                            );
                        }

                        return $method;
                    },
                    $allowedMethods
                )
            )
        );

        if ($allowedMethods === []) {
            throw new \InvalidArgumentException(
                'At least one allowed HTTP method is required.'
            );
        }

        return new self(
            405,
            $message,
            [
                'Allow' => implode(', ', $allowedMethods),
            ],
            $context,
            $previous
        );
    }

    /**
     * @brief Create a 409 Conflict exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function conflict(
        string $message = 'The request conflicts with the current resource state.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            409,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 413 Content Too Large exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function contentTooLarge(
        string $message = 'The request content is too large.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            413,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 415 Unsupported Media Type exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function unsupportedMediaType(
        string $message = 'The request media type is not supported.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            415,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 422 Unprocessable Content exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function unprocessable(
        string $message = 'The request content could not be processed.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            422,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 429 Too Many Requests exception.
     *
     * @param int|null             $retryAfter Seconds before retrying.
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function tooManyRequests(
        ?int $retryAfter = null,
        string $message = 'Too many requests.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        if ($retryAfter !== null && $retryAfter < 0) {
            throw new \InvalidArgumentException(
                'The retry delay cannot be negative.'
            );
        }

        $headers = [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return new self(
            429,
            $message,
            $headers,
            $context,
            $previous
        );
    }

    /**
     * @brief Create a 500 Internal Server Error exception.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function internalServerError(
        string $message = 'An internal server error occurred.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            500,
            $message,
            context: $context,
            previous: $previous
        );
    }

    /**
     * @brief Create a 503 Service Unavailable exception.
     *
     * @param int|null             $retryAfter Seconds before retrying.
     * @param string               $message
     * @param array<string, mixed> $context
     * @param Throwable|null       $previous
     *
     * @return self
     */
    public static function serviceUnavailable(
        ?int $retryAfter = null,
        string $message = 'The service is temporarily unavailable.',
        array $context = [],
        ?Throwable $previous = null
    ): self {
        if ($retryAfter !== null && $retryAfter < 0) {
            throw new \InvalidArgumentException(
                'The retry delay cannot be negative.'
            );
        }

        $headers = [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return new self(
            503,
            $message,
            $headers,
            $context,
            $previous
        );
    }

    /**
     * @brief Return the associated HTTP status code.
     *
     * @return int
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @brief Return a copy of the associated response headers.
     *
     * @return Headers
     */
    public function headers(): Headers
    {
        return clone $this->headers;
    }

    /**
     * @brief Return the first value of an associated response header.
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
     * @brief Return diagnostic exception context.
     *
     * This data should be logged internally and filtered before being
     * included in an HTTP response.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @brief Determine whether the exception represents a client error.
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400
            && $this->statusCode < 500;
    }

    /**
     * @brief Determine whether the exception represents a server error.
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * @param int $statusCode HTTP error status code.
     *
     * @return void
     */
    private static function assertValidStatusCode(
        int $statusCode
    ): void {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException(
                "An HTTP exception requires a status code between 400 and 599: {$statusCode}"
            );
        }
    }

    /**
     * @param int $statusCode HTTP status code.
     *
     * @return string
     */
    private static function defaultMessage(
        int $statusCode
    ): string {
        return match ($statusCode) {
            400 => 'Bad request.',
            401 => 'Authentication is required.',
            402 => 'Payment is required.',
            403 => 'Access is forbidden.',
            404 => 'The requested resource was not found.',
            405 => 'The HTTP method is not allowed.',
            406 => 'The requested response format is not acceptable.',
            408 => 'The request timed out.',
            409 => 'The request conflicts with the current resource state.',
            410 => 'The requested resource is no longer available.',
            411 => 'A content length is required.',
            412 => 'A request precondition failed.',
            413 => 'The request content is too large.',
            414 => 'The request URI is too long.',
            415 => 'The request media type is not supported.',
            416 => 'The requested range cannot be satisfied.',
            417 => 'The request expectation could not be satisfied.',
            422 => 'The request content could not be processed.',
            423 => 'The requested resource is locked.',
            424 => 'A required dependency failed.',
            425 => 'The request was received too early.',
            426 => 'A protocol upgrade is required.',
            428 => 'A request precondition is required.',
            429 => 'Too many requests.',
            431 => 'The request headers are too large.',
            451 => 'The resource is unavailable for legal reasons.',

            500 => 'An internal server error occurred.',
            501 => 'The requested functionality is not implemented.',
            502 => 'The upstream server returned an invalid response.',
            503 => 'The service is temporarily unavailable.',
            504 => 'The upstream server timed out.',
            505 => 'The HTTP version is not supported.',
            507 => 'The server has insufficient storage.',
            508 => 'A processing loop was detected.',
            511 => 'Network authentication is required.',

            default => $statusCode < 500
                ? 'An HTTP client error occurred.'
                : 'An HTTP server error occurred.',
        };
    }
}
