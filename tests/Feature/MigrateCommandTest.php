<?php

namespace Alley\WpToPsr4\Tests\Feature;

use Alley\WpToPsr4\MigrateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateCommandTest extends TestCase
{
    public function test_it_can_migrate_fixtures()
    {
        $fixtures = __DIR__.'/../fixtures';

        // Copy the fixtures/before directory to a temporary directory.
        $tempDir = $fixtures.'/runtime';

        if (is_dir($tempDir)) {
            shell_exec("rm -rf $tempDir");
        }

        shell_exec("cp -r $fixtures/before $tempDir");

        $tester = new CommandTester(new MigrateCommand);

        $tester->execute([
            'path' => $tempDir,
            '--no-git' => true,
        ]);

        $tester->assertCommandIsSuccessful();

        $this->assertEquals($this->getMd5Sum("$fixtures/after"), $this->getMd5Sum($tempDir));
    }

    protected function getMd5Sum(string $dir): string
    {
        $previous = getcwd();

        chdir($dir);

        $md5 = trim(shell_exec('find . -type f -exec md5sum {} + | sort -k 2 | md5sum'));

        chdir($previous);

        return $md5;
    }
}
