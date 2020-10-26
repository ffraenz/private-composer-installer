<?php

namespace FFraenz\PrivateComposerInstaller\Test;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function copy;
use function file_get_contents;
use function mkdir;
use function preg_match;

class IntegrationTest extends TestCase
{
    protected function getPWD(): string
    {
        return __DIR__ . '/../stubs/tmp';
    }

    protected function setUp(): void
    {
        // Create working directory
        @mkdir($this->getPWD());
    }

    protected function tearDown(): void
    {
        // Remove working directory
        $process = new Process(['rm', '-r', $this->getPWD()]);
        $process->mustRun();
    }

    public function testWordPressComposerIntegration()
    {
        $path           = __DIR__ . '/../stubs/wordpress';
        $pluginFilePath = $this->getPWD()
            . '/public/content/plugins/classic-editor/classic-editor.php';

        // Install project
        copy($path . '/composer-install.json', $this->getPWD() . '/composer.json');
        copy($path . '/.env', $this->getPWD() . '/.env');
        $install = new Process(
            [
                __DIR__ . '/../../vendor/composer/composer/bin/composer',
                'install',
                '--no-interaction',
            ],
            $this->getPWD()
        );
        $install->setTimeout(60);
        $install->mustRun();
        $this->assertTrue($install->isSuccessful());

        // Verify plugin file
        $pluginFile = @file_get_contents($pluginFilePath);
        $this->assertTrue($pluginFile !== false);
        $this->assertTrue(preg_match('/Version:\s+1\.5/', $pluginFile) === 1);

        // Update phpdotenv and dependency
        copy($path . '/composer-update.json', $this->getPWD() . '/composer.json');
        $update = new Process(
            [
                __DIR__ . '/../../vendor/composer/composer/bin/composer',
                'update',
                '--no-interaction',
            ],
            $this->getPWD()
        );
        $update->setTimeout(60);
        $update->mustRun();
        $this->assertTrue($update->isSuccessful());

        // Verify plugin file
        $this->assertTrue($install->isSuccessful());
        $pluginFile = @file_get_contents($pluginFilePath);
        $this->assertTrue($pluginFile !== false);
        $this->assertTrue(preg_match('/Version:\s+1\.6/', $pluginFile) === 1);
    }
}
