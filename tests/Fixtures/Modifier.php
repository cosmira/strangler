<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use Cosmira\Strangler\StranglerModifierInterface;

final class Modifier implements StranglerModifierInterface
{
    public bool $transformed = false;

    public function transformQuery(string $actionId, array $query): array
    {
        return ['page' => $query['start'] ?? 0];
    }

    public function transformPayload(string $actionId, array $payload): array
    {
        unset($payload['ID']);

        return ['name' => $payload['NAME'] ?? null];
    }

    public function transformResponse(string $actionId, int $status, string $body, array $headers): array
    {
        $this->transformed = true;

        return ['status' => 202, 'body' => 'mapped:'.$body, 'headers' => $headers];
    }
}
