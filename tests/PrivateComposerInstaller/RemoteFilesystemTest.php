<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use FFraenz\PrivateComposerInstaller\RemoteFilesystem;

class RemoteFilesystemTest extends \PHPUnit_Framework_TestCase
{
    protected $io;

    protected function setUp()
    {
        $this->io = $this->getMock('Composer\IO\IOInterface');
    }

    public function testExtendsComposerRemoteFilesystem()
    {
        $this->assertInstanceOf(
            'Composer\Util\RemoteFilesystem',
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
            'http://example.org', $privateFileUrl, $file));
        $this->assertFileExists($file);
        $this->assertContains('testCopy', file_get_contents($file));
        unlink($file);
    }
}
