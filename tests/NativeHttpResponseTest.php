<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class NativeHttpResponseTest extends TestCase
{
    public function testNativeHeadersReplaceLegacyValuesAndHeadHasNoBody(): void
    {
        $this->withServer('response.php', static function (Client $client): void {
            foreach (['GET', 'HEAD'] as $method) {
                $response = $client->request($method, '/');
                self::assertSame(201, $response->getStatusCode());
                self::assertSame(['application/json'], $response->getHeader('Content-Type'));
                self::assertSame(['no-store'], $response->getHeader('Cache-Control'));
                self::assertSame(['first', 'second', 'third'], $response->getHeader('X-Repeated'));
                self::assertSame('1', $response->getHeaderLine('X-Strangler'));
                self::assertSame('1', $response->getHeaderLine('X-End-Calls'));
                self::assertFalse($response->hasHeader('Set-Cookie'));
                self::assertSame($method === 'HEAD' ? '' : '{"ok":true}', (string) $response->getBody());
            }
        });
    }

    public function testDocumentedBackendRequiresTokenBeforeTrustingUserContext(): void
    {
        $this->withServer('trusted-backend.php', static function (Client $client): void {
            foreach ([
                [],
                ['X-User-Id' => '42'],
                ['X-Strangler' => '1', 'X-User-Id' => '42'],
                ['X-Strangler-Token' => 'wrong', 'X-User-Id' => '42'],
            ] as $headers) {
                $response = $client->get('/', ['headers' => $headers]);
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('', (string) $response->getBody());
            }

            $response = $client->get('/', ['headers' => [
                'X-Strangler-Token' => 'server-secret', 'X-User-Id' => '42',
            ]]);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"user_id":"42"}', (string) $response->getBody());

            $guest = $client->get('/', ['headers' => ['X-Strangler-Token' => 'server-secret']]);
            self::assertSame(200, $guest->getStatusCode());
            self::assertSame('{"user_id":""}', (string) $guest->getBody());
        }, 'server-secret');
    }

    public function testDocumentedBackendFailsClosedWithoutServerSecret(): void
    {
        $this->withServer('trusted-backend.php', static function (Client $client): void {
            $response = $client->get('/', ['headers' => [
                'X-Strangler-Token' => 'server-secret', 'X-User-Id' => '42',
            ]]);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
        });
    }

    public function testYiiEndHookRunsOnceForLegacyAndDeniedResponses(): void
    {
        $this->withServer('response.php', static function (Client $client): void {
            foreach (['/legacy' => 200, '/denied' => 403] as $path => $status) {
                $response = $client->get($path);
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('1', $response->getHeaderLine('X-End-Calls'));
                self::assertSame($path === '/legacy' ? 'legacy response' : 'denied', (string) $response->getBody());
            }
        });
    }

    /** @param callable(Client): void $check */
    private function withServer(string $router, callable $check, ?string $token = null): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        $environment = getenv();
        unset($environment['STRANGLER_TOKEN']);
        if ($token !== null) {
            $environment['STRANGLER_TOKEN'] = $token;
        }
        $pipes = [];
        $server = proc_open([
            PHP_BINARY, '-S', $address, __DIR__.'/Fixtures/http/'.$router,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $environment);
        self::assertIsResource($server);

        try {
            $connected = false;
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://'.$address, timeout: 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $connected = true;
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($connected, 'PHP HTTP server must become ready.');

            $check(new Client(['base_uri' => 'http://'.$address, 'timeout' => 5, 'http_errors' => false]));
        } finally {
            proc_terminate($server);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($server);
        }
    }
}
