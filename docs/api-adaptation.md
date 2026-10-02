# Adapting different API contracts

Changing the destination URL is enough only when both APIs accept the same requests
and return the same responses. When their contracts differ, provide a modifier that
translates between them. The existing client continues using the legacy API.

```text
legacy client → Yii checks → request mapping → new API
legacy client ← legacy response format ← response mapping ← new API
```

## 1. Define the mapping for one feature

For example, the legacy catalog API and its replacement have these contracts:

| Operation | Legacy API | New API |
| --- | --- | --- |
| List items | `GET /index.php?r=catalog/get&start=20&limit=10` | `GET /api/items?page=3&per_page=10` |
| Create an item | `POST /index.php?r=catalog/create` | `POST /api/items` |
| Update an item | `POST /index.php?r=catalog/update`, with `ID` in the body | `PUT /api/items/42` |

The update request sent by the existing client is:

```json
{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}
```

The new API expects the identifier in the URL and a different body structure:

```json
{"product":{"title":"Desk","enabled":true},"category":{"id":7}}
```

It responds with:

```json
{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}
```

The existing client still expects:

```json
{"success":true,"data":{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}}
```

Agree on field names, nesting, types, pagination, statuses and error formats before
enabling the feature. The examples below implement this specific catalog contract;
adapt them to your application's validated input and backend response schema.

## 2. Implement the translations

Create an application-owned **API contract adapter**. `AbstractStranglerModifier`
implements unchanged query, payload and response behavior; override the methods
your contract needs. It implements the existing `StranglerModifierInterface`:

```php
use Cosmira\Strangler\AbstractStranglerModifier;

final class CatalogApiModifier extends AbstractStranglerModifier
{
    public function transformQuery(string $actionId, array $query): array
    {
        if ($actionId !== 'get') {
            return [];
        }

        $limit = max(1, (int) ($query['limit'] ?? 20));
        $start = max(0, (int) ($query['start'] ?? 0));

        return [
            'page' => intdiv($start, $limit) + 1,
            'per_page' => $limit,
        ];
    }

    public function transformPayload(string $actionId, array $payload): array
    {
        if (!in_array($actionId, ['create', 'update'], true)) {
            return [];
        }

        return [
            'product' => [
                'title' => $payload['NAME'],
                'enabled' => (bool) $payload['ACTIVE'],
            ],
            'category' => ['id' => (int) $payload['CATEGORY_ID']],
        ];
    }

    public function transformResponse(
        string $actionId,
        int $status,
        string $body,
        array $headers,
    ): array {
        // Preserve redirects and empty responses.
        if ($status < 200 || ($status >= 300 && $status < 400) || $body === '') {
            return ['status' => $status, 'body' => $body, 'headers' => $headers];
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400) {
            $legacy = [
                'success' => false,
                'error' => [
                    'code' => $response['code'] ?? $response['error'] ?? 'request_failed',
                    'fields' => $response['errors'] ?? [],
                ],
            ];
        } elseif ($actionId === 'get') {
            $legacy = [
                'success' => true,
                'total' => $response['meta']['total'],
                'rows' => array_map($this->legacyItem(...), $response['data']),
            ];
        } else {
            $legacy = ['success' => true, 'data' => $this->legacyItem($response['data'])];
        }

        $headers = ['Content-Type' => ['application/json; charset=utf-8']];

        return [
            'status' => $status,
            'body' => json_encode($legacy, JSON_THROW_ON_ERROR),
            'headers' => $headers,
        ];
    }

    private function legacyItem(array $item): array
    {
        return [
            'ID' => $item['id'],
            'NAME' => $item['title'],
            'ACTIVE' => $item['enabled'] ? 1 : 0,
            'CATEGORY_ID' => $item['category']['id'],
        ];
    }
}
```

For a smaller change, extend the same base class and override only the relevant
method; the other two remain unchanged. No new configuration layer is required.

Each method has a distinct purpose:

- `transformQuery()` returns the query parameters the new API accepts. Here it replaces
  offset pagination with page pagination and omits the old parameters.
- `transformPayload()` returns the new JSON body. Here it renames fields, creates nested
  objects and converts legacy integer flags to booleans. `ID` is deliberately left out.
- `transformResponse()` returns the status, a **serialized response body**, and headers
  for the existing client. Here it maps both individual items and paginated lists.

