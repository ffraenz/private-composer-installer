<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Environment;

use Dotenv\Repository\RepositoryInterface as RepoInterface;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;

class DotenvRepository implements RepositoryInterface
{
    /**
     * @var \Dotenv\Repository\RepositoryInterface
     */
    protected $repo;

    /**
     * Create a new repository instance.
     *
     * @param \Dotenv\Repository\RepositoryInterface $repo
     *
     * @return void
     */
    public function __construct(RepoInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Get an environment value by the given key.
     *
     * @param string $key
     *
     * @throws \FFraenz\PrivateComposerInstaller\Exception\MissingEnvException
     *
     * @return string
     */
    public function get(string $key): string
    {
        $value = $this->repo->get($key);

        // is_string check can be removed when phpdotenv v4 is dropped
        if (empty($value) || ! is_string($value)) {
            throw new MissingEnvException($key);
        }

        return $value;
    }
}
