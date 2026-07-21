# Ivi HTTP

HTTP request, response, header and uploaded-file primitives for the IviPHP ecosystem.

## Overview

`iviphp/http` provides the low-level HTTP objects used by IviPHP applications and packages.

The package includes:

- case-insensitive HTTP header management;
- request creation from PHP superglobals;
- query, body, JSON, cookie and server access;
- normalized single and multiple file uploads;
- secure uploaded-file validation and movement;
- optional Gzip file compression;
- immutable response transformations;
- JSON, HTML, text, redirect and empty responses;
- structured HTTP exceptions.

This package does not provide routing, middleware execution or application bootstrapping. Those responsibilities belong to higher-level IviPHP components.

## Installation

```bash
composer require iviphp/http
```

## Requirements

- PHP 8.2 or later
- Composer
- `iviphp/support`
- PHP Fileinfo extension for trusted media-type detection
- PHP Zlib extension for optional Gzip compression

## Request

Create a request manually:

```php
<?php

declare(strict_types=1);

use Ivi\Http\Request;

$request = new Request(
    method: 'POST',
    uri: '/users?page=2',
    headers: [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer secret-token',
    ],
    query: [
        'page' => 2,
    ],
    rawBody: '{"name":"Gaspard"}'
);
```

Create a request from PHP superglobals:

```php
<?php

declare(strict_types=1);

use Ivi\Http\Request;

$request = Request::fromGlobals();
```

`Request::fromGlobals()` reads:

- `$_SERVER`;
- `$_GET`;
- `$_POST`;
- `$_COOKIE`;
- `$_FILES`;
- `php://input`.

Optional arguments can be passed for isolated tests.

```php
$request = Request::fromGlobals(
    server: [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/products',
        'CONTENT_TYPE' => 'application/json',
    ],
    query: [],
    post: [],
    cookies: [],
    files: [],
    rawBody: '{"name":"Keyboard"}'
);
```

## Request method and URI

```php
$method = $request->method();
$uri = $request->uri();
$path = $request->path();
$queryString = $request->queryString();

if ($request->isMethod('POST')) {
    // Handle the POST request.
}
```

The request path is not automatically URL-decoded. This preserves encoded path separators and avoids changing security-sensitive URI data.

## Request headers

```php
$contentType = $request->header('Content-Type');

$values = $request->headerValues('Accept');

$exists = $request->hasHeader('Authorization');

$headers = $request->headers();
```

Header names are case-insensitive.

```php
$request->header('content-type');
$request->header('Content-Type');
$request->header('CONTENT-TYPE');
```

All three calls resolve the same header.

## Content type and authentication

```php
$contentType = $request->contentType();

if ($request->isJson()) {
    // The request uses application/json or a +json media type.
}

$token = $request->bearerToken();
```

`contentType()` removes parameters such as the character set.

```text
application/json; charset=UTF-8
```

becomes:

```text
application/json
```

## Query parameters

```php
$page = $request->query('page', 1);

$filters = $request->query('filters');

$category = $request->query(
    'filters.category'
);

if ($request->hasQuery('page')) {
    // The query key exists.
}

$allQueryParameters = $request->queryParameters();
```

Nested query values can be accessed with dot notation.

## Parsed request body

```php
$body = $request->parsedBody();

$name = $request->bodyParameter('name');

$email = $request->bodyParameter(
    'user.email',
    'unknown@example.com'
);
```

Dot notation is available when the parsed body is an array.

## JSON requests

```php
$data = $request->json();

$name = $request->json('name');

$email = $request->json(
    'user.email',
    null
);
```

Invalid JSON throws an `InvalidArgumentException` containing the original `JsonException`.

Check the media type before expecting JSON:

```php
if ($request->isJson()) {
    $data = $request->json();
}
```

## Combined input

Query parameters and body values can be accessed through one interface.

```php
$input = $request->allInput();

$name = $request->input('name');

$page = $request->input('page', 1);

if ($request->hasInput('email')) {
    // The input key exists.
}
```

Body values override query values when the same key exists.

## Cookies

```php
$sessionId = $request->cookie('session_id');

if ($request->hasCookie('preferences')) {
    // The cookie exists.
}

$cookies = $request->cookies();
```

Cookie values are untrusted user input and must be validated before use.

## Server values

```php
$remoteAddress = $request->server('REMOTE_ADDR');

$server = $request->serverParameters();

$isSecure = $request->isSecure();
```

`isSecure()` checks the direct PHP server environment.

