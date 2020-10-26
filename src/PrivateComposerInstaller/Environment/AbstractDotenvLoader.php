<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use Dotenv\Dotenv;
use Dotenv\Repository\RepositoryInterface as RepoInterface;
use FFraenz\PrivateComposerInstaller\Environment\RepositoryInterface;

abstract class AbstractDotenvLoader implements LoaderInterface
{
    /** @var string[] */
    protected $paths;

    /** @var string|null */
    protected $name;

    /**
     * Create a new loader instance.
     *
     * @param string[]    $paths
     * @return void
     */
    public function __construct(array $paths, ?string $name = null)
    {
        $this->paths = $paths;
        $this->name  = $name;
    }

    /**
     * Load and return an environment repository.
     */
    public function load(): RepositoryInterface
    {
        $repo = $this->createRepo();

        Dotenv::create($repo, $this->paths, $this->name)->safeLoad();

        return new DotenvRepository($repo);
    }

    /**
     * Create a repository instance.
     */
    abstract protected function createRepo(): RepoInterface;
}
