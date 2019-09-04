<?php

namespace FFraenz\PrivateComposerInstaller;

use Composer\Config;
use Composer\IO\IOInterface;

/**
 * A composer remote filesystem making it possible to
 * copy files from a private file url.
 */
class RemoteFilesystem extends \Composer\Util\RemoteFilesystem
{
    /**
     * The private file url that should be used
     * instead of the given file url in copy.
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
     * Returns the private file URL.
     * @return string
     */
    public function getPrivateFileUrl(): string
    {
        return $this->privateFileUrl;
    }
}
