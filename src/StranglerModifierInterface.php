<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

interface StranglerModifierInterface
{
    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function transformQuery(string $actionId, array $query): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function transformPayload(string $actionId, array $payload): array;

    /**
     * @param array<string, mixed> $headers
     * @return array{status: int, body: string, headers: array<string, mixed>}
     */
    public function transformResponse(
        string $actionId,
        int $status,
        string $body,
        array $headers,
    ): array;
}
