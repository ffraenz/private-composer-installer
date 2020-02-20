<?php

namespace FFraenz\PrivateComposerInstaller;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;

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
     * @var Env
     */
    protected $env;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->env = new Env(getcwd(), '.env');
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::PRE_PACKAGE_INSTALL => 'injectVersion',
            PackageEvents::PRE_PACKAGE_UPDATE => 'injectVersion',
            PluginEvents::PRE_FILE_DOWNLOAD => ['injectPlaceholders', -1],
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
     * Injects package version into dist url if it contains placeholders.
     * @param PackageEvent $event
     * @return void
     */
    public function injectVersion(PackageEvent $event): void
    {
        $package = $this->getOperationPackage($event->getOperation());
        $url = $package->getDistUrl();

        // Check if package dist url contains any placeholders
        $placeholders = $this->getUrlPlaceholders($url);
        if (count($placeholders) > 0) {
            $version = $package->getPrettyVersion();

            if (array_search('version', $placeholders) !== false) {
                // If there is a version placeholder in the URL, fulfill it
                $package->setDistUrl(preg_replace('/{%version}/i', $version, $url));
            } elseif (strpos($url, $version) === false) {
                // If the exact version is not already part of the URL, append
                // it as a hash to the end of the URL to force a re-download
                // when updating the version
                $package->setDistUrl($url . '#v' . $version);
            }
        }
    }

    /**
     * Replaces placeholders with corresponding environment variables.
     * @param PreFileDownloadEvent $event
     * @return void
     */
    public function injectPlaceholders(PreFileDownloadEvent $event): void
    {
        $url = $event->getProcessedUrl();

        // Check if package url contains any placeholders
        $placeholders = $this->getUrlPlaceholders($url);
        if (count($placeholders) > 0) {
            // Replace each placeholder with env var
            foreach ($placeholders as $placeholder) {
                $value = $this->env->get($placeholder);
                $url = str_replace('{%' . $placeholder . '}', $value, $url);
            }

            // Download file from different location
            $originalRemoteFilesystem = $event->getRemoteFilesystem();
            $event->setRemoteFilesystem(new RemoteFilesystem(
                $url,
                $this->io,
                $this->composer->getConfig(),
                $originalRemoteFilesystem->getOptions(),
                $originalRemoteFilesystem->isTlsDisabled()
            ));
        }
    }

    /**
     * Returns package for given operation.
     * @param OperationInterface $operation
     * @return PackageInterface
     */
    protected function getOperationPackage(
        OperationInterface $operation
    ): PackageInterface {
        if ($operation->getJobType() === 'update') {
            return $operation->getTargetPackage();
        }
        return $operation->getPackage();
    }

    /**
     * Retrieves placeholders for given url.
     * @param ?string $url
     * @return string[]
     */
    protected function getUrlPlaceholders(?string $url): array
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
     * Returns the composer instance.
     * @return Composer
     */
    public function getComposer(): Composer
    {
        return $this->composer;
    }

    /**
     * Returns the IO interface object.
     * @return IOInterface
     */
    public function getIO(): IOInterface
    {
        return $this->io;
    }
}
