<?php

namespace CliMode\Command\Fun;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\Figlet;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * figlet <text>: big letters.
 */
class FigletCommand extends TerminalCommand
{
    protected static $defaultName = 'figlet';
    protected $category = 'fun';

    /** Longest text we letter; five rows per line add up quickly */
    const MAX_CHARS = 60;

    protected function configure(): void
    {
        $this->setDescription(t('Prints text in big letters'))
            ->addArgument('text', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, t('The text (or pipe it in)'))
            ->addOption('width', 'w', InputOption::VALUE_REQUIRED, t('Characters per line (8 to 200); longer text wraps'), 60)
            ->addOption('fill', 'f', InputOption::VALUE_REQUIRED, t('The character the letters are made of'), '#')
            ->setHelp(t("Letters, digits and . , : ! ? - _ + = / ' only. The width should fit your window, or the lines wrap and the letters fall apart.") . "\n\n" . t('Examples:') . "\n  figlet LAN party\n  figlet -f '*' GG\n  figlet hello | lolcat");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();
        $stdin = $c->stdin;
        $text = trim(implode(' ', (array) $input->getArgument('text')));
        if ($text === '' && $stdin !== null) {
            $text = trim($stdin);
        }
        if ($text === '') {
            $output->writeln('<error> ' . $c->snark->usage('figlet', t('no text. Even a banner needs something to shout')) . ' </error>');

            return 1;
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            $output->writeln('<error> ' . t('figlet: that is %1$d characters. Up to %2$d, please: this is a banner, not a novel.', mb_strlen($text), self::MAX_CHARS) . ' </error>');

            return 1;
        }
        $fill = (string) $input->getOption('fill');
        $lines = Figlet::render($text, (int) $input->getOption('width'), $fill === '' ? '#' : mb_substr($fill, 0, 1));
        $output->writeln($lines, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
