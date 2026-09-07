<?php /** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withCache(cacheDirectory: __DIR__ . '/var/cache/rector')
    ->withPhpSets(
        php84: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        phpunitCodeQuality: true,
        phpunitNarrowAsserts: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
    )
    ->withComposerBased(
        doctrine: true,
        phpunit: true,
        symfony: true,
    )
    ->withImportNames()
    ->withSkip([
        LocallyCalledStaticMethodToNonStaticRector::class,
    ]);
