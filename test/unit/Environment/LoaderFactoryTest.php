<?php

namespace FFraenz\PrivateComposerInstaller\Test\Environment;

use FFraenz\PrivateComposerInstaller\Environment\LoaderFactory;
use PHPUnit\Framework\TestCase;

use function implode;

class LoaderFactoryTest extends TestCase
{
    public function testComputePaths()
    {
        self::assertSame(
            '.',
            implode(',', LoaderFactory::computePaths('.'))
        );
        self::assertSame(
            '/',
            implode(',', LoaderFactory::computePaths('/'))
        );
        self::assertSame(
            'foo/bar,foo,.',
            implode(',', LoaderFactory::computePaths('foo/bar'))
        );
        self::assertSame(
            '/foo/bar,/foo,/',
            implode(',', LoaderFactory::computePaths('/foo/bar'))
        );
    }
}
