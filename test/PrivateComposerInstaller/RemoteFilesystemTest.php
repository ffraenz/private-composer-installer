<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\IO\IOInterface;
use FFraenz\PrivateComposerInstaller\RemoteFilesystem;
use PHPUnit\Framework\TestCase;

class RemoteFilesystemTest extends TestCase
{
    protected $io;

    protected function setUp()
    {
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
        $this->assertContains('testCopy', file_get_contents($file));
        unlink($file);
    }
}
