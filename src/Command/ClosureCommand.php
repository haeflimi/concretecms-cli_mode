<?php

namespace CliMode\Command;

use CliMode\Terminal\Context;
use CliMode\Terminal\TerminalCommandAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * A command made of a closure, for small things (and as the quickest way for packages to add one):
 *
 *   ClosureCommand::make('uptime', 'How long has it been', function (Context $c, InputInterface $in, OutputInterface $out) {
 *       $out->writeln('a while');
 *       return 0;
 *   }, ['category' => 'system', 'aliases' => ['up'], 'arguments' => [['name', InputArgument::OPTIONAL, 'Description']]]);
 *
 * Config keys: category, access, aliases, hidden, help, arguments ([name, mode, description, default]),
 * options ([name, shortcut, mode, description, default]), paths (Tab completes page paths), lenient (accept any
 * arguments and options without complaint).
 */
class ClosureCommand extends TerminalCommand
{
    /** @var callable */
    protected $callback;

    /** @var array<string, mixed> */
    protected $config;

    /** @var string */
    protected $commandName;

    /** @var string */
    protected $commandDescription;

    /** @var callable|null function (Context): string[] words Tab offers for arguments */
    protected $completer;

    public static function make(string $name, string $description, callable $callback, array $config = []): self
    {
        return new self($name, $description, $callback, $config);
    }

    public function __construct(string $name, string $description, callable $callback, array $config = [])
    {
        $this->commandName = $name;
        $this->commandDescription = $description;
        $this->callback = $callback;
        $this->config = $config;
        $this->category = (string) ($config['category'] ?? 'misc');
        $this->access = (string) ($config['access'] ?? TerminalCommandAccess::PUBLIC);
        $this->completesPaths = !empty($config['paths']);
        parent::__construct($name);
    }

    /**
     * @param callable $completer function (Context $context): string[]
     */
    public function setCompleter(callable $completer): self
    {
        $this->completer = $completer;

        return $this;
    }

    public function completeWords(string $word, array $words): array
    {
        if ($this->completer) {
            $options = (array) call_user_func($this->completer, $this->context());

            return array_values(array_filter($options, static function ($o) use ($word) {
                return $word === '' || strpos((string) $o, $word) === 0;
            }));
        }

        return parent::completeWords($word, $words);
    }

    protected function configure(): void
    {
        $this->setDescription($this->commandDescription);
        $this->setAliases((array) ($this->config['aliases'] ?? []));
        $this->setHidden(!empty($this->config['hidden']));
        if (!empty($this->config['lenient'])) {
            $this->ignoreValidationErrors();
        }
        if (!empty($this->config['help'])) {
            $this->setHelp((string) $this->config['help']);
        }
        foreach ((array) ($this->config['arguments'] ?? []) as $argument) {
            $this->addArgument($argument[0], $argument[1] ?? InputArgument::OPTIONAL, $argument[2] ?? '', $argument[3] ?? null);
        }
        foreach ((array) ($this->config['options'] ?? []) as $option) {
            $this->addOption($option[0], $option[1] ?? null, $option[2] ?? InputOption::VALUE_NONE, $option[3] ?? '', $option[4] ?? null);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int) call_user_func($this->callback, $this->context(), $input, $output, $this);
    }
}
