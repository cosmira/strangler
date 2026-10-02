<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CWebApplication;

final class WebApplication extends CWebApplication
{
    public bool $shouldEnd = true;

    public function end(mixed $status = 0, mixed $exit = true): void
    {
        if ($this->shouldEnd) {
            throw new ApplicationEnded();
        }
    }
}
