<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use Dotenv\Parser\Parser;

class LoaderFactory
{
    /**
     * Create a new environment loader.
     *
     * @param string      $path
     * @param string|null $name
     *
     * @return \FFraenz\PrivateComposerInstaller\Environment\LoaderInterface
     */
    public static function create(string $path = null, string $name = null): LoaderInterface
    {
        if (class_exists(Parser::class)) {
            return new Dotenv5Loader(self::computePaths(realpath($path ?? getcwd())), $name);
        }

        return new Dotenv4Loader(self::computePaths(realpath($path ?? getcwd())), $name);
    }

    /**
     * Compute all parent directory paths of a directory, including itself.
     *
     * @param string $path
     *
     * @return string[]
     */
    private static function computePaths(string $path): array
    {
        $paths = [$path];

        while (!in_array($path, ['.', DIRECTORY_SEPARATOR], true)) {
          $path = dirname($path);
          $paths[] = $path;
        }

        return $paths;
    }
}
