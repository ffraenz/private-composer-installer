<?php

namespace FFraenz\PrivateComposerInstaller;

use Dotenv\Exception\InvalidPathException;
use Dotenv\Loader\Loader;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Store\StoreBuilder;

class DotenvEnvResolver implements EnvResolverInterface
{
    /**
     * @var ArrayAdapter
     */
    protected $dotenvAdapter;

    /**
     * @var PutenvAdapter
     */
    protected $getenvAdapter;

    /**
     * @var string
     */
    protected $dotenvPath;

    /**
     * @var string
     */
    protected $dotenvName;

    /**
     * Constructor
     * @param string $path Path to the directory containing the dot env file
     * @param string $name Name of the dot env file
     */
    public function __construct(string $path, string $name = '.env')
    {
        $this->getenvAdapter = new PutenvAdapter();
        $this->dotenvPath = $path;
        $this->dotenvName = $name;
    }

    /**
     * Lazily creates an ArrayAdapter instance providing .env file variables.
     * @return ArrayAdapter
     */
    protected function getDotenvAdapter(): ArrayAdapter
    {
        if ($this->dotenvAdapter === null) {
            $this->dotenvAdapter = new ArrayAdapter();

            try {
                $repository = RepositoryBuilder::create()
                    ->withReaders([])
                    ->withWriters([$this->dotenvAdapter])
                    ->make();

                $fileStore = StoreBuilder::create()
                    ->withPaths([$this->dotenvPath])
                    ->withNames([$this->dotenvName])
                    ->make();

                $loader = new Loader();
                $loader->load($repository, $fileStore->read());
            } catch (InvalidPathException $e) {
                // Consider the .env file to be empty
            }
        }
        return $this->dotenvAdapter;
    }

    /**
     * Return an env value for the given key.
     * @param string $key Env var key
     * @return mixed|null Env var value or null, if it is not set
     */
    public function get(string $key)
    {
        // Try to read variable via putenv/getenv
        return $this->getenvAdapter->get($key)
            ->getOrCall(function () use ($key) {
                // Try to read variable from .env file
                return $this->getDotenvAdapter()->get($key)
                    ->getOrCall(function () use ($key) {
                        return null;
                    });
            });
    }
}
