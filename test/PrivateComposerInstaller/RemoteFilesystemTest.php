<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use FFraenz\PrivateComposerInstaller\RemoteFilesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\SetUpTearDownTrait;

class RemoteFilesystemTest extends TestCase
{
    use SetUpTearDownTrait;

    protected $io;

    protected function doSetUp()
    {
        // As of Composer 2 this class is no longer in use
        if (version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0.0', '>=')) {
            $this->markTestSkipped();
        }

        $this->io = $this->createMock(IOInterface::class);
    }

    public function testExtendsComposerRemoteFilesystem()
    {
        $this->assertInstanceOf(
            \Composer\Util\RemoteFilesystem::class,
            new RemoteFilesystem('', $this->io)
        );
    }

    public function testCopyUsesPrivateFileUrl()
    {
        // Test inspired by testCopy in
        // Composer\Test\Util\RemoteFilesystemTest
        $privateFileUrl = 'file://' . __FILE__;
        $filesystem = new RemoteFilesystem($privateFileUrl, $this->io);

        $file = tempnam(sys_get_temp_dir(), 'ff');
        $this->assertTrue($filesystem->copy(
            'http://example.org',
            $privateFileUrl,
            $file
        ));
        $this->assertFileExists($file);
        $this->assertStringContainsString('testCopy', file_get_contents($file));
        unlink($file);
    }
}
