<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        // PhpSessionStore::$logger is deliberately declared with a default and
        // left mutable rather than promoted and readonly, so that a subclass
        // which does not call parent::__construct() - the shape of any subclass
        // written before this class had a constructor - gets a null logger
        // instead of an uninitialized typed property. The reasoning is recorded
        // on the property itself.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/src/DataStore/PhpSessionStore.php',
        ],
        ReadOnlyPropertyRector::class => [
            __DIR__ . '/src/DataStore/PhpSessionStore.php',
        ],
        InlineConstructorDefaultToPropertyRector::class => [
            __DIR__ . '/src/DataStore/PhpSessionStore.php',
        ],
        // Same property, same reason: the default is not redundant, it is what
        // a subclass skipping the constructor falls back to.
        RemoveDefaultValueFromAssignedPropertyRector::class => [
            __DIR__ . '/src/DataStore/PhpSessionStore.php',
        ],
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
//        privatization: true,
//        naming: true,
        instanceOf: true,
        earlyReturn: true,
//        strictBooleans: true,
//        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
//        doctrineCodeQuality: true,
//        symfonyCodeQuality: true,
//        symfonyConfigs: true,
    );