Forwarded headers such as `X-Forwarded-Proto` are intentionally not trusted automatically. Proxy trust must be configured by a higher-level application component.

## Request attributes

Request attributes can store routing and middleware data.

```php
$request = $request->withAttribute(
    'user_id',
    42
);

$userId = $request->attribute('user_id');

if ($request->hasAttribute('user_id')) {
    // The attribute exists.
}

$request = $request->withoutAttribute('user_id');
```

Request transformation methods return a new request.

```php
$updated = $request
    ->withMethod('PUT')
    ->withUri('/users/42')
    ->withHeader('Accept', 'application/json');
```

The original request is not modified.

## Uploaded files

Retrieve an uploaded file:

```php
use Ivi\Http\UploadedFile;

$file = $request->file('avatar');

if ($file instanceof UploadedFile) {
    // Handle the uploaded file.
}
```

Check whether an upload entry exists:

```php
if ($request->hasFile('avatar')) {
    $file = $request->file('avatar');
}
```

Multiple and nested PHP upload arrays are normalized automatically.

```php
$files = $request->file('documents');
```

A nested upload may return an array containing multiple `UploadedFile` instances.

## Creating an UploadedFile

From a PHP `$_FILES` entry:

```php
use Ivi\Http\UploadedFile;

$file = UploadedFile::fromArray([
    'tmp_name' => '/tmp/php-upload',
    'name' => 'photo.jpg',
    'type' => 'image/jpeg',
    'size' => 245760,
    'error' => UPLOAD_ERR_OK,
]);
```

For tests and internal file processing:

```php
$file = UploadedFile::fromPath(
    __DIR__ . '/fixtures/photo.jpg'
);
```

Files created with `fromPath()` are not treated as genuine PHP HTTP uploads.

## Upload validity

```php
if (!$file->isValid()) {
    throw new RuntimeException(
        $file->errorMessage()
    );
}
```

A genuine HTTP upload is considered valid only when:

- the PHP upload error is `UPLOAD_ERR_OK`;
- the temporary file exists;
- the temporary file is readable;
- PHP confirms that it was uploaded through HTTP;
- the file has not already been moved.

## Uploaded filename security

The original filename is available through:

```php
$originalName = $file->clientFilename();
```

The client-provided filename must never be used directly as a filesystem path.

Use the sanitized filename instead:

```php
$safeName = $file->safeClientFilename();
```

Provide a fallback when necessary:

```php
$safeName = $file->safeClientFilename(
    'uploaded-file'
);
```

The client extension can be retrieved with:

```php
$extension = $file->clientExtension();
```

The extension is still client-controlled and must not be used as the only validation mechanism.

## Media-type validation

The client-provided type is available through:

```php
$clientType = $file->clientMediaType();
```

This value is not trustworthy.

Detect the media type from the file contents:

```php
$detectedType = $file->mediaType();
```

Validate against accepted types:

```php
$file->assertMediaType([
    'image/jpeg',
    'image/png',
    'image/webp',
]);
```

Wildcard families are supported:

```php
$file->assertMediaType([
    'image/*',
]);
```

Content-based media-type detection requires the PHP Fileinfo extension.

## Upload size validation

Retrieve the real filesystem size:

```php
$size = $file->size();
```

Retrieve the client-declared size:

```php
$declaredSize = $file->declaredSize();
```

Validate the maximum accepted size:

```php
$file->assertMaximumSize(
    5 * 1024 * 1024
);
```

The example allows files up to 5 MiB.

Server limits such as `upload_max_filesize` and `post_max_size` must also be configured correctly.

## Upload checksums

Calculate a SHA-256 checksum:

```php
$checksum = $file->checksum();
```

Use another supported hash algorithm:

```php
$checksum = $file->checksum('sha512');
```

Checksums can help with:

- duplicate detection;
- integrity verification;
- content-addressed storage;
- audit records.

A checksum does not replace malware scanning or media validation.

## Reading uploaded files

Open the uploaded file as a binary stream:

```php
$stream = $file->openStream();

try {
    while (!feof($stream)) {
        $chunk = fread($stream, 8192);

        if ($chunk === false) {
            throw new RuntimeException(
                'Unable to read uploaded file.'
            );
        }

        // Process the chunk.
    }
} finally {
    fclose($stream);
}
```

Streaming avoids loading large files entirely into memory.

## Moving uploaded files

```php
$destination = __DIR__
    . '/storage/uploads/'
    . $file->safeClientFilename();

$file->moveTo($destination);
```

Existing files are not overwritten by default.