For the list action, the example expects the backend to return
`{"data":[...],"meta":{"total":123}}` and returns
`{"success":true,"rows":[...],"total":123}` to the legacy client.
Action names passed to the modifier are lowercase: `get`, `create`, `update`.

The pagination conversion is exact when `start` is a multiple of `limit`. The bypass
rule below keeps other offsets in Yii **before any upstream request**, so the example
never silently rounds to a different set of items. Alternatively, retain offset
support in the new backend.

The example emits JSON with a Content-Type header. Add any other headers required by
the legacy contract deliberately; upstream cache validators such as ETag describe the
original body and should not be reused unchanged after rewriting it.

The same method maps backend errors and package-generated errors. For example:

| Failure | Response passed to the adapter | Legacy response |
| --- | --- | --- |
| Validation | `422 {"code":"validation_failed","errors":{"title":["Required"]}}` | `422 {"success":false,"error":{"code":"validation_failed","fields":{"title":["Required"]}}}` |
| Timeout | `503 {"state":"error","error":"service_temporarily_unavailable"}` | `503 {"success":false,"error":{"code":"service_temporarily_unavailable","fields":[]}}` |

The status remains an error. Invalid JSON or missing path parameters produce a `400`
through this response adapter too. If the configured adapter cannot be created,
Strangler returns its own configuration error because there is no adapter to call.
Malformed backend JSON or a bug in your adapter reaches Yii's application error
handler; test that scenario. Do not report failures as success or replay failed writes.

## 3. Connect the modifier and routes

Make the modifier available through your application's autoloading. In the controller
from [Getting started](../README.md#getting-started), replace the Strangler filter entry
with this configuration, keeping it **last**, after the existing security checks:

```php
Strangler::proxy('catalog')
    ->usingModifier(new CatalogApiModifier())
    ->bodyIdentifier('ID')
    ->bypassUsing(static function (CController $controller, string $actionId, array $query): bool {
        $limit = max(1, (int) ($query['limit'] ?? 20));
        $start = max(0, (int) ($query['start'] ?? 0));

        return $actionId === 'get' && $start % $limit !== 0;
    })
    ->get('get', '/api/items')
    ->post('create', '/api/items')
    ->put('update', '/api/items/{id}')
    ->build()
```

The first route argument names the **Yii action**; the second is the **new API path**.
The builder method chooses the outgoing HTTP method. For example, `put('update', ...)`
turns a legacy POST to `actionUpdate` into an upstream PUT.

`bodyIdentifier('ID')` supplies `{id}` from the original payload when the Yii request
has no `id` parameter. Strangler captures it before calling the adapter, so removing
`ID` from the outgoing body still produces `/api/items/42`. The request's `id` takes
precedence. Other placeholders use the translated body, then translated query, then
original query, accepting lowercase or uppercase keys. Values are URL-encoded;
unresolved parameters return `400` instead of sending an incomplete path.

Each Yii action can have one route; duplicate declarations are rejected. The builder
also provides `patch()` and `head()`. These methods set the outgoing HTTP method,
while the preceding Yii filters enforce permitted incoming methods and CSRF rules.

By default, Strangler reads a JSON body. If the old client sends form fields such as
`item[ID]=42&item[NAME]=Desk&item[ACTIVE]=1&item[CATEGORY_ID]=7`, add a payload reader:

```php
->payloadUsing(static fn(CController $controller): array => $_POST['item'] ?? [])
```

Add this to the same builder chain, retaining its adapter and bypass rule. For flat
form fields, read `$_POST` instead of `$_POST['item']`. The reader must return an array;
validate the submitted structure in the preceding Yii filters. Form fields become
JSON upstream; this does not forward uploaded files.

## 4. Enable the feature after checking compatibility

Keep `features.catalog` disabled until the existing client works with the translated
requests and responses. Check the outgoing URL, method, query and body, then verify
success, validation errors, permissions and timeouts from the client's perspective.

The package tests execute this catalog adapter with mock HTTP responses. Before
rollout, repeat these contract checks against your application's actual client and
backend; source coverage does not prove feature compatibility.

Only add routes whose contracts you have adapted. Unmapped actions continue in Yii.
If an action has a legacy-only payload variant, keep that variant in Yii explicitly:

```php
->bypassWhenPayloadHas('update', ['LEGACY_ONLY'])
```

This decision happens before any upstream request. Turning off the feature also keeps
subsequent requests in Yii; neither mechanism retries an operation already sent upstream.
