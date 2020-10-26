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
    /** @var string|null */
    protected $pwd;

    protected function setUp(): void
    {
        // Choose tmp working directory to run test case in
        $this->pwd = sys_get_temp_dir() . '/private-composer-installer';

        // Create working directory
        @mkdir($this->pwd);
    }

    protected function tearDown(): void
    {
        // Remove working directory
        $process = new Process(['rm', '-r', $this->pwd]);
        $process->mustRun();
        $this->pwd = null;
    }

    public function testWordPressComposerIntegration()
    {
        $path           = __DIR__ . '/../stubs/wordpress';
        $pluginFilePath = $this->pwd
            . '/public/content/plugins/classic-editor/classic-editor.php';

        // Install project
        copy($path . '/composer-install.json', $this->pwd . '/composer.json');
        copy($path . '/.env', $this->pwd . '/.env');
        $install = new Process(
            [
                __DIR__ . '/../../vendor/composer/composer/bin/composer',
                'install',
                '--no-interaction',
            ],
            $this->pwd
        );
        $install->setTimeout(60);
        $install->mustRun();
        $this->assertTrue($install->isSuccessful());

        // Verify plugin file
        $pluginFile = @file_get_contents($pluginFilePath);
        $this->assertTrue($pluginFile !== false);
        $this->assertTrue(preg_match('/Version:\s+1\.5/', $pluginFile) === 1);

        // Update phpdotenv and dependency
        copy($path . '/composer-update.json', $this->pwd . '/composer.json');
        $update = new Process(
            [
                __DIR__ . '/../../vendor/composer/composer/bin/composer',
                'update',
                '--no-interaction',
            ],
            $this->pwd
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
