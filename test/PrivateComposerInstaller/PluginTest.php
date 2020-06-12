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
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Util\RemoteFilesystem;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;
use FFraenz\PrivateComposerInstaller\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    protected function tearDown(): void
    {
        // Unset environment variables
        putenv('KEY_FOO');
        putenv('KEY_BAR');

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

    public function testActivateMakesComposerAndIOAvailable()
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $this->assertEquals($composer, $plugin->getComposer());
        $this->assertEquals($io, $plugin->getIO());
    }

    public function testSubscribesToEvents()
    {
        $subscribedEvents = Plugin::getSubscribedEvents();
        $this->assertEquals(
            $subscribedEvents[PackageEvents::PRE_PACKAGE_INSTALL],
            'handlePrePackageInstall'
        );
        $this->assertEquals(
            $subscribedEvents[PackageEvents::PRE_PACKAGE_UPDATE],
            'handlePrePackageUpdate'
        );
        $this->assertEquals(
            $subscribedEvents[PluginEvents::PRE_FILE_DOWNLOAD],
            ['handlePreFileDownload', -1]
        );
    }

    public function testIgnorePackagesWithoutDistUrl()
    {
        putenv('KEY_FOO=TEST');
        $this->expectDistUrl(
            null,
            '1.2.3',
            null
        );
    }

    public function testIgnorePackagesWithoutPlaceholders()
    {
        putenv('KEY_FOO=TEST');
        $this->expectDistUrl(
            'https://example.com/download',
            '1.2.3',
            'https://example.com/download'
        );
    }

    public function testSkipVersionInjectionIfAlreadyPresent()
    {
        putenv('KEY_FOO=TEST');
        $this->expectDistUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}'
        );
    }

    public function testInjectVersion()
    {
        putenv('KEY_FOO=TEST');
        $this->expectDistUrl(
            'https://example.com/d?key={%KEY_FOO}',
            '1.2.3',
            'https://example.com/d?key={%KEY_FOO}#v1.2.3'
        );
    }

    public function testFulfillVersionPlaceholder()
    {
        putenv('KEY_FOO=TEST');
        $this->expectDistUrl(
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
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}'
        );
    }

    public function testIgnoresProcessedUrlWithoutPlaceholders()
    {
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d',
            'https://example.com/r/1.2.3/d'
        );
    }

    public function testInjectsSinglePlaceholderFromEnv()
    {
        putenv('KEY_FOO=TEST');
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=TEST'
        );
    }

    public function testInjectsSinglePlaceholderMultipleTimes()
    {
        putenv('KEY_FOO=TEST');
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&confirm={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=TEST&confirm=TEST'
        );
    }

    public function testInjectsMultiplePlaceholdersFromEnv()
    {
        putenv('KEY_FOO=Hello');
        putenv('KEY_BAR=World');
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
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
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testPrefersVariableFromEnv()
    {
        putenv('KEY_BAR=YAY');
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=YAY' . PHP_EOL . 'KEY_BAR=NAY' . PHP_EOL
        );
        $this->expectProcessedUrl(
            'https://example.com/r/1.2.3/d?foo={%KEY_FOO}&bar={%KEY_BAR}',
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
            'https://example.com/r/1.2.3/d?foo=YAY'
        );
        $this->assertEquals(null, getenv('KEY_FOO'));
    }

    protected function expectDistUrl($distUrl, $version, $expectedDistUrl)
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

        // Test package install event
        $plugin = new Plugin();
        $plugin->handlePrePackageInstall($this->mockInstallEvent($package, 'install'));

        // Test package update event
        $plugin = new Plugin();
        $plugin->handlePrePackageUpdate($this->mockInstallEvent($package, 'update'));
    }

    protected function expectProcessedUrl($processedUrl, $expectedUrl)
    {
        $changeExpected = $processedUrl !== $expectedUrl;
        $usesRemoteFilesystem = PluginInterface::PLUGIN_API_VERSION === '1.0.0';

        $composer = $this
            ->getMockBuilder(Composer::class)
            ->setMethods(['getConfig'])
            ->getMock();

        $io = $this
            ->getMockBuilder(IOInterface::class)
            ->getMock();

        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods($usesRemoteFilesystem ? [
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem',
            ] : [
                'getProcessedUrl',
                'setProcessedUrl',
            ])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn($processedUrl);

        if ($usesRemoteFilesystem) {
            $config = $this
                ->getMockBuilder(Config::class)
                ->getMock();

            $composer
                ->method('getConfig')
                ->willReturn($config);

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
                    function ($rfs) use ($config, $io, $options, $tlsDisabled) {
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
        }

        // Trigger plugin
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->handlePreFileDownload($event);
    }

    protected function mockInstallEvent(
        PackageInterface $package,
        string $jobType
    ): PackageEvent {
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
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        return $packageEvent;
    }
}
