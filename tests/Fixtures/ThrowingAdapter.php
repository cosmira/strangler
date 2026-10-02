<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use RuntimeException;

final class ThrowingAdapter
{
    public function __construct()
    {
        throw new RuntimeException('adapter bug');
    }
}
