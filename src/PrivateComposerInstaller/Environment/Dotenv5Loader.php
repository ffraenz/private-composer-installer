<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\RepositoryInterface as RepoInterface;

class Dotenv5Loader extends AbstractDotenvLoader
{
    /**
     * Create a repository instance.
     */
    protected function createRepo(): RepoInterface
    {
        return RepositoryBuilder::createWithNoAdapters()
            ->addReader(ServerConstAdapter::class)
            ->addAdapter(ArrayAdapter::class)
            ->make();
    }
}
