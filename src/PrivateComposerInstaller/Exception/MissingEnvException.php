<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Exception;

use Exception;

use function sprintf;

class MissingEnvException extends Exception
{
    /**
     * Constructor
     *
     * @param string $key Environment key
     */
    public function __construct(string $key)
    {
        parent::__construct(sprintf(
            'Can\'t resolve placeholder {%%%1$s}. '
            . 'Environment variable \'%1$s\' is not set.',
            $key
        ));
    }
}
