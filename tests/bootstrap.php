<?php

declare(strict_types=1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_ENABLE_EXCEPTION_HANDLER', false);

require dirname(__DIR__).'/vendor/autoload.php';

// Delay Yii's global constants until isolated tests can select debug mode.
spl_autoload_register(static function (string $class): void {
    if (! in_array($class, [
        'Yii', 'YiiBase', 'CController', 'CException', 'CFilter', 'CFilterChain',
        'CHttpRequest', 'CLogger', 'CWebApplication', 'CWebUser',
    ], true)) {
        return;
    }

    require_once dirname(__DIR__).'/vendor/yiisoft/yii/framework/yii.php';
    if ($class !== 'Yii' && $class !== 'YiiBase') {
        Yii::autoload($class);
    }
}, true, true);
