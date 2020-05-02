<?php

namespace ricwein\Indexer\Commands\Cache;

use Intervention\Image\Exception\NotReadableException as INotReadableException;
use Phpfastcache\Exceptions\PhpfastcacheDriverCheckException;
use Phpfastcache\Exceptions\PhpfastcacheDriverException;
use Phpfastcache\Exceptions\PhpfastcacheDriverNotFoundException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidConfigurationException;
use ReflectionException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception as FileSystemException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\Exceptions\UnsupportedException;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Storage;
use ricwein\Indexer\Core\Cache;
use ricwein\Indexer\Indexer\FileInfo\FileInfo;
use ricwein\Indexer\Indexer\Index;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ricwein\Indexer\Config\Config;
use Throwable;

class Warmup extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Directory Index Cache WarmUp.')
            ->setHelp('This command indexes the whole Directory recursively. This might take a while.')
            ->addOption('force-index', null, InputOption::VALUE_NONE, 'Force-index Directory by flushing the cache first.')
            ->addOption('CI', null, InputOption::VALUE_NONE, 'Optimizes Outputs for CI logs.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws AccessDeniedException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheDriverException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheInvalidConfigurationException
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::getInstance();

        if (!$config->cache['enabled']) {
            throw new RuntimeException('Unable to warm-up the cache, if the cache is disabled in the config!', 400);
        }

        $cache = new Cache($config->cache['engine'], $config->cache);
        $formatter = $this->getHelper('formatter');

        if (null !== $path = $config->path) {

            if (strpos($path, '/') === 0) {
                $storage = new Storage\Disk($path);
            } else {
                $storage = new Storage\Disk(__DIR__ . '/../../../../' . $path);
            }

        } else {
            $storage = new Storage\Disk(__DIR__ . '/../../../../');
        }

        $rootDir = new Directory($storage, Constraint::STRICT & ~Constraint::DISALLOW_LINK);
        if (!$rootDir->isDir() || !$rootDir->isReadable()) {
            throw new RuntimeException("Unable to read root directory: {$rootDir->path()->raw}", 500);
        }

        $timeStart = time();
        $lastTime = $timeStart;
        $outputIsCI = (bool)$input->getOption('CI');

        // STEP 1: clean cache
        if ($input->getOption('force-index')) {
            if (!$outputIsCI) {
                $output->writeln($formatter->formatSection('Setup', 'cleanup cache...'));
            } else {
                $output->write($formatter->formatSection('Setup', 'cleanup cache...'));
            }
            $cache->clear();
            if (!$outputIsCI) {
                $output->writeln(sprintf(' - <info>done</info> (%ds)', time() - $lastTime));
            } else {
                $output->writeln(sprintf('<info>done</info> (%ds)', time() - $lastTime));
            }
        }
        $lastTime = time();

        // STEP 2: index directories and files
        $progress = new ProgressIndicator($output);
        $message = $formatter->formatSection('Indexing', 'traverse directory...');
        if (!$outputIsCI) {
            $output->writeln($message);
            $progress->start('indexing: /');
        } else {
            $output->write($message);
        }

        $index = new Index($rootDir, $config, $cache);

        $files = $index->list(static function (?SplFileInfo $file = null) use ($progress, $rootDir, $outputIsCI) {
            if ($outputIsCI) {
                return;
            }

            if ($file !== null && $file->isDir()) {
                $path = ltrim(str_replace($rootDir->path()->real, '', $file->getRealPath()), '/');
                $progress->setMessage($path);
            }

            $progress->advance();
        });

        if (!$outputIsCI) {
            $progress->finish(sprintf('<info>done</info> (%ds)', time() - $lastTime));
        } else {
            $output->writeln(sprintf('<info>done</info> (%ds)', time() - $lastTime));
        }
        $lastTime = time();

        // STEP 3: create file-infos for each file
        $message = $formatter->formatSection('Indexing', 'calculating file-hashes and thumbnails...');
        if (!$outputIsCI) {
            $output->writeln($message);
            $progress->start('hashing: /');
        } else {
            $output->write($message);
        }

        if ($config->indexRoot) {
            array_unshift($files, '/');
        }

        foreach ($files as $file) {
            $storage = new Storage\Disk($rootDir, $file);

            if (strpos($storage->path()->real, $rootDir->path()->real) !== 0) {
                if ($output->isVerbose()) {
                    $output->writeln("\n  ↳ <fg=yellow>[WARNING]</> skipping {$storage->path()->filename} - not withing safepath.");
                }
                continue;
            }

            if (!$outputIsCI && $storage->isDir()) {
                $path = ltrim(str_replace($rootDir->path()->real, '', $storage->path()->real), '/');
                $progress->setMessage($path);
            }

            try {

                $fileInfo = new FileInfo($storage, $cache, $config, $rootDir, $rootDir->storage()->getConstraints());

                // fetch and calculate file metadata
                $fileInfo->meta()->updateAllAttributes();

                // try to create thumbnail if possible
                $fileInfo->getThumbnail();

            } catch (ConstraintsException|FileSystemException|INotReadableException $e) {
                if ($output->isVerbose()) {
                    $path = $outputIsCI ? ltrim(str_replace($rootDir->path()->real, '', $storage->path()->real), '/') : $storage->path()->filename;
                    $output->writeln("\n  ↳ <fg=yellow>[WARNING]</> skipping {$path} - {$e->getMessage()}.\n");
                }
            } catch (Throwable $e) {
                if ($outputIsCI) {
                    $path = ltrim(str_replace($rootDir->path()->real, '', $storage->path()->real), '/');
                    $output->writeln("\n path: {$path}");
                }
                throw $e;
            }

            if (!$outputIsCI) {
                $progress->advance();
            }
        }
        if (!$outputIsCI) {
            $progress->finish(sprintf('<info>done</info> (%ds)', time() - $lastTime));
        } else {
            $output->writeln(sprintf('<info>done</info> (%ds)', time() - $lastTime));
        }

        $output->writeln($formatter->formatSection('Indexing', sprintf(
            'Finished indexing %s files in %ds.',
            number_format(count($files), 0, ',', '.'),
            time() - $timeStart
        )));

        return 0;
    }

}
