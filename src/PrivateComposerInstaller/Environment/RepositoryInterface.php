<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

interface RepositoryInterface
{
    /**
     * Get an environment value by the given key.
     *
     * @param string $key
     *
     * @throws \FFraenz\PrivateComposerInstaller\Exception\MissingEnvException
     *
     * @return string
     */
    public function get(string $key): string;
}
