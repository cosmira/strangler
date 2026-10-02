<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use CLogger;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Throwable;
use Yii;

final class StranglerProxy
{
    public function __construct(private ?ClientInterface $client = null) {}

    /**
     * @param array{
     *     feature?: string,
     *     routes?: array<string, array{method: string, path: string}>
     * } $config
     */
    public static function shouldHandle(array $config, string $actionId): bool
    {
        if (!self::isEnabled($config['feature'] ?? null)) {
            return false;
        }

        $routes = $config['routes'] ?? [];
        $actionKey = self::normalizeActionKey($actionId);

        return isset($routes[$actionKey]);
    }

    /**
     * @param array{
     *     bypassPayloadFields?: array<string, string[]>,
     *     payloadReader?: callable(CController): array<string, mixed>,
     *     bypass?: callable(CController, string, array<string, mixed>): bool
     * } $config
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
        $fields = $config['bypassPayloadFields'][$actionKey] ?? [];

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
     * @param array{
     *     routes?: array<string, array{method: string, path: string}>,
     *     modifier?: StranglerModifierInterface|class-string|array<string, mixed>,
     *     bodyIdentifier?: string,
     *     payloadReader?: callable(CController): array<string, mixed>,
     *     afterRequest?: callable(string, string): void
     * } $config
     */
    public function handle(CController $controller, array $config, string $actionId): void
    {
        $actionKey = self::normalizeActionKey($actionId);
        $route = $config['routes'][$actionKey] ?? null;

        if ($route === null) {
            return;
        }

        $settings = self::settings();
        $baseUri = self::baseUri($settings);

        if ($baseUri === '') {
            self::sendError(500, 'strangler_not_configured');
            return;
        }

        $request = Yii::app()->request;
        $startedAt = microtime(true);

        $modifierConfig = $config['modifier'] ?? null;
        $modifier = self::resolveModifier($modifierConfig);
        if ($modifier === null && self::hasModifierConfig($modifierConfig)) {
            self::sendError(500, 'strangler_modifier_not_configured');
            return;
        }

        $query = $_GET;
        unset($query['r']);

        $payload = self::readPayload($controller, $config);
        $routeId = $request->getParam('id');
        $missingRouteId = $routeId === null || $routeId === '';
        if ($missingRouteId && isset($config['bodyIdentifier'])) {
            $routeId = $payload[$config['bodyIdentifier']] ?? null;
        }
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

            self::sendError(503, 'service_temporarily_unavailable');

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

            $statusCode = (int) ($transformed['status'] ?? $statusCode);
            $responseBody = (string) ($transformed['body'] ?? $responseBody);
            $responseHeaders = (array) ($transformed['headers'] ?? $responseHeaders);
        }

        $timeMs = (int) round((microtime(true) - $startedAt) * 1000);

        self::debugLog(sprintf(
            'Strangler request proxied | feature: %s | action: %s | method: %s'
                . ' | base_uri: %s | path: %s | status: %s | time_ms: %s',
            (string) ($config['feature'] ?? ''),
            $actionId,
            $method,
            $baseUri,
            $path,
            $statusCode,
            $timeMs,
        ));

        self::sendResponse(
            $statusCode,
            $responseBody,
            $responseHeaders,
            $timeMs,
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $query
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function requestOptions(
        array $settings,
        string $method,
        array $query,
        array $payload,
    ): array {
        $headers = array_merge(self::extractForwardHeaders(), [
            'X-User-Id' => (string) Yii::app()->user->id,
            'X-Strangler' => '1',
            'X-Strangler-Locale' => (string) (Yii::app()->language ?? ''),
            'Accept' => 'application/json',
        ]);

        $options = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::QUERY => $query,
            'base_uri' => self::baseUri($settings),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => (bool) ($settings['allow_redirects'] ?? false),
            RequestOptions::VERIFY => (bool) ($settings['verify_ssl'] ?? false),
            RequestOptions::TIMEOUT => (float) ($settings['timeout'] ?? 10),
            RequestOptions::CONNECT_TIMEOUT => (float) ($settings['connect_timeout'] ?? 3),
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $options[RequestOptions::JSON] = $payload;
        }

        return $options;
    }

    private static function normalizeActionKey(string $actionId): string
    {
        return strtolower($actionId);
    }

