<?php

namespace Fusonic\SuluSyncBundle\Command;

use DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ImportCommand extends Command
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var ProgressBar
     */
    private $progressBar;

    private $lastFileName;
    private $importDirectory;
    private $databaseHost;
    private $databaseUser;
    private $databaseName;
    private $databasePassword;
    private $kernelRootDir;

    public function __construct(
        $databaseHost,
        $databaseName,
        $databaseUser,
        $databasePassword,
        $kernelRootDir
    ) {
        parent::__construct();

        $this->databaseHost = $databaseHost;
        $this->databaseUser = $databaseUser;
        $this->databaseName = $databaseName;
        $this->databasePassword = $databasePassword;
        $this->kernelRootDir = $kernelRootDir;
    }

    protected function configure()
    {
        $this
            ->setName("sulu:import")
            ->setDescription("Imports contents exported with the sulu:export command from the remote host.")
            ->addArgument(
                "dir",
                InputArgument::REQUIRED,
                "Dir to look for files ti import"
            )
            ->addOption(
                "skip-assets",
                null,
                null,
                "Skip the download of assets."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $skipAssets = $this->input->getOption("skip-assets");

        $this->progressBar = new ProgressBar($this->output, $skipAssets ? 4 : 6);
        $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% <info>%message%</info>');

        $this->importDirectory = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $input->getArgument("dir") . DIRECTORY_SEPARATOR;

        $this->lastFileName = null;
        $filesArray = [];

        foreach (glob($this->importDirectory . '*.sql') as $file) {
            $file = str_replace($this->importDirectory, "", $file);
            $file = str_replace('.sql', "", $file);

            if (!$this->lastFileName) {
                $this->lastFileName = $file;
            } else {
                $a = DateTime::createFromFormat('Y-m-d-H-i-s', $file);
                $b = DateTime::createFromFormat('Y-m-d-H-i-s', $this->lastFileName);

                if ($a > $b) {
                    $this->lastFileName = $file;
                }
            }
        }

        $this->importPHPCR();
        $this->importDatabase();

        if (!$skipAssets) {
            $this->importUploads();
        }

        $this->progressBar->finish();

        $this->output->writeln(
            PHP_EOL . "<info>Successfully imported contents. Export version: $this->lastFileName</info>"
        );
    }

    private function importPHPCR()
    {
        $this->progressBar->setMessage("Importing PHPCR repository...");
        $this->executeCommand(
            "doctrine:phpcr:workspace:purge",
            [
                "--force" => true,
            ],
            new NullOutput()
        );
        $this->executeCommand(
            "doctrine:phpcr:workspace:import",
            [
                "filename" => $this->importDirectory . $this->lastFileName . ".phpcr"
            ],
            new NullOutput()
        );
        $this->progressBar->advance();
    }

    private function importDatabase()
    {
        $this->progressBar->setMessage("Importing database...");
        $filename = $this->importDirectory . $this->lastFileName . ".sql";
        $command =
            "mysql -h {$this->databaseHost} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? " -p" . escapeshellarg($this->databasePassword) : "") .
            " " . escapeshellarg($this->databaseName) . " < " . "{$filename}";

        $process = new Process($command);
        $process->run();
        $this->progressBar->advance();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function importUploads()
    {
        $this->progressBar->setMessage("Importing uploads...");
        $filename = $this->importDirectory . $this->lastFileName . ".tar.gz";

        // Directory path with new Symfony directory structure - i.e. var/uploads.
        $path = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "uploads";
        if (file_exists($path)) {
            $path = "var/uploads";
        } else {
            $path = "uploads";
        }

        $process = new Process("tar -xvf {$filename} {$path}  --no-overwrite-dir");
        $process->run();
        $this->progressBar->advance();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function executeCommand($cmd, array $params, OutputInterface $output)
    {
        $command = $this->getApplication()->find($cmd);
        $command->run(
            new ArrayInput(
                ["command" => $cmd] + $params
            ),
            $output
        );
    }
}