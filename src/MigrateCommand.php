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
            ->addArgument('path', InputOption::VALUE_OPTIONAL, 'Path to the WordPress codebase to migrate.', [getcwd()])
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
     * @param  InputInterface  $input Input interface.
     * @param  OutputInterface  $output Output interface.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = realpath($input->getArgument('path')[0]);

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

            if ($dryRun) {
                $output->writeln("<info>Would move {$oldPath} to {$newPath}.</info>");

                continue;
            } else {
                $output->writeln("<comment>Moving {$oldPath} to {$newPath}...</comment>");

                if ($useGit) {
                    exec("git mv {$oldPath} {$newPath}");
                } else {
                    rename($oldPath, $newPath);
                }
            }

            $output->writeln("<comment>Updating class name in {$newPath}...</comment>");

            $contents = str(file_get_contents($newPath));

            if ($dryRun) {
                $output->writeln("<info>Would replace {$oldClassName} with {$newClassName} in {$newPath}.</info>");

                continue;
            } else {
                file_put_contents($newPath, $contents->replace("{$type} {$oldClassName} ", "{$type} {$newClassName} ")->value());
            }
        }

        if ($dryRun) {
            $output->writeln('<info>Dry-run complete, no files were moved.</info>');
        } else {
            $output->writeln('<info>File migration complete. All files and classes were renamed. Ensure you have added "psr-4" to the "autoload" section of "composer.json" and run "composer dump-autoload".</info>');
        }

        $output->writeln('');
        $output->writeln('<info>Starting directory migration...</info>');

        $directories = $this->collectDirectories($output, $baseDir, $files);

        $output->writeln('<info>Found '.count($directories).' directories to migrate.</info>');

        foreach ($directories as $item) {
            [$oldPath, $newPath] = $item;

            if ($dryRun) {
                $output->writeln("<info>Would move {$oldPath} to {$newPath}.</info>");
            } elseif (! is_dir($oldPath)) {
                $output->writeln("<error>Old directory {$oldPath} does not exist, ignoring...</error>");
            } else {
                $output->writeln("<comment>Moving {$oldPath} to {$newPath}...</comment>");

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

        return Command::SUCCESS;
    }

    /**
     * Collect PHP files from the given path.
     */
    protected function collectPhpFiles(OutputInterface $output, string $path, array $exclude): array
    {
        $index = [];

        $finder = (new Finder())
            ->files()
            ->in($path)
            ->name('*.php')
            ->notPath($exclude);

        foreach ($finder as $file) {
            // Check if the file name has any uppercase letters.
            if (preg_match('/[A-Z]/', $file->getFilename())) {
                $output->writeln("<error>File {$file->getRelativePathname()} does not seem like a valid WordPress file, ignoring...</error>");

                continue;
            }

            $type = str($file->getFilename())->before('-')->value();

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

            $oldClassName = str($file->getFilename())
                ->after("{$type}-")
                ->before('.php')
                ->studlyUnderscore()
                ->when(
                    $type === 'test',
                    fn (Stringable $str) => $str->append('Test_')
                )
                ->replace('Wordpress', 'WordPress');

            // Check if the class name is found in the file.
            $contents = str($file->getContents());

            if (! $contents->contains("{$type} {$oldClassName} ", true)) {
                $output->writeln("<error>Cannot determine the proper class name for {$file->getRelativePathname()}, ignoring...</error>");

                continue;
            }

            $index[] = [
                $type === 'test' ? 'class' : $type,
                $file->getRealPath(),
                $file->getPath().DIRECTORY_SEPARATOR.$newClassName->value().'.php',
                $oldClassName->value(),
                $newClassName->value(),
            ];
        }

        return $index;
    }

    /**
     * Collect an index of all directories to move. Then sort it by the deepest
     * nested folders first.
     */
    protected function collectDirectories(OutputInterface $output, string $basePath, array $fileIndex): array
    {
        $dirs = new Collection();

        foreach ($fileIndex as $file) {
            [,, $newPath] = $file;

            $dirs->push(dirname($newPath));
        }

        $dirs = $dirs
            ->unique()
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
