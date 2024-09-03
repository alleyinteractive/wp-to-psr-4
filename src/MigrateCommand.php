<?php

namespace Alley\WpToPsr4;

use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class MigrateCommand extends Command
{
    /**
     * Configure the install command.
     */
    protected function configure()
    {
        $this->setName('migrate-files')
            ->setDescription('Migrate WordPress codebase files to PSR-4.')
            ->addArgument('path', InputOption::VALUE_OPTIONAL, 'Path to the WordPress codebase to migrate.')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Run the migration without making any changes.')
            ->addOption('no-git', null, InputOption::VALUE_NONE, 'Do not move files with Git.')
            ->addOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Exclude a directory or file from the migration.');

        Stringable::macro('studlyUnderscore', function () {
            $this->value = ucwords(str_replace(['-', '_'], ' ', $this->value));

            $this->value = str_replace(' ', '_', $this->value);

            return $this;
        });
    }

    /**
     * Execute the command.
     *
     * @param  InputInterface  $input  Input interface.
     * @param  OutputInterface  $output  Output interface.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = $input->getArgument('path');

        if (is_array($baseDir)) {
            $baseDir = $baseDir[0] ?? null;
        }

        // If no path is provided, default to the current working directory.
        if (empty($baseDir)) {
            $baseDir = getcwd();
        }

        $baseDir = realpath($baseDir);
        $useGit = ! $input->getOption('no-git');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<info>Running in dry-run mode, no files will be moved.</info>');
        }

        if (! $baseDir || ! is_dir($baseDir)) {
            $output->writeln('<error>Invalid path provided.</error>');

            return Command::FAILURE;
        }

        // Ensure the base path does not end with a trailing slash.
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);

        $output->writeln("<info>Migrating WordPress codebase at {$baseDir}...</info>");

        $files = $this->collectPhpFiles($output, $baseDir, $input->getOption('exclude'));

        $output->writeln('<info>Found '.count($files).' files to migrate.</info>');

        foreach ($files as $file) {
            [$type, $oldPath, $newPath, $oldClassName, $newClassName] = $file;

            $oldPathWithoutBase = str($oldPath)->after($baseDir.DIRECTORY_SEPARATOR);
            $newPathWithoutBase = str($newPath)->after($baseDir.DIRECTORY_SEPARATOR);

            if ($dryRun) {
                $output->writeln("Would move <comment>{$oldPathWithoutBase}</comment> to <comment>{$newPathWithoutBase}</comment>.");
            } else {
                $output->writeln("<info>Moving <comment>{$oldPathWithoutBase}</comment> to <comment>{$newPathWithoutBase}</comment></info>");

                if ($useGit) {
                    exec("git mv {$oldPath} {$newPath}");
                } else {
                    rename($oldPath, $newPath);
                }
            }

            $newPathWithoutBase = str($newPath)->after($baseDir.DIRECTORY_SEPARATOR);

            if ($dryRun) {
                $output->writeln("<info>Would replace <comment>{$oldClassName}</comment> with <comment>{$newClassName}</comment> in <comment>{$newPathWithoutBase}</comment>.</info>");
            } else {
                $output->writeln("<info>Updating class name in <comment>{$newPathWithoutBase}</comment></info>");

                $contents = str(file_get_contents($newPath));

                file_put_contents(
                    $newPath,
                    $contents->replaceMatches(
                        "/{$type} {$oldClassName}\b/",
                        fn ($match) => "{$type} {$newClassName}",
                    )->value(),
                );
            }
        }

        if ($dryRun) {
            $output->writeln('<info>Dry-run complete, no files were moved.</info>');
        } else {
            $output->writeln('<info>File migration complete.</info>');
        }

        $output->writeln('');
        $output->writeln('<info>Starting directory migration</info>');

        $directories = $this->collectDirectories($output, $baseDir, $files);

        $output->writeln('<info>Found '.count($directories).' directories to migrate.</info>');

        foreach ($directories as $item) {
            [$oldPath, $newPath] = $item;

            $oldPathWithoutBase = str($oldPath)->after($baseDir.DIRECTORY_SEPARATOR);
            $newPathWithoutBase = str($newPath)->after($baseDir.DIRECTORY_SEPARATOR);

            if ($dryRun) {
                $output->writeln("Would move <comment>{$oldPathWithoutBase}</comment> to <comment>{$newPathWithoutBase}</comment>.");
            } elseif (! is_dir($oldPath)) {
                $output->writeln("<error>Old directory <comment>{$oldPath}</comment> does not exist, ignoring.</error>");
            } else {
                $output->writeln("<info>Moving <comment>{$oldPathWithoutBase}</comment> to <comment>{$newPathWithoutBase}</comment>.</info>");

                if ($useGit) {
                    exec("git mv {$oldPath} {$oldPath}-bak");
                    exec("git mv {$oldPath}-bak {$newPath}");
                } else {
                    rename($oldPath, $newPath);
                }
            }
        }

        if ($dryRun) {
            $output->writeln('<info>Dry-run complete, no directories were moved.</info>');
        } else {
            $output->writeln('<info>Directory migration complete.</info>');
        }

        $output->writeln('');
        $output->writeln('<info>All matched files/directories/classes were renamed to match PSR-4 autoloading standards. Ensure you have added "psr-4" to the "autoload" section of "composer.json" and run "composer dump-autoload".</info>');
        $output->writeln('');
        $output->writeln('<info>Example JSON for your "composer.json" file:</info>');
        $output->writeln(
            <<<'EOF'
<bg=yellow;options=bold>
{
    "name": "vendor/your-plugin",
    "autoload-dev": {
        "psr-4": {
            "Alley\WP\Create_WordPress_Plugin\Tests\": "tests"
        }
    }
}
</>
EOF
        );

        return Command::SUCCESS;
    }

    /**
     * Collect PHP files from the given path.
     */
    protected function collectPhpFiles(OutputInterface $output, string $path, array $exclude): array
    {
        $index = [];

        $finder = (new Finder)
            ->files()
            ->in($path)
            ->name('*.php')
            ->notPath($exclude)
            ->notName('bootstrap.php');

        foreach ($finder as $file) {
            // Check if the file name has any uppercase letters.
            if (preg_match('/[A-Z]/', $file->getFilename())) {
                $output->writeln("<error>File {$file->getRelativePathname()} does not seem like a valid WordPress file, ignoring...</error>");

                continue;
            }

            $type = str($file->getFilename())->before('-')->value();

            // Map the type name to the value that would appear in the PHP file
            // when declaring the object type.
            $typeDeclaration = match ($type) {
                'test' => 'class',
                default => $type,
            };

            if (! in_array($type, ['class', 'trait', 'interface', 'enum', 'test'], true)) {
                $output->writeln("<error>File {$file->getRelativePathname()} does not seem like a valid WordPress file (unknown type), ignoring...</error>");

                continue;
            }

            $newClassName = str($file->getFilename())
                ->after("{$type}-")
                ->before('.php')
                ->studly()
                ->when(
                    $type === 'test',
                    fn (Stringable $str) => $str->append('Test')
                )
                ->replace('Wordpress', 'WordPress');

            $oldClassNameStr = str($file->getFilename())
                ->after("{$type}-")
                ->before('.php')
                ->studlyUnderscore()
                ->replace('Wordpress', 'WordPress');

            $oldClassNames = [$oldClassNameStr];

            if ($type === 'test') {
                $oldClassNames = [
                    $oldClassNameStr->prepend('Test_'),
                    $oldClassNameStr->append('_Test'),
                ];
            }

            // Check if the class name is found in the file.
            $contents = str($file->getContents());

            // Check if the class name is found in the file.
            foreach ($oldClassNames as $oldClassName) {
                if (! $contents->match("/{$typeDeclaration} {$oldClassName}\b/")) {
                    continue;
                }

                $index[] = [
                    $typeDeclaration,
                    $file->getRealPath(),
                    $file->getPath().DIRECTORY_SEPARATOR.$newClassName->value().'.php',
                    $oldClassName->value(),
                    $newClassName->value(),
                ];

                // Break out of the loop if we found the class name for the file and continue to the next file.
                continue 2;
            }

            $output->writeln("<error>Cannot determine the proper class name for {$file->getRelativePathname()} (type {$type}), ignoring file...</error>");
        }

        return $index;
    }

    /**
     * Collect an index of all directories to move. Then sort it by the deepest
     * nested folders first.
     */
    protected function collectDirectories(OutputInterface $output, string $basePath, array $fileIndex): array
    {
        $dirs = new Collection;

        foreach ($fileIndex as $file) {
            [,, $newPath] = $file;

            if ($dirs->contains($newPath)) {
                continue;
            }

            $dirs->push(dirname($newPath));

            // Add the parent folders of the file as well until we reach the base path.
            while (true) {
                $newPath = dirname($newPath);

                if ($newPath === $basePath) {
                    break;
                }

                $dirs->push($newPath);
            }
        }

        $dirs = $dirs
            ->unique()
            ->values()
            // Sort by the deepest nested folders first.
            ->sort(fn ($a, $b) => substr_count($b, DIRECTORY_SEPARATOR) <=> substr_count($a, DIRECTORY_SEPARATOR));

        // Now convert the paths to a CamelCase/Structure after the base path.
        return $dirs->map(function ($dir) use ($basePath) {
            // Ignore the base path.
            if ($basePath === $dir) {
                return null;
            }

            $dir = str($dir);

            if (! $dir->startsWith($basePath)) {
                throw new \RuntimeException("Directory {$dir} does not start with the base path {$basePath}.");
            }

            $parts = $dir->after($basePath.DIRECTORY_SEPARATOR)->explode(DIRECTORY_SEPARATOR);

            // Only rename the deepest nested folder.
            $folder = str($parts->pop())
                ->studly()
                ->replace('Wordpress', 'WordPress')
                ->value();

            $parts->push($folder);

            // Otherwise, return the path to the folder.
            return [
                $dir,
                (string) str($parts->implode(DIRECTORY_SEPARATOR))->prepend($basePath.DIRECTORY_SEPARATOR),
            ];
        })
            ->unique()
            ->filter()
            ->toArray();
    }
}
