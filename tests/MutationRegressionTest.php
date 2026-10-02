<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CController;
use CLogger;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerAdapterInterface;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MutationRegressionTest extends TestCase
{
    private Controller $controller;

    private CLogger $logger;

    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $this->logger = new CLogger();
        Yii::setLogger($this->logger);
        new WebApplication([
            'basePath'    => __DIR__,
            'runtimePath' => __DIR__,
            'language'    => 'en',
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
    }

    public function testTransportFailureLogsTheNormalizedActionResolvedPathAndException(): void
    {
        $_GET = ['id' => 'a/b'];
        $_SERVER['HTTP_X_REQUEST_ID'] = 'incoming-id';
        StranglerProxy::application()->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test',
            'headers'  => ['X-Request-Id' => 'server-id'],
        ]);
        $config = Strangler::proxy('example')->get('get', '/api/items/{id}')->build()['config'];
        $failure = new ConnectException('Connection refused', new HttpRequest('GET', '/api/items/a%2Fb'));
        $proxy = new StranglerProxy($this->client([$failure]));

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'GeT'));

        self::assertSame('{"state":"error","error":"service_temporarily_unavailable"}', $body);
        $this->assertLog(
            'Strangler request failed | feature: example | request_id: server-id'
                .' | action: get | path: /api/items/a%2Fb | error: Connection refused',
            CLogger::LEVEL_ERROR,
        );
    }

    /** @param array<string, mixed> $adapter */
    #[DataProvider('invalidAdapterLogs')]
    public function testInvalidAdapterLogsAnActionableWarning(array $adapter, string $message): void
    {
        $config = Strangler::proxy('example')->usingAdapter($adapter)->get('get', '/api/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([]));

        self::assertSame(
            '{"state":"error","error":"strangler_adapter_not_configured"}',
            $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')),
        );
        $this->assertLog($message, CLogger::LEVEL_WARNING, 2);
        $logs = $this->logger->getLogs();
        self::assertIsArray($logs[1]);
        self::assertSame([
            'Strangler request rejected | feature: example | action: get | path: /api/items'
                .' | error: strangler_adapter_not_configured | request_id: ',
            CLogger::LEVEL_WARNING, 'strangler',
        ], array_slice($logs[1], 0, 3));
    }

    /** @return list<array{array<string, mixed>, string}> */
    public static function invalidAdapterLogs(): array
    {
        return [
            [['unexpected' => true], 'Strangler adapter config must contain class'],
            [['class' => 7], 'Strangler adapter config must contain class'],
            [['class' => \stdClass::class], 'Strangler adapter must implement StranglerAdapterInterface: stdClass'],
            [['class' => 'missing.Component'], 'Strangler adapter could not be created: missing.Component | error: Alias "missing.Component" is invalid. Make sure it points to an existing directory or file.'],
        ];
    }

    public function testAllAdapterCallbacksReceiveNormalizedActionAndOriginalDataInOrder(): void
    {
        $_GET = ['r' => 'example/update', 'filter' => ['name' => 'Alice']];
        $adapter = new class implements StranglerAdapterInterface
        {
            /** @var list<array<array-key, mixed>> */
            public array $calls = [];

            public function transformQuery(string $actionId, array $query): array
            {
                $this->calls[] = ['query', $actionId, $query];

                return ['page' => 2];
            }

            public function transformPayload(string $actionId, array $payload): array
            {
                $this->calls[] = ['payload', $actionId, $payload];

                return ['name' => 'mapped'];
            }

            public function transformResponse(string $actionId, int $status, string $body, array $headers): array
            {
                $this->calls[] = ['response', $actionId, $status, $body, $headers];

                return ['status' => 202, 'body' => 'changed', 'headers' => ['X-Mapped' => ['one', 'two']]];
            }
        };
        $receivedController = null;
        $config = Strangler::proxy('example')->usingAdapter($adapter)
            ->payloadUsing(static function (CController $controller) use (&$receivedController): array {
                $receivedController = $controller;

                return ['name' => 'original'];
            })->put('UpDaTe', '/api/items')->build()['config'];
        $headers = [];
        $status = null;
        $emitter = new ResponseEmitter(
            static function (string $header, bool $replace) use (&$headers): void {
                $headers[] = [$header, $replace];
            },
            static function (int $code) use (&$status): void {
                $status = $code;
            },
        );
        $proxy = new StranglerProxy($this->client([new Response(201, ['X-Upstream' => 'before'], 'original')]), $emitter);

        self::assertSame('changed', $this->capture(fn () => $proxy->handle($this->controller, $config, 'UPDATE')));
        self::assertSame($this->controller, $receivedController);
        self::assertSame([
            ['query', 'update', ['filter' => ['name' => 'Alice']]],
            ['payload', 'update', ['name' => 'original']],
            ['response', 'update', 201, 'original', ['X-Upstream' => ['before']]],
        ], $adapter->calls);
        self::assertSame(202, $status);
        self::assertSame([['X-Mapped: one', true], ['X-Mapped: two', false], ['X-Strangler: 1', true]], $headers);
    }

    private function assertLog(string $message, string $level, int $count = 1): void
    {
        $logs = $this->logger->getLogs();
        self::assertCount($count, $logs);
        $log = $logs[0];
        self::assertIsArray($log);
        self::assertSame([$message, $level, 'strangler'], array_slice($log, 0, 3));
    }

    public function testEmptyOrMissingFeatureCannotEnableAnOtherwiseMappedRoute(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', ['features' => ['' => true]]);
        $routes = ['get' => ['method' => 'GET', 'path' => '/get']];

        self::assertFalse(StranglerProxy::shouldHandle(['routes' => $routes], 'get'));
        self::assertFalse(StranglerProxy::shouldHandle(['feature' => '', 'routes' => $routes], 'get'));
    }

    public function testInvalidScalarFeatureCollectionCannotEnableANumericFeature(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', ['features' => 'x']);

        self::assertFalse(StranglerProxy::shouldHandle([
            'feature' => '0',
            'routes'  => ['get' => ['method' => 'GET', 'path' => '/get']],
        ], 'get'));
    }

    public function testRequestLoggingIsDisabledWhenTheSettingIsOmitted(): void
    {
        $config = Strangler::proxy('example')->get('get', '/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([new Response(200, [], 'body')]));

        self::assertSame('body', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
        self::assertSame([], $this->logger->getLogs());
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectedRequestsLogFeaturePathErrorAndForwardedCorrelationId(
        string $path,
        string $origin,
        string $error,
    ): void {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'request-42';
        StranglerProxy::application()->getParams()->add('strangler', ['base_uri' => $origin]);
        $config = Strangler::proxy('example')->get('get', $path)->build()['config'];
        $proxy = new StranglerProxy($this->client([]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'GeT'));

        $this->assertLog(
            'Strangler request rejected | feature: example | action: get | path: '.$path
                .' | error: '.$error.' | request_id: request-42',
            CLogger::LEVEL_WARNING,
        );
    }

    /** @return list<array{string, string, string}> */
    public static function rejectedRequests(): array
    {
        return [
            ['/items/{id}', 'https://backend.test', 'strangler_route_parameter_missing'],
            ['/items', '', 'strangler_not_configured'],
        ];
    }

    /** @param list<Response|ConnectException> $responses */
    private function client(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
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
