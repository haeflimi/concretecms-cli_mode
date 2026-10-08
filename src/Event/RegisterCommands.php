<?php

namespace CliMode\Event;

use CliMode\Terminal\CommandRegistry;
use Symfony\Contracts\EventDispatcher\Event;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dispatched as "on_cli_mode_register_commands" the first time the terminal needs its commands. Listen to it
 * to add commands without caring about the order in which packages start:
 *
 *   $app->make('director')->addListener('on_cli_mode_register_commands', function ($event) {
 *       $event->getRegistry()->register(MyCommand::class);
 *   });
 */
class RegisterCommands extends Event
{
    /** @var CommandRegistry */
    protected $registry;

    public function __construct(CommandRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getRegistry(): CommandRegistry
    {
        return $this->registry;
    }
}
