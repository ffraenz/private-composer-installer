<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\Installer\PackageEvents;
use Composer\Plugin\PluginEvents;
use FFraenz\PrivateComposerInstaller\Plugin;

class PluginTest extends \PHPUnit_Framework_TestCase
{
    public function testImplementsPluginInterface()
    {
        $this->assertInstanceOf(
            'Composer\Plugin\PluginInterface',
            new Plugin()
        );
    }

    public function testImplementsEventSubscriberInterface()
    {
        $this->assertInstanceOf(
            'Composer\EventDispatcher\EventSubscriberInterface',
            new Plugin()
        );
    }

    public function testActivateMakesComposerAndIOAvailable()
    {
        $composer = $this->getMockBuilder('Composer\Composer')->getMock();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $this->assertAttributeEquals($composer, 'composer', $plugin);
        $this->assertAttributeEquals($io, 'io', $plugin);
    }

    public function testSubscribesToPrePackageInstallEvent()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PackageEvents::PRE_PACKAGE_INSTALL],
            'injectVersion'
        );
    }

    public function testSubscribesToPreUpdateInstallEvent()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PackageEvents::PRE_PACKAGE_UPDATE],
            'injectVersion'
        );
    }

    public function testSubscribesToPreFileDownloadEvent()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PluginEvents::PRE_FILE_DOWNLOAD],
            ['injectPlaceholders', -1]
        );
    }
}
