<?php

namespace FFraenz\PrivateComposerInstaller\Resolver;

interface ResolverInterface
{
    /**
     * Resolve the given key to an environment value.
     * @param string $key Environment key
     * @return mixed|null Environment value or null, if not available
     */
    public function get(string $key);
}
