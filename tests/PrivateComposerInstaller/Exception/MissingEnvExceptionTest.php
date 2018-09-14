<?php

namespace FFraenz\PrivateComposerInstaller\Test\Exception;

use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;
use PHPUnit\Framework\TestCase;

class MissingEnvExceptionTest extends TestCase
{
    public function testMessage()
    {
        $key = 'PLUGIN_ACF_KEY';
        $exception = new MissingEnvException($key);
        $this->assertEquals(
            'Can\'t resolve placeholder {%PLUGIN_ACF_KEY}. ' .
            'Environment variable \'PLUGIN_ACF_KEY\' is not set.',
            $exception->getMessage()
        );
    }
}
