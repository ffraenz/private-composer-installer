<?php

namespace FFraenz\PrivateComposerInstaller;

use Composer\Config;
use Composer\IO\IOInterface;

/**
 * Composer 1 remote filesystem allowing the plugin to copy files from a private
 * file URL.
 */
class RemoteFilesystem extends \Composer\Util\RemoteFilesystem
{
    /**
     * Private file URL that replaces the given file URL in copy
     * @var string
     */
    protected $privateFileUrl;

    /**
     * @inheritdoc
     */
    public function __construct(
        $privateFileUrl,
        IOInterface $io,
        Config $config = null,
        array $options = [],
        $disableTls = false
    ) {
        $this->privateFileUrl = $privateFileUrl;
        parent::__construct($io, $config, $options, $disableTls);
    }

    /**
     * @inheritdoc
     */
    public function copy(
        $originUrl,
        $fileUrl,
        $fileName,
        $progress = true,
        $options = []
    ) {
        // Use privateFileUrl instead of the provided fileUrl
        return parent::copy(
            $originUrl,
            $this->privateFileUrl,
            $fileName,
            $progress,
            $options
        );
    }

    /**
     * Return the private file URL
     * @return string
     */
    public function getPrivateFileUrl(): string
    {
        return $this->privateFileUrl;
    }
}
