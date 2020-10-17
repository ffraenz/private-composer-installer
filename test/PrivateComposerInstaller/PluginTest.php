<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Util\RemoteFilesystem;
use FFraenz\PrivateComposerInstaller\Environment\LoaderInterface;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;
use FFraenz\PrivateComposerInstaller\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    protected function tearDown(): void
    {
        // Unset environment variables
        unset($_SERVER['KEY_FOO']);
        unset($_SERVER['KEY_BAR']);

        // Remove dot env file
        $dotenv = getcwd() . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($dotenv)) {
            unlink($dotenv);
        }
    }

    public function testImplementsPluginInterface()
    {
        $this->assertInstanceOf(PluginInterface::class, new Plugin());
    }

    public function testImplementsEventSubscriberInterface()
    {
        $this->assertInstanceOf(EventSubscriberInterface::class, new Plugin());
    }

    public function testActivateAndDeactivateSetsAndClearsComposerAndIO()
    {
        $composer = $this->createComposerMock();
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $this->assertEquals($composer, $plugin->getComposer());
        $this->assertEquals($io, $plugin->getIO());
        $plugin->deactivate($composer, $io);
        $this->assertNull($plugin->getComposer());
        $this->assertNull($plugin->getIO());
        $plugin->uninstall($composer, $io);
    }

    public function testDeactivateClearsComposerAndIO()
    {
        $composer = $this->createComposerMock();
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $this->assertEquals($composer, $plugin->getComposer());
        $this->assertEquals($io, $plugin->getIO());
    }

    public function testLazyEnvironmentLoaderInstantiation()
    {
        $composer = $this->createComposerMock();
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $this->assertInstanceOf(LoaderInterface::class, $plugin->getEnvironmentLoader());
    }

    public function testSetEnvironmentLoader()
    {
        $composer = $this->createComposerMock();
        $io = $this->createMock(IOInterface::class);
        $loader = $this->createMock(LoaderInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->setEnvironmentLoader($loader);
        $this->assertEquals($loader, $plugin->getEnvironmentLoader());
    }

    public function testSubscribesToEvents()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PluginEvents::PRE_FILE_DOWNLOAD],
            ['handlePreDownloadEvent', -1]
        );
        if (self::isComposer1()) {
            $this->assertEquals(
                $subscribedEvents[PackageEvents::PRE_PACKAGE_INSTALL],
                'handlePreInstallUpdateEvent'
            );
            $this->assertEquals(
                $subscribedEvents[PackageEvents::PRE_PACKAGE_UPDATE],
                'handlePreInstallUpdateEvent'
            );
        }
    }

    public function testIgnoreVersionLockWithoutDistUrl()
    {
        if (! self::isComposer1()) {
            $this->markTestSkipped();
        }
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectLockedDistUrl(
            null,
            '1.2.3',
            null
        );
    }

    public function testIgnoreVersionLockWithoutPlaceholders()
    {
        if (! self::isComposer1()) {
            $this->markTestSkipped();
        }
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectLockedDistUrl(
            'https://example.com/download',
            '1.2.3',
            'https://example.com/download'
        );
    }

    public function testSkipVersionLockIfAlreadyPresent()
    {
        if (! self::isComposer1()) {
            $this->markTestSkipped();
        }
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectLockedDistUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}'
        );
    }

    public function testVersionLockWithoutVersionPlaceholder()
    {
        if (! self::isComposer1()) {
            $this->markTestSkipped();
        }
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectLockedDistUrl(
            'https://example.com/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/d?key={%KEY_FOO}#v1.2.3'
        );
    }

    public function testVersionLockWithVersionPlaceholder()
    {
        if (! self::isComposer1()) {
            $this->markTestSkipped();
        }
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectLockedDistUrl(
            'https://example.com/r/{%VerSion}/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}'
        );
    }

    public function testThrowsExceptionWhenEnvVariableIsMissing()
    {
        $this->expectException(MissingEnvException::class);
        $this->expectExceptionMessage(
            'Can\'t resolve placeholder {%KEY_FOO}. ' .
            'Environment variable \'KEY_FOO\' is not set.'
        );
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}'
        );
    }

    public function testIgnoresProcessedUrlWithoutPlaceholders()
    {
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d',
            '1.2.3',
            'https://example.com/r/1.2.3/d'
        );
    }

    public function testInjectsSinglePlaceholderFromEnv()
    {
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key=TEST'
        );
    }

    public function testInjectsSinglePlaceholderMultipleTimes()
    {
        $_SERVER['KEY_FOO'] = 'TEST';
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&confirm={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key=TEST&confirm=TEST'
        );
    }

    public function testInjectsMultiplePlaceholdersFromEnv()
    {
        $_SERVER['KEY_FOO'] = 'Hello';
        $_SERVER['KEY_BAR'] = 'World';
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testInjectsMultiplePlaceholdersFromDotenvFile()
    {
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=Hello' . PHP_EOL . 'KEY_BAR=World' . PHP_EOL
        );
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testPrefersVariableFromEnv()
    {
        $_SERVER['KEY_BAR'] = 'YAY';
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=YAY' . PHP_EOL . 'KEY_BAR=NAY' . PHP_EOL
        );
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?foo={%KEY_FOO}&bar={%KEY_BAR}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?foo=YAY&bar=YAY'
        );
    }

    public function testSideEffectFreeDotenvLoading()
    {
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=YAY' . PHP_EOL
        );
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?foo={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?foo=YAY'
        );
        $this->assertFalse(isset($_SERVER['KEY_FOO']));
    }

    protected function expectProcessedUrl($processedUrl, $version, $expectedUrl)
    {
        $changeExpected = $processedUrl !== $expectedUrl;

        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(self::isComposer1() ? [
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem',
            ] : [
                'getContext',
                'getProcessedUrl',
                'setProcessedUrl',
            ])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn($processedUrl);

        if (self::isComposer1()) {
            $options = ['options' => 'array'];
            $tlsDisabled = true;
            $rfs = $this
                ->getMockBuilder(RemoteFilesystem::class)
                ->disableOriginalConstructor()
                ->setMethods(['getOptions', 'isTlsDisabled'])
                ->getMock();

            $rfs
                ->method('getOptions')
                ->willReturn($options);

            $rfs
                ->method('isTlsDisabled')
                ->willReturn($tlsDisabled);

            $event
                ->method('getRemoteFilesystem')
                ->willReturn($rfs);

            $event
                ->expects($changeExpected ? $this->once() : $this->never())
                ->method('setRemoteFilesystem')
                ->with($this->callback(
                    function ($rfs) use ($options, $tlsDisabled, $expectedUrl) {
                        $this->assertEquals($options, $rfs->getOptions());
                        $this->assertEquals($tlsDisabled, $rfs->isTlsDisabled());
                        $this->assertEquals(
                            $expectedUrl,
                            $rfs->getPrivateFileUrl()
                        );
                        return true;
                    }
                ));
        } else {
            $event
                ->expects($changeExpected ? $this->once() : $this->never())
                ->method('setProcessedUrl')
                ->with($this->equalTo($expectedUrl));

            // Mock a package context
            $package = $this
                ->getMockBuilder(PackageInterface::class)
                ->setMethods(['getPrettyVersion'])
                ->getMockForAbstractClass();

            $package
                ->method('getPrettyVersion')
                ->willReturn($version);

            $event
                ->method('getContext')
                ->willReturn($package);
        }

        $composer = $this->createComposerMock();
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->handlePreDownloadEvent($event);
    }

    protected function expectLockedDistUrl($distUrl, $version, $expectedDistUrl)
    {
        $changeExpected = $distUrl !== $expectedDistUrl;

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->method('getPrettyVersion')
            ->willReturn($version);

        $package
            ->method('getDistUrl')
            ->willReturn($distUrl);

        if ($changeExpected) {
            $package
                ->expects($this->exactly(2))
                ->method('setDistUrl')
                ->with($expectedDistUrl);
        } else {
            $package
                ->expects($this->never())
                ->method('setDistUrl');
        }

        // Activate plugin
        $composer = $this->createComposerMock();
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);

        // Test package install event
        $event = $this->createInstallEventMock($package, 'install');
        $plugin->handlePreInstallUpdateEvent($event);

        // Test package update event
        $event = $this->createInstallEventMock($package, 'update');
        $plugin->handlePreInstallUpdateEvent($event);
    }

    protected function createComposerMock()
    {
        $rootPackage = $this
            ->getMockBuilder(RootPackage::class)
            ->disableOriginalConstructor()
            ->setMethods(['getExtra'])
            ->getMock();

        $rootPackage
            ->method('getExtra')
            ->willReturn([]);

        $composer = $this
            ->getMockBuilder(Composer::class)
            ->setMethods(['getConfig', 'getPackage'])
            ->getMock();

        $composer
            ->method('getPackage')
            ->willReturn($rootPackage);

        $config = $this->createMock(Config::class);

        $composer
            ->method('getConfig')
            ->willReturn($config);

        return $composer;
    }

    protected function createInstallEventMock(
        PackageInterface $package,
        $jobType
    ) {
        // Mock an operation
        $operation = $this
            ->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getJobType',
                $jobType === 'update' ? 'getTargetPackage' : 'getPackage',
            ])
            ->getMock();

        $operation
            ->method('getJobType')
            ->willReturn($jobType);

        $operation
            ->expects($this->once())
            ->method($jobType === 'update' ? 'getTargetPackage' : 'getPackage')
            ->willReturn($package);

        // Mock a package event
        $packageEvent = $this
            ->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOperation'])
            ->getMock();

        $packageEvent
            ->method('getOperation')
            ->willReturn($operation);

        return $packageEvent;
    }

    protected static function isComposer1()
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '<');
    }
}
