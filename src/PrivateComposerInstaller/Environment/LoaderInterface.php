<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

interface LoaderInterface
{
    /**
     * Load and return an environment repository.
     *
     * @return \FFraenz\PrivateComposerInstaller\Environment\RepositoryInterface
     */
    public function load(): RepositoryInterface;
}
