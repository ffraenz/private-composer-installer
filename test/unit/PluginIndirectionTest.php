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

use function bin2hex;
use function random_bytes;
use function version_compare;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;

class PluginIndirectionTest extends TestCase
{
    public function testFetchesInlineIndirection()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testFetchesInlineIndirectionWithKeyPath()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testFetchesInlineIndirectionWithVersionAsNumber()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.3.0';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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
                    'version'  => 1.3,
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

    public function testFetchesInlineIndirectionWithVersionAsString()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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
                    'version'  => $version . '.0', // Adding an extra segment to test Semver support.
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

    public function testFetchesPresetIndirection()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $_SERVER['KEY_FOO'] = 'foo';

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%KEY_FOO}#preset-1';
        $fulfilledUrl = 'https://example.com/api/download?v=foo#preset-1#v' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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
            $package,
            $rootPackage
        );
    }

    public function testThrowsExceptionWhenIndirectionFormatIsMissing()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Misconfigured package acme/example: Option "indirection.parse.format" '
            . 'must be one of "json", received false'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'download_key' => 'download',
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

    public function testThrowsExceptionWhenIndirectionKeyIsMissing()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Misconfigured package acme/example: Option "indirection.parse.download_key" '
            . 'must be a valid property or property path, received false'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format' => 'json',
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

    public function testThrowsExceptionWhenIndirectionKeyNotFoundInResponseBody()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected property "download" for package acme/example, not found in:'
            . PHP_EOL . PHP_EOL . '{"foo":false}'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testThrowsExceptionWhenIndirectionKeyPathNotFoundInResponseBody()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected a property path "a.b.c.0" for package acme/example, interrupted at "c", found true in:'
            . PHP_EOL . PHP_EOL . '{"a":{"b":true}}'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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
                'body'    => JsonFile::encode(
                    ['a' => ['b' => true]],
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

    public function testThrowsExceptionWhenIndirectionRequestIsBad()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(TransportException::class);

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testThrowsExceptionWhenIndirectionRequestIsUnauthorized()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(TransportException::class);

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testThrowsExceptionWhenIndirectionResponseNotFound()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(TransportException::class);

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testThrowsExceptionWhenIndirectionResponseBodyIsEmpty()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected a data structure from indirect URL for package acme/example'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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
                'body'    => '',
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

    public function testThrowsExceptionWhenIndirectionSanitizerDownloadKeyHasInvalidValue()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected a valid URL at property "download" for package acme/example, found 42 in:'
            . PHP_EOL . PHP_EOL . '{"download":42}'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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

        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            [
                'url'     => $fulfilledUrl,
                'body'    => JsonFile::encode(
                    ['download' => 42],
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

    public function testThrowsExceptionWhenIndirectionSanitizerDownloadKeyPathHasInvalidValue()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected a valid URL at property path "a.b.c.0" for package acme/example, found true in:'
            . PHP_EOL . PHP_EOL . '{"a":{"b":{"c":[true]}}}'
        );

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $package = $this->createPackageMock('acme/example', $version);

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
                'body'    => JsonFile::encode(
                    ['a' => ['b' => ['c' => [true]]]],
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

    public function testThrowsExceptionWhenIndirectionSanitizerVersionKeyHasInvalidValue()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $foundVersion = $version . '+foo bar';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected a valid version at property "version" for package acme/example, '
            . 'found \'' . $foundVersion . '\' in:' . PHP_EOL . PHP_EOL
            . '{"download":"' . $expectedUrl . '","version":"' . $foundVersion . '"}'
        );

        $package = $this->createPackageMock('acme/example', $version);

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

    public function testThrowsExceptionWhenIndirectionSanitizerVersionKeyPathHasInvalidValue()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected a valid version at property path "a.v" for package acme/example, found false in:'
            . PHP_EOL . PHP_EOL . '{"a":{"d":"' . $expectedUrl . '","v":false}}'
        );

        $package = $this->createPackageMock('acme/example', $version);

        $package
            ->method('getExtra')
            ->willReturn([
                'private-composer-installer' => [
                    'indirection' => [
                        'parse' => [
                            'format'       => 'json',
                            'download_key' => 'a.d',
                            'version_key'  => 'a.v',
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
                        'a' => [
                            'd' => $expectedUrl,
                            'v' => false,
                        ],
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

    public function testThrowsExceptionWhenIndirectionVersionIsUnsatisfied()
    {
        if (self::isComposer1()) {
            $this->markTestSkipped();
        }

        $version      = '1.2.3';
        $foundVersion = '1.3.0';
        $processedUrl = 'https://example.com/api/download?v={%VERSION}';
        $fulfilledUrl = 'https://example.com/api/download?v=' . $version;
        $expectedUrl  = 'https://example.com/r/' . bin2hex(random_bytes(5)) . '/d';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Expected download version from indirect URL (' . $foundVersion . ') '
            . 'to match installed version (' . $version . ') for package acme/example'
        );

        $package = $this->createPackageMock('acme/example', $version);

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

    protected static function isComposer1(): bool
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '<');
    }
}
