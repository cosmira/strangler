<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use CException;
use CLogger;
use Closure;
use CWebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use LogicException;
use Yii;

/**
 * @phpstan-import-type Config from Strangler
 */
final readonly class StranglerProxy
{
    /**
     * @var Closure(): float
     */
    private Closure $clock;

    /**
     * Send responses through the Yii output boundary.
     */
    private ResponseEmitter $emitter;

    /**
     * Supply a client and optional output/clock adapters.
     *
     * @param Closure(): float|null $clock
     */
    public function __construct(
        /**
         * Upstream HTTP client, created lazily when omitted.
         */
        private ?ClientInterface $client = null,
        ?ResponseEmitter $emitter = null,
        ?Closure $clock = null,
    ) {
        $this->emitter = $emitter ?? new ResponseEmitter();
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param Config $config
     */
    public static function shouldHandle(array $config, string $actionId): bool
    {
        if (! self::isEnabled($config['feature'] ?? null)) {
            return false;
        }

        $routes = $config['routes'] ?? [];
        $actionKey = self::normalizeActionKey($actionId);

        return isset($routes[$actionKey]);
    }

    /**
     * @param Config $config
     */
    public static function shouldBypass(
        CController $controller,
        array $config,
        string $actionId,
    ): bool {
        $actionKey = self::normalizeActionKey($actionId);
        $bypass = $config['bypass'] ?? null;
        if ($bypass !== null && $bypass($controller, $actionKey, $_GET)) {
            return true;
        }
        $bypassFields = $config['bypassPayloadFields'] ?? [];
        $fields = $bypassFields[$actionKey] ?? [];

        if ($fields === []) {
            return false;
        }

        $payload = self::readPayload($controller, $config);

        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Config $config
     */
    public function handle(CController $controller, array $config, string $actionId): void
    {
        $actionKey = self::normalizeActionKey($actionId);
        $routes = $config['routes'] ?? [];
        $route = $routes[$actionKey] ?? null;

        if ($route === null) {
            return;
        }

        $settings = self::settings();
        $baseUri = self::baseUri($settings);

        if ($baseUri === '') {
            $this->sendError(500, 'strangler_not_configured');

            return;
        }

        $request = self::application()->getRequest();
        $startedAt = ($this->clock)();

        $modifierConfig = $config['modifier'] ?? null;
        $modifier = self::resolveModifier($modifierConfig);
        if ($modifier === null && self::hasModifierConfig($modifierConfig)) {
            $this->sendError(500, 'strangler_modifier_not_configured');

            return;
        }

        $query = $_GET;
        unset($query['r']);

        $payload = self::readPayload($controller, $config);
        $routeId = self::routeIdentifier($request->getParam('id'), $payload, $config);
        $forwardPayload = $payload;
        $forwardQuery = $query;

        if ($modifier !== null) {
            $forwardQuery = $modifier->transformQuery($actionKey, $forwardQuery);
            $forwardPayload = $modifier->transformPayload($actionKey, $forwardPayload);
        }

        $path = self::resolvePath($route['path'], $query, $forwardPayload, $routeId);
        $method = strtoupper($route['method']);

        try {
            $client = $this->client ?? new Client();

            $options = self::requestOptions($settings, $method, $forwardQuery, $forwardPayload);
            $response = $client->request($method, $path, $options);
        } catch (GuzzleException $exception) {
            self::afterRequest($config, $method, $path);

            Yii::log(
                sprintf(
                    'Strangler request failed | action: %s | path: %s | error: %s',
                    $actionId,
                    $path,
                    $exception->getMessage(),
                ),
                CLogger::LEVEL_ERROR,
                'strangler',
            );

            $this->sendError(503, 'service_temporarily_unavailable');

            return;
        }

        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();
        $responseHeaders = $response->getHeaders();

        self::afterRequest($config, $method, $path);

        if ($modifier !== null) {
            $transformed = $modifier->transformResponse(
                $actionKey,
                $statusCode,
                $responseBody,
                $responseHeaders,
            );

            $statusCode = $transformed['status'];
            $responseBody = $transformed['body'];
            $responseHeaders = $transformed['headers'];
        }

        $timeMs = (int) round((($this->clock)() - $startedAt) * 1000);

        self::debugLog(sprintf(
            'Strangler request proxied | feature: %s | action: %s | method: %s'
                .' | base_uri: %s | path: %s | status: %s | time_ms: %s',
            (string) ($config['feature'] ?? ''),
            $actionId,
            $method,
            $baseUri,
            $path,
            $statusCode,
            $timeMs,
        ));

        $this->emitter->send(
            $statusCode,
            $responseBody,
            $responseHeaders,
            $timeMs,
        );
    }

    /**
     * @param array<array-key, mixed> $settings
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function requestOptions(
        array $settings,
        string $method,
        array $query,
        array $payload,
    ): array {
        $headers = array_merge(self::extractForwardHeaders(), [
            'X-User-Id'          => self::userId(),
            'X-Strangler'        => '1',
            'X-Strangler-Locale' => self::application()->getLanguage(),
            'Accept'             => 'application/json',
        ]);

        $options = [
            RequestOptions::HEADERS         => $headers,
            RequestOptions::QUERY           => $query,
            'base_uri'                      => self::baseUri($settings),
            RequestOptions::HTTP_ERRORS     => false,
            RequestOptions::ALLOW_REDIRECTS => (bool) ($settings['allow_redirects'] ?? false),
            RequestOptions::VERIFY          => (bool) ($settings['verify_ssl'] ?? false),
            RequestOptions::TIMEOUT         => self::timeout($settings, 'timeout', 10),
            RequestOptions::CONNECT_TIMEOUT => self::timeout($settings, 'connect_timeout', 3),
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $options[RequestOptions::JSON] = $payload;
        }

        return $options;
    }

    /**
     * Normalize legacy action names for route lookup.
     */
    private static function normalizeActionKey(string $actionId): string
    {
        return strtolower($actionId);
    }

    /**
     * @param Config $config
     *
     * @return array<array-key, mixed>
     */
    private static function readPayload(CController $controller, array $config): array
    {
        $reader = $config['payloadReader'] ?? null;

        if ($reader !== null) {
            return $reader($controller);
        }

        $decoded = json_decode((string) self::application()->getRequest()->getRawBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param Config $config
     */
    private static function afterRequest(array $config, string $method, string $path): void
    {
        $callback = $config['afterRequest'] ?? null;
        if ($callback !== null) {
            $callback($method, $path);
        }
    }

    /**
     * Read the named feature flag from application settings.
     */
    private static function isEnabled(?string $feature): bool
    {
        if ($feature === null || $feature === '') {
            return false;
        }

        $strangler = self::settings();
        $features = $strangler['features'] ?? [];
        if (! is_array($features)) {
            return false;
        }

        return (bool) ($features[$feature] ?? false);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function settings(): array
    {
        $settings = self::application()->getParams()->itemAt('strangler') ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function baseUri(array $settings): string
    {
        $uri = $settings['base_uri'] ?? '';

        return is_string($uri) ? rtrim(trim($uri), '/') : '';
    }

    /**
     * Determine whether a modifier was explicitly configured.
     */
    private static function hasModifierConfig(mixed $modifierConfig): bool
    {
        return ! ($modifierConfig === null || $modifierConfig === '' || $modifierConfig === []);
    }

    /**
     * Resolve an instance or Yii component configuration.
     */
    private static function resolveModifier(mixed $modifierConfig): ?StranglerModifierInterface
    {
        if (! self::hasModifierConfig($modifierConfig)) {
            return null;
        }

        if ($modifierConfig instanceof StranglerModifierInterface) {
            return $modifierConfig;
        }

        if (is_string($modifierConfig)) {
            $modifierConfig = ['class' => $modifierConfig];
        }

        if (! is_array($modifierConfig) || ! is_string($modifierConfig['class'] ?? null)) {
            Yii::log(
                'Strangler modifier config must contain class',
                CLogger::LEVEL_WARNING,
                'strangler',
            );

            return null;
        }

        return self::createModifier($modifierConfig);
    }

    /**
     * Instantiate and validate an application-owned Yii modifier.
     *
     * @param array{class: string, ...} $modifierConfig
     */
    private static function createModifier(array $modifierConfig): ?StranglerModifierInterface
    {
        try {
            $modifier = Yii::createComponent($modifierConfig);
        } catch (CException $exception) {
            Yii::log(
                sprintf(
                    'Strangler modifier could not be created: %s | error: %s',
                    $modifierConfig['class'],
                    $exception->getMessage(),
                ),
                CLogger::LEVEL_WARNING,
                'strangler',
            );

            return null;
        }

        if (! $modifier instanceof StranglerModifierInterface) {
            Yii::log(
                sprintf(
                    'Strangler modifier must implement StranglerModifierInterface: %s',
                    $modifierConfig['class'],
                ),
                CLogger::LEVEL_WARNING,
                'strangler',
            );

            return null;
        }

        return $modifier;
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     */
    private static function resolvePath(
        string $pathTemplate,
        array $query,
        array $body,
        mixed $routeId,
    ): string {
        $routeId = is_scalar($routeId) ? (string) $routeId : null;

        $pattern = '/\{([^}]+)\}/';

        return (string) preg_replace_callback($pattern, static function (array $matches) use (
            $query,
            $body,
            $routeId
        ): string {
            $placeholder = $matches[1];
            $upper = strtoupper($placeholder);

            if ($placeholder === 'id' && $routeId !== null && $routeId !== '') {
                return rawurlencode($routeId);
            }

            $value = $body[$placeholder] ?? $body[$upper]
                ?? $query[$placeholder] ?? $query[$upper] ?? null;

            if (! is_scalar($value) || $value === '') {
                return '';
            }

            return rawurlencode((string) $value);
        }, $pathTemplate);
    }

    /**
     * Emit a JSON error and end the Yii request.
     */
    private function sendError(int $status, string $code): void
    {
        $this->emitter->error($status, $code);
    }

    /**
     * Log request details when debugging or request logging is enabled.
     */
    private static function debugLog(string $message): void
    {
        $settings = self::settings();

        $debug = YII_DEBUG;
        $logRequests = $settings['log_requests'] ?? false;
        if (! $debug && ! $logRequests) {
            return;
        }

        Yii::log($message, CLogger::LEVEL_INFO, 'strangler');
    }

    /**
     * @return array<string, string>
     */
    private static function extractForwardHeaders(): array
    {
        $result = [];
        foreach ($_SERVER as $key => $value) {
            if (! str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $headerName = str_replace('_', '-', substr($key, 5));
            $headerName = implode('-', array_map(
                ucfirst(...),
                explode('-', strtolower($headerName)),
            ));

            if (in_array($headerName, ['Host', 'Content-Length', 'Connection'], true)) {
                continue;
            }

            if (is_string($value)) {
                $result[$headerName] = $value;
            }
        }

        return $result;
    }

    /**
     * Require the web application used by this Yii controller adapter.
     */
    public static function application(): CWebApplication
    {
        $application = Yii::app();
        if (! $application instanceof CWebApplication) {
            throw new LogicException('Strangler requires a Yii web application.');
        }

        return $application;
    }

    /**
     * Convert only scalar Yii user identifiers to HTTP header values.
     */
    private static function userId(): string
    {
        $id = self::application()->getUser()->getId();

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * Read numeric timeout settings, retaining defaults for invalid input.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function timeout(array $settings, string $name, float $default): float
    {
        $value = $settings[$name] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Prefer the request identifier before the original body identifier.
     *
     * @param array<array-key, mixed> $payload
     * @param Config                  $config
     */
    private static function routeIdentifier(mixed $id, array $payload, array $config): mixed
    {
        $missingId = $id === null || $id === '';
        if ($missingId && isset($config['bodyIdentifier'])) {
            return $payload[$config['bodyIdentifier']] ?? null;
        }

        return $id;
    }
}
