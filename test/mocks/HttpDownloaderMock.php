<?php

declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * {@link https://github.com/composer/composer/blob/2.7.7/tests/Composer/Test/Mock/HttpDownloaderMock.php}
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view its LICENSE:
 *
 * The MIT License (MIT)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace FFraenz\PrivateComposerInstaller\Test;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use UnexpectedValueException;

use function array_diff_key;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_slice;
use function count;
use function implode;
use function is_array;
use function json_encode;

use const PHP_EOL;

/**
 * @phpstan-type HTTPExpectation array{
 *     url: non-empty-string,
 *     options: array<mixed>|null,
 *     status: int,
 *     body: string,
 *     headers: list<string>,
 * }
 * @phpstan-type ExpectationInit array{
 *     url: non-empty-string,
 *     options?: array<mixed>,
 *     status?: int,
 *     body?: string,
 *     headers?: list<string>,
 * }
 * @phpstan-type DefaultHandler array{
 *     status: int,
 *     body: string,
 *     headers: list<string>,
 * }
 * @phpstan-type HandlerInit array{
 *     status?: int,
 *     body?: string,
 *     headers?: list<string>,
 * }
 */
class HttpDownloaderMock extends HttpDownloader
{
    /** @var array<HTTPExpectation>|null */
    private $expectations;
    /** @var bool */
    private $strict = false;
    /** @var DefaultHandler */
    private $defaultHandler = ['status' => 200, 'body' => '', 'headers' => []];
    /** @var string[] */
    private $log = [];

    public function __construct(?IOInterface $io = null, ?Config $config = null)
    {
        if ($io === null) {
            $io = new BufferIO();
        }
        if ($config === null) {
            $config = new Config(false);
        }
        parent::__construct($io, $config);
    }

    /**
     * @param array<ExpectationInit> $expectations
     * @param bool                   $strict         Set to true if you want to provide *all*
     *     expected HTTP requests, and not just a subset you are interested in testing.
     * @param HandlerInit            $defaultHandler Default URL handler for undefined requests
     *     if not in strict mode.
     */
    public function expects(
        array $expectations,
        bool $strict = false,
        array $defaultHandler = ['status' => 200, 'body' => '', 'headers' => []]
    ): void {
        $default            = ['url' => '', 'options' => null, 'status' => 200, 'body' => '', 'headers' => ['']];
        $this->expectations = array_map(static function (array $expect) use ($default): array {
            if (count($diff = array_diff_key(array_merge($default, $expect), $default)) > 0) {
                throw new UnexpectedValueException(
                    'Unexpected keys in process execution step: ' . implode(', ', array_keys($diff))
                );
            }

            return array_merge($default, $expect);
        }, $expectations);
        $this->strict       = $strict;

        $this->defaultHandler = array_merge($this->defaultHandler, $defaultHandler);
    }

    public function assertComplete(): void
    {
        // this was not configured to expect anything, so no need to react here
        if (! is_array($this->expectations)) {
            return;
        }

        if (count($this->expectations) > 0) {
            $expectations = array_map(static function ($expect): string {
                return $expect['url'];
            }, $this->expectations);
            throw new AssertionFailedError(
                'There are still ' . count($this->expectations)
                . ' expected HTTP requests which have not been consumed:' . PHP_EOL
                . implode(PHP_EOL, $expectations) . PHP_EOL . PHP_EOL
                . 'Received calls:' . PHP_EOL . implode(PHP_EOL, $this->log)
            );
        }

        // dummy assertion to ensure the test is not marked as having no assertions
        Assert::assertTrue(true); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    /**
     * {@inheritdoc}
     */
    public function get($fileUrl, $options = []): Response
    {
        if ('' === $fileUrl) {
            throw new LogicException('url cannot be an empty string');
        }

        $this->log[] = $fileUrl;

        if (
            is_array($this->expectations) &&
            count($this->expectations) > 0 &&
            $fileUrl === $this->expectations[0]['url'] &&
            ($this->expectations[0]['options'] === null || $options === $this->expectations[0]['options'])
        ) {
            $expect = array_shift($this->expectations);

            return $this->respond(
                $fileUrl,
                $expect['status'],
                $expect['headers'],
                $expect['body']
            );
        }

        if (! $this->strict) {
            return $this->respond(
                $fileUrl,
                $this->defaultHandler['status'],
                $this->defaultHandler['headers'],
                $this->defaultHandler['body']
            );
        }

        throw new AssertionFailedError(
            'Received unexpected request for "' . $fileUrl . '" with options "' . json_encode($options) . '"' . PHP_EOL
            . (is_array($this->expectations) && count($this->expectations) > 0
                ? 'Expected "' . $this->expectations[0]['url'] . (
                    $this->expectations[0]['options'] !== null
                        ? '" with options "' . json_encode($this->expectations[0]['options'])
                        : '') . '" at this point.'
                : 'Expected no more calls at this point.') . PHP_EOL
            . 'Received calls:' . PHP_EOL . implode(PHP_EOL, array_slice($this->log, 0, -1))
        );
    }

    /**
     * @param list<string> $headers
     * @param non-empty-string $url
     */
    private function respond(string $url, int $status, array $headers, string $body): Response
    {
        if ($status < 400) {
            return new Response(['url' => $url], $status, $headers, $body);
        }

        $e = new TransportException('The "' . $url . '" file could not be downloaded', $status);
        $e->setHeaders($headers);
        $e->setResponse($body);

        throw $e;
    }
}
