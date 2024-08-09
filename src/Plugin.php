<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use FFraenz\PrivateComposerInstaller\Environment\LoaderFactory;
use FFraenz\PrivateComposerInstaller\Environment\LoaderInterface;
use FFraenz\PrivateComposerInstaller\Environment\RepositoryInterface;
use InvalidArgumentException;
use UnexpectedValueException;

use function array_key_exists;
use function array_merge;
use function array_replace_recursive;
use function array_search;
use function array_unique;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;
use function parse_url;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strpos;
use function strtolower;
use function trim;
use function var_export;
use function version_compare;

use const PHP_EOL;
use const PHP_URL_FRAGMENT;
use const PHP_URL_SCHEME;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer|null */
    protected $composer;

    /** @var IOInterface|null */
    protected $io;

    /** @var array|null */
    protected $config;

    /** @var LoaderInterface|null */
    protected $loader;

    /** @var RepositoryInterface|null */
    protected $repository;

    /**
     * Return the composer instance.
     */
    public function getComposer(): ?Composer
    {
        return $this->composer;
    }

    /**
     * Return the IO interface object.
     */
    public function getIO(): ?IOInterface
    {
        return $this->io;
    }

    /**
     * Return the root config value for the given key.
     *
     * Returns the entire root config array, if key is set to null.
     *
     * @return mixed
     */
    public function getConfig(?string $key = null)
    {
        if ($this->config === null) {
            $this->config = [
                'dotenv-path' => null,
                'dotenv-name' => null,
                'presets'     => [],
            ];

            $rootPackage  = $this->getComposer()->getPackage();
            $extra        = $rootPackage->getExtra();
            $config       = $extra['private-composer-installer'] ?? [];
            $this->config = array_merge($this->config, $config);
        }

        return $key !== null ? ($this->config[$key] ?? null) : $this->config;
    }

    /**
     * Return the preset config value for the given key.
     *
     * Returns the entire preset array, if key is set to null.
     *
     * @return mixed
     */
    public function getPreset(string $preset, ?string $key = null)
    {
        $presets = $this->getConfig('presets');

        $options = $presets[$preset] ?? null;

        return $key !== null ? ($options[$key] ?? null) : $options;
    }

    /**
     * Set the environment variable loader instance.
     */
    public function setEnvironmentLoader(LoaderInterface $loader): void
    {
        $this->loader     = $loader;
        $this->repository = null;
    }

    /**
     * Get the environment variable loader instance.
     */
    public function getEnvironmentLoader(): LoaderInterface
    {
        if ($this->loader === null) {
            $this->loader = LoaderFactory::create(
                $this->getConfig('dotenv-path'),
                $this->getConfig('dotenv-name')
            );
        }

        return $this->loader;
    }

    /**
     * Load and return the environment repository.
     *
     * If the repository has already been loaded, this is not repeated.
     */
    public function getEnvironmentRepository(): RepositoryInterface
    {
        if ($this->repository === null) {
            $this->repository = $this->getEnvironmentLoader()->load();
        }

        return $this->repository;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents()
    {
        return self::isComposer1() ? [
            PackageEvents::PRE_PACKAGE_INSTALL => 'handlePreInstallUpdateEvent',
            PackageEvents::PRE_PACKAGE_UPDATE  => 'handlePreInstallUpdateEvent',
            PluginEvents::PRE_FILE_DOWNLOAD    => ['handlePreDownloadEvent', -1],
        ] : [
            PluginEvents::PRE_FILE_DOWNLOAD => ['handlePreDownloadEvent', -1],
        ];
    }

    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    /**
     * @inheritDoc
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $this->composer = null;
        $this->io       = null;
    }

    /**
     * @inheritDoc
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * Handle PRE_PACKAGE_INSTALL and PRE_PACKAGE_UPDATE Composer events.
     *
     * Only gets triggered running Composer 1.
     */
    public function handlePreInstallUpdateEvent(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        $package = $operation->getJobType() === 'update'
            ? $operation->getTargetPackage()
            : $operation->getPackage();

        // If running Composer 1 the package version needs to be injected
        // before the dist URL gets written into `composer.lock`
        $distUrl = $package->getDistUrl();
        $version = $package->getPrettyVersion();

        $filteredDistUrl = $this->fulfillVersionPlaceholder($distUrl, $version);
        if ($filteredDistUrl !== $distUrl) {
            $package->setDistUrl($filteredDistUrl);
        }
    }

    /**
     * Fulfill package URL placeholders before downloading the package.
     */
    public function handlePreDownloadEvent(PreFileDownloadEvent $event): void
    {
        $filteredCacheKey = $filteredProcessedUrl = $processedUrl = $event->getProcessedUrl();

        if ($presetKey = parse_url($processedUrl, PHP_URL_FRAGMENT)) {
            // Extract preset fragment before fullfilling the version placeholder
            // to avoid a possible double fragment (`#<preset>#v<version>`).
            // Do not strip the preset fragment to ensure its stored
            // inside `composer.lock` to allow cache-busting.
            $presetConfig = $this->getPreset($presetKey);
        }

        if (! self::isComposer1() && $event->getType() === 'package') {
            // Fulfill version placeholder for packages
            // In Composer 1 this step is done upon package install & update
            /** @var PackageInterface $package */
            $package = $event->getContext();
            $version = $package->getPrettyVersion();
            $extra   = $package->getExtra()['private-composer-installer'] ?? $presetConfig ?? [];

            $filteredCacheKey = $filteredProcessedUrl = $this->fulfillVersionPlaceholder(
                $filteredProcessedUrl,
                $version
            );
        }

        // Fulfill env placeholders
        $filteredProcessedUrl = $this->fulfillPlaceholders($filteredProcessedUrl);

        if (isset($package, $extra['indirection'])) {
            $filteredProcessedUrl = $this->fetchIndirection(
                $event->getHttpDownloader(),
                $filteredProcessedUrl,
                $package->getName(),
                $extra['indirection']
            );
        }

        // Submit changes to Composer, if any
        if ($filteredProcessedUrl !== $processedUrl) {
            if (self::isComposer1()) {
                // Swap out remote filesystem to change processed URL
                $originalRemoteFilesystem = $event->getRemoteFilesystem();
                $event->setRemoteFilesystem(new RemoteFilesystem(
                    $filteredProcessedUrl,
                    $this->io,
                    $this->composer->getConfig(),
                    $originalRemoteFilesystem->getOptions(),
                    $originalRemoteFilesystem->isTlsDisabled()
                ));
            } else {
                // Set processed URL and cache key
                $event->setProcessedUrl($filteredProcessedUrl);
                $event->setCustomCacheKey($filteredCacheKey);
            }
        }
    }

    /**
     * Handle indirect package download URL.
     *
     * @param  string  $url     The package intermediary URL.
     * @param  mixed[] $options The indirection settings.
     * @return string Returns the package download URL on sucess or
     *     the serialized response from the intermediary on failure.
     */
    public function fetchIndirection(
        HttpDownloader $httpDownloader,
        string $url,
        string $packageName,
        array $options
    ): string {
        $format = $options['parse']['format'] ?? false;
        if ($format !== 'json') {
            /**
             * @todo [2024-08-06] Add support for RegExp of non-JSON body (such as XML).
             * @todo [2024-08-06] Add support for PHP serialized body (such as Gravity Forms).
             */
            throw new InvalidArgumentException(sprintf(
                'Misconfigured package %s: Option "indirection.parse.format" '
                . 'must be one of "json", received %s',
                $packageName,
                var_export($format, true)
            ));
        }

        $key = $options['parse']['key'] ?? false;
        if ($key === false) {
            throw new InvalidArgumentException(sprintf(
                'Misconfigured package %s: Option "indirection.parse.key" '
                . 'must be a valid property or property path, received %s',
                $packageName,
                var_export($key, true)
            ));
        }

        try {
            $response = $httpDownloader->get($url, [
                'http' => array_replace_recursive(['method' => 'GET'], $options['http'] ?? []),
                'ssl'  => $options['ssl'] ?? [],
            ]);
        } catch (TransportException $e) {
            if ($e->getCode() === 400) {
                $message = sprintf(
                    'Invalid request to indirect URL for package %s: %s',
                    $packageName,
                    $e->getMessage()
                );
            } elseif (in_array($e->getCode(), [401, 403])) {
                $message = sprintf(
                    'Invalid authentication to indirect URL for package %s: %s',
                    $packageName,
                    $e->getMessage()
                );
            } else {
                $message = sprintf(
                    'Could not query indirect URL for package %s: %s',
                    $packageName,
                    $e->getMessage()
                );
            }

            throw new TransportException($message, $e->getCode(), $e);
        }

        if (! $response->getBody()) {
            throw new UnexpectedValueException(sprintf(
                'Expected a data structure from indirect URL for package %s',
                $packageName
            ));
        }

        $data = $response->decodeJson();

        // Look for literal key in response.
        if (array_key_exists($key, $data)) {
            if (is_string($data[$key]) && parse_url($data[$key], PHP_URL_SCHEME)) {
                return $data[$key];
            }

            throw new UnexpectedValueException(sprintf(
                'Expected a URL at property "%s" for package %s, '
                . 'found %s in:' . PHP_EOL . PHP_EOL . '%s',
                $key,
                $packageName,
                var_export($data[$key], true),
                self::excerptResponseBody($response)
            ));
        }

        // If not a key path, bail early.
        if (mb_strpos($key, '.') === false) {
            throw new UnexpectedValueException(sprintf(
                'Expected property "%s" for package %s, '
                . 'not found in:' . PHP_EOL . PHP_EOL . '%s',
                $key,
                $packageName,
                self::excerptResponseBody($response)
            ));
        }

        // Iterate segments of key path to traverse response.
        foreach (explode('.', $key) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                throw new UnexpectedValueException(sprintf(
                    'Expected a property path "%s" for package %s, '
                    . 'interrupted at "%s", found %s in:' . PHP_EOL . PHP_EOL . '%s',
                    $key,
                    $packageName,
                    $segment,
                    var_export($data, true),
                    self::excerptResponseBody($response)
                ));
            }
        }

        if (is_string($data) && parse_url($data, PHP_URL_SCHEME)) {
            return $data;
        }

        throw new UnexpectedValueException(sprintf(
            'Expected a URL at property path "%s" for package %s, '
            . 'found %s in:' . PHP_EOL . PHP_EOL . '%s',
            $key,
            $packageName,
            var_export($data, true),
            self::excerptResponseBody($response)
        ));
    }

    /**
     * Filter the dist URL for a given package.
     *
     * Filtered dist URLs get stored inside `composer.lock`.
     *
     * @param string|int|null $version Non-normalized package version
     */
    public function fulfillVersionPlaceholder(?string $url, $version): ?string
    {
        // Check if package dist url contains any placeholders (incl. version)
        $placeholders = $this->identifyPlaceholders($url);

        if (count($placeholders) > 0 && $version !== null) {
            // Inject version into URL
            if (array_search('version', $placeholders) !== false) {
                // If there is a version placeholder in the URL, fulfill it
                $url = preg_replace('/{%version}/i', $version, $url);
            } elseif (strpos($url, $version) === false) {
                // If the exact version is not already part of the URL, append
                // it as a hash to the end of the URL to force a re-download
                // when updating the version
                $url .= '#v' . $version;
            }
        }

        return $url;
    }

    /**
     * Filter the given processed URL before downloading.
     *
     * Filtered processed URLs do not get stored inside `composer.lock`.
     */
    public function fulfillPlaceholders(?string $url): ?string
    {
        $placeholders = $this->identifyPlaceholders($url);

        // Replace each placeholder with env var
        foreach ($placeholders as $placeholder) {
            $value = $this->getEnvironmentRepository()->get($placeholder);
            $url   = str_replace('{%' . $placeholder . '}', $value, $url);
        }

        return $url;
    }

    /**
     * Retrieve placeholders for the given URL.
     *
     * @return string[]
     */
    public function identifyPlaceholders(?string $url): array
    {
        if (empty($url)) {
            return [];
        }

        $matches = [];
        preg_match_all('/{%([A-Za-z0-9-_]+)}/', $url, $matches);

        $placeholders = [];
        foreach ($matches[1] as $match) {
            // The 'version' placeholder is case-insensitive
            $placeholders[] = strtolower($match) !== 'version'
                ? $match
                : 'version';
        }

        return array_unique($placeholders);
    }

    protected static function excerptResponseBody(Response $response): string
    {
        $body = $response->getBody();
        if (! is_string($body)) {
            return '<empty response>';
        }

        $body   = trim($body);
        $length = mb_strlen($body);
        if ($length === 0) {
            return '<empty response>';
        }

        return mb_substr($body, 0, 100) . ($length > 100 ? '...' : '');
    }

    /**
     * Test if this plugin runs within Composer 2.
     */
    protected static function isComposer1(): bool
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '<');
    }
}
