<?php

declare(strict_types=1);

use Pig\CodingAgent\Extensions\ExtensionApi;
use PigIphoneUse\Extension;

/*
 * pig-iphone-use — drive an iPhone over USB through WebDriverAgent: the view tree, taps, swipes,
 * typing, screenshots and apps, plus the `/iphone` command.
 *
 * A package installed from git is not run through Composer, so nothing autoloads its classes;
 * this registers the loader for the package's own namespace, once.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PigIphoneUse\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

return static function (ExtensionApi $pig): void {
    Extension::register($pig);
};
