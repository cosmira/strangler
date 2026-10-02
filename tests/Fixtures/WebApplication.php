<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CWebApplication;

final class WebApplication extends CWebApplication
{
    public function end(mixed $status = 0, mixed $exit = true): void
    {
        throw new ApplicationEnded();
    }
}
