<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector;

return RectorConfig::configure()
    ->withPHPStanConfigs([__DIR__.'/phpstan.neon'])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: false,
        codingStyle: false,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withComposerBased(
        phpunit: true
    )
    ->withParallel(
        timeoutSeconds: 120,
        maxNumberOfProcess: 8,
        jobSize: 20,
    )
    ->withSkip([
        // Keep the suite compatible with PHPUnit 11; this adds a PHPUnit 12-only attribute.
        AllowMockObjectsForDataProviderRector::class,
        ClosureToArrowFunctionRector::class,
        // This override prevents the real CWebUser::init() from opening a session in tests.
        RemoveEmptyClassMethodRector::class => [__DIR__.'/tests/Fixtures/User.php'],
    ])
    ->withMemoryLimit('3G')
    ->withPhpSets(php82: true);
