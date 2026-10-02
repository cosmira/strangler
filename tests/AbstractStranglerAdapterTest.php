<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\AbstractStranglerAdapter;
use PHPUnit\Framework\TestCase;

final class AbstractStranglerAdapterTest extends TestCase
{
    public function testUnmodifiedContractsKeepEveryValue(): void
    {
        $adapter = new class extends AbstractStranglerAdapter {};
        $query = ['offset' => 0, 'filter' => ['active' => false]];
        $payload = ['ID' => 42, 'nullable' => null, 'list' => ['a', 'b']];
        $headers = ['Content-Type' => ['application/json'], 'X-Trace' => ['one', 'two']];

        self::assertSame($query, $adapter->transformQuery('get', $query));
        self::assertSame($payload, $adapter->transformPayload('create', $payload));
        self::assertSame(['status' => 422, 'body' => '{"errors":["bad"]}', 'headers' => $headers],
            $adapter->transformResponse('create', 422, '{"errors":["bad"]}', $headers));
    }
}
