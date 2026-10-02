<?php

declare(strict_types=1);

$guide = file_get_contents(dirname(__DIR__, 3).'/docs/integration.md');
if ($guide === false || preg_match('/```php\r?\n(\$expected = getenv.*?)\r?\n```/s', $guide, $match) !== 1) {
    throw new RuntimeException('The documented backend entry point must exist.');
}

// Exercise the documented trust check, including its actual exit behavior.
$userId = '';
eval($match[1]);
header('Content-Type: application/json');
echo json_encode(['user_id' => $userId], JSON_THROW_ON_ERROR);
