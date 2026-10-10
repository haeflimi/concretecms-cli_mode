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

    /**
     * Handle of the chosen attitude (see AttitudeRegistry). Polite unless chosen otherwise; the 0, 1 and 2 that
     * versions before 1.3.0 saved are understood.
     */
    public function getAttitude(): string
    {
        $handle = $this->value('attitude', null);
        if ($handle === null) {
            $legacy = $this->value('snark', null);

            return [0 => 'polite', 1 => 'mildly_snarky', 2 => 'super_snarky'][$legacy === null ? 0 : (int) $legacy] ?? AttitudeRegistry::DEFAULT_HANDLE;
        }

        return (string) $handle;
    }

    /**
     * The message of the day in the terminal's current language, else the English one.
     */
    public function getMotd(?string $locale = null): string
    {
        $motds = $this->getMotds();
        $locale = $locale ?? \Concrete\Core\Localization\Localization::activeLocale();

        return $motds[$locale] ?? $motds[self::DEFAULT_LOCALE] ?? '';
    }

    /**
     * @return array<string, string> locale => message of the day, only the languages that have one (before 1.8.0 it
     *                                was one text, which counts as the English one)
     */
    public function getMotds(): array
    {
        $saved = $this->value('motd', []);
        if (is_string($saved)) {
            $saved = [self::DEFAULT_LOCALE => $saved];
        }

        return array_filter(array_map('strval', (array) $saved), static function ($text) {
            return trim($text) !== '';
        });
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
     * @return string[] class names of attitudes registered in application/config/cli_mode.php (key "attitudes")
     */
    public function getConfiguredAttitudes(): array
    {
        return array_values(array_filter((array) $this->config->get('cli_mode.attitudes', []), 'is_string'));
    }

    /**
     * @return string[] class names of commands registered in application/config/cli_mode.php (key "commands")
     */
    public function getConfiguredCommands(): array
    {
        return array_values(array_filter((array) $this->config->get('cli_mode.commands', []), 'is_string'));
    }

    /**
     * @param array<string, string>|string $motd locale => message of the day (a plain string is the English one)
     */
    public function save(bool $enabled, string $access, bool $launcher, string $attitude, $motd, string $forced = self::FORCE_OFF, bool $notFound = false): void
    {
        $this->store->save('cli_mode.notfound', $notFound);
        $this->store->save('cli_mode.forced', in_array($forced, [self::FORCE_VISITORS, self::FORCE_EVERYONE], true) ? $forced : self::FORCE_OFF);
        $this->store->save('cli_mode.enabled', $enabled);
        $this->store->save('cli_mode.access', in_array($access, [self::ACCESS_REGISTERED, self::ACCESS_ADMINS], true) ? $access : self::ACCESS_EVERYONE);
        $this->store->save('cli_mode.launcher', $launcher);
        $this->store->save('cli_mode.attitude', preg_match('/^[a-z0-9_]+$/', $attitude) ? $attitude : AttitudeRegistry::DEFAULT_HANDLE);
        $motds = [];
        foreach (is_array($motd) ? $motd : [self::DEFAULT_LOCALE => $motd] as $locale => $text) {
            if (isset(self::languages()[$locale]) && trim((string) $text) !== '') {
                $motds[$locale] = rtrim(str_replace("\r\n", "\n", (string) $text));
            }
        }
        // before 1.8.0 the message was one text in the row "motd" itself: the saver only replaces rows below it
        app('database')->connection()->delete('Config', ['configNamespace' => '', 'configGroup' => 'cli_mode', 'configItem' => 'motd']);
        $this->store->save('cli_mode.motd', $motds);
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

    /** the terminal speaks English unless the session picked another language with "lang" */
    public const DEFAULT_LOCALE = 'en_US';

    /**
     * The languages the terminal can speak: English plus every translation shipped in the package's languages folder.
     *
     * @return array<string, string> locale => name in that language
     */
    public static function languages(): array
    {
        $locales = [self::DEFAULT_LOCALE];
        foreach (glob(dirname(__DIR__, 2) . '/languages/*/LC_MESSAGES/messages.mo') ?: [] as $file) {
            $locales[] = basename(dirname($file, 2));
        }
        $languages = [];
        foreach (array_unique($locales) as $locale) {
            $name = '';
            try {
                $name = (string) \Punic\Language::getName($locale, $locale);
            } catch (\Throwable $e) {
            }
            $languages[$locale] = $name !== '' ? $name : $locale;
        }

        return $languages;
    }

    /**
     * The terminal language a browser would rather have (Accept-Language, in its order): the first wish the terminal
     * can fulfil, the website's own variant preferred for a bare language (de → de_CH on a Swiss site).
     *
     * @param string[] $wishes e.g. ['de_CH', 'de', 'en_US', 'en'] from Request::getLanguages()
     */
    public static function preferredLocale(array $wishes, string $siteLocale = ''): ?string
    {
        $locales = array_keys(self::languages());
        foreach ($wishes as $wish) {
            $wish = strtolower(str_replace('-', '_', (string) $wish));
            foreach ($locales as $locale) {
                if (strtolower($locale) === $wish) {
                    return $locale;
                }
            }
            $language = strtok($wish, '_');
            $candidates = array_values(array_filter($locales, static function ($locale) use ($language) {
                return strtolower(strtok($locale, '_')) === $language;
            }));
            if ($candidates) {
                return in_array($siteLocale, $candidates, true) ? $siteLocale : $candidates[0];
            }
        }

        return null;
    }

    /**
     * @return string the locale for a session's choice, the default when there is none or it is not available (any more)
     */
    public static function locale(?string $chosen): string
    {
        return $chosen !== null && isset(self::languages()[$chosen]) ? $chosen : self::DEFAULT_LOCALE;
    }

    /**
     * Texts of the browser side, in the current locale (the shell's own output is translated on the server).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'greeting' => t('Type %s for the commands.', "\x1b[1mhelp\x1b[22m"),
            'login' => t('login: '),
            'password' => t('Password: '),
            'terminal' => t('Terminal'),
            'close' => t('Close terminal'),
            'open' => t('Open the terminal'),
        ];
    }
}
