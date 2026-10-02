<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

interface StranglerAdapterInterface
{
    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    public function transformQuery(string $actionId, array $query): array;

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public function transformPayload(string $actionId, array $payload): array;

    /**
     * @param array<string, array<string>> $headers
     *
     * @return array{status: int, body: string, headers: array<string, array<string>>}
     */
    public function transformResponse(
        string $actionId,
        int $status,
        string $body,
        array $headers,
    ): array;
}
