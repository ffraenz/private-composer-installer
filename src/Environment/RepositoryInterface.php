<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;

interface RepositoryInterface
{
    /**
     * Get an environment value by the given key.
     *
     * @throws MissingEnvException
     */
    public function get(string $key): string;
}
