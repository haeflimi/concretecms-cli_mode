<?php

namespace CliMode\Command\Fun;

use CliMode\Command\TerminalCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * lolcat: a rainbow for the piped text (or for its arguments). The terminal knows the 16 ANSI colors, so the
 * rainbow is made of the bright ones and follows the theme's palette.
 */
class LolcatCommand extends TerminalCommand
{
    protected static $defaultName = 'lolcat';
    protected $category = 'text';

    /** SGR codes: bright red, yellow, green, cyan, blue, magenta */
    const COLORS = [91, 93, 92, 96, 94, 95];

    protected function configure(): void
    {
        $this->setDescription(t('Colors the input like a rainbow (use it after a pipe)'))
            ->addArgument('text', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, t('Text, when nothing is piped in'))
            ->addOption('spread', 's', InputOption::VALUE_REQUIRED, t('Characters per color band (1 to 20)'), 3)
            ->setHelp(t('Examples:') . "\n  ls | lolcat\n  figlet LAN | lolcat\n  lolcat hello world");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stdin = $this->context()->stdin;
        $text = $stdin !== null && $stdin !== '' ? rtrim($stdin, "\r\n") : trim(implode(' ', (array) $input->getArgument('text')));
        if ($text === '') {
            $output->writeln('<error> ' . $this->context()->snark->usage('lolcat', t('nothing to color. Pipe something in or give me words')) . ' </error>');

            return 1;
        }
        $spread = max(1, min(20, (int) $input->getOption('spread')));
        $offset = random_int(0, count(self::COLORS) * $spread - 1);
        $result = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $y => $line) {
            $row = '';
            $x = 0;
            foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                if (trim($char) === '') {
                    $row .= $char;
                } else {
                    // the bands run diagonally, like in the original
                    $row .= "\e[" . self::COLORS[intdiv($x + $y + $offset, $spread) % count(self::COLORS)] . 'm' . $char;
                }
                ++$x;
            }
            $result[] = $row . "\e[39m";
        }
        $output->write(implode("\n", $result) . "\n", false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
