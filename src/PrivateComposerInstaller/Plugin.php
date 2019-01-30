<?php

namespace FFraenz\PrivateComposerInstaller;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Dotenv\Dotenv;
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
     * @var boolean
     */
    protected $envInitialized = false;

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
    public function injectVersion(PackageEvent $event)
    {
        $package = $this->getOperationPackage($event->getOperation());
        $url = $package->getDistUrl();

        // Check if package dist url contains any placeholders
        $placeholders = $this->getUrlPlaceholders($url);
        if (count($placeholders) > 0) {
            $version = $package->getPrettyVersion();

            // Check if a version placeholder is present
            if (array_search('version', $placeholders) !== false) {
                // Replace existing placeholder
                $url = str_replace('{%version}', $version, $url);
            } else {
                // Append version to the location hash to make the url change
                // when updating the version forcing a re-download
                $url .= '#v' . $version;
            }

            $package->setDistUrl($url);
        }
    }

    /**
     * Replaces placeholders with corresponding environment variables.
     * @param PreFileDownloadEvent $event
     * @return void
     */
    public function injectPlaceholders(PreFileDownloadEvent $event)
    {
        $url = $event->getProcessedUrl();

        // Check if package url contains any placeholders
        $placeholders = $this->getUrlPlaceholders($url);
        if (count($placeholders) > 0) {
            // Replace each placeholder with env var
            foreach ($placeholders as $placeholder) {
                $value = $this->getEnv($placeholder);
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
    protected function getOperationPackage(OperationInterface $operation)
    {
        if ($operation->getJobType() === 'update') {
            return $operation->getTargetPackage();
        }
        return $operation->getPackage();
    }

    /**
     * Retrieves environment variable for given key.
     * @param string $key
     * @return mixed
     */
    protected function getEnv($key)
    {
        // Retrieve env var
        $value = getenv($key);

        // Lazily initialize environment if env var is not set
        if (empty($value) && ! $this->envInitialized) {
            $this->envInitialized = true;

            // Load dot env file if it exists
            if (file_exists(getcwd() . DIRECTORY_SEPARATOR . '.env')) {
                $dotenv = Dotenv::create(getcwd());
                $dotenv->load();

                // Retrieve env var from dot env file
                $value = getenv($key);
            }
        }

        // Check if env var is set
        if (empty($value)) {
            throw new MissingEnvException($key);
        }

        return $value;
    }

    /**
     * Retrieves placeholders for given url.
     * @param string $url
     * @return string[]
     */
    protected function getUrlPlaceholders($url)
    {
        $matches = [];
        preg_match_all('/{%([A-Za-z0-9-_]+)}/', $url, $matches);

        $placeholders = [];
        foreach ($matches[1] as $match) {
            array_push($placeholders, $match);
        }
        return array_unique($placeholders);
    }
}
