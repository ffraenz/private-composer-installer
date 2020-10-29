<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\RepositoryInterface as RepoInterface;

class Dotenv4Loader extends AbstractDotenvLoader
{
    /**
     * Create a repository instance.
     */
    protected function createRepo(): RepoInterface
    {
        $adapter = new ArrayAdapter();

        return RepositoryBuilder::create()
            ->withReaders([new ServerConstAdapter(), $adapter])
            ->withWriters([$adapter])
            ->make();
    }
}
