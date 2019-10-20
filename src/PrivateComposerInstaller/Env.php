<?php

namespace FFraenz\PrivateComposerInstaller;

use Dotenv\Environment\Adapter\ArrayAdapter;
use Dotenv\Environment\DotenvFactory;
use Dotenv\Loader;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;

class Env
{
    protected $adapter;
    protected $path;
    protected $loaded = false;

    public function __construct($path)
    {
        $this->adapter = new ArrayAdapter();
        $this->path = $path;
    }

    public function load()
    {
        $this->loaded = true;
        $loader = new Loader([$this->path], new DotenvFactory([$this->adapter]));
        $loader->load();
    }

    public function get($key)
    {
        if (!$this->loaded) {
            $this->load();
        }

        return $this->adapter->get($key)->getOrCall(function() use ($key) {
            throw new MissingEnvException($key);
        });
    }
}
