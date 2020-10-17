<?php

namespace FFraenz\PrivateComposerInstaller\Test\Environment;

use FFraenz\PrivateComposerInstaller\Environment\LoaderFactory;
use FFraenz\PrivateComposerInstaller\Environment\LoaderInterface;
use FFraenz\PrivateComposerInstaller\Environment\RepositoryInterface;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;
use PHPUnit\Framework\TestCase;

class LoaderTest extends TestCase
{
    public function testCanCreateAndLoad()
    {
        $loader = LoaderFactory::create();
        self::assertInstanceOf(LoaderInterface::class, $loader);
        self::assertInstanceOf(RepositoryInterface::class, $loader->load());
    }

    /**
     * @depends testCanCreateAndLoad
     */
    public function testCanLoadCustomPathAndName()
    {
        $repo = LoaderFactory::create(__DIR__.'/../../stubs/example', '.env.test')->load();
        self::assertSame('Hi there!', $repo->get('EG_VAR'));
    }

    /**
     * @depends testCanCreateAndLoad
     */
    public function testCanLoadParentFile()
    {
        $cwd = getcwd();
        chdir(__DIR__.'/../../stubs/foo/bar');
        $repo = LoaderFactory::create()->load();
        self::assertSame('Hi', $repo->get('FOO_VAR'));
        chdir($cwd);
    }

    /**
     * @depends testCanCreateAndLoad
     */
    public function testExceptionOnEmptyValue()
    {
        self::expectException(MissingEnvException::class);
        LoaderFactory::create()->load()->get('qwertyuiop');
    }
}
