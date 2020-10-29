<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use Dotenv\Parser\Parser;
use FFraenz\PrivateComposerInstaller\Environment\LoaderInterface;

use function class_exists;
use function count;
use function dirname;
use function getcwd;
use function realpath;

class LoaderFactory
{
    /**
     * Create a new environment loader.
     */
    public static function create(?string $path = null, ?string $name = null): LoaderInterface
    {
        $paths = $path !== null
            ? [realpath($path)]
            : self::computePaths(realpath(getcwd()));

        if (class_exists(Parser::class)) {
            return new Dotenv5Loader($paths, $name);
        }

        return new Dotenv4Loader($paths, $name);
    }

    /**
     * Compute all parent directory paths of a directory, including itself.
     *
     * @return string[]
     */
    public static function computePaths(string $path): array
    {
        $paths = [$path];
        $path  = dirname($path);

        while ($paths[count($paths) - 1] !== $path) {
            $paths[] = $path;
            $path    = dirname($path);
        }

        return $paths;
    }
}
