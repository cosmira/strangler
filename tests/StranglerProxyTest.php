<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CController;
use CFilterChain;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerFilter;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Modifier;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\ThrowingModifier;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Yii;

#[PreserveGlobalState(false)]
final class StranglerProxyTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<array-key, mixed>}> */
    private array $history = [];

    private Controller $controller;

    private WebApplication $application;

    private Request $request;

    protected function setUp(): void
    {
        if (! defined('YII_DEBUG')) {
            define('YII_DEBUG', $this->name() === 'testDebugLoggingAndRoundedTiming');
        }
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $logger = Yii::getLogger();
        self::assertInstanceOf(\CLogger::class, $logger);
        $logger->flush(false);
        $this->application = new WebApplication([
            'basePath'    => __DIR__,
            'runtimePath' => __DIR__,
            'language'    => 'ru',
            'components'  => [
                'request' => ['class' => Request::class],
                'user'    => ['class' => User::class],
            ],
            'params' => ['strangler' => [
                'base_uri' => 'https://backend.test',
                'features' => ['example' => true],
            ]],
        ]);
        $request = $this->application->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $this->request = $request;
        $this->controller = new Controller('example');
        http_response_code(200);
    }

    #[RunInSeparateProcess]
    public function testConfiguredFilterForwardsInsteadOfRunningTheLegacyAction(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/api/items')->build();
        $filter['client'] = $this->client([new Response(200, [], 'upstream')]);
        $chain = CFilterChain::create($this->controller, $this->controller->createAction('get'), [$filter]);

        $body = $this->capture(static fn () => $chain->run());

        self::assertSame('upstream', $body);
        self::assertFalse($this->controller->ran);
        self::assertCount(1, $this->history);
    }

    #[DataProvider('legacyCases')]
    public function testDisabledUnmappedAndUnsupportedRequestsRunInYii(string $kind): void
    {
        $builder = Strangler::proxy('example')->get($kind === 'unmapped' ? 'view' : 'get', '/api/items');
        if ($kind === 'disabled') {
            $this->application->getParams()->add('strangler', ['features' => ['example' => false]]);
        }
        if ($kind === 'unsupported') {
            $builder->bypassUsing(static fn () => true);
        }
        $filter = $builder->build();
        $filter['client'] = $this->client([]);
        $chain = CFilterChain::create($this->controller, $this->controller->createAction('get'), [$filter]);

        $chain->run();

        self::assertTrue($this->controller->ran);
        self::assertCount(0, $this->history);
    }

    /** @return list<array{string}> */
    public static function legacyCases(): array
    {
        return [['disabled'], ['unmapped'], ['unsupported']];
    }

    public function testBypassSeesControllerActionAndTheOriginalQuery(): void
    {
        $_GET = ['filter' => ['unsupported' => '*']];
        $received = [];
        $config = Strangler::proxy('example')
            ->bypassUsing(static function (CController $controller, string $action, array $query) use (&$received): bool {
                $received = [$controller, $action, $query];

                return true;
            })->build()['config'];

        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET'));
        self::assertSame([$this->controller, 'get', $_GET], $received);
    }

    public function testBypassRetainsFieldsWhoseValueIsNull(): void
    {
        $config = Strangler::proxy('example')
            ->payloadUsing(static fn () => ['special' => null])
            ->bypassWhenPayloadHas('PUT', ['special'])
            ->build()['config'];

        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'put'));
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'post'));
    }

    public function testTransformsRequestAndResponseAndKeepsTheBodyIdentifier(): void
    {
        $_GET = ['r' => 'example/update', 'start' => 10];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token';
        $_SERVER['HTTP_CONNECTION'] = 'keep-alive';
        $_SERVER['HTTP_CONTENT_LENGTH'] = '99';
        $modifier = new Modifier();
        $events = [];
        $config = Strangler::proxy('example')
            ->usingModifier($modifier)
            ->payloadUsing(static fn () => ['ID' => 'a/b c', 'NAME' => 'Alice'])
            ->bodyIdentifier('ID')
            ->put('update', '/api/items/{id}')
            ->afterRequest(static function (string $method, string $path) use (&$events, $modifier): void {
                $events[] = [$method, $path, $modifier->transformed];
            })->build()['config'];
        $proxy = $this->proxy($this->client([new Response(201, ['X-Upstream' => 'yes'], 'saved')]));

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'UPDATE'));

        self::assertSame('mapped:saved', $body);
        self::assertSame(202, http_response_code());
        self::assertSame([['PUT', '/api/items/a%2Fb%20c', false]], $events);
        $request = $this->history[0]['request'];
        self::assertSame('https://backend.test/api/items/a%2Fb%20c?page=10', (string) $request->getUri());
        self::assertSame(['name' => 'Alice'], json_decode((string) $request->getBody(), true));
        self::assertSame('Bearer token', $request->getHeaderLine('Authorization'));
        self::assertSame('7', $request->getHeaderLine('X-User-Id'));
        self::assertSame('ru', $request->getHeaderLine('X-Strangler-Locale'));
        self::assertSame('1', $request->getHeaderLine('X-Strangler'));
        self::assertFalse($request->hasHeader('Connection'));
        self::assertNotSame('99', $request->getHeaderLine('Content-Length'));
    }

    public function testUrlIdentifierTakesPrecedenceOverPayload(): void
    {
        $_GET = ['id' => 'url-id'];
        $config = Strangler::proxy('example')->bodyIdentifier('ID')
            ->payloadUsing(static fn () => ['ID' => 'body-id'])
            ->put('update', '/api/items/{id}')->build()['config'];
        $proxy = $this->proxy($this->client([new Response(204)]));

        $this->capture(fn () => $proxy->handle($this->controller, $config, 'update'));

        self::assertSame('/api/items/url-id', $this->history[0]['request']->getUri()->getPath());
    }

    #[DataProvider('upstreamStatuses')]
    public function testUpstreamErrorsAndRedirectsAreReturnedWithoutRetry(int $status): void
    {
        $proxy = $this->proxy($this->client([new Response($status, ['Location' => '/next'], 'unchanged')]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame($status, http_response_code());
        self::assertSame('unchanged', $body);
        self::assertCount(1, $this->history);
    }

    /** @return list<array{int}> */
    public static function upstreamStatuses(): array
    {
        return [[302], [422], [500]];
    }

    public function testTransportFailureCallsTheHookOnceAndReturns503(): void
    {
        $events = [];
        $config = Strangler::proxy('example')->post('create', '/api/items')
            ->afterRequest(static function (string $method, string $path) use (&$events): void {
                $events[] = [$method, $path];
            })->build()['config'];
        $failure = new ConnectException('Connection refused', new HttpRequest('POST', '/api/items'));
        $proxy = $this->proxy($this->client([$failure]));

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'create'));

        self::assertSame(503, http_response_code());
        self::assertSame(['state' => 'error', 'error' => 'service_temporarily_unavailable'], json_decode($body, true));
        self::assertSame([['POST', '/api/items']], $events);
        self::assertCount(1, $this->history);
    }

    public function testMissingBackendReturns500WithoutSendingARequest(): void
    {
        $this->application->getParams()->add('strangler', []);
        $proxy = $this->proxy($this->client([]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame(500, http_response_code());
        self::assertSame(['state' => 'error', 'error' => 'strangler_not_configured'], json_decode($body, true));
        self::assertCount(0, $this->history);
    }

    /** @param string|array<string, mixed> $modifier */
    #[DataProvider('invalidModifiers')]
    public function testInvalidModifierFailsBeforeSendingARequest(string|array $modifier): void
    {
        $config = Strangler::proxy('example')->usingModifier($modifier)
            ->get('get', '/api/items')->build()['config'];
        $proxy = $this->proxy($this->client([]));

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame(500, http_response_code());
        self::assertSame(['state' => 'error', 'error' => 'strangler_modifier_not_configured'], json_decode($body, true));
        self::assertCount(0, $this->history);
    }

    /** @return list<array{string|array<string, mixed>}> */
    public static function invalidModifiers(): array
    {
        return [['missing.Component'], [['bad' => 'config']], [['class' => 'stdClass']]];
    }

    public function testYiiCreatesAConfiguredModifier(): void
    {
        $config = Strangler::proxy('example')->usingModifier(['class' => Modifier::class])
            ->get('get', '/api/items')->build()['config'];
        $proxy = $this->proxy($this->client([new Response(200, [], 'items')]));

        self::assertSame('mapped:items', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
    }

    public function testJsonPayloadIsReadWithoutAnApplicationSpecificController(): void
    {
        $this->request->body = '{"name":"Alice"}';
        $config = Strangler::proxy('example')->post('create', '/api/items')->build()['config'];
        $proxy = $this->proxy($this->client([new Response(201)]));

        $this->capture(fn () => $proxy->handle($this->controller, $config, 'create'));

        self::assertSame(['name' => 'Alice'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testHookFailureIsVisible(): void
    {
        $config = Strangler::proxy('example')->get('get', '/api/items')
            ->afterRequest(static function (): void {
                throw new RuntimeException('notification failed');
            })->build()['config'];
        $proxy = $this->proxy($this->client([new Response(200)]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('notification failed');
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));
    }

    public function testHookTransportExceptionIsNotMistakenForAnUpstreamFailure(): void
    {
        $calls = 0;
        $config = Strangler::proxy('example')->get('get', '/api/items')
            ->afterRequest(static function () use (&$calls): void {
                $calls++;

                throw new ConnectException('hook failed', new HttpRequest('POST', '/notify'));
            })->build()['config'];
        $proxy = $this->proxy($this->client([new Response(200)]));

        try {
            $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));
            self::fail('Hook exceptions must remain visible.');
        } catch (ConnectException $exception) {
            self::assertSame('hook failed', $exception->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertCount(1, $this->history);
    }

    public function testInjectedClientKeepsProxyErrorAndRedirectPolicies(): void
    {
        $handler = HandlerStack::create(new MockHandler([new Response(422, [], 'invalid')]));
        $handler->push($this->historyMiddleware());
        $proxy = $this->proxy(new Client(['handler' => $handler]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame(422, http_response_code());
        self::assertSame('invalid', $body);
        self::assertSame('https://backend.test/api/items', (string) $this->history[0]['request']->getUri());
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testBackendUriIsTrimmedBeforeItIsUsedByTheClient(): void
    {
        $this->application->getParams()->add('strangler', ['base_uri' => '  https://backend.test/  ', 'log_requests' => true]);
        $proxy = $this->proxy($this->client([new Response(200)]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame('https://backend.test/api/items', (string) $this->history[0]['request']->getUri());
        $logger = Yii::getLogger();
        self::assertInstanceOf(\CLogger::class, $logger);
        $logs = $logger->getLogs('info', 'strangler');
        self::assertIsArray($logs[0]);
        self::assertIsString($logs[0][0]);
        self::assertStringContainsString('base_uri: https://backend.test |', $logs[0][0]);
    }

    public function testBuilderExportsAllHttpMethodsAndIndependentActions(): void
    {
        $config = Strangler::proxy('example')->get('GET', '/get')->post('CREATE', '/post')
            ->put('UPDATE', '/put')->delete('DELETE', '/delete')
            ->bypassWhenPayloadHas('UPDATE', ['one'])->bypassWhenPayloadHas('CREATE', ['two'])
            ->build()['config'];

        self::assertSame('example', $config['feature'] ?? null);
        self::assertSame([
            'get'    => ['method' => 'GET', 'path' => '/get'],
            'create' => ['method' => 'POST', 'path' => '/post'],
            'update' => ['method' => 'PUT', 'path' => '/put'],
            'delete' => ['method' => 'DELETE', 'path' => '/delete'],
        ], $config['routes'] ?? null);
        self::assertSame(['update' => ['one'], 'create' => ['two']], $config['bypassPayloadFields'] ?? null);
    }

    #[RunInSeparateProcess]
    public function testUnconfiguredFilterContinuesAndHandledFilterStopsTheChain(): void
    {
        $action = $this->controller->createAction('get');
        $chain = CFilterChain::create($this->controller, $action, [[StranglerFilter::class]]);
        $chain->run();
        self::assertTrue($this->controller->ran);

        $this->controller->ran = false;
        $this->application->shouldEnd = false;
        $filter = Strangler::proxy('example')->get('get', '/get')->build();
        $filter['client'] = $this->client([new Response(200, [], 'handled')]);
        $chain = CFilterChain::create($this->controller, $action, [$filter]);
        ob_start();

        try {
            $chain->run();
            self::assertSame('handled', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        self::assertFalse($this->controller->ran);
    }

    public function testMissingRouteDoesNothing(): void
    {
        $proxy = $this->proxy($this->client([]));
        $proxy->handle($this->controller, [], 'missing');
        self::assertSame([], $this->history);
    }

    public function testErrorGuardsReturnEvenIfAnApplicationOverrideDoesNotExit(): void
    {
        $this->application->shouldEnd = false;
        $this->application->getParams()->add('strangler', []);
        $config = Strangler::proxy('example')->get('get', '/get')->build()['config'];
        $proxy = $this->proxy($this->client([]));
        ob_start();

        try {
            $proxy->handle($this->controller, $config, 'get');
            self::assertSame('{"state":"error","error":"strangler_not_configured"}', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->application->getParams()->add('strangler', ['base_uri' => 'https://backend.test']);
        $config['modifier'] = ['bad' => 'config'];
        ob_start();

        try {
            $proxy->handle($this->controller, $config, 'get');
            self::assertSame('{"state":"error","error":"strangler_modifier_not_configured"}', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->application->getParams()->add('strangler', ['base_uri' => 'unsupported://backend.test']);
        ob_start();

        try {
            ($this->proxy())->handle($this->controller, ['routes' => ['get' => ['method' => 'GET', 'path' => '/get']]], 'get');
            self::assertSame('{"state":"error","error":"service_temporarily_unavailable"}', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        self::assertSame([], $this->history);
    }

    public function testFeatureFlagsDefaultToDisabledAndNormalizeActionNames(): void
    {
        self::assertFalse(StranglerProxy::shouldHandle([], 'get'));
        self::assertFalse(StranglerProxy::shouldHandle(['feature' => ''], 'get'));
        self::assertFalse(StranglerProxy::shouldHandle(['feature' => 'unknown'], 'get'));
        self::assertFalse(StranglerProxy::shouldHandle(['feature' => 'example'], 'get'));
        $config = Strangler::proxy('example')->get('GET', '/get')->build()['config'];
        self::assertTrue(StranglerProxy::shouldHandle($config, 'GET'));
        self::assertFalse(StranglerProxy::shouldHandle($config, 'other'));
        $this->application->getParams()->add('strangler', ['features' => 'invalid']);
        self::assertFalse(StranglerProxy::shouldHandle($config, 'get'));
        $this->application->getParams()->add('strangler', 'invalid');
        self::assertFalse(StranglerProxy::shouldHandle($config, 'get'));
        $this->application->getParams()->remove('strangler');
        self::assertFalse(StranglerProxy::shouldHandle($config, 'get'));
    }

    public function testBypassCanDeclineAndFallsThroughToOtherPayloadFields(): void
    {
        $config = Strangler::proxy('example')->bypassUsing(static fn (): bool => false)
            ->payloadUsing(static fn (): array => ['second' => false])
            ->bypassWhenPayloadHas('GET', ['first', 'second'])->build()['config'];
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET'));
        $config['payloadReader'] = static fn (): array => [];
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'get'));
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, [], 'get'));
        $config['payloadReader'] = static function (): array {
            self::fail('Unmapped bypass fields must not read the payload.');
        };
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'unmapped'));
    }

    /** @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     */
    #[DataProvider('placeholderCases')]
    public function testPlaceholderPrecedenceAndEncoding(array $query, array $payload, string $path, string $expected): void
    {
        $_GET = $query;
        $config = Strangler::proxy('example')->payloadUsing(static fn (): array => $payload)
            ->bodyIdentifier('ID')->put('update', $path)->build()['config'];
        $proxy = $this->proxy($this->client([new Response(200)]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'update'));
        self::assertSame($expected, $this->history[0]['request']->getUri()->getPath());
    }

    /** @return list<array{array<array-key, mixed>, array<array-key, mixed>, string, string}> */
    public static function placeholderCases(): array
    {
        return [
            [['slug' => 'query', 'SLUG' => 'upper-query'], ['slug' => 'a/b c', 'SLUG' => 'upper-body'], '/{slug}', '/a%2Fb%20c'],
            [['slug' => 'query', 'SLUG' => 'upper-query'], ['SLUG' => 'upper/body'], '/{slug}', '/upper%2Fbody'],
            [['slug' => 'query', 'SLUG' => 'upper-query'], [], '/{slug}', '/query'],
            [['SLUG' => 'upper/query'], [], '/{slug}', '/upper%2Fquery'],
            [[], [], '/items/{missing}', '/items/'],
            [[], ['slug' => []], '/items/{slug}', '/items/'],
            [[], ['slug' => ''], '/items/{slug}', '/items/'],
            [[], ['slug' => 0], '/{slug}', '/0'],
            [[], ['slug' => true], '/{slug}', '/1'],
            [['id' => 42], [], '/{id}', '/42'],
            [['id' => ''], ['ID' => 'body'], '/{id}', '/body'],
            [['id' => []], ['id' => 'fallback'], '/{id}', '/fallback'],
            [[], ['ID' => false, 'id' => 'fallback'], '/{id}', '/fallback'],
            [['id' => 'ignored'], ['slug' => 'correct'], '/{slug}', '/correct'],
        ];
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('payloadCases')]
    public function testJsonObjectsListsAndInvalidPayloads(string $body, array $expected): void
    {
        $this->request->body = $body;
        $config = Strangler::proxy('example')->post('create', '/items')->build()['config'];
        $proxy = $this->proxy($this->client([new Response(201)]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'create'));
        self::assertSame($expected, json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    /** @return list<array{string, array<array-key, mixed>}> */
    public static function payloadCases(): array
    {
        return [['["one","two"]', ['one', 'two']], ['invalid', []], ['null', []], ['"scalar"', []]];
    }

    #[DataProvider('httpMethods')]
    public function testMethodsQueryAndPayloadPolicy(string $method, bool $hasBody): void
    {
        $_GET = ['r' => 'legacy/action', 'q' => 'a b'];
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['HTTP_X_STRANGLER'] = 'spoof';
        $_SERVER['HTTP_X_USER_ID'] = 'spoof';
        $_SERVER['HTTP_X_STRANGLER_LOCALE'] = 'spoof';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom';
        $_SERVER['HTTP_X_INVALID'] = [];
        $this->request->body = '{"value":42}';
        $proxy = $this->proxy($this->client([new Response(200)]));
        $config = ['routes' => ['get' => ['method' => strtolower($method), 'path' => '/get']]];
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'GET'));
        $request = $this->history[0]['request'];
        self::assertSame($method, $request->getMethod());
        self::assertSame('q=a%20b', $request->getUri()->getQuery());
        self::assertSame($hasBody ? '{"value":42}' : '', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('custom', $request->getHeaderLine('X-Custom-Header'));
        self::assertFalse($request->hasHeader('X-Invalid'));
        self::assertSame('backend.test', $request->getHeaderLine('Host'));
        self::assertSame('7', $request->getHeaderLine('X-User-Id'));
        self::assertSame('1', $request->getHeaderLine('X-Strangler'));
        self::assertSame('ru', $request->getHeaderLine('X-Strangler-Locale'));
        $options = $this->history[0]['options'];
        self::assertSame(10.0, $options['timeout']);
        self::assertSame(3.0, $options['connect_timeout']);
        self::assertFalse($options['verify']);
        self::assertFalse($options['http_errors']);
        self::assertFalse($options['allow_redirects']);
    }

    /** @return list<array{string, bool}> */
    public static function httpMethods(): array
    {
        return [['GET', false], ['HEAD', false], ['POST', true], ['PUT', true], ['PATCH', true], ['DELETE', true]];
    }

    public function testExplicitSettingsAndInvalidTimeoutFallbacks(): void
    {
        $this->application->getParams()->add('strangler', [
            'base_uri'   => 'https://backend.test', 'timeout' => '2.5', 'connect_timeout' => 0,
            'verify_ssl' => 1, 'allow_redirects' => 1,
        ]);
        $config = Strangler::proxy('example')->get('get', '/get')->build()['config'];
        $proxy = $this->proxy($this->client([new Response(200)]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));
        self::assertSame(2.5, $this->history[0]['options']['timeout']);
        self::assertSame(0.0, $this->history[0]['options']['connect_timeout']);
        self::assertTrue($this->history[0]['options']['verify']);
        self::assertIsArray($this->history[0]['options']['allow_redirects']);
        self::assertSame(5, $this->history[0]['options']['allow_redirects']['max']);
        $this->application->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test', 'timeout' => [], 'connect_timeout' => 'invalid',
        ]);
        $proxy = $this->proxy($this->client([new Response(200)]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));
        self::assertSame(10.0, $this->history[1]['options']['timeout']);
        self::assertSame(3.0, $this->history[1]['options']['connect_timeout']);
    }

    public function testGuestIdentityAndInvalidBackendSettings(): void
    {
        $user = $this->application->getUser();
        self::assertInstanceOf(User::class, $user);
        $user->identifier = null;
        $config = Strangler::proxy('example')->get('get', '/get')->build()['config'];
        $proxy = $this->proxy($this->client([new Response(200)]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));
        self::assertSame('', $this->history[0]['request']->getHeaderLine('X-User-Id'));
        $this->application->getParams()->add('strangler', ['base_uri' => []]);
        self::assertSame('{"state":"error","error":"strangler_not_configured"}', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
    }

    public function testClassNameAndEmptyModifierConfigurations(): void
    {
        foreach ([Modifier::class, '', []] as $modifier) {
            $config = Strangler::proxy('example')->usingModifier($modifier)->get('get', '/get')->build()['config'];
            $proxy = $this->proxy($this->client([new Response(200, [], 'body')]));
            self::assertSame($modifier === Modifier::class ? 'mapped:body' : 'body', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
        }
    }

    public function testApplicationProgrammingFailuresStayVisible(): void
    {
        $config = Strangler::proxy('example')->usingModifier(ThrowingModifier::class)->get('get', '/get')->build()['config'];
        $proxy = $this->proxy($this->client([]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('modifier bug');
        $proxy->handle($this->controller, $config, 'get');
    }

    public function testWebApplicationIsRequired(): void
    {
        Yii::setApplication(null);
        $logger = Yii::getLogger();
        self::assertInstanceOf(\CLogger::class, $logger);
        $logger->flush(false);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Strangler requires a Yii web application.');
        StranglerProxy::application();
    }

    #[DataProvider('loggingCases')]
    public function testLoggingPolicyAndRoundedTiming(bool $logRequests): void
    {
        $this->assertLogging(false, $logRequests);
    }

    #[RunInSeparateProcess]
    public function testDebugLoggingAndRoundedTiming(): void
    {
        $this->assertLogging(true, false, 1.0014, 1001);
        $this->assertLogging(true, true, 1.0016, 1002);
    }

    private function assertLogging(bool $debug, bool $logRequests, float $elapsed = 1.0016, int $expectedMs = 1002): void
    {
        $logger = Yii::getLogger();
        self::assertInstanceOf(\CLogger::class, $logger);
        $logger->flush(false);
        $this->application->getParams()->add('strangler', ['base_uri' => 'https://backend.test', 'log_requests' => $logRequests]);
        $times = [100.0, 100.0 + $elapsed];
        $headers = [];
        $emitter = new ResponseEmitter(static function (string $header, bool $replace) use (&$headers): void {
            $headers[] = [$header, $replace];
        });
        $proxy = $this->proxy($this->client([new Response(201, [], 'body')]), $emitter,
            static function () use (&$times): float {
                return array_shift($times) ?? throw new RuntimeException('clock exhausted');
            });
        $config = Strangler::proxy('example')->get('GET', '/get')->build()['config'];
        self::assertSame('body', $this->capture(fn () => $proxy->handle($this->controller, $config, 'GET')));
        $logs = $logger->getLogs('info', 'strangler');
        if ($debug || $logRequests) {
            self::assertCount(1, $logs);
            self::assertIsArray($logs[0]);
            self::assertSame('Strangler request proxied | feature: example | action: GET | method: GET | base_uri: https://backend.test | path: /get | status: 201 | time_ms: '.$expectedMs, $logs[0][0]);
        } else {
            self::assertSame([], $logs);
        }
        self::assertSame($debug ? [['X-Strangler: 1', true], ['X-Strangler-Time: '.$expectedMs, true]] : [['X-Strangler: 1', true]], $headers);
    }

    /** @return list<array{bool}> */
    public static function loggingCases(): array
    {
        return [[false], [true]];
    }

    /** @param (\Closure(): float)|null $clock */
    private function proxy(
        ?ClientInterface $client = null,
        ?ResponseEmitter $emitter = null,
        ?\Closure $clock = null,
    ): StranglerProxy {
        $emitter ??= new ResponseEmitter(static function (string $header, bool $replace): void {});

        return new StranglerProxy($client, $emitter, $clock);
    }

    /**
     * @return callable(callable): callable
     */
    private function historyMiddleware(): callable
    {
        return Middleware::tap(function (RequestInterface $request, array $options): void {
            $this->history[] = ['request' => $request, 'options' => $options];
        });
    }

    /** @param list<Response|ConnectException> $responses */
    private function client(array $responses): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push($this->historyMiddleware());

        return new Client(['handler' => $handler, 'base_uri' => 'https://backend.test',
            'http_errors'            => false, 'allow_redirects' => false]);
    }

    /** @param callable(): mixed $operation */
    private function capture(callable $operation): string
    {
        ob_start();

        try {
            $operation();
            self::fail('The proxy must end the Yii request.');
        } catch (ApplicationEnded) {
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
