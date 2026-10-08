<?php

namespace CliMode\Terminal;

use CliMode\Event\RegisterAttitudes;
use CliMode\Terminal\Attitude\Attitude;
use CliMode\Terminal\Attitude\PoliteAttitude;
use Concrete\Core\Application\Application;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * All attitudes the terminal can have. Packages register theirs in on_start() or by listening to the
 * "on_cli_mode_register_attitudes" event; the site can list attitude classes in application/config/cli_mode.php
 * (key "attitudes"). The dashboard offers whatever is in here.
 */
class AttitudeRegistry
{
    const DEFAULT_HANDLE = 'polite';

    /** @var Application */
    protected $app;

    /** @var array<int, string|Attitude> */
    protected $pending = [];

    /** @var Attitude[]|null */
    protected $attitudes;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param string|Attitude $attitude an Attitude class name (created by the container) or an instance
     */
    public function register($attitude): self
    {
        $this->pending[] = $attitude;
        // registered after the first use: make the next all() pick it up
        $this->attitudes = null;

        return $this;
    }

    /**
     * @return Attitude[] by handle, in the order they were registered (a later one replaces an earlier one with the same handle)
     */
    public function all(): array
    {
        if ($this->attitudes === null) {
            $this->app->make('director')->dispatch('on_cli_mode_register_attitudes', new RegisterAttitudes($this));
            foreach ($this->app->make(Settings::class)->getConfiguredAttitudes() as $class) {
                if (class_exists($class)) {
                    $this->pending[] = $class;
                }
            }
            $attitudes = [];
            foreach ($this->pending as $entry) {
                try {
                    $attitude = is_string($entry) ? $this->app->make($entry) : $entry;
                } catch (\Throwable $e) {
                    continue;
                }
                if ($attitude instanceof Attitude && preg_match('/^[a-z0-9_]+$/', $attitude->handle())) {
                    $attitudes[$attitude->handle()] = $attitude;
                }
            }
            $this->attitudes = $attitudes;
        }

        return $this->attitudes;
    }

    /**
     * The attitude with this handle; polite when there is none (a package that brought it may be gone).
     */
    public function get(string $handle): Attitude
    {
        $all = $this->all();

        return $all[$handle] ?? $all[self::DEFAULT_HANDLE] ?? new PoliteAttitude();
    }
}
