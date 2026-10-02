<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CWebUser;

final class User extends CWebUser
{
    public function init(): void {}

    public function getId(): int
    {
        return 7;
    }
}
