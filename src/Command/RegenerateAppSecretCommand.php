<?php

namespace App\Command;

use Exception;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:regenerate-secret'
)]
class RegenerateAppSecretCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            name: 'env-file',
            mode: InputArgument::REQUIRED,
            description: 'env File {.env, .env.local}'
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $envFileName = $input->getArgument('env-file');
        $file = new File(new Storage\Disk(__DIR__ . '/../../', $envFileName));

        if (!$file->isFile() || !$file->isWriteable()) {
            $io->error("Invalid env-file provided. File not found.");
            return Command::INVALID;
        }

        $io->note("Updated file: {$file->getPath()->getRelativePathToSafePath()}");

        $secret = bin2hex(random_bytes(16));
        $lines = $file->readAsLines();
        $wasReplaced = false;
        foreach ($lines as $lineNr => $line) {
            $lines[$lineNr] = preg_replace('/APP_SECRET=[a-f0-9]{32}/', "APP_SECRET=$secret", $line, -1, $count);
            if ($count > 0) {
                $wasReplaced = true;
            }
        }

        if (!$wasReplaced) {
            $lines[] = "APP_SECRET=$secret" . PHP_EOL;
        }

        $file->write(implode('', $lines));

        $io->success("New APP_SECRET was generated: $secret");

        return Command::SUCCESS;
    }
}
