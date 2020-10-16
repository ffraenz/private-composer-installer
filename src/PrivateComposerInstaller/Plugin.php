<?php

namespace FFraenz\PrivateComposerInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use FFraenz\PrivateComposerInstaller\Exception\MissingEnvException;
use FFraenz\PrivateComposerInstaller\Resolver\Dotenv4Resolver;
use FFraenz\PrivateComposerInstaller\Resolver\ResolverInterface;

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
     * @var ResolverInterface
     */
    protected $resolver;

    /**
     * Return the composer instance.
     * @return Composer|null
     */
    public function getComposer(): Composer
    {
        return $this->composer;
    }

    /**
     * Return the IO interface object.
     * @return IOInterface|null
     */
    public function getIO(): IOInterface
    {
        return $this->io;
    }

    /**
     * Lazily instantiate the root resolver instance.
     * @return ResolverInterface
     */
    public function getResolver(): ResolverInterface
    {
        if ($this->resolver === null) {
            $this->resolver = new Dotenv4Resolver();
        }
        return $this->resolver;
    }

    /**
     * Set the resolver instance.
     * @param ResolverInterface $resolver Resolver instance
     * @return void
     */
    public function setResolver(ResolverInterface $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return self::isComposer1() ? [
            PackageEvents::PRE_PACKAGE_INSTALL => 'handlePreInstallUpdateEvent',
            PackageEvents::PRE_PACKAGE_UPDATE => 'handlePreInstallUpdateEvent',
            PluginEvents::PRE_FILE_DOWNLOAD => ['handlePreDownloadEvent', -1],
        ] : [
            PluginEvents::PRE_FILE_DOWNLOAD => ['handlePreDownloadEvent', -1],
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
     * Handle PRE_PACKAGE_INSTALL and PRE_PACKAGE_UPDATE Composer events.
     * Only gets triggered running Composer 1.
     * @param PackageEvent $event Composer install or update event
     * @return void
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
     * @param PreFileDownloadEvent $event Composer event
     * @return void
     */
    public function handlePreDownloadEvent(PreFileDownloadEvent $event): void
    {
        $filteredProcessedUrl = $processedUrl = $event->getProcessedUrl();

        if (! self::isComposer1()) {
            // Fulfill version placeholder
            // In Composer 1 this step is done upon package install & update
            $package = $event->getContext();
            $version = $package->getPrettyVersion();
            $filteredProcessedUrl = $this->fulfillVersionPlaceholder(
                $filteredProcessedUrl,
                $version
            );
        }

        // Fulfill env placeholders
        $filteredProcessedUrl = $this->fulfillPlaceholders($processedUrl);

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
                // Set processed URL
                $event->setProcessedUrl($filteredProcessedUrl);
            }
        }
    }

    /**
     * Filter the dist URL for a given package. Filtered dist URLs get stored
     * inside `composer.lock`.
     * @param string|null $url Dist URL
     * @param string $version Package version
     * @return Filtered dist URL
     */
    public function fulfillVersionPlaceholder($url, $version)
    {
        // Check if package dist url contains any placeholders (incl. version)
        $placeholders = $this->identifyPlaceholders($url);
        if (count($placeholders) > 0) {
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
     * Filter the given processed URL before downloading. Filtered processed
     * URLs do not get stored inside `composer.lock`.
     * @param string|null $url Processed URL
     * @return Filtered processed URL
     */
    public function fulfillPlaceholders($url): array
    {
        $placeholders = $this->identifyPlaceholders($url);
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
    public function identifyPlaceholders($url)
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
     * @param string $key Environment key
     * @throws MissingEnvException If the given key cannot be resolved.
     * @return Environment value
     */
    public function resolveEnvValue($key)
    {
        $value = $this->getResolver()->get($key);
        if (empty($value) || ! is_string($value)) {
            throw new MissingEnvException($key);
        }
        return $value;
    }

    /**
     * Test if this plugin runs within Composer 2.
     * @return boolean True, if Composer 2 or later is in use
     */
    protected static function isComposer1(): bool
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '<');
    }
}
