<?php

namespace Concrete\Package\MyPackage\Terminal;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\TerminalCommandAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Example of a command for the CLI Mode terminal, written like a Concrete CLI command (it is a Symfony Console
 * command). Register it in your package's on_start():
 *
 *     $this->app->make(\CliMode\Terminal\CommandRegistry::class)->register(\Concrete\Package\MyPackage\Terminal\HelloCommand::class);
 *
 * or, to not care about the order in which packages start, in a listener:
 *
 *     $this->app->make('director')->addListener('on_cli_mode_register_commands', function ($event) {
 *         $event->getRegistry()->register(HelloCommand::class);
 *     });
 */
class HelloCommand extends TerminalCommand
{
    protected static $defaultName = 'hello';

    /** Group in the output of "help": system, site, text, lan, fun or your own */
    protected $category = 'fun';

    /** Who may run it: PUBLIC, REGISTERED or ADMIN (override isAllowedFor() for anything finer, e.g. a group) */
    protected $access = TerminalCommandAccess::PUBLIC;

    protected function configure(): void
    {
        $this->setDescription(t('Greets somebody'))
            ->addArgument('who', InputArgument::OPTIONAL, t('Whom to greet'), 'world')
            ->addOption('shout', 's', InputOption::VALUE_NONE, t('In capitals'))
            ->setHelp(t('Examples:') . "\n  hello\n  hello Pixel Paula --shout");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The context knows the user, the shell state and the snark; $this->context()->app is the Concrete container
        $greeting = t('Hello %1$s from %2$s!', $input->getArgument('who'), $this->context()->username());
        $output->writeln('<info>' . ($input->getOption('shout') ? strtoupper($greeting) : $greeting) . '</info>');

        return self::SUCCESS; // anything but 0 counts as failure for && and ||
    }
}
