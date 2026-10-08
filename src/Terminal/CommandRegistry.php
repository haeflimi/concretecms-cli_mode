<?php

namespace CliMode\Terminal;

use CliMode\Command\TerminalCommand;
use CliMode\Event\RegisterCommands;
use Concrete\Core\Application\Application;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * All commands the terminal knows. Packages register theirs in on_start() or by listening to the
 * "on_cli_mode_register_commands" event; the site can list command classes in application/config/cli_mode.php.
 */
class CommandRegistry
{
    /** @var Application */
    protected $app;

    /** @var array<int, string|TerminalCommand> */
    protected $pending = [];

    /** @var TerminalCommand[]|null */
    protected $commands;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param string|TerminalCommand $command a TerminalCommand class name (created by the container) or an instance
     */
    public function register($command): self
    {
        $this->pending[] = $command;
        // registered after the first use: make the next all() pick it up
        $this->commands = null;

        return $this;
    }

    /**
     * @return TerminalCommand[] by name
     */
    public function all(): array
    {
        if ($this->commands === null) {
            $this->app->make('director')->dispatch('on_cli_mode_register_commands', new RegisterCommands($this));
            foreach ($this->app->make(Settings::class)->getConfiguredCommands() as $class) {
                if (class_exists($class)) {
                    $this->pending[] = $class;
                }
            }
            $commands = [];
            foreach ($this->pending as $entry) {
                try {
                    $command = is_string($entry) ? $this->app->make($entry) : $entry;
                } catch (\Throwable $e) {
                    continue;
                }
                if ($command instanceof TerminalCommand && (string) $command->getName() !== '') {
                    $commands[$command->getName()] = $command;
                }
            }
            ksort($commands);
            $this->commands = $commands;
        }

        return $this->commands;
    }

    /**
     * The command a name or alias belongs to, whether the user may run it or not.
     */
    public function resolve(string $name): ?TerminalCommand
    {
        $commands = $this->all();
        if (isset($commands[$name])) {
            return $commands[$name];
        }
        foreach ($commands as $command) {
            if (in_array($name, $command->getAliases(), true)) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Names and aliases of the commands the user may run (hidden ones only with $withHidden).
     *
     * @return string[]
     */
    public function names(Context $context, bool $withHidden = false): array
    {
        $names = [];
        foreach ($this->all() as $command) {
            if (!$command->isAllowedFor($context) || (!$withHidden && $command->isHidden())) {
                continue;
            }
            $names[] = $command->getName();
            foreach ($command->getAliases() as $alias) {
                $names[] = $alias;
            }
        }
        sort($names);

        return array_values(array_unique($names));
    }
}
