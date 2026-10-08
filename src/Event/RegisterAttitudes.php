<?php

namespace CliMode\Event;

use CliMode\Terminal\AttitudeRegistry;
use Symfony\Contracts\EventDispatcher\Event;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dispatched as "on_cli_mode_register_attitudes" the first time the terminal needs its attitudes. Listen to it
 * to add attitudes without caring about the order in which packages start:
 *
 *   $app->make('director')->addListener('on_cli_mode_register_attitudes', function ($event) {
 *       $event->getRegistry()->register(PirateAttitude::class);
 *   });
 */
class RegisterAttitudes extends Event
{
    /** @var AttitudeRegistry */
    protected $registry;

    public function __construct(AttitudeRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getRegistry(): AttitudeRegistry
    {
        return $this->registry;
    }
}
