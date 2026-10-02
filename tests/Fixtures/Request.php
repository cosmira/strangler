<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CHttpRequest;

final class Request extends CHttpRequest
{
    public string $body = '{}';

    public function getRawBody(): string
    {
        return $this->body;
    }
}
