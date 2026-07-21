<?php

declare(strict_types=1);

/**
 *
 * @file UploadedFile.php
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
 * @class UploadedFile
 *
 * @brief Represents and securely manages a received HTTP upload.
 *
 * UploadedFile validates PHP upload errors, distinguishes genuine HTTP
 * uploads from local test files, detects the real media type, calculates
 * checksums and securely moves or compresses uploaded files.
 *
 * Client-provided filenames and media types must never be considered
 * trustworthy.
 *
 * @since 0.1.0
 */
final class UploadedFile
{
    /**
     * Whether the file has already been moved.
     */
    private bool $moved = false;

    /**
     * Final destination after the file has been moved.
     */
    private ?string $movedPath = null;

    /**
     * @param string      $temporaryPath   Temporary source path.
     * @param string|null $clientFilename  Client-provided filename.
     * @param string|null $clientMediaType Client-provided media type.
     * @param int|null    $declaredSize    Client-declared file size.
     * @param int         $error           PHP upload error code.
     * @param bool        $httpUpload      Whether the file came from PHP HTTP upload handling.
     */
    public function __construct(
        private readonly string $temporaryPath,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
        private readonly ?int $declaredSize = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $httpUpload = false
    ) {
        $this->assertValidError($error);

        if ($declaredSize !== null && $declaredSize < 0) {
            throw new \InvalidArgumentException(
                'The uploaded file size cannot be negative.'
            );
        }

        if (str_contains($temporaryPath, "\0")) {
            throw new \InvalidArgumentException(
                'The uploaded file path contains an invalid null byte.'
            );
        }

        if (
            $clientFilename !== null
            && str_contains($clientFilename, "\0")
        ) {
            throw new \InvalidArgumentException(
                'The uploaded filename contains an invalid null byte.'
            );
        }

        if (
            $error === UPLOAD_ERR_OK
            && trim($temporaryPath) === ''
        ) {
            throw new \InvalidArgumentException(
                'A successful upload must have a temporary file path.'
            );
        }
    }

    /**
     * @brief Create an uploaded file from one PHP $_FILES entry.
     *
     * This method accepts only a single normalized upload entry.
     * Nested multi-file upload arrays must be normalized before use.
     *
     * @param array{
     *     tmp_name?: mixed,
     *     name?: mixed,
     *     type?: mixed,
     *     size?: mixed,
     *     error?: mixed
     * } $file PHP upload information.
     *
     * @return self
     */
    public static function fromArray(array $file): self
    {
        $temporaryPath = $file['tmp_name'] ?? '';
        $clientFilename = $file['name'] ?? null;
        $clientMediaType = $file['type'] ?? null;
        $declaredSize = $file['size'] ?? null;
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if (!is_string($temporaryPath)) {
            throw new \InvalidArgumentException(
                'The uploaded file temporary path must be a string.'
            );
        }

        if (
            $clientFilename !== null
            && !is_string($clientFilename)
        ) {
            throw new \InvalidArgumentException(
                'The uploaded filename must be a string or null.'
            );
        }

        if (
            $clientMediaType !== null
            && !is_string($clientMediaType)
        ) {
            throw new \InvalidArgumentException(
                'The uploaded media type must be a string or null.'
            );
        }

        if (
            $declaredSize !== null
            && !is_int($declaredSize)
        ) {
            throw new \InvalidArgumentException(
                'The uploaded file size must be an integer or null.'
            );
        }

        if (!is_int($error)) {
            throw new \InvalidArgumentException(
                'The uploaded file error code must be an integer.'
            );
        }

        return new self(
            temporaryPath: $temporaryPath,
            clientFilename: self::nullableString($clientFilename),
            clientMediaType: self::nullableString($clientMediaType),
            declaredSize: $declaredSize,
            error: $error,
            httpUpload: true
        );
    }

    /**
     * @brief Create an upload representation from a local file.
     *
     * This method is useful for testing and internal file processing.
     * The file is not treated as a genuine PHP HTTP upload.
     *
     * @param string      $path             Local file path.
     * @param string|null $clientFilename   Optional filename.
     * @param string|null $clientMediaType  Optional media type.
     *
     * @return self
     */
    public static function fromPath(
        string $path,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): self {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(
                "Uploaded source file not found: {$path}"
            );
        }

