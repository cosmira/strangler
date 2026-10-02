# Integrating Strangler safely

Strangler routes requests; your application owns access rules, the API contract and
the deployment procedure. Complete these steps for each feature before enabling it.

## Access checks and Yii hooks

Put authentication, HTTP verb checks, CSRF validation for session-authenticated
writes, input validation and resource permissions **before Strangler**, in filters
that complete their checks before calling `$filterChain->run()`.
Use your actual application's filters; names in the README are examples.

Forwarding skips the legacy action, `beforeAction()` and `afterAction()`, including
inherited controller implementations. Ending the proxied response also prevents
previous filters from returning to `postFilter()`. Audit transaction cleanup and
logging in those hooks. If cleanup is necessary, use a tested request-end mechanism
such as Yii's `onEndRequest`; it is not a place to defer an access decision.

Strangler does not infer resource permissions from `X-User-Id`. Decide which checks
remain in Yii and which business invariants the new backend must enforce. A public
Yii action may forward a guest request; its user identifier is an empty string.

## Trusted channel

The backend must accept identity headers only from your Yii application. A private
network or authenticated gateway can provide this trust. If you choose a shared
service token, configure it on the server, never from the incoming client's header.
Use HTTPS and a secret supplied through your deployment's environment on both sides.

In Yii's configuration bootstrap:

```php
$token = getenv('STRANGLER_TOKEN');
if (!is_string($token) || $token === '') {
    throw new RuntimeException('STRANGLER_TOKEN must be configured.');
}

$catalogEnabled = filter_var(
    getenv('STRANGLER_CATALOG_ENABLED') ?: 'false',
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE,
) ?? false;

// Add this under Yii's params.
$strangler = [
    'base_uri' => 'https://backend.example',
    'headers' => ['X-Strangler-Token' => $token],
    'forward_headers' => ['Accept-Language', 'X-Request-Id', 'Idempotency-Key'],
    'features' => ['catalog' => $catalogEnabled],
    'timeout' => 10,
    'connect_timeout' => 3,
];
```

Assign `$strangler` to `params.strangler`. Only boolean `true` enables a feature;
strings such as `'false'` do not. Parse environment values explicitly as above.

At the new backend's entry point, before routing or using `X-User-Id`:

```php
$expected = getenv('STRANGLER_TOKEN');
$provided = $_SERVER['HTTP_X_STRANGLER_TOKEN'] ?? '';

if (!is_string($expected) || $expected === '') {
    http_response_code(503);
    exit;
}

if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit;
}

$userId = $_SERVER['HTTP_X_USER_ID'] ?? ''; // Empty for a permitted guest request.
```

Verify that a direct call without the token fails and a permitted Yii request works.
A client's `X-Strangler-Token` is discarded; only the server configuration supplies
it. The diagnostic `X-Strangler: 1` must never authorize a request. Rotate the token
through your deployment process; keep it out of repositories and request logs.

## Headers, TLS and payloads

By default, only `Accept-Language`, `X-Request-Id` and `Idempotency-Key` are forwarded
from clients. Set `forward_headers` to the additional business headers your API
requires. `headers` contains server-owned values, which override forwarded values.
Client identity, marker, locale and token headers are never forwarded, even if listed;
transport-specific headers are excluded too.

Cookie and Authorization are excluded by default. An explicit `forward_headers`
entry can opt in only when your backend contract requires those client credentials;
this is a deliberate change from the normal Yii-owned session model. Server-owned
backend credentials belong in `headers`. Backend `Set-Cookie` is always removed.
The JSON request has its own Content-Type. If an adapter rewrites a response body,
set its Content-Type and remove obsolete ETag, compression and cache metadata; the
[catalog example](api-adaptation.md) shows an explicit response-header policy.

Certificate verification defaults to `true`. For an internal certificate authority,
set `verify_ssl` to an absolute CA-bundle path. Disable it only deliberately in a
local development environment. Positive finite `timeout` and `connect_timeout`
values are in seconds; invalid values, including zero, use the defaults of 10 and 3.

The default payload reader accepts a JSON object or array. An empty body means no
payload; malformed JSON, `null` and scalar JSON return `400` before forwarding.
`payloadUsing()` reads form fields explicitly and must return an array; validate its
structure in the preceding filters. The filter uses one payload snapshot for the
bypass decision and forwarding. File uploads and arbitrary raw bodies are outside
this JSON adapter's scope.

## Rollout and rollback

1. Keep the feature disabled while adapting and testing the old client's contract.
2. Verify the outgoing URL, method, body and query; then test successful responses,
   validation errors, denied access, malformed input and backend timeouts.
3. Agree who writes each dataset and what Yii will see if the feature is disabled.
   A flag changes routing; it does not migrate data back or restore an old schema.
4. Define how deployment or support staff change the flag and reload configuration.
   PHP workers may reload config per request; cached config or long-running workers
   need your application's explicit reload procedure. Check the next request.
5. Enable one adapted action, observe it, and expand only after compatibility holds.

Turning off a feature affects subsequent requests after configuration is reloaded.
In-flight requests continue with the executor already chosen. If new data is no
longer compatible with Yii, rollback requires a data plan, not just a flag change.

Requests are never automatically retried or replayed in Yii. Forwarded
`Idempotency-Key` works only if the backend defines and enforces that contract;
the package does not deduplicate operations by itself.

For repeatable deployments, check out the reviewed package commit, retain the
application's `composer.lock`, and run `composer install`. A local path repository
must point to that same checkout when Composer installs it. A branch name alone
is not a fixed deployment version.

## Logging and callbacks

Set `params.strangler.log_requests` to `true`. Add a Yii log route if the application
does not already collect the category:

```php
'components' => [
    'log' => [
        'class' => 'CLogRouter',
        'routes' => [[
            'class' => 'CFileLogRoute',
            'levels' => 'info, warning, error',
            'categories' => 'strangler',
            'logFile' => 'strangler.log',
        ]],
    ],
],
```

If a request stays in Yii, check the feature's boolean value, its mapped action and
configured bypass conditions. Use your existing `X-Request-Id` to correlate systems;
Strangler forwards it but does not create a new correlation service.

The optional `afterRequest($method, $path)` callback runs after a transport attempt,
including failure, **before response mapping**. It does not receive the final client
response. Keep it small: a thrown callback or adapter exception reaches Yii's error
handler and can prevent delivery of a response even after a successful backend
write. Test these hooks and never replay writes to compensate for their errors.
