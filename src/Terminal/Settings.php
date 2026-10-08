<?php

namespace CliMode\Terminal;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Site settings of the terminal (Dashboard › System › Basics › CLI Mode), stored in the database config.
 */
class Settings
{
    public const ACCESS_EVERYONE = 'everyone';
    public const ACCESS_REGISTERED = 'registered';
    public const ACCESS_ADMINS = 'admins';

    public const FORCE_OFF = 'off';
    public const FORCE_VISITORS = 'visitors';
    public const FORCE_EVERYONE = 'everyone';

    /** Pages that always render normally, so that people can sign in, manage their account and download files. */
    public const BYPASS_PATHS = ['/login', '/register', '/account', '/members', '/download_file', '/ccm', '/dashboard', '/index.php/ccm'];

    /** @var Repository */
    protected $config;

    /** @var Repository */
    protected $store;

    public function __construct(Repository $config, \Concrete\Core\Application\Application $app)
    {
        $this->config = $config;
        $this->store = $app->make('config/database');
    }

    /**
     * Saved value (dashboard), else the file config (application/config/cli_mode.php), else the default.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    protected function value(string $key, $default)
    {
        $saved = $this->store->get('cli_mode.' . $key);

        return $saved !== null ? $saved : $this->config->get('cli_mode.' . $key, $default);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->value('enabled', true);
    }

    public function getAccess(): string
    {
        $access = (string) $this->value('access', self::ACCESS_EVERYONE);

        return in_array($access, [self::ACCESS_EVERYONE, self::ACCESS_REGISTERED, self::ACCESS_ADMINS], true) ? $access : self::ACCESS_EVERYONE;
    }

    /**
     * Mandatory mode: off, visitors (not signed in) or everyone except administrators.
     */
    public function getForced(): string
    {
        $mode = (string) $this->value('forced', self::FORCE_OFF);

        return in_array($mode, [self::FORCE_VISITORS, self::FORCE_EVERYONE], true) ? $mode : self::FORCE_OFF;
    }

    /**
     * Whether the terminal is the only way through the website for this user. Administrators are never locked out
     * (they could not switch it off again), but may preview it with ?forcecli on any page.
     */
    public function isForcedFor(User $user, bool $preview = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        if (self::isAdmin($user)) {
            return $preview;
        }
        $mode = $this->getForced();

        return $mode === self::FORCE_EVERYONE || ($mode === self::FORCE_VISITORS && !$user->isRegistered());
    }

    public static function isBypassPath(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::BYPASS_PATHS as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the website's 404 page is replaced by the terminal (with the 404 as ASCII art in the welcome message).
     */
    public function replacesNotFound(): bool
    {
        return $this->isEnabled() && (bool) $this->value('notfound', false);
    }

    /**
     * The checkbox state as saved, independent of whether the whole terminal is switched on.
     */
    public function isNotFoundSaved(): bool
    {
        return (bool) $this->value('notfound', false);
    }

    public function showLauncher(): bool
    {
        return (bool) $this->value('launcher', true);
    }

    /** 0 = plain messages, 1 = mild remarks, 2 = full sass */
    public function getSnark(): int
    {
        return max(0, min(2, (int) $this->value('snark', 2)));
    }

    public function getMotd(): string
    {
        return (string) $this->value('motd', '');
    }

    /**
     * ASCII art shown when the terminal opens: config key cli_mode.banner (application/config/cli_mode.php), empty = the default.
     */
    public function getBanner(): string
    {
        return rtrim((string) $this->config->get('cli_mode.banner', ''));
    }

    /**
     * ASCII art of the 404 welcome message: config key cli_mode.banner404, empty = the default.
     */
    public function getBanner404(): string
    {
        return rtrim((string) $this->config->get('cli_mode.banner404', ''));
    }

    /**
     * @return string[] class names of commands registered in application/config/cli_mode.php (key "commands")
     */
    public function getConfiguredCommands(): array
    {
        return array_values(array_filter((array) $this->config->get('cli_mode.commands', []), 'is_string'));
    }

    public function save(bool $enabled, string $access, bool $launcher, int $snark, string $motd, string $forced = self::FORCE_OFF, bool $notFound = false): void
    {
        $this->store->save('cli_mode.notfound', $notFound);
        $this->store->save('cli_mode.forced', in_array($forced, [self::FORCE_VISITORS, self::FORCE_EVERYONE], true) ? $forced : self::FORCE_OFF);
        $this->store->save('cli_mode.enabled', $enabled);
        $this->store->save('cli_mode.access', in_array($access, [self::ACCESS_REGISTERED, self::ACCESS_ADMINS], true) ? $access : self::ACCESS_EVERYONE);
        $this->store->save('cli_mode.launcher', $launcher);
        $this->store->save('cli_mode.snark', max(0, min(2, $snark)));
        $this->store->save('cli_mode.motd', trim($motd));
    }

    /**
     * @param bool $notFound the request is for the 404 page the terminal replaces: everybody may use it there
     */
    public function mayUse(User $user, bool $notFound = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        if ($notFound && $this->replacesNotFound()) {
            return true;
        }
        // whoever is forced into the terminal may use it, whatever the access setting says
        if ($this->isForcedFor($user)) {
            return true;
        }
        switch ($this->getAccess()) {
            case self::ACCESS_ADMINS:
                return self::isAdmin($user);
            case self::ACCESS_REGISTERED:
                return $user->isRegistered();
            default:
                return true;
        }
    }

    public static function isAdmin(User $user): bool
    {
        if (!$user->isRegistered()) {
            return false;
        }
        $admins = Group::getByID(ADMIN_GROUP_ID);

        return $user->isSuperUser() || ($admins && $user->inGroup($admins));
    }
}
