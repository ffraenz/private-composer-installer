<?php

namespace FFraenz\PrivateComposerInstaller\Exception;

class MissingEnvException extends \Exception
{
    public function __construct($key)
    {
        parent::__construct(sprintf(
            'Can\'t resolve placeholder {%%%1$s}. ' .
            'Environment variable \'%1$s\' is not set.',
            $key
        ));
    }
}
