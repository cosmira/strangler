<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\AbstractStranglerModifier;
use PHPUnit\Framework\TestCase;

final class AbstractStranglerModifierTest extends TestCase
{
    public function testUnmodifiedContractsKeepEveryValue(): void
    {
        $modifier = new class extends AbstractStranglerModifier {};
        $query = ['offset' => 0, 'filter' => ['active' => false]];
        $payload = ['ID' => 42, 'nullable' => null, 'list' => ['a', 'b']];
        $headers = ['Content-Type' => ['application/json'], 'X-Trace' => ['one', 'two']];

        self::assertSame($query, $modifier->transformQuery('get', $query));
        self::assertSame($payload, $modifier->transformPayload('create', $payload));
        self::assertSame(['status' => 422, 'body' => '{"errors":["bad"]}', 'headers' => $headers],
            $modifier->transformResponse('create', 422, '{"errors":["bad"]}', $headers));
    }
}