```php
$file->moveTo(
    destination: $destination,
    overwrite: true
);
```

Optional permissions can be applied:

```php
$file->moveTo(
    destination: $destination,
    permissions: 0640
);
```

The destination directory must already exist and be writable.

After movement:

```php
$file->hasMoved();
$file->movedPath();
$file->path();
```

`path()` returns the temporary path before movement and the final path afterward.

An uploaded file cannot be moved twice.

## Upload compression

Compression is explicit and optional.

```php
$file->compressTo(
    __DIR__ . '/storage/uploads/data.json.gz'
);
```

Select a Gzip compression level from `0` to `9`:

```php
$file->compressTo(
    destination: __DIR__ . '/storage/uploads/data.json.gz',
    level: 6
);
```

Allow replacement of an existing compressed file:

```php
$file->compressTo(
    destination: __DIR__ . '/storage/uploads/data.json.gz',
    level: 6,
    overwrite: true
);
```

Compression creates a compressed copy and does not move or delete the source upload.

Automatic compression is intentionally avoided. Formats such as these are already compressed or may gain little from Gzip:

- JPEG;
- PNG;
- WebP;
- MP4;
- ZIP;
- PDF.

Gzip compression is most useful for text-based files such as:

- JSON;
- CSV;
- XML;
- plain text;
- application logs;
- large SQL exports.

Compression requires the PHP Zlib extension.

## Upload security recommendations

For production upload handling:

1. Validate the PHP upload error.
2. Enforce a maximum file size.
3. Detect the media type from file contents.
4. Allow only explicitly supported media types.
5. Generate a server-controlled storage filename.
6. Store uploads outside the public web root when possible.
7. Prevent script execution inside upload directories.
8. Calculate a checksum when integrity matters.
9. Scan untrusted files when malware detection is required.
10. Never trust the original filename, extension or media type.

Example:

```php
$file = $request->file('document');

if (!$file instanceof UploadedFile) {
    throw new RuntimeException(
        'A document upload is required.'
    );
}

if (!$file->isValid()) {
    throw new RuntimeException(
        $file->errorMessage()
    );
}

$file->assertMaximumSize(
    10 * 1024 * 1024
);

$file->assertMediaType([
    'application/pdf',
]);

$storageName = bin2hex(
    random_bytes(16)
) . '.pdf';

$file->moveTo(
    __DIR__ . '/storage/documents/' . $storageName,
    permissions: 0640
);
```

## Headers

Create a header collection:

```php
use Ivi\Http\Headers;

$headers = new Headers([
    'Content-Type' => 'application/json',
    'X-Request-Id' => 'request-123',
]);
```

Read headers:

```php
$contentType = $headers->get('Content-Type');

$values = $headers->getAll('Set-Cookie');

$exists = $headers->has('X-Request-Id');
```

Set and append values:

```php
$headers->set(
    'Cache-Control',
    'no-store'
);

$headers->add(
    'Set-Cookie',
    'theme=dark; Path=/'
);
```

Remove headers:

```php
$headers->remove('X-Request-Id');
```

Return all values:

```php
$all = $headers->all();
```

Flatten values into strings:

```php
$flattened = $headers->flatten();
```

Create modified copies:

```php
$updated = $headers->with(
    'Content-Type',
    'text/plain'
);

$withoutCache = $updated->without(
    'Cache-Control'
);
```

Header names and values are validated to prevent invalid names and line-break injection.

## Response

Create a basic response:

```php
<?php

declare(strict_types=1);

use Ivi\Http\Response;

$response = new Response(
    body: 'Hello',
    statusCode: 200,
    headers: [
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]
);
```

## Text responses

```php
$response = Response::text(
    'Hello from IviPHP'
);
```

With a custom status:

```php
$response = Response::text(
    'Resource created',
    201
);
```

## HTML responses

```php
$response = Response::html(
    '<h1>Welcome</h1>'
);
```

The default content type is:

```text
text/html; charset=UTF-8
```

## JSON responses

```php
$response = Response::json([
    'success' => true,
    'data' => [
        'id' => 42,
        'name' => 'Product',
    ],
]);
```

With a status code:

```php
$response = Response::json(
    [
        'message' => 'Product created.',
    ],
    201
);
```

Invalid JSON values throw an `InvalidArgumentException` containing the original `JsonException`.

## Redirect responses

```php
$response = Response::redirect(
    '/dashboard'
);
```

Permanent redirect:

```php
$response = Response::redirect(
    '/new-location',
    301
);
```

