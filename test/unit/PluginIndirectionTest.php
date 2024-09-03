<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Util\HttpDownloader;
use FFraenz\PrivateComposerInstaller\Plugin;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function version_compare;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use const PHP_VERSION;

class PluginIndirectionTest extends TestCase
{
    /**
     * @dataProvider provideDataForIndirectionDefinition
     */
    public function testIndirectionDefinition(
        string $packageVersion,
        string $processedUrl,
        string $fulfilledUrl,
        PackageInterface $package,
        RootPackage $rootPackage
    ): void {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $expectedUrl = self::getMockDownloadUrl();

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode([
                    'download' => $expectedUrl,
                ]),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $packageVersion,
            $httpDownloader,
            $package,
            $rootPackage
        );
    }

    public function provideDataForIndirectionDefinition(): iterable
    {
        $_SERVER['KEY_FOO'] = $foo = 'foo';

        $version = '1.2.3';

        $processedUrl = self::getMockIntermediaryUrl('?v={%KEY_FOO}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$foo}", "#v{$version}");

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                        ],
                    ],
                ],
            ]);

        $rootPackage = $this->createRootPackageMock();

        yield 'from private package (inline)' => [
            $version,
            $processedUrl,
            $fulfilledUrl,
            $package,
            $rootPackage,
        ];

        $processedUrl = self::getMockIntermediaryUrl('?v={%KEY_FOO}', '#preset-1');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$foo}", "#preset-1#v{$version}");

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $rootPackage = $this->createRootPackageMock();

        $rootPackage
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'presets' => [
                        'preset-1' => [
                            'indirection' => [
                                'parse' => [
                                    'format'       => 'json',
                                    'download_key' => 'download',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        yield 'from root package (preset)' => [
            $version,
            $processedUrl,
            $fulfilledUrl,
            $package,
            $rootPackage,
        ];
    }

    public function testIndirectionWithKeyPath(): void
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'a.b.c.0',
                        ],
                    ],
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode([
                    'a' => ['b' => ['c' => [$expectedUrl]]],
                ]),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    /**
     * @dataProvider provideDataForIndirectionWithVersion
     * @param mixed $indirectionVersion
     */
    public function testIndirectionWithVersion($indirectionVersion): void
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.3.0';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                            'version_key'  => 'version',
                        ],
                    ],
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode([
                    'download' => $expectedUrl,
                    'version'  => $indirectionVersion,
                ]),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function provideDataForIndirectionWithVersion(): array
    {
        return [
            'version: number' => [
                1.3,
            ],
            'version: string' => [
                '1.3.0.0', // Adding an extra segment to test Semver support.
            ],
        ];
    }

    /**
     * @dataProvider provideDataWhenIndirectionMisconfigured
     * @param mixed $indirectionVersion
     */
    public function testThrowsExceptionWhenIndirectionMisconfigured(
        string $exceptionMessage,
        array $indirectionOptions
    ): void {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => $indirectionOptions,
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode([
                    'download' => $expectedUrl,
                ]),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function provideDataWhenIndirectionMisconfigured(): array
    {
        return [
            'misconfigured: format'       => [
                'Misconfigured package acme/example: Option "indirection.parse.format" '
                . 'must be one of "json", "serialize", received false',
                [
                    'parse' => [
                        'missing_format' => null,
                        'download_key'   => 'download',
                    ],
                ],
            ],
            'misconfigured: download_key' => [
                'Misconfigured package acme/example: Option "indirection.parse.download_key" '
                . 'must be a valid property or property path, received false',
                [
                    'parse' => [
                        'format'               => 'json',
                        'missing_download_key' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideDataWhenIndirectionKeyNotFoundInResponseBody
     */
    public function testThrowsExceptionWhenIndirectionKeyNotFoundInResponseBody(
        string $exceptionMessage,
        string $downloadKey
    ): void {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => $downloadKey,
                        ],
                    ],
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode(
                    ['foo' => false],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function provideDataWhenIndirectionKeyNotFoundInResponseBody(): iterable
    {
        return [
            'single key' => [
                'Expected property "download" for package acme/example, not found in:'
                . PHP_EOL . PHP_EOL . '{"foo":false}',
                'download',
            ],
            'key path'   => [
                'Expected a property path "a.b.c.0" for package acme/example, interrupted at "a", not found in:'
                . PHP_EOL . PHP_EOL . '{"foo":false}',
                'a.b.c.0',
            ],
        ];
    }

    public function testThrowsExceptionWhenIndirectionRequestIsBad(): void
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(TransportException::class);

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                        ],
                    ],
                ],
            ]);

        $exception      = new TransportException('HTTP/1.1 400 BAD REQUEST', 400);
        $httpDownloader = $this->createHttpDownloaderMock();
        $httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with($fulfilledUrl)
            ->willThrowException($exception);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function testThrowsExceptionWhenIndirectionRequestIsUnauthorized(): void
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(TransportException::class);

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                        ],
                    ],
                ],
            ]);

        $exception      = new TransportException('HTTP/1.1 401 UNAUTHORIZED', 401);
        $httpDownloader = $this->createHttpDownloaderMock();
        $httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with($fulfilledUrl)
            ->willThrowException($exception);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function testThrowsExceptionWhenIndirectionResponseNotFound(): void
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(TransportException::class);

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                        ],
                    ],
                ],
            ]);

        $exception      = new TransportException('HTTP/1.1 404 NOT FOUND', 404);
        $httpDownloader = $this->createHttpDownloaderMock();
        $httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with($fulfilledUrl)
            ->willThrowException($exception);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    /**
     * @dataProvider provideDataWhenIndirectionResponseBodyIsInvalid
     */
    public function testThrowsExceptionWhenIndirectionResponseBodyIsInvalid(
        string $exceptionMessage,
        array $indirectionOptions,
        array $indirectionResponse
    ): void {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => $indirectionOptions,
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects(
            [['url' => $fulfilledUrl] + $indirectionResponse],
            true
        );

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function provideDataWhenIndirectionResponseBodyIsInvalid(): iterable
    {
        yield 'json: empty' => [
            'Expected a data structure from indirect URL for package acme/example: '
            . 'The input does not contain valid JSON' . PHP_EOL . 'Parse error on line 1',
            [
                'parse' => [
                    'format'       => 'json',
                    'download_key' => 'download',
                ],
            ],
            [
                'body'    => '',
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ];

        yield 'json: invalid' => [
            'Expected a data structure from indirect URL for package acme/example: '
            . 'Found "hello" instead of associative array',
            [
                'parse' => [
                    'format'       => 'json',
                    'download_key' => 'download',
                ],
            ],
            [
                'body'    => '"hello"',
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ];

        yield 'serialize: empty' => [
            'Expected a data structure from indirect URL for package acme/example: '
            . 'Found false instead of associative array',
            [
                'parse' => [
                    'format'       => 'serialize',
                    'download_key' => 'download',
                ],
            ],
            [
                'body'    => '',
                'headers' => [
                    'Content-Type: text/plain',
                ],
            ],
        ];

        $exceptionMessage = version_compare(PHP_VERSION, '8.1', '>=')
            ? 'Expected a data structure from indirect URL for package acme/example: '
                . 'unserialize(): Error at offset 5 of 6 bytes'
            : 'Expected a data structure from indirect URL for package acme/example: '
                . 'unserialize(): Unexpected end of serialized data';

        yield 'serialize: malformed' => [
            $exceptionMessage,
            [
                'parse' => [
                    'format'       => 'serialize',
                    'download_key' => 'download',
                ],
            ],
            [
                'body'    => 'a:1:{}',
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideDataWhenIndirectionSanitizerDownloadKeyHasInvalidValue
     */
    public function testThrowsExceptionWhenIndirectionSanitizerDownloadKeyHasInvalidValue(
        string $exceptionMessage,
        string $indirectionDownloadKey,
        array $indirectionResponseData
    ): void {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => $indirectionDownloadKey,
                        ],
                    ],
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode(
                    $indirectionResponseData,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function provideDataWhenIndirectionSanitizerDownloadKeyHasInvalidValue(): iterable
    {
        return [
            'single key' => [
                'Expected a valid URL at property "download" for package acme/example, found 42 in:'
                . PHP_EOL . PHP_EOL . '{"download":42}',
                'download',
                ['download' => 42],
            ],
            'key path'   => [
                'Expected a valid URL at property path "a.b.c.0" for package acme/example, found true in:'
                . PHP_EOL . PHP_EOL . '{"a":{"b":{"c":[true]}}}',
                'a.b.c.0',
                ['a' => ['b' => ['c' => [true]]]],
            ],
        ];
    }

    /**
     * @dataProvider provideDataWhenIndirectionSanitizerVersionKeyHasInvalidValue
     */
    public function testThrowsExceptionWhenIndirectionSanitizerVersionKeyHasInvalidValue(
        string $exceptionMessage,
        string $indirectionVersionKey,
        array $indirectionResponseData
    ): void {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                            'version_key'  => $indirectionVersionKey,
                        ],
                    ],
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode(
                    ['download' => $expectedUrl] + $indirectionResponseData,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    public function provideDataWhenIndirectionSanitizerVersionKeyHasInvalidValue(): iterable
    {
        $expectedUrl = self::getMockDownloadUrl();

        return [
            'single key' => [
                'Expected a valid version at property "version" for package acme/example, '
                . 'found "1.2.3+foo bar" in:' . PHP_EOL . PHP_EOL
                . '{"download":"' . $expectedUrl . '","version":"1.2.3+foo bar"}',
                'version',
                [
                    'version' => '1.2.3+foo bar',
                ],
            ],
            'key path'   => [
                'Expected a valid version at property path "a.v" for package acme/example, found false in:'
                . PHP_EOL . PHP_EOL . '{"download":"' . $expectedUrl . '","a":{"v":false}}',
                'a.v',
                [
                    'a' => [
                        'v' => false,
                    ],
                ],
            ],
        ];
    }

    public function testThrowsExceptionWhenIndirectionVersionIsUnsatisfied(): void
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $foundVersion = '1.3.0';
        $processedUrl = self::getMockIntermediaryUrl('?v={%VERSION}');
        $fulfilledUrl = self::getMockIntermediaryUrl("?v={$version}");
        $expectedUrl  = self::getMockDownloadUrl();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected download version from indirect URL (' . $foundVersion . ') '
            . 'to match installed version (' . $version . ') for package acme/example'
        );

        $package = $this->createPackageMock(self::getMockPackageName(), $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'download',
                            'version_key'  => 'version',
                        ],
                    ],
                ],
            ]);

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode(
                    [
                        'download' => $expectedUrl,
                        'version'  => $foundVersion,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'headers' => [
                    'Content-Type: application/json',
                ],
            ],
        ], true);

        $this->expectIndirection(
            $expectedUrl,
            $processedUrl,
            $version,
            $httpDownloader,
            $package
        );
    }

    protected function createPlugin(
        ?Composer $composer = null,
        ?IOInterface $io = null
    ): Plugin {
        $plugin = new Plugin();
        $plugin->activate(
            $composer ?? $this->createComposerMock(),
            $io ?? $this->createIOMock()
        );

        return $plugin;
    }

    /**
     * @return Composer&MockObject
     */
    protected function createComposerMock(
        ?RootPackage $rootPackage = null,
        ?Config $config = null
    ): MockObject {
        $composer = $this
            ->getMockBuilder(Composer::class)
            ->getMock();

        $composer
            ->method('getPackage')
            ->willReturn($rootPackage ?? $this->createRootPackageMock());

        $composer
            ->method('getConfig')
            ->willReturn($config ?? $this->createConfigMock());

        return $composer;
    }

    /**
     * @return Config&MockObject
     */
    protected function createConfigMock(): MockObject
    {
        return $this->createMock(Config::class);
    }

    /**
     * @return HttpDownloader&MockObject
     */
    protected function createHttpDownloaderMock(): MockObject
    {
        return $this
            ->getMockBuilder(HttpDownloader::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return IOInterface&MockObject
     */
    protected function createIOMock(): MockObject
    {
        return $this->createMock(IOInterface::class);
    }

    /**
     * @return PreFileDownloadEvent&MockObject
     */
    protected function createPreFileDownloadEventMock(
        string $expectedUrl,
        string $processedUrl,
        string $version,
        ?PackageInterface $package = null
    ): MockObject {
        $changeExpected = $expectedUrl !== $processedUrl;

        $event = $this
            ->getMockBuilder(PreFileDownloadEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event
            ->method('getProcessedUrl')
            ->willReturn($processedUrl);

        $event
            ->method('getType')
            ->willReturn('package');

        $event
            ->expects(
                // If we are expecting an Exception, this method
                // will probably not be called.
                ! $this->getExpectedException() && $changeExpected
                ? $this->once()
                : $this->never()
            )
            ->method('setProcessedUrl')
            ->with($this->equalTo($expectedUrl));

        if (! $package) {
            $package = $this->createPackageMock(null, $version);
        }

        $event
            ->method('getContext')
            ->willReturn($package);

        return $event;
    }

    /**
     * @return PackageInterface&MockObject
     */
    protected function createPackageMock(?string $name = null, ?string $version = null): MockObject
    {
        $package = $this
            ->getMockBuilder(PackageInterface::class)
            ->getMockForAbstractClass();

        if ($name) {
            $package
                ->method('getName')
                ->willReturn($name);
        }

        if ($version) {
            $package
                ->method('getPrettyVersion')
                ->willReturn($version);
        }

        return $package;
    }

    /**
     * @return RootPackage&MockObject
     */
    protected function createRootPackageMock(): MockObject
    {
        return $this
            ->getMockBuilder(RootPackage::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    protected function expectIndirection(
        string $expectedUrl,
        string $processedUrl,
        string $version,
        HttpDownloader $httpDownloader,
        ?PackageInterface $package = null,
        ?RootPackage $rootPackage = null
    ): void {
        if (! $package) {
            $package = $this->createPackageMock('foo/baz', $version);
        }

        if (! $rootPackage) {
            $rootPackage = $this->createRootPackageMock();
        }

        $event = $this->createPreFileDownloadEventMock(
            $expectedUrl,
            $processedUrl,
            $version,
            $package
        );

        $event
            ->method('getHttpDownloader')
            ->willReturn($httpDownloader);

        $plugin = $this->createPlugin(
            $this->createComposerMock($rootPackage)
        );
        $plugin->handlePreDownloadEvent($event);
    }

    protected static function getMockDownloadUrl(): string
    {
        return 'https://example.com/r/xyzzy/d';
    }

    protected static function getMockIntermediaryUrl(?string $query, ?string $fragment = null): string
    {
        return 'https://example.com/api/download' . $query . $fragment;
    }

    protected static function getMockPackageName(): string
    {
        return 'acme/example';
    }

    protected static function isComposer1(): bool
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '<');
    }
}
