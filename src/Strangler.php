<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;

final class Strangler
{
    /** @var array<string, mixed> */
    private array $config;

    private function __construct(string $feature)
    {
        $this->config = ['feature' => $feature, 'routes' => []];
    }

    public static function proxy(string $feature): self
    {
        return new self($feature);
    }

    /** @param StranglerModifierInterface|class-string|array<string, mixed> $modifier */
    public function usingModifier(StranglerModifierInterface|string|array $modifier): self
    {
        $this->config['modifier'] = $modifier;

        return $this;
    }

    /** @param callable(CController): array<string, mixed> $reader */
    public function payloadUsing(callable $reader): self
    {
        $this->config['payloadReader'] = $reader;

        return $this;
    }

    /** @param callable(CController, string, array<string, mixed>): bool $bypass */
    public function bypassUsing(callable $bypass): self
    {
        $this->config['bypass'] = $bypass;

        return $this;
    }

    /** @param callable(string, string): void $callback */
    public function afterRequest(callable $callback): self
    {
        $this->config['afterRequest'] = $callback;

        return $this;
    }

    public function bodyIdentifier(string $field): self
    {
        $this->config['bodyIdentifier'] = $field;

        return $this;
    }

    /** @param list<string> $fields */
    public function bypassWhenPayloadHas(string $action, array $fields): self
    {
        $this->config['bypassPayloadFields'][strtolower($action)] = $fields;

        return $this;
    }

    public function get(string $action, string $path): self
    {
        return $this->route($action, 'GET', $path);
    }

    public function post(string $action, string $path): self
    {
        return $this->route($action, 'POST', $path);
    }

    public function put(string $action, string $path): self
    {
        return $this->route($action, 'PUT', $path);
    }

    public function delete(string $action, string $path): self
    {
        return $this->route($action, 'DELETE', $path);
    }

    /** @return array{0: class-string<StranglerFilter>, config: array<string, mixed>} */
    public function build(): array
    {
        return [StranglerFilter::class, 'config' => $this->config];
    }

    private function route(string $action, string $method, string $path): self
    {
        $this->config['routes'][strtolower($action)] = ['method' => $method, 'path' => $path];

        return $this;
    }
}
