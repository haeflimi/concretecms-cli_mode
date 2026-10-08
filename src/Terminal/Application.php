<?php

namespace CliMode\Terminal;

use CliMode\Command\TerminalCommand;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The Symfony Console application behind the shell: it parses arguments and options, validates them and renders
 * command help. It holds only the commands the user may run, each of them knowing its context.
 */
class Application extends SymfonyApplication
{
    public function __construct(Context $context, CommandRegistry $registry)
    {
        parent::__construct('snarkbash', '1.0');
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);
        foreach ($registry->all() as $command) {
            if ($command->isAllowedFor($context)) {
                $command->setContext($context);
                $this->add($command);
            }
        }
        $this->setDefaultCommand('help');
    }

    protected function getDefaultCommands(): array
    {
        return [];
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new \Symfony\Component\Console\Input\InputArgument('command', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display help for the command'),
        ]);
    }

    /**
     * @return TerminalCommand[] the visible commands, by name
     */
    public function visibleCommands(): array
    {
        $visible = [];
        foreach ($this->all() as $name => $command) {
            if ($command instanceof TerminalCommand && !$command->isHidden() && $command->getName() === $name) {
                $visible[$name] = $command;
            }
        }

        return $visible;
    }
}