Temporary method-preserving redirect:

```php
$response = Response::redirect(
    '/maintenance',
    307
);
```

Redirect locations are validated against empty values and response-header injection.

## Empty responses

```php
$response = Response::noContent();
```

Equivalent form:

```php
$response = Response::empty(204);
```

Informational responses, `204 No Content` and `304 Not Modified` cannot contain a body.

## Reading a response

```php
$status = $response->statusCode();
$reason = $response->reasonPhrase();
$body = $response->body();
$length = $response->bodyLength();
$contentType = $response->contentType();

$headers = $response->headers();
```

Check the response category:

```php
$response->isInformational();
$response->isSuccessful();
$response->isRedirect();
$response->isClientError();
$response->isServerError();
$response->isError();
$response->isEmpty();
```

## Transforming a response

Responses support immutable transformations.

```php
$updated = $response
    ->withStatus(201)
    ->withHeader('X-Request-Id', 'request-123')
    ->withAddedHeader('Set-Cookie', 'theme=dark; Path=/')
    ->withContentLength();
```

Replace the body:

```php
$updated = $response->withBody(
    'Updated response'
);
```

Remove a header:

```php
$updated = $response->withoutHeader(
    'X-Internal-Header'
);
```

The original response remains unchanged.

## Sending a response

```php
$response
    ->withContentLength()
    ->send();
```

Send headers and status without the body:

```php
$response->send(
    sendBody: false
);
```

Sending after PHP output has already started throws a `RuntimeException`.

## HTTP exceptions

Create a generic HTTP exception:

```php
use Ivi\Http\Exceptions\HttpException;

throw new HttpException(
    statusCode: 400,
    message: 'The request is invalid.'
);
```

Common factories are available:

```php
throw HttpException::badRequest();

throw HttpException::unauthorized(
    challenge: 'Bearer'
);

throw HttpException::forbidden();

throw HttpException::notFound();

throw HttpException::conflict();

throw HttpException::contentTooLarge();

throw HttpException::unsupportedMediaType();

throw HttpException::unprocessable();

throw HttpException::internalServerError();
```

Method not allowed:

```php
throw HttpException::methodNotAllowed([
    'GET',
    'POST',
]);
```

Rate limiting:

```php
throw HttpException::tooManyRequests(
    retryAfter: 60
);
```

Temporary service outage:

```php
throw HttpException::serviceUnavailable(
    retryAfter: 120
);
```

## Exception headers

Some exception factories include HTTP response headers automatically.

```php
$exception = HttpException::methodNotAllowed([
    'GET',
    'POST',
]);

$allow = $exception->header('Allow');
```

Other examples include:

- `WWW-Authenticate` for unauthorized requests;
- `Retry-After` for rate limits;
- `Retry-After` for temporary service outages.

## Exception context

Internal diagnostic data can be attached to an exception:

```php
throw HttpException::notFound(
    message: 'The product was not found.',
    context: [
        'product_id' => 42,
        'request_id' => 'request-123',
    ]
);
```

Read the context:

```php
$context = $exception->context();
```

Exception context is intended for logs and debugging. It must not be returned directly to clients without filtering.

## Converting an exception to a response

```php
try {
    throw HttpException::notFound();
} catch (HttpException $exception) {
    $response = Response::json(
        [
            'error' => [
                'status' => $exception->statusCode(),
                'message' => $exception->getMessage(),
            ],
        ],
        $exception->statusCode(),
        $exception->headers()
    );
}
```

Production applications should avoid exposing internal exception messages and context for server errors.

## Available classes

### `Headers`

```php
$headers->has($name);
$headers->get($name, $default);
$headers->getAll($name);
$headers->set($name, $value);
$headers->add($name, $value);
$headers->remove($name);
$headers->all();
$headers->flatten();
$headers->clear();
$headers->with($name, $value);
$headers->without($name);
```

### `Request`

```php
Request::fromGlobals();

$request->method();
$request->isMethod($method);
$request->uri();
$request->path();
$request->queryString();

$request->headers();
$request->hasHeader($name);
$request->header($name, $default);
$request->headerValues($name);
$request->contentType();
$request->isJson();
$request->contentLength();
$request->bearerToken();

$request->queryParameters();
$request->query($key, $default);
$request->hasQuery($key);

$request->parsedBody();
$request->bodyParameter($key, $default);
$request->rawBody();
$request->json($key, $default);

$request->allInput();
$request->input($key, $default);
$request->hasInput($key);

$request->cookies();
$request->cookie($name, $default);
$request->hasCookie($name);

$request->uploadedFiles();
$request->file($key);
$request->hasFile($key);

$request->serverParameters();
$request->server($key, $default);
$request->isSecure();

$request->attributes();
$request->attribute($name, $default);
$request->hasAttribute($name);

$request->withMethod($method);
$request->withUri($uri);
$request->withHeader($name, $value);
$request->withoutHeader($name);
$request->withParsedBody($body);
$request->withAttribute($name, $value);
$request->withoutAttribute($name);
```

