<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerModifierInterface;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Yii;

final class ApiAdaptationGuideTest extends TestCase
{
    public function testPublishedExamplePreservesTheLegacyCatalogContract(): void
    {
        $modifier = $this->modifier();
        self::assertSame(['page' => 3, 'per_page' => 10], $modifier->transformQuery('get', ['start' => 20, 'limit' => 10]));
        self::assertSame([], $modifier->transformQuery('create', []));
        self::assertSame([], $modifier->transformPayload('get', []));
        self::assertSame([
            'product'  => ['title' => 'Desk', 'enabled' => true],
            'category' => ['id' => 7],
        ], $modifier->transformPayload('update', ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7]));
        $item = ['id' => 42, 'title' => 'Desk', 'enabled' => true, 'category' => ['id' => 7]];
        $legacyItem = ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7];
        $response = $modifier->transformResponse('create', 201, json_encode(['data' => $item], JSON_THROW_ON_ERROR), []);
        self::assertSame(201, $response['status']);
        self::assertSame(['success' => true, 'data' => $legacyItem], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['Content-Type' => ['application/json; charset=utf-8']], $response['headers']);
        $response = $modifier->transformResponse('get', 200,
            json_encode(['data' => [$item], 'meta' => ['total' => 50]], JSON_THROW_ON_ERROR), []);
        self::assertSame(['success' => true, 'total' => 50, 'rows' => [$legacyItem]],
            json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['status' => 204, 'body' => '', 'headers' => []], $modifier->transformResponse('update', 204, '', []));
    }

    public function testPublishedExampleMapsValidationAndTransportErrors(): void
    {
        $modifier = $this->modifier();
        $response = $modifier->transformResponse('create', 422, '{"code":"validation_failed","errors":{"title":["Required"]}}', []);
        self::assertSame(422, $response['status']);
        self::assertSame(['success' => false, 'error' => ['code' => 'validation_failed', 'fields' => ['title' => ['Required']]]],
            json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        $response = $modifier->transformResponse('create', 503, '{"state":"error","error":"service_temporarily_unavailable"}', []);
        self::assertSame(503, $response['status']);
        self::assertSame(['success' => false, 'error' => ['code' => 'service_temporarily_unavailable', 'fields' => []]],
            json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testGuideUpdateActuallyUsesTheNewMethodPathAndLegacyResponse(): void
    {
        $_GET = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['base_uri' => 'https://backend.test', 'features' => ['catalog' => true]]],
        ]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [],
            '{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}')]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $config = Strangler::proxy('catalog')->usingModifier($this->modifier())->bodyIdentifier('ID')
            ->payloadUsing(static fn (): array => ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7])
            ->put('update', '/api/items/{id}')->build()['config'];
        $proxy = new StranglerProxy($client, new ResponseEmitter(static function (): void {}));
        ob_start();

        try {
            $proxy->handle(new Controller('catalog'), $config, 'update');
            self::fail('Expected Yii request termination.');
        } catch (ApplicationEnded) {
            self::assertSame('{"success":true,"data":{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}}', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertIsArray($history[0]);
        $request = $history[0]['request'];
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/api/items/42', $request->getUri()->getPath());
        self::assertSame('{"product":{"title":"Desk","enabled":true},"category":{"id":7}}', (string) $request->getBody());
    }

    private function modifier(): StranglerModifierInterface
    {
        if (! class_exists('CatalogApiModifier', false)) {
            $guide = file_get_contents(dirname(__DIR__).'/docs/api-adaptation.md');
            self::assertIsString($guide);
            if (preg_match('/```php\n(.*?)\n```/s', $guide, $matches) !== 1) {
                throw new RuntimeException('The guide must contain its runnable modifier example.');
            }
            $path = tempnam(sys_get_temp_dir(), 'strangler-guide-');
            self::assertIsString($path);

            try {
                file_put_contents($path, "<?php\n".$matches[1]);
                require $path;
            } finally {
                unlink($path);
            }
        }
        $className = 'CatalogApiModifier';
        if (! class_exists($className)) {
            throw new RuntimeException('The published modifier example must declare its class.');
        }
        $modifier = new $className();
        self::assertInstanceOf(StranglerModifierInterface::class, $modifier);

        return $modifier;
    }
}
