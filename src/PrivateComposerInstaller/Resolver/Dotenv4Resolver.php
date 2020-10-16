<?php

namespace FFraenz\PrivateComposerInstaller\Resolver;

use Dotenv\Exception\InvalidPathException;
use Dotenv\Loader\Loader;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Store\StoreBuilder;

class Dotenv4Resolver implements ResolverInterface
{
    /**
     * @var ArrayAdapter
     */
    protected $dotenvAdapter;

    /**
     * @var PutenvAdapter
     */
    protected $putenvAdapter;

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
     */
    public function __construct()
    {
        $this->putenvAdapter = new PutenvAdapter();
        $this->dotenvPath = getcwd();
        $this->dotenvName = '.env';
    }

    /**
     * Lazily creates an ArrayAdapter instance providing .env file variables.
     * @return ArrayAdapter
     */
    protected function getDotenvAdapter(): ArrayAdapter
    {
        if ($this->dotenvAdapter === null) {
            if (method_exists('ArrayAdapter', 'create')) {
                // vlucas/phpdotenv ^5.0
                $this->dotenvAdapter = ArrayAdapter::create();
            } else {
                // vlucas/phpdotenv ^4.0
                $this->dotenvAdapter = new ArrayAdapter();
            }

            try {
                if (method_exists('RepositoryBuilder', 'createWithNoAdapters')) {
                    // vlucas/phpdotenv ^5.0
                    $repository = RepositoryBuilder::createWithNoAdapters()
                        ->addWriter($this->dotenvAdapter)
                        ->make();
                } else {
                    // vlucas/phpdotenv ^4.0
                    $repository = RepositoryBuilder::create()
                        ->withReaders([])
                        ->withWriters([$this->dotenvAdapter])
                        ->make();
                }

                $fileStore = StoreBuilder::create()
                    ->withPaths([$this->dotenvPath])
                    ->withNames([$this->dotenvName])
                    ->make();

                $loader = new Loader();
                $loader->load($repository, $fileStore->read());
            } catch (InvalidPathException $e) {
                // Environment variables could not be loaded
                // Continue with empty array adapter
            }
        }
        return $this->dotenvAdapter;
    }

    /**
     * Resolve the given key to an environment value.
     * @param string $key Environment key
     * @return mixed|null Environment value or null, if not available
     */
    public function get(string $key)
    {
        // Try to read variable via putenv/getenv
        return $this->putenvAdapter->get($key)
            ->getOrCall(function () use ($key) {
                // Try to read variable from .env file
                return $this->getDotenvAdapter()->get($key)
                    ->getOrCall(function () use ($key) {
                        return null;
                    });
            });
    }
}
