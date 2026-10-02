<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CWebUser;

final class User extends CWebUser
{
    public mixed $identifier = 7;

    public function init(): void {}

    public function getId(): mixed
    {
        return $this->identifier;
    }
}
