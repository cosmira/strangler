# Yii Strangler Proxy

A Strangler Fig adapter for gradually replacing legacy Yii 1.1 applications.
Forward migrated controller actions to a modern HTTP backend while the remaining
actions keep running in Yii; retire the legacy implementation one route at a time.

Supports PHP 8.2 through 8.5, Yii 1.1 and Guzzle 7.

## Installation

This package is under development and has no tagged release yet. For local development,
add a Composer `path` repository pointing to this checkout and require `cosmira/strangler`.
Load Composer's `vendor/autoload.php` before the Yii bootstrap.

## Yii configuration

```php
'params' => [
    'strangler' => [
        'base_uri' => 'https://backend.example',
        'timeout' => 10,
        'connect_timeout' => 3,
        'verify_ssl' => true,
        'allow_redirects' => false,
        'log_requests' => false,
        'features' => ['catalog' => true],
    ],
],
```

`base_uri` must be explicitly configured. A missing URI produces a JSON 500 response.
Feature flags default to disabled. Disabling a flag or omitting an action's route
leaves the request in Yii.

## Controller filter

```php
use Cosmira\Strangler\Strangler;

public function filters()
{
    return [
        Strangler::proxy('catalog')
            ->get('get', '/api/items')
            ->get('view', '/api/items/{id}')
            ->post('create', '/api/items')
            ->put('update', '/api/items/{id}')
            ->delete('delete', '/api/items/{id}')
            ->build(),
    ];
}
```

Action names are case-insensitive. Path placeholders are URL-encoded; `id` comes
from the Yii request. `bodyIdentifier('ID')` supplies a fallback before a modifier
removes identifiers from the payload. Other placeholders use the transformed
payload, then the original query, accepting their upper-case legacy keys too.

Payloads default to decoded JSON objects or arrays. Override their extraction with
`payloadUsing(callable $reader)`, which receives the `CController` and returns an array.
The route determines the outgoing method. POST, PUT, PATCH and DELETE send JSON;
GET forwards query parameters without a body. The Yii routing parameter `r` is removed.

## Application-owned behavior

`usingModifier()` accepts a `StranglerModifierInterface` instance, class name or Yii
component configuration. The modifier transforms the query, payload and response.
Field mappings and domain-specific compatibility rules belong to the application.
An invalid configured modifier produces a JSON 500 response before sending a request.

`bypassUsing(callable $bypass)` receives the controller, normalized action and original
query. Returning `true` keeps the request in Yii. `bypassWhenPayloadHas('update', ['special'])`
also keeps a request in Yii when a listed payload key exists, including a `null` value.

`afterRequest(callable $callback)` receives the outgoing method and resolved path once
after receiving an upstream response, before response transformation, or after a Guzzle
transport exception. Applications can use it to notify their existing UI. It does not
mean a write committed: an error or timeout can leave the upstream outcome uncertain.
Exceptions raised by application callbacks remain visible to Yii.

## HTTP behavior

The proxy forwards request headers except Host, Content-Length and Connection, then
sets `X-User-Id`, `X-Strangler`, `X-Strangler-Locale` and JSON Accept headers. Those
identity headers describe the current Yii user; the receiving application owns trust
validation and authorization.

Upstream error responses retain their status and body, including responses with a
Location header. Content-Length and Transfer-Encoding response headers are omitted.
Guzzle transport errors become JSON 503 responses. Requests are never retried by the
package. HTTP error exceptions and redirects are disabled by default, including
when a `ClientInterface` is supplied through the filter's `client` property.

The default timeouts are 10 seconds total and 3 seconds to connect. Configure TLS
verification explicitly; `verify_ssl` currently defaults to `false` to retain the
legacy proxy's behavior. `X-Strangler-Time` is included when `YII_DEBUG` is enabled.

## Tests and ownership

```sh
composer install
composer test -- --order-by=random
```

Package tests use actual Yii filters and Guzzle MockHandler; no remote backend or
application database is needed. Host applications retain their own end-to-end contract
tests for permissions, business results and legacy compatibility. This initial extraction
does not include application-specific REST mappers or install itself into a host project.