        $size = filesize($path);

        return new self(
            temporaryPath: $path,
            clientFilename: $clientFilename ?? basename($path),
            clientMediaType: $clientMediaType,
            declaredSize: $size === false ? null : $size,
            error: UPLOAD_ERR_OK,
            httpUpload: false
        );
    }

    /**
     * @brief Return the original temporary path.
     *
     * The path may no longer exist after moveTo() has completed.
     *
     * @return string
     */
    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    /**
     * @brief Return the current file path.
     *
     * Before movement, this is the temporary upload path. After movement,
     * this is the final destination.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->movedPath ?? $this->temporaryPath;
    }

    /**
     * @brief Return the destination used by moveTo().
     *
     * @return string|null
     */
    public function movedPath(): ?string
    {
        return $this->movedPath;
    }

    /**
     * @brief Return the original client-provided filename.
     *
     * This value must not be used directly as a filesystem path.
     *
     * @return string|null
     */
    public function clientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * @brief Return a sanitized version of the client filename.
     *
     * Directory traversal sequences, control characters and unsupported
     * filename characters are removed.
     *
     * @param string $fallback Filename used when sanitization produces an empty value.
     *
     * @return string
     */
    public function safeClientFilename(string $fallback = 'upload'): string
    {
        if ($fallback === '') {
            throw new \InvalidArgumentException(
                'The fallback filename cannot be empty.'
            );
        }

        $filename = $this->clientFilename;

        if ($filename === null || trim($filename) === '') {
            return $fallback;
        }

        $filename = str_replace('\\', '/', $filename);
        $filename = basename($filename);

        $filename = preg_replace(
            '/[\x00-\x1F\x7F]+/',
            '',
            $filename
        ) ?? '';

        $filename = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            $filename
        ) ?? '';

        $filename = trim($filename, ".-_ \t\n\r\0\x0B");

        if ($filename === '' || $filename === '.' || $filename === '..') {
            return $fallback;
        }

        if (strlen($filename) > 200) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);

            $reserved = $extension === ''
                ? 0
                : strlen($extension) + 1;

            $base = substr(
                $base,
                0,
                max(1, 200 - $reserved)
            );

            $filename = $extension === ''
                ? $base
                : $base . '.' . $extension;
        }

        return $filename;
    }

    /**
     * @brief Return the extension supplied by the client filename.
     *
     * This extension must not be trusted for security validation.
     *
     * @return string|null
     */
    public function clientExtension(): ?string
    {
        $filename = $this->clientFilename;

        if ($filename === null || $filename === '') {
            return null;
        }

        $extension = pathinfo(
            $this->safeClientFilename(),
            PATHINFO_EXTENSION
        );

        return $extension === ''
            ? null
            : strtolower($extension);
    }

    /**
     * @brief Return the media type supplied by the client.
     *
     * This value must not be trusted for upload validation.
     *
     * @return string|null
     */
    public function clientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * @brief Detect the real media type from the file contents.
     *
     * PHP's Fileinfo extension is used instead of trusting the client.
     *
     * @return string|null
     */
    public function mediaType(): ?string
    {
        $path = $this->readablePath();

        if (!class_exists(\finfo::class)) {
            return null;
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $fileInfo->file($path);

        if (!is_string($mediaType) || $mediaType === '') {
            return null;
        }

        return strtolower($mediaType);
    }

    /**
     * @brief Return the real file size in bytes.
     *
     * The filesystem size is preferred over the client-declared size.
     *
     * @return int|null
     */
    public function size(): ?int
    {
        $path = $this->path();

        if (is_file($path)) {
            $size = filesize($path);

            if ($size !== false) {
                return $size;
            }
        }

        return $this->declaredSize;
    }

    /**
     * @brief Return the client-declared upload size.
     *
     * @return int|null
     */
    public function declaredSize(): ?int
    {
        return $this->declaredSize;
    }

    /**
     * @brief Return the PHP upload error code.
     *
     * @return int
     */
    public function error(): int
    {
        return $this->error;
    }

    /**
     * @brief Determine whether this represents a genuine HTTP upload.
     *
     * @return bool
     */
    public function isHttpUpload(): bool
    {
        return $this->httpUpload;
    }

    /**
     * @brief Determine whether the upload is currently valid.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        if ($this->moved || $this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        if (
            !is_file($this->temporaryPath)
            || !is_readable($this->temporaryPath)
        ) {
            return false;
        }

        if (
            $this->httpUpload
            && !is_uploaded_file($this->temporaryPath)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @brief Determine whether the uploaded file has been moved.
     *
     * @return bool
     */
    public function hasMoved(): bool
    {
        return $this->moved;
    }

    /**
     * @brief Open the uploaded file as a readable binary stream.
     *
     * @return resource
     */
    public function openStream(): mixed
    {
        $path = $this->readablePath();
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new \RuntimeException(
                "Unable to open uploaded file: {$path}"
            );
        }

        return $stream;
    }

    /**
     * @brief Calculate a checksum from the uploaded file contents.
     *
     * SHA-256 is used by default.
     *
     * @param string $algorithm Hash algorithm supported by PHP.
     *
     * @return string
     */
    public function checksum(string $algorithm = 'sha256'): string
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new \InvalidArgumentException(
                "Unsupported checksum algorithm: {$algorithm}"
            );
        }

        $path = $this->readablePath();
        $checksum = hash_file($algorithm, $path);

        if ($checksum === false) {
            throw new \RuntimeException(
                "Unable to calculate checksum for uploaded file: {$path}"
            );
        }

        return $checksum;
    }

    /**
     * @brief Ensure the uploaded file does not exceed a size limit.
     *
     * @param int $maximumBytes Maximum accepted size in bytes.
     *
     * @return void
     */
    public function assertMaximumSize(int $maximumBytes): void
    {
        if ($maximumBytes < 0) {
            throw new \InvalidArgumentException(
                'The maximum upload size cannot be negative.'
            );
        }

        $size = $this->size();

        if ($size !== null && $size > $maximumBytes) {
            throw new \RuntimeException(
                "Uploaded file exceeds the maximum size of {$maximumBytes} bytes."
            );
        }
    }

    /**
     * @brief Ensure the detected media type is allowed.
     *
     * Wildcard families such as image/* are supported.
     *
     * @param array<int, string> $allowedMediaTypes Accepted media types.
     *
     * @return void
     */
    public function assertMediaType(array $allowedMediaTypes): void
    {
        if ($allowedMediaTypes === []) {
            throw new \InvalidArgumentException(
                'At least one allowed media type is required.'
            );
        }

        $mediaType = $this->mediaType();

        if ($mediaType === null) {
            throw new \RuntimeException(
                'Unable to detect the uploaded file media type.'
            );
        }

        foreach ($allowedMediaTypes as $allowedMediaType) {
            $allowedMediaType = strtolower(trim($allowedMediaType));

            if ($allowedMediaType === $mediaType) {
                return;
            }

            if (
                str_ends_with($allowedMediaType, '/*')
                && str_starts_with(
                    $mediaType,
                    substr($allowedMediaType, 0, -1)
                )
            ) {
                return;
            }
        }

        throw new \RuntimeException(
            "Uploaded media type is not allowed: {$mediaType}"
        );
    }

    /**
     * @brief Securely move the uploaded file.
     *
     * Existing files are not overwritten unless explicitly requested.
     * The destination directory must already exist and be writable.
     *
     * @param string   $destination Final destination path.
     * @param bool     $overwrite   Whether an existing file may be replaced.
     * @param int|null $permissions Optional filesystem permissions.
     *
     * @return string Final destination path.
     */
    public function moveTo(
        string $destination,
        bool $overwrite = false,
        ?int $permissions = null
    ): string {
        $source = $this->uploadSourcePath();

        $this->assertDestination(
            $destination,
            $overwrite
        );

        if (
            $permissions !== null
            && ($permissions < 0 || $permissions > 0777)
        ) {
            throw new \InvalidArgumentException(
                'File permissions must be between 0000 and 0777.'
            );
        }

        if (
            $overwrite
            && (file_exists($destination) || is_link($destination))
            && !unlink($destination)
        ) {
            throw new \RuntimeException(
                "Unable to replace existing destination: {$destination}"
            );
        }

        $moved = $this->httpUpload
            ? move_uploaded_file($source, $destination)
            : $this->moveLocalFile($source, $destination);

        if (!$moved) {
            throw new \RuntimeException(
                "Unable to move uploaded file to: {$destination}"
            );
        }

        $this->moved = true;
        $this->movedPath = $destination;

        clearstatcache(true, $destination);

        if (
            $permissions !== null
            && !chmod($destination, $permissions)
        ) {
            throw new \RuntimeException(
                "Uploaded file was moved but its permissions could not be changed: {$destination}"
            );
        }

        return $destination;
    }

    /**
     * @brief Create a Gzip-compressed copy of the uploaded file.
     *
     * Compression is explicit because many formats such as JPEG, PNG,
     * MP4, ZIP and PDF may already be compressed.
     *
     * The source upload is not moved or deleted by this operation.
     *
     * @param string $destination Destination path for the Gzip file.
     * @param int    $level       Compression level from 0 to 9.
     * @param bool   $overwrite   Whether an existing destination may be replaced.
     *
     * @return string Final compressed file path.
     */
    public function compressTo(
        string $destination,
        int $level = 6,
        bool $overwrite = false
    ): string {
        if ($level < 0 || $level > 9) {
            throw new \InvalidArgumentException(
                'The Gzip compression level must be between 0 and 9.'
            );
        }

        if (
            !function_exists('gzopen')
            || !function_exists('gzwrite')
        ) {
            throw new \RuntimeException(
                'The PHP Zlib extension is required for Gzip compression.'
            );
        }

        $source = $this->readablePath();

        $this->assertDestination(
            $destination,
            $overwrite
        );

        $directory = dirname($destination);
        $temporaryDestination = tempnam(
            $directory,
            '.ivi-upload-'
        );

        if ($temporaryDestination === false) {
            throw new \RuntimeException(
                "Unable to create a temporary compression file in: {$directory}"
            );
        }

        $input = null;
        $output = null;
        $completed = false;

        try {
            $input = fopen($source, 'rb');

            if ($input === false) {
                throw new \RuntimeException(
                    "Unable to read uploaded file: {$source}"
                );
            }

            $output = gzopen(
                $temporaryDestination,
                'wb' . $level
            );

            if ($output === false) {
                throw new \RuntimeException(
                    "Unable to open Gzip destination: {$temporaryDestination}"
                );
            }

            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false) {
                    throw new \RuntimeException(
                        "Unable to read uploaded file during compression: {$source}"
                    );
                }

                if ($chunk === '') {
                    continue;
                }

                $length = strlen($chunk);
                $offset = 0;

                while ($offset < $length) {
                    $written = gzwrite(
                        $output,
                        substr($chunk, $offset)
                    );

                    if ($written === false || $written === 0) {
                        throw new \RuntimeException(
                            "Unable to write compressed upload: {$temporaryDestination}"
                        );
                    }

                    $offset += $written;
                }
            }

            fclose($input);
            $input = null;

            if (!gzclose($output)) {
                $output = null;

                throw new \RuntimeException(
                    "Unable to finalize compressed upload: {$temporaryDestination}"
                );
            }

            $output = null;

            if (
                $overwrite
                && (file_exists($destination) || is_link($destination))
                && !unlink($destination)
            ) {
                throw new \RuntimeException(
                    "Unable to replace compressed destination: {$destination}"
                );
            }

            if (!rename($temporaryDestination, $destination)) {
                throw new \RuntimeException(
                    "Unable to store compressed upload at: {$destination}"
                );
            }

            $completed = true;

            clearstatcache(true, $destination);

            return $destination;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                gzclose($output);
            }

            if (
                !$completed
                && file_exists($temporaryDestination)
            ) {
                @unlink($temporaryDestination);
            }
        }
    }

    /**
     * @brief Return a human-readable upload error message.
     *
     * @return string
     */
    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK =>
                'The file was uploaded successfully.',

            UPLOAD_ERR_INI_SIZE =>
                'The uploaded file exceeds the server upload size limit.',

            UPLOAD_ERR_FORM_SIZE =>
                'The uploaded file exceeds the form upload size limit.',

            UPLOAD_ERR_PARTIAL =>
                'The file was only partially uploaded.',

            UPLOAD_ERR_NO_FILE =>
                'No file was uploaded.',

            UPLOAD_ERR_NO_TMP_DIR =>
                'The server temporary upload directory is missing.',

            UPLOAD_ERR_CANT_WRITE =>
                'The server failed to write the uploaded file.',

            UPLOAD_ERR_EXTENSION =>
                'A PHP extension stopped the file upload.',

            default =>
                'An unknown file upload error occurred.',
        };
    }

    /**
     * @brief Return a valid upload source path.
     *
     * @return string
     */
    private function uploadSourcePath(): string
    {
        if ($this->moved) {
            throw new \RuntimeException(
                'The uploaded file has already been moved.'
            );
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                $this->errorMessage()
            );
        }

        if (
            !is_file($this->temporaryPath)
            || !is_readable($this->temporaryPath)
        ) {
            throw new \RuntimeException(
                "Uploaded temporary file is unavailable: {$this->temporaryPath}"
            );
        }

        if (
            $this->httpUpload
            && !is_uploaded_file($this->temporaryPath)
        ) {
            throw new \RuntimeException(
                'The temporary file is not a valid PHP HTTP upload.'
            );
        }

        return $this->temporaryPath;
    }

    /**
     * @brief Return the currently readable file path.
     *
     * @return string
     */
    private function readablePath(): string
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                $this->errorMessage()
            );
        }

        $path = $this->path();

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "Uploaded file is unavailable or unreadable: {$path}"
            );
        }

        return $path;
    }

    /**
     * @brief Validate a destination path.
     *
     * @param string $destination Destination path.
     * @param bool   $overwrite   Whether replacement is allowed.
     *
     * @return void
     */
    private function assertDestination(
        string $destination,
        bool $overwrite
    ): void {
        if (trim($destination) === '') {
            throw new \InvalidArgumentException(
                'The upload destination cannot be empty.'
            );
        }

        if (str_contains($destination, "\0")) {
            throw new \InvalidArgumentException(
                'The upload destination contains an invalid null byte.'
            );
        }

        if (is_dir($destination)) {
            throw new \InvalidArgumentException(
                "The upload destination must be a file path: {$destination}"
            );
        }

        if (
            !$overwrite
            && (file_exists($destination) || is_link($destination))
        ) {
            throw new \RuntimeException(
                "The upload destination already exists: {$destination}"
            );
        }

        $directory = dirname($destination);

        if (!is_dir($directory)) {
            throw new \RuntimeException(
                "Upload destination directory not found: {$directory}"
            );
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(
                "Upload destination directory is not writable: {$directory}"
            );
        }
    }

    /**
     * @brief Move a local or test file.
     *
     * A copy-and-delete fallback is used for cross-filesystem movement.
     *
     * @param string $source      Source file.
     * @param string $destination Destination file.
     *
     * @return bool
     */
    private function moveLocalFile(
        string $source,
        string $destination
    ): bool {
        if (@rename($source, $destination)) {
            return true;
        }

        $directory = dirname($destination);
        $temporaryDestination = tempnam(
            $directory,
            '.ivi-upload-'
        );

        if ($temporaryDestination === false) {
            return false;
        }

        try {
            if (!copy($source, $temporaryDestination)) {
                return false;
            }

            if (!rename($temporaryDestination, $destination)) {
                return false;
            }

            if (!unlink($source)) {
                @unlink($destination);

                return false;
            }

            return true;
        } finally {
            if (file_exists($temporaryDestination)) {
                @unlink($temporaryDestination);
            }
        }
    }

    /**
     * @param int $error PHP upload error code.
     *
     * @return void
     */
    private function assertValidError(int $error): void
    {
        $validErrors = [
            UPLOAD_ERR_OK,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
            UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
            UPLOAD_ERR_EXTENSION,
        ];

        if (!in_array($error, $validErrors, true)) {
            throw new \InvalidArgumentException(
                "Invalid PHP upload error code: {$error}"
            );
        }
    }

    /**
     * @param string|null $value Nullable string.
     *
     * @return string|null
     */
    private static function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
