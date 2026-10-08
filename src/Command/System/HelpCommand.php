<?php

namespace CliMode\Command\System;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\Application;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * help: the command list grouped by category, or the manual of one command (arguments, options, examples).
 */
class HelpCommand extends TerminalCommand
{
    protected static $defaultName = 'help';
    protected $category = 'system';

    protected function configure(): void
    {
        $this->setDescription('Lists the commands, or explains one of them')
            ->setAliases(['man', 'list'])
            ->addArgument('topic', InputArgument::OPTIONAL, 'The command to explain')
            ->setHelp("Without an argument: every command you may run, by category.\nWith a command name: its manual.\n\nExamples:\n  help\n  help ls\n  man events\n\nNew here? Run <info>guide</info> for a short tour.");
    }

    public function completeWords(string $word, array $words): array
    {
        $names = $this->context()->registry->names($this->context());

        return array_values(array_filter($names, static function ($n) use ($word) {
            return $word === '' || strpos($n, $word) === 0;
        }));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Application $application */
        $application = $this->getApplication();
        $name = $input->getArgument('topic');
        if ($name !== null) {
            $command = $this->context()->registry->resolve((string) $name);
            if (!$command || !$command->isAllowedFor($this->context())) {
                $output->writeln('<error> ' . ($command ? $this->context()->snark->denied($name) : 'No manual entry for ' . $name . '. Try harder.') . ' </error>');

                return self::FAILURE;
            }
            (new DescriptorHelper())->describe($output, $command, ['format' => 'txt']);

            return self::SUCCESS;
        }

        $byCategory = [];
        foreach ($application->visibleCommands() as $command) {
            if ($command instanceof TerminalCommand) {
                $byCategory[$command->getCategory()][] = $command;
            }
        }
        $order = array_flip(['system', 'site', 'text', 'lan', 'fun']);
        uksort($byCategory, static function ($a, $b) use ($order) {
            return [$order[$a] ?? 99, $a] <=> [$order[$b] ?? 99, $b];
        });
        $width = 0;
        foreach ($application->visibleCommands() as $command) {
            $width = max($width, strlen($command->getName()));
        }
        $output->writeln('<accent>snarkbash</accent> <dim>' . $this->context()->hostname() . ' · ' . count($application->visibleCommands()) . ' commands available</dim>');
        foreach ($byCategory as $category => $commands) {
            $output->writeln('');
            $output->writeln('<comment>' . ucfirst($category) . '</comment>');
            foreach ($commands as $command) {
                $output->writeln(sprintf('  <info>%s</info>%s  %s', $command->getName(), str_repeat(' ', $width - strlen($command->getName())), $command->getDescription()));
            }
        }
        $output->writeln('');
        $output->writeln('<dim>help <command> explains one · guide gives you the tour · Tab completes · ↑ ↓ walk through the history</dim>');
        $footer = $this->context()->snark->emptyHelpFooter();
        if ($footer !== '') {
            $output->writeln('<snark>' . $footer . '</snark>');
        }

        return self::SUCCESS;
    }
}
