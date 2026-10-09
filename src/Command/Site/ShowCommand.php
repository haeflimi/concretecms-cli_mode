<?php

namespace CliMode\Command\Site;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\AsciiImage;
use Concrete\Core\File\File;
use Concrete\Core\Permission\Checker;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * show <file-id>: an image from the file manager as ASCII art.
 */
class ShowCommand extends TerminalCommand
{
    protected static $defaultName = 'show';
    protected $category = 'site';

    /** Files bigger than this are not read into memory */
    const MAX_BYTES = 15 * 1024 * 1024;

    protected function configure(): void
    {
        $this->setDescription(t('Shows an image from the file manager as ASCII art'))
            ->addArgument('file-id', InputArgument::REQUIRED, t('ID of the file in the file manager'))
            ->addOption('width', 'w', InputOption::VALUE_REQUIRED, t('Characters per line (8 to 200)'), 64)
            ->addOption('invert', 'i', InputOption::VALUE_NONE, t('Dark becomes dense (for light themes like "paper")'))
            ->setHelp(t('The width should fit your window, otherwise lines wrap and the picture falls apart.') . "\n\n" . t('Examples:') . "\n  show 12\n  show 12 --width=100\n  show 12 -i | head -n 20");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();
        $id = (string) $input->getArgument('file-id');
        $file = ctype_digit($id) ? File::getByID((int) $id) : null;
        $version = $file && !$file->isError() ? $file->getApprovedVersion() : null;
        // a file the user may not view looks exactly like one that does not exist
        if (!$version || !(new Checker($file))->canViewFile()) {
            $output->writeln('<error> ' . $c->snark->noSuchPath('show', $id) . ' </error>');

            return 1;
        }
        if ((int) $version->getFullSize() > self::MAX_BYTES) {
            $output->writeln('<error> ' . t('show: %1$s is too big for a terminal (limit %2$d MB).', OutputFormatter::escape($version->getFileName()), self::MAX_BYTES >> 20) . ' </error>');

            return 1;
        }
        $data = $version->getFileContents();
        if ($data === null || $data === '') {
            $output->writeln('<error> ' . t('show: %s cannot be read.', OutputFormatter::escape($version->getFileName())) . ' </error>');

            return 1;
        }
        try {
            $lines = AsciiImage::render($data, (int) $input->getOption('width'), (bool) $input->getOption('invert'));
        } catch (\RuntimeException $e) {
            $output->writeln('<error> show: ' . OutputFormatter::escape($version->getFileName()) . ': ' . OutputFormatter::escape($e->getMessage()) . ' </error>');

            return 1;
        }
        $output->writeln($lines, OutputInterface::OUTPUT_RAW);
        $output->writeln('<dim>' . OutputFormatter::escape($version->getFileName()) . '</dim>');

        return 0;
    }
}
