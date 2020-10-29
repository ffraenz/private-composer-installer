<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use FFraenz\PrivateComposerInstaller\Environment\RepositoryInterface;

interface LoaderInterface
{
    /**
     * Load and return an environment repository.
     */
    public function load(): RepositoryInterface;
}
