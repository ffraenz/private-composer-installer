<?php

namespace Kniwweler\PrivateComposerInstaller\Exception;

class MissingEnvException extends \Exception
{
    public function __construct($key) {
        parent::__construct(sprintf(
            'Can\'t resolve placeholder {%%%s}. ' .
            'Environment variable \'%s\' could not be found.',
            $key, $key
        ));
    }
}
