<?php

namespace FFraenz\PrivateComposerInstaller;

use Dotenv\Environment\Adapter\ArrayAdapter;
use Dotenv\Environment\Adapter\PutenvAdapter;
use Dotenv\Environment\DotenvFactory;
use Dotenv\Loader;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;

class Env
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
     * Constructor
     * @param string $dotenvPath Path to the .env file
     */
    public function __construct(string $dotenvPath)
    {
        $this->getenvAdapter = new PutenvAdapter();
        $this->dotenvPath = $dotenvPath;
    }

    /**
     * Lazily creates an ArrayAdapter instance providing .env file variables.
     * @return ArrayAdapter
     */
    protected function getDotenvAdapter(): ArrayAdapter
    {
        if ($this->dotenvAdapter === null) {
            $this->dotenvAdapter = new ArrayAdapter();

            // Load the .env file if it exists or leave the array adapter empty
            if (file_exists($this->dotenvPath)) {
                $dotenvFactory = new DotenvFactory([$this->dotenvAdapter]);
                $loader = new Loader([$this->dotenvPath], $dotenvFactory);
                $loader->load();
            }
        }
        return $this->dotenvAdapter;
    }

    /**
     * Returns an env variable for the given key.
     * @param string $key Env variable key
     * @throws MissingEnvException if there is no env var set for the given key.
     * @return mixed
     */
    public function get(string $key)
    {
        // Try to read variable via putenv/getenv
        return $this->getenvAdapter->get($key)
            ->getOrCall(function () use ($key) {
                // Try to read variable from .env file
                return $this->getDotenvAdapter()->get($key)
                    ->getOrCall(function () use ($key) {
                        // Env variable is not available
                        throw new MissingEnvException($key);
                    });
            });
    }
}
