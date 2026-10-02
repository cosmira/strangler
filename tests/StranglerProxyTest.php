<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CFilterChain;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Modifier;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StranglerProxyTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    private Controller $controller;

    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        Yii::createApplication(WebApplication::class, [
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
        $this->controller = new Controller('example');
        http_response_code(200);
    }

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
            Yii::app()->params['strangler'] = ['features' => ['example' => false]];
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

    public static function legacyCases(): array
    {
        return [['disabled'], ['unmapped'], ['unsupported']];
    }

    public function testBypassSeesControllerActionAndTheOriginalQuery(): void
    {
        $_GET = ['filter' => ['unsupported' => '*']];
        $received = [];
        $config = Strangler::proxy('example')
            ->bypassUsing(static function ($controller, $action, $query) use (&$received): bool {
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
            ->afterRequest(static function ($method, $path) use (&$events, $modifier): void {
                $events[] = [$method, $path, $modifier->transformed];
            })->build()['config'];
        $proxy = new StranglerProxy($this->client([new Response(201, ['X-Upstream' => 'yes'], 'saved')]));

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
        $proxy = new StranglerProxy($this->client([new Response(204)]));

        $this->capture(fn () => $proxy->handle($this->controller, $config, 'update'));

        self::assertSame('/api/items/url-id', $this->history[0]['request']->getUri()->getPath());
    }

    #[DataProvider('upstreamStatuses')]
    public function testUpstreamErrorsAndRedirectsAreReturnedWithoutRetry(int $status): void
    {
        $proxy = new StranglerProxy($this->client([new Response($status, ['Location' => '/next'], 'unchanged')]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame($status, http_response_code());
        self::assertSame('unchanged', $body);
        self::assertCount(1, $this->history);
    }

    public static function upstreamStatuses(): array
    {
        return [[302], [422], [500]];
    }

    public function testTransportFailureCallsTheHookOnceAndReturns503(): void
    {
        $events = [];
        $config = Strangler::proxy('example')->post('create', '/api/items')
            ->afterRequest(static function ($method, $path) use (&$events): void {
                $events[] = [$method, $path];
            })->build()['config'];
        $failure = new ConnectException('Connection refused', new HttpRequest('POST', '/api/items'));
        $proxy = new StranglerProxy($this->client([$failure]));

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'create'));

        self::assertSame(503, http_response_code());
        self::assertSame(['state' => 'error', 'error' => 'service_temporarily_unavailable'], json_decode($body, true));
        self::assertSame([['POST', '/api/items']], $events);
        self::assertCount(1, $this->history);
    }

    public function testMissingBackendReturns500WithoutSendingARequest(): void
    {
        Yii::app()->params['strangler'] = [];
        $proxy = new StranglerProxy($this->client([]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame(500, http_response_code());
        self::assertSame('strangler_not_configured', json_decode($body, true)['error']);
        self::assertCount(0, $this->history);
    }

    #[DataProvider('invalidModifiers')]
    public function testInvalidModifierFailsBeforeSendingARequest(string|array $modifier): void
    {
        $config = Strangler::proxy('example')->usingModifier($modifier)
            ->get('get', '/api/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([]));

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame(500, http_response_code());
        self::assertSame('strangler_modifier_not_configured', json_decode($body, true)['error']);
        self::assertCount(0, $this->history);
    }

    public static function invalidModifiers(): array
    {
        return [['missing.Component'], [['bad' => 'config']], [['class' => 'stdClass']]];
    }

    public function testYiiCreatesAConfiguredModifier(): void
    {
        $config = Strangler::proxy('example')->usingModifier(['class' => Modifier::class])
            ->get('get', '/api/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([new Response(200, [], 'items')]));

        self::assertSame('mapped:items', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
    }

    public function testJsonPayloadIsReadWithoutAnApplicationSpecificController(): void
    {
        Yii::app()->request->body = '{"name":"Alice"}';
        $config = Strangler::proxy('example')->post('create', '/api/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([new Response(201)]));

        $this->capture(fn () => $proxy->handle($this->controller, $config, 'create'));

        self::assertSame(['name' => 'Alice'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testHookFailureIsVisible(): void
    {
        $config = Strangler::proxy('example')->get('get', '/api/items')
            ->afterRequest(static function (): void {
                throw new RuntimeException('notification failed');
            })->build()['config'];
        $proxy = new StranglerProxy($this->client([new Response(200)]));

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
        $proxy = new StranglerProxy($this->client([new Response(200)]));

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
        $handler->push(Middleware::history($this->history));
        $proxy = new StranglerProxy(new Client(['handler' => $handler]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame(422, http_response_code());
        self::assertSame('invalid', $body);
        self::assertSame('https://backend.test/api/items', (string) $this->history[0]['request']->getUri());
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testBackendUriIsTrimmedBeforeItIsUsedByTheClient(): void
    {
        Yii::app()->params['strangler'] = ['base_uri' => '  https://backend.test/  '];
        $proxy = new StranglerProxy($this->client([new Response(200)]));
        $config = Strangler::proxy('example')->get('get', '/api/items')->build()['config'];

        $this->capture(fn () => $proxy->handle($this->controller, $config, 'get'));

        self::assertSame('https://backend.test/api/items', (string) $this->history[0]['request']->getUri());
    }

    /** @param list<Response|ConnectException> $responses */
    private function client(array $responses): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->history));

        return new Client(['handler' => $handler, 'base_uri' => 'https://backend.test',
            'http_errors'            => false, 'allow_redirects' => false]);
    }

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
