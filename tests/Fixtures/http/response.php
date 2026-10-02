<?php

declare(strict_types=1);

use Cosmira\Strangler\ResponseEmitter;

define('YII_DEBUG', false);
require dirname(__DIR__, 2).'/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

$application = new CWebApplication([
    'basePath'    => dirname(__DIR__, 2),
    'runtimePath' => dirname(__DIR__, 2),
]);
$endCalls = 0;
$application->attachEventHandler('onEndRequest', static function (CEvent $event) use (&$endCalls): void {
    $endCalls++;
    header('X-End-Calls: '.$endCalls);
});

if ($_SERVER['REQUEST_URI'] === '/legacy' || $_SERVER['REQUEST_URI'] === '/denied') {
    $denied = $_SERVER['REQUEST_URI'] === '/denied';
    http_response_code($denied ? 403 : 200);
    echo $denied ? 'denied' : 'legacy response';
    $application->end();
}

header('Content-Type: text/html');
header('Cache-Control: private');
header('X-Repeated: legacy');
(new ResponseEmitter())->send(201, '{"ok":true}', [
    'Content-Type'  => ['application/json'],
    'Cache-Control' => ['no-store'],
    'X-Repeated'    => ['first', 'second'],
    'x-repeated'    => ['third'],
    'Set-Cookie'    => ['backend-session=secret'],
], 0);
