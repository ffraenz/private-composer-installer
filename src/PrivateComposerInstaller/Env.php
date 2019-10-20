<?php

namespace FFraenz\PrivateComposerInstaller;

use Dotenv\Environment\Adapter\ArrayAdapter;
use Dotenv\Environment\Adapter\PutenvAdapter;
use Dotenv\Environment\DotenvFactory;
use Dotenv\Loader;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;

class Env
{
    protected $arrayAdapter;
    protected $getenvAdapter;
    protected $path;
    protected $loaded = false;

    public function __construct($path)
    {
        $this->arrayAdapter = new ArrayAdapter();
        $this->getenvAdapter = new PutenvAdapter();
        $this->path = $path;
    }

    public function load()
    {
        $this->loaded = true;
        $loader = new Loader([$this->path], new DotenvFactory([$this->arrayAdapter]));
        $loader->load();
    }

    public function get($key)
    {
        $this->getenvAdapter->get($key)->getOrCall(function() use ($key) {
            if (!$this->loaded) {
                $this->load();
            }

            return $this->arrayAdapter->get($key)->getOrCall(function() use ($key) {
                throw new MissingEnvException($key);
            });
        });
    }
}
