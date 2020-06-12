<?php

namespace FFraenz\PrivateComposerInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var EnvResolverInterface
     */
    protected $envResolver;

    /**
     * Return the composer instance.
     * @return Composer
     */
    public function getComposer(): Composer
    {
        return $this->composer;
    }

    /**
     * Return the IO interface object.
     * @return IOInterface
     */
    public function getIO(): IOInterface
    {
        return $this->io;
    }

    /**
     * Lazily instantiate an env resolver instance.
     * @return EnvResolverInterface
     */
    public function getEnvResolver(): EnvResolverInterface
    {
        if ($this->envResolver === null) {
            $this->envResolver = new DotenvEnvResolver(getcwd(), '.env');
        }
        return $this->envResolver;
    }

    /**
     * Set the env resolver instance.
     * @param EnvResolverInterface $envResolver Env resolver instance
     * @return void
     */
    public function setEnvResolver(EnvResolverInterface $envResolver): void
    {
        $this->envResolver = $envResolver;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::PRE_PACKAGE_INSTALL => 'handlePrePackageInstall',
            PackageEvents::PRE_PACKAGE_UPDATE => 'handlePrePackageUpdate',
            PluginEvents::PRE_FILE_DOWNLOAD => ['handlePreFileDownload', -1],
        ];
    }

    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @inheritdoc
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $this->composer = null;
        $this->io = null;
    }

    /**
     * @inheritdoc
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        //
    }

    /**
     * Handle PRE_PACKAGE_INSTALL composer event.
     * @param PackageEvent $event
     * @return void
     */
    public function handlePrePackageInstall(PackageEvent $event): void
    {
        $package = $event->getOperation()->getPackage();
        $distUrl = $package->getDistUrl();
        $filteredDistUrl = $this->filterDistUrl($distUrl, $package);

        if ($filteredDistUrl !== $distUrl) {
            $package->setDistUrl($filteredDistUrl);
        }
    }

    /**
     * Handle PRE_PACKAGE_UPDATE composer event.
     * @param PackageEvent $event
     * @return void
     */
    public function handlePrePackageUpdate(PackageEvent $event): void
    {
        $package = $event->getOperation()->getTargetPackage();
        $distUrl = $package->getDistUrl();
        $filteredDistUrl = $this->filterDistUrl($distUrl, $package);

        if ($filteredDistUrl !== $distUrl) {
            $package->setDistUrl($filteredDistUrl);
        }
    }

    /**
     * Handle PRE_FILE_DOWNLOAD composer event
     * @param PreFileDownloadEvent $event
     * @return void
     */
    public function handlePreFileDownload(PreFileDownloadEvent $event): void
    {
        $processedUrl = $event->getProcessedUrl();
        $filteredProcessedUrl = $this->filterProcessedUrl($processedUrl);

        if ($filteredProcessedUrl !== $processedUrl) {
            if (PluginInterface::PLUGIN_API_VERSION === '1.0.0') {
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
                // Set processed URL
                $event->setProcessedUrl($filteredProcessedUrl);
            }
        }
    }

    /**
     * Filter the dist URL for a given package. Filtered dist URLs get stored
     * inside `composer.lock`.
     * @param string|null $url Dist URL
     * @param PackageInterface $package Package object
     * @return Filtered dist URL
     */
    public function filterDistUrl(?string $url, PackageInterface $package): ?string
    {
        // Check if package dist url contains any placeholders (incl. version)
        $placeholders = $this->readUrlPlaceholders($url);
        if (count($placeholders) > 0) {
            // Inject version into URL
            $version = $package->getPrettyVersion();
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
     * Filter the given processed URL before downloading. Filtered processed
     * URLs do not get stored inside `composer.lock`.
     * @param string|null $url Processed URL
     * @return Filtered processed URL
     */
    public function filterProcessedUrl(?string $url): ?string
    {
        $placeholders = $this->readUrlPlaceholders($url);
        if (count($placeholders) > 0) {
            // Replace each placeholder with env var
            foreach ($placeholders as $placeholder) {
                $value = $this->resolveEnvValue($placeholder);
                $url = str_replace('{%' . $placeholder . '}', $value, $url);
            }
        }
        return $url;
    }

    /**
     * Retrieve placeholders for the given url.
     * @param ?string $url
     * @return string[]
     */
    public function readUrlPlaceholders(?string $url): array
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
     * Resolve environment value by the given key.
     * @param string $key Env key
     * @throws MissingEnvException If the given key cannot be resolved.
     * @return Env value
     */
    public function resolveEnvValue(string $key): string
    {
        $value = $this->getEnvResolver()->get($key);
        if (empty($value) || ! is_string($value)) {
            throw new MissingEnvException($key);
        }
        return $value;
    }
}