    /** @param array<string, mixed> $config */
    private static function readPayload(CController $controller, array $config): array
    {
        $reader = $config['payloadReader'] ?? null;

        if ($reader !== null) {
            return $reader($controller);
        }

        $decoded = json_decode((string) Yii::app()->request->getRawBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $config */
    private static function afterRequest(array $config, string $method, string $path): void
    {
        $callback = $config['afterRequest'] ?? null;
        if ($callback !== null) {
            $callback($method, $path);
        }
    }

    private static function isEnabled(?string $feature): bool
    {
        if ($feature === null || $feature === '') {
            return false;
        }

        $strangler = Yii::app()->params['strangler'] ?? [];
        $features = $strangler['features'] ?? [];

        return (bool) ($features[$feature] ?? false);
    }

    private static function settings(): array
    {
        $settings = Yii::app()->params['strangler'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function baseUri(array $settings): string
    {
        return rtrim(trim((string) ($settings['base_uri'] ?? '')), '/');
    }

    private static function hasModifierConfig(mixed $modifierConfig): bool
    {
        return !($modifierConfig === null || $modifierConfig === '' || $modifierConfig === []);
    }

    private static function resolveModifier(mixed $modifierConfig): ?StranglerModifierInterface
    {
        if (!self::hasModifierConfig($modifierConfig)) {
            return null;
        }

        if ($modifierConfig instanceof StranglerModifierInterface) {
            return $modifierConfig;
        }

        if (is_string($modifierConfig)) {
            $modifierConfig = ['class' => $modifierConfig];
        }

        if (!is_array($modifierConfig) || !isset($modifierConfig['class'])) {
            Yii::log(
                'Strangler modifier config must contain class',
                CLogger::LEVEL_WARNING,
                'strangler',
            );

            return null;
        }

        try {
            $modifier = Yii::createComponent($modifierConfig);
        } catch (Throwable $exception) {
            Yii::log(
                sprintf(
                    'Strangler modifier could not be created: %s | error: %s',
                    (string) $modifierConfig['class'],
                    $exception->getMessage(),
                ),
                CLogger::LEVEL_WARNING,
                'strangler',
            );

            return null;
        }

        if (!$modifier instanceof StranglerModifierInterface) {
            Yii::log(
                sprintf(
                    'Strangler modifier must implement StranglerModifierInterface: %s',
                    (string) $modifierConfig['class'],
                ),
                CLogger::LEVEL_WARNING,
                'strangler',
            );

            return null;
        }

        return $modifier;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private static function resolvePath(
        string $pathTemplate,
        array $query,
        array $body,
        mixed $routeId,
    ): string {
        $routeId = is_scalar($routeId) ? (string) $routeId : null;

        return preg_replace_callback('/\{([^}]+)\}/', static function (array $matches) use (
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

            if (!is_scalar($value) || $value === '') {
                return '';
            }

            return rawurlencode((string) $value);
        }, $pathTemplate) ?? $pathTemplate;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function sendResponse(
        int $status,
        string $body,
        array $headers,
        int $timeMs,
    ): void {
        foreach ($headers as $name => $values) {
            $headerName = strtolower($name);

            if (in_array($headerName, ['transfer-encoding', 'content-length'], true)) {
                continue;
            }

            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        header('X-Strangler: 1', true);
        if (defined('YII_DEBUG') && YII_DEBUG) {
            header('X-Strangler-Time: ' . $timeMs, true);
        }

        http_response_code($status);
        echo $body;
        Yii::app()->end();
    }

    private static function sendError(int $status, string $code): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8', true);
        header('X-Strangler: 1', true);

        echo json_encode([
            'state' => 'error',
            'error' => $code,
        ], JSON_UNESCAPED_UNICODE);

        Yii::app()->end();
    }

    private static function debugLog(string $message): void
    {
        $settings = self::settings();

        $debug = defined('YII_DEBUG') && YII_DEBUG;
        $logRequests = (bool) ($settings['log_requests'] ?? false);
        if (!$debug && !$logRequests) {
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
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }

            $headerName = str_replace('_', '-', substr($key, 5));
            $headerName = implode('-', array_map('ucfirst', explode('-', strtolower($headerName))));

            if (in_array($headerName, ['Host', 'Content-Length', 'Connection'], true)) {
                continue;
            }

            $result[$headerName] = (string) $value;
        }

        return $result;
    }
}