### `UploadedFile`

```php
UploadedFile::fromArray($file);
UploadedFile::fromPath($path);

$file->temporaryPath();
$file->path();
$file->movedPath();

$file->clientFilename();
$file->safeClientFilename($fallback);
$file->clientExtension();

$file->clientMediaType();
$file->mediaType();

$file->size();
$file->declaredSize();
$file->error();
$file->errorMessage();

$file->isHttpUpload();
$file->isValid();
$file->hasMoved();

$file->openStream();
$file->checksum($algorithm);

$file->assertMaximumSize($maximumBytes);
$file->assertMediaType($allowedMediaTypes);

$file->moveTo($destination, $overwrite, $permissions);
$file->compressTo($destination, $level, $overwrite);
```

### `Response`

```php
Response::text($body, $statusCode, $headers);
Response::html($body, $statusCode, $headers);
Response::json($data, $statusCode, $headers);
Response::redirect($location, $statusCode, $headers);
Response::empty($statusCode, $headers);
Response::noContent($headers);

$response->statusCode();
$response->reasonPhrase();
$response->body();
$response->bodyLength();
$response->isEmpty();
$response->allowsBody();

$response->headers();
$response->hasHeader($name);
$response->header($name, $default);
$response->headerValues($name);
$response->contentType();

$response->isInformational();
$response->isSuccessful();
$response->isRedirect();
$response->isClientError();
$response->isServerError();
$response->isError();

$response->withStatus($statusCode);
$response->withBody($body);
$response->withHeader($name, $value);
$response->withAddedHeader($name, $value);
$response->withoutHeader($name);
$response->withContentLength();

$response->send($sendBody);
```

### `HttpException`

```php
HttpException::badRequest();
HttpException::unauthorized();
HttpException::forbidden();
HttpException::notFound();
HttpException::methodNotAllowed($allowedMethods);
HttpException::conflict();
HttpException::contentTooLarge();
HttpException::unsupportedMediaType();
HttpException::unprocessable();
HttpException::tooManyRequests();
HttpException::internalServerError();
HttpException::serviceUnavailable();

$exception->statusCode();
$exception->headers();
$exception->header($name, $default);
$exception->context();
$exception->isClientError();
$exception->isServerError();
```

## Design principles

- Small framework-independent HTTP primitives
- Case-insensitive and validated headers
- Immutable request and response transformations
- Explicit JSON parsing and encoding failures
- Safe normalization of PHP file uploads
- Content-based upload media detection
- Stream-oriented large-file handling
- No automatic trust of proxy headers
- No automatic trust of client upload metadata
- No hidden global request or response state
- Explicit exception status codes and headers

## Package boundaries

This package is responsible for:

- representing incoming requests;
- representing outgoing responses;
- managing HTTP headers;
- normalizing uploaded files;
- securely moving and optionally compressing uploads;
- representing HTTP failures.

It is not responsible for:

- routing;
- middleware pipelines;
- controllers;
- sessions;
- authentication;
- CSRF protection;
- validation rules;
- template rendering;
- application lifecycle management.

These responsibilities belong to other IviPHP packages.

## Ecosystem

This package is part of the IviPHP ecosystem:

- `iviphp/contracts`
- `iviphp/support`
- `iviphp/config`
- `iviphp/container`
- `iviphp/validation`
- `iviphp/database`
- `iviphp/cache`
- `iviphp/view`
- `iviphp/auth`
- `iviphp/framework`

## Contributing

Contributions should preserve the focused scope of the package.

Changes should:

- remain independent from the full framework;
- avoid global request or response state;
- preserve header-injection protections;
- preserve safe upload handling;
- avoid trusting client-controlled file metadata;
- avoid loading large files entirely into memory;
- keep compression explicit;
- provide clear exceptions for invalid HTTP operations.

## Security

Please report security issues privately to the Softadastra maintainers instead of opening a public issue.

## License

This project is licensed under the MIT License.

## Maintainer

Created and maintained by [Softadastra](https://softadastra.com/).
