<?php

namespace FFraenz\PrivateComposerInstaller;

interface EnvResolverInterface
{
    /**
     * Return an env value for the given key.
     * @param string $key Env var key
     * @return mixed|null Env var value or null, if it is not set
     */
    public function get($key);
}
