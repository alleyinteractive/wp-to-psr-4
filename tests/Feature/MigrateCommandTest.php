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

        // Compare the md5sum of the directories
        $expectedMd5 = trim(shell_exec("find $fixtures/after -type f -exec md5sum {} + | sort -k 2 | md5sum"));
        $actualMd5 = trim(shell_exec("find $tempDir -type f -exec md5sum {} + | sort -k 2 | md5sum"));

        // Assert that the md5sums are equal
        $this->assertEquals($expectedMd5, $actualMd5);
    }
}
