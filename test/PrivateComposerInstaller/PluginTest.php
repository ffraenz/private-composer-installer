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

    public function testIgnorePackagesWithoutPlaceholders()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods([
                'getDistUrl',
                'getPrettyVersion',
                'setDistUrl',
            ])
            ->getMockForAbstractClass();

        $package
            ->expects($this->exactly(2))
            ->method('getDistUrl')
            ->willReturn('https://example.com/download');

        $package
            ->expects($this->never())
            ->method('getPrettyVersion');

        $package
            ->expects($this->never())
            ->method('setDistUrl');

        // Test package install event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'install'));

        // Test package update event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'update'));
    }

    public function testSkipVersionInjectionIfAlreadyPresent()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->exactly(2))
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->exactly(2))
            ->method('getDistUrl')
            ->willReturn('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        $package
            ->expects($this->never())
            ->method('setDistUrl');

        // Test package install event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'install'));

        // Test package update event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'update'));
    }

    public function testInjectVersion()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->exactly(2))
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->exactly(2))
            ->method('getDistUrl')
            ->willReturn('https://example.com/d?key={%KEY_FOO}');

        $package
            ->expects($this->exactly(2))
            ->method('setDistUrl')
            ->with('https://example.com/d?key={%KEY_FOO}#v1.2.3');

        // Test package install event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'install'));

        // Test package update event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'update'));
    }

    public function testFulfillVersionPlaceholder()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock a package
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->setMethods(['getDistUrl', 'getPrettyVersion', 'setDistUrl'])
            ->getMockForAbstractClass();

        $package
            ->expects($this->exactly(2))
            ->method('getPrettyVersion')
            ->willReturn('1.2.3');

        $package
            ->expects($this->exactly(2))
            ->method('getDistUrl')
            ->willReturn('https://example.com/r/{%VerSion}/d?key={%KEY_FOO}');

        $package
            ->expects($this->exactly(2))
            ->method('setDistUrl')
            ->with('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        // Test package install event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'install'));

        // Test package update event
        $plugin = new Plugin();
        $plugin->injectVersion($this->mockInstallEvent($package, 'update'));
    }

    public function testIgnoresProcessedUrlWithoutPlaceholders()
    {
        // Mock a RemoteFilesystem
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem'
            ])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn('https://example.com/r/1.2.3/d');

        $event
            ->expects($this->never())
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        $event
            ->expects($this->never())
            ->method('setRemoteFilesystem')
            ->willReturn($rfs);

        // Test placeholder injection
        $plugin = new Plugin();
        $plugin->injectPlaceholders($event);
    }

    public function testProcessedUrlWithPlaceholdersConfiguresFilesystem()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Mock RemoteFilesystem instance
        $options = ['options' => 'array'];
        $tlsDisabled = true;
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOptions', 'isTlsDisabled'])
            ->getMock();

        $rfs
            ->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $rfs
            ->expects($this->once())
            ->method('isTlsDisabled')
            ->willReturn($tlsDisabled);

        // Mock Config instance
        $config = $this
            ->getMockBuilder(Config::class)
            ->getMock();

        // Mock Composer instance
        $composer = $this
            ->getMockBuilder(Composer::class)
            ->setMethods(['getConfig'])
            ->getMock();

        $composer
            ->expects($this->once())
            ->method('getConfig')
            ->willReturn($config);

        // Mock IOInterface instance
        $io = $this
            ->getMockBuilder(IOInterface::class)
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem',
            ])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        $event
            ->expects($this->once())
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        $event
            ->expects($this->once())
            ->method('setRemoteFilesystem')
            ->with($this->callback(
                function ($rfs) use ($config, $io, $options, $tlsDisabled) {
                    $this->assertEquals($options, $rfs->getOptions());
                    $this->assertEquals($tlsDisabled, $rfs->isTlsDisabled());
                    return true;
                }
            ));

        // Trigger placeholder injection
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->injectPlaceholders($event);
    }

    public function testInjectsSinglePlaceholderFromEnv()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=TEST'
        );
    }

    public function testInjectsSinglePlaceholderMultipleTimes()
    {
        // Make an env variable available
        putenv('KEY_FOO=TEST');

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&confirm={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?key=TEST&confirm=TEST'
        );
    }

    public function testInjectsMultiplePlaceholdersFromEnv()
    {
        // Make env variables available
        putenv('KEY_FOO=Hello');
        putenv('KEY_BAR=World');

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testInjectsMultiplePlaceholdersFromDotenvFile()
    {
        // Make env variables available through dot env file
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=Hello' . PHP_EOL . 'KEY_BAR=World' . PHP_EOL
        );

        // Test placeholder injection
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?key={%KEY_FOO}&secret={%KEY_BAR}',
            'https://example.com/r/1.2.3/d?key=Hello&secret=World'
        );
    }

    public function testPrefersVariableFromEnv()
    {
        // Make foo env variable available
        putenv('KEY_BAR=YAY');

        // Make diffrent env variable available through dot env file
        file_put_contents(
            getcwd() . DIRECTORY_SEPARATOR . '.env',
            'KEY_FOO=YAY' . PHP_EOL . 'KEY_BAR=NAY' . PHP_EOL
        );

        // Expect the env variable to be used over the dot env file
        $this->expectFileDownload(
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
        $this->expectFileDownload(
            'https://example.com/r/1.2.3/d?foo={%KEY_FOO}',
            'https://example.com/r/1.2.3/d?foo=YAY'
        );
        $this->assertEquals(null, getenv('KEY_FOO'));
    }

    public function testThrowsExceptionWhenEnvVariableIsMissing()
    {
        // Expect an exception
        $this->expectException(MissingEnvException::class);
        $this->expectExceptionMessage(
            'Can\'t resolve placeholder {%KEY_FOO}. ' .
            'Environment variable \'KEY_FOO\' is not set.'
        );

        // Mock a RemoteFilesystem
        $rfs = $this
            ->getMockBuilder(RemoteFilesystem::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProcessedUrl', 'getRemoteFilesystem'])
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getProcessedUrl')
            ->willReturn('https://example.com/r/1.2.3/d?key={%KEY_FOO}');

        $event
            ->expects($this->never())
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        // Test placeholder injection
        $plugin = new Plugin();
        $plugin->injectPlaceholders($event);
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
            ->expects($this->once())
            ->method('getOperation')
            ->willReturn($operation);

        return $packageEvent;
    }

    protected function expectFileDownload($processedUrl, $expectedUrl)
    {
        // Mock RemoteFilesystem instance
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

        // Mock Config instance
        $config = $this
            ->getMockBuilder(Config::class)
            ->getMock();

        // Mock Composer instance
        $composer = $this
            ->getMockBuilder(Composer::class)
            ->setMethods(['getConfig'])
            ->getMock();

        $composer
            ->method('getConfig')
            ->willReturn($config);

        // Mock IOInterface instance
        $io = $this
            ->getMockBuilder(IOInterface::class)
            ->getMock();

        // Mock an Event
        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getProcessedUrl',
                'getRemoteFilesystem',
                'setRemoteFilesystem',
            ])
            ->getMock();

        $event
            ->method('getProcessedUrl')
            ->willReturn($processedUrl);

        $event
            ->method('getRemoteFilesystem')
            ->willReturn($rfs);

        $event
            ->method('setRemoteFilesystem')
            ->with($this->callback(
                function ($rfs) use ($expectedUrl) {
                    $this->assertEquals(
                        $expectedUrl,
                        $rfs->getPrivateFileUrl()
                    );
                    return true;
                }
            ));

        // Trigger placeholder injection
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->injectPlaceholders($event);
    }
}
