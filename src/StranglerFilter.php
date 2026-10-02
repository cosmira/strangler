<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CFilter;
use GuzzleHttp\ClientInterface;

final class StranglerFilter extends CFilter
{
    public ?ClientInterface $client = null;

    /** @var array<string, mixed> */
    public array $config = [];

    protected function preFilter(mixed $filterChain): bool
    {
        $actionId = $filterChain->action->id;

        if ($this->config === [] || ! StranglerProxy::shouldHandle($this->config, $actionId)) {
            return true;
        }

        if (StranglerProxy::shouldBypass($filterChain->controller, $this->config, $actionId)) {
            return true;
        }

        (new StranglerProxy($this->client))->handle(
            $filterChain->controller,
            $this->config,
            $actionId,
        );

        return false;
    }
}
