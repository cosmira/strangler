<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

/**
 * Extend this adapter when only part of the API contract needs translation.
 */
abstract class AbstractStranglerModifier implements StranglerModifierInterface
{
    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    public function transformQuery(string $actionId, array $query): array
    {
        return $query;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public function transformPayload(string $actionId, array $payload): array
    {
        return $payload;
    }

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
    ): array {
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}
