<?php

namespace Fusonic\SuluSyncBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ExportCommand extends Command
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

    private $timestampString;
    private $databaseHost;
    private $databaseUser;
    private $databaseName;
    private $databasePassword;
    private $kernelRootDir;
    private $exportDirectory;

    public function __construct(
        $databaseHost,
        $databaseName,
        $databaseUser,
        $databasePassword,
        $kernelRootDir
    ) {
        parent::__construct();

        $dt = new \DateTime('NOW');
        $this->timestampString = $dt->format('Y-m-d-H-i-s');

        $this->databaseHost = $databaseHost;
        $this->databaseUser = $databaseUser;
        $this->databaseName = $databaseName;
        $this->databasePassword = $databasePassword;
        $this->kernelRootDir = $kernelRootDir;
    }

    protected function configure()
    {
        $this
            ->setName("sulu:export")
            ->setDescription("Exports Sulu content (PHPCR, database, uploads) to the chosen project directory.")
            ->setHelp('This command allows you to export your Sulu content')
            ->addArgument(
                'dir',
                InputArgument::REQUIRED,
                'Dump export directory'
            )
            ->addArgument(
                'export_msg',
                InputArgument::REQUIRED,
                'Export message'
            )
            ->addOption(
                "export-assets",
                null,
                null,
                "Skip assets downloading"
            )
            ->addOption(
                "export-indices",
                null,
                null,
                "Export ElasticSearch indices"
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $exportAssets = $this->input->getOption("export-assets");
        $exportIndices = $this->input->getOption("export-indices");

        $this->progressBar = new ProgressBar($this->output, 3);
        $this->progressBar->setFormat("%current%/%max% [%bar%] %percent:3s%% <info>%message%</info>");

        $this->exportDirectory = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $input->getArgument("dir");

        $this->exportPHPCR();
        $this->exportDatabase();

        if ($exportAssets) {
            $this->exportUploads();
        }

        if ($exportIndices) {
            $this->exportIndices();
        }

        $this->logExport($input->getArgument("export_msg"));

        $this->progressBar->finish();

        $this->output->writeln(
            PHP_EOL . "<info>Successfully exported content.</info>"
        );
    }

    private function exportPHPCR()
    {
        $this->progressBar->setMessage("Exporting PHPCR repository...");
        $this->executeCommand(
            "doctrine:phpcr:workspace:export",
            [
                "-p" => "/cmf",
                "filename" => $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->timestampString}.phpcr"
            ]
        );
        $this->progressBar->advance();
    }

    private function exportDatabase()
    {
        $this->progressBar->setMessage("Exporting database...");
        $command =
            "mysqldump -h {$this->databaseHost} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? " -p" . escapeshellarg($this->databasePassword) : "") .
            " " . escapeshellarg($this->databaseName) . " > " . $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->timestampString}.sql";

        $process = new Process($command);
        $process->run();
        $this->progressBar->advance();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function exportUploads()
    {
        $this->progressBar->setMessage("Exporting uploads...");

        // Directory path with new Symfony directory structure - i.e. var/uploads.
        $exportPath = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "uploads";
        if (!file_exists($exportPath)) {
            // Old-fashioned directory structure.
            $exportPath = $this->kernelRootDir . DIRECTORY_SEPARATOR . ".."  . DIRECTORY_SEPARATOR . "uploads";
        }

        $process = new Process(
            "tar cvf " . $this->exportDirectory . DIRECTORY_SEPARATOR . $this->timestampString . ".tar.gz {$exportPath}"
        );
        $process->setTimeout(300);
        $process->run();
        $this->progressBar->advance();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function exportIndices()
    {
        $this->progressBar->setMessage("Exporting Elastic Search indices...");
        $this->executeCommand(
            "ongr:es:index:export",
            [
                "filename" => $this->exportDirectory . DIRECTORY_SEPARATOR . "{$this->timestampString}.json"
            ]
        );
        $this->progressBar->advance();

    }

    private function logExport($message)
    {
        $this->progressBar->setMessage("Logging export statement...");
        $command =
            "echo " . $this->timestampString . ': ' . $message . '\n >> ' . $this->exportDirectory . DIRECTORY_SEPARATOR . 'export_logs.log';
        $process = new Process($command);
        $process->run();
        $this->progressBar->advance();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function executeCommand($cmd, array $params)
    {
        $command = $this->getApplication()->find($cmd);
        $command->run(
            new ArrayInput(
                ["command" => $cmd] + $params
            ),
            new NullOutput()
        );
    }
}