<?php

declare(strict_types=1);

namespace FFraenz\PrivateComposerInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use FFraenz\PrivateComposerInstaller\Environment\LoaderFactory;
use FFraenz\PrivateComposerInstaller\Environment\LoaderInterface;
use FFraenz\PrivateComposerInstaller\Environment\RepositoryInterface;

use function array_merge;
use function array_search;
use function array_unique;
use function count;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function strpos;
use function strtolower;
use function version_compare;

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
     * Return the config value for the given key.
     *
     * Returns the entire root config array, if key is set to null.
     *
     * @return mixed
     */
    public function getConfig(?string $key)
    {
        if ($this->config === null) {
            $this->config = [
                'dotenv-path' => null,
                'dotenv-name' => null,
            ];

            $rootPackage  = $this->getComposer()->getPackage();
            $extra        = $rootPackage->getExtra();
            $config       = $extra['private-composer-installer'] ?? [];
            $this->config = array_merge($this->config, $config);
        }

        return $key !== null ? ($this->config[$key] ?? null) : $this->config;
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
        $filteredProcessedUrl = $filteredCacheKey = $processedUrl =
            $event->getProcessedUrl();

        if (! self::isComposer1() && $event->getType() === 'package') {
            // Fulfill version placeholder for packages
            // In Composer 1 this step is done upon package install & update
            $package = $event->getContext();
            $version = $package->getPrettyVersion();

            $filteredProcessedUrl = $filteredCacheKey =
                $this->fulfillVersionPlaceholder(
                    $filteredProcessedUrl,
                    $version
                );
        }

        // Fulfill env placeholders
        $filteredProcessedUrl = $this->fulfillPlaceholders($filteredProcessedUrl);

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
            $placeholders[] =
                strtolower($match) !== 'version'
                    ? $match
                    : 'version';
        }

        return array_unique($placeholders);
    }

    /**
     * Test if this plugin runs within Composer 2.
     */
    protected static function isComposer1(): bool
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '<');
    }
}
