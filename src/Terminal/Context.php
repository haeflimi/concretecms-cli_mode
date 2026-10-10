<?php

namespace CliMode\Terminal;

use Concrete\Core\Application\Application;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Everything a command may need: who runs it, the shell state, the snark, the piped input and a way to ask the
 * browser for something (clear the screen, open a page, ...).
 */
class Context
{
    /** @var Application */
    public $app;

    /** @var User */
    public $user;

    /** @var State */
    public $state;

    /** @var Snark */
    public $snark;

    /** @var Settings */
    public $settings;

    /** @var CommandRegistry */
    public $registry;

    /** @var bool the terminal is the only way through the website for this user (see Settings::isForcedFor) */
    public $forced = false;

    /** @var bool the terminal replaces the 404 page (see Settings::replacesNotFound) */
    public $notFound = false;

    /** @var string|null text piped into the running command */
    public $stdin;

    /** @var array<int, array{type: string, data: array}> */
    public $actions = [];

    /** @var Dialog|null a dialog a command started, the shell starts it after the command */
    public $pendingDialog;

    public function __construct(Application $app, User $user, State $state, Snark $snark, Settings $settings, CommandRegistry $registry)
    {
        $this->app = $app;
        $this->user = $user;
        $this->state = $state;
        $this->snark = $snark;
        $this->settings = $settings;
        $this->registry = $registry;
    }

    public function isRegistered(): bool
    {
        return (bool) $this->user->isRegistered();
    }

    public function isAdmin(): bool
    {
        return Settings::isAdmin($this->user);
    }

    /**
     * @param string $access TerminalCommand::ACCESS_*
     */
    public function hasAccess(string $access): bool
    {
        switch ($access) {
            case TerminalCommandAccess::ADMIN:
                return $this->isAdmin();
            case TerminalCommandAccess::REGISTERED:
                return $this->isRegistered();
            default:
                return true;
        }
    }

    public function username(): string
    {
        return $this->isRegistered() ? strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $this->user->getUserName())) : 'guest';
    }

    public function hostname(): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $this->app->make('site')->getSite()->getSiteName()));
        $name = trim($name, '-');

        return $name !== '' ? $name : 'localhost';
    }

    /**
     * Ask the browser for something. Known types: clear, redirect (url; the terminal closes, unless reopen is true), exit, theme (name).
     */
    public function addAction(string $type, array $data = []): void
    {
        $this->actions[] = ['type' => $type, 'data' => $data];
    }

    /**
     * Starts a conversation once the running command is done: the next lines the visitor types go to it.
     */
    public function startDialog(Dialog $dialog): void
    {
        $this->pendingDialog = $dialog;
    }

    /**
     * The visitor signed in (or registered and was signed in) during this request: from now on everything runs as them.
     */
    public function switchUser(User $user): void
    {
        $this->app->instance(User::class, $user);
        $this->user = $user;
    }

    /**
     * Value of a shell variable ($USER, $PWD, ...), user-set ones win.
     */
    public function env(string $name): ?string
    {
        if (array_key_exists($name, $this->state->env)) {
            return (string) $this->state->env[$name];
        }
        switch ($name) {
            case 'USER':
            case 'LOGNAME':
                return $this->username();
            case 'HOSTNAME':
                return $this->hostname();
            case 'PWD':
                return $this->state->cwd;
            case 'HOME':
                return '/';
            case 'SHELL':
                return '/bin/snarkbash';
            case 'TERM':
                return 'xterm-256color';
            case 'LANG':
                return 'en_US.UTF-8';
            case '?':
                return (string) $this->state->lastCode;
            case 'RANDOM':
                return (string) random_int(0, 32767);
            default:
                return null;
        }
    }

    /**
     * @return array<string, string> all variables with their values
     */
    public function allEnv(): array
    {
        $names = ['USER', 'HOSTNAME', 'PWD', 'HOME', 'SHELL', 'TERM', 'LANG'];
        $all = [];
        foreach ($names as $name) {
            $all[$name] = (string) $this->env($name);
        }

        return array_merge($all, array_map('strval', $this->state->env));
    }
}
