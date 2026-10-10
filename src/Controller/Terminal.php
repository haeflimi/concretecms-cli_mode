<?php

namespace CliMode\Controller;

use CliMode\Terminal\AttitudeRegistry;
use CliMode\Terminal\CommandRegistry;
use CliMode\Terminal\Context;
use CliMode\Terminal\Login;
use CliMode\Terminal\Settings;
use CliMode\Terminal\Shell;
use CliMode\Terminal\SiteTree;
use CliMode\Terminal\Snark;
use CliMode\Terminal\State;
use CliMode\Terminal\Welcome;
use Concrete\Core\Controller\Controller;
use Concrete\Core\Localization\Localization;
use Concrete\Core\User\User;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The three JSON endpoints of the terminal: boot (banner, prompt, token), exec (run a line) and complete (Tab).
 */
class Terminal extends Controller
{
    public const TOKEN = 'cli_mode';
    protected const RATE_LIMIT = 90;

    public function boot(): JsonResponse
    {
        $context = $this->context();
        if (!$context) {
            return $this->denied();
        }
        $state = $context->state;
        $landing = '';
        $banner = null;
        if ($context->notFound) {
            [$banner, $state->cwd] = $this->notFoundBanner($context, (string) $this->request->query->get('path', '/'));
            $state->save();
        } elseif ($context->forced) {
            // somebody followed a link: the terminal starts at the page that link points to
            $path = (string) $this->request->query->get('path', '/');
            $tree = new SiteTree($context);
            $page = $tree->page($path);
            if ($page) {
                $state->cwd = $tree->path($page);
                $where = "\e[96m" . $state->cwd . "\e[39m";
                $landing = $context->snark->level() > 0
                    ? t('You followed a link. Cute. Welcome to %s.', $where) . ' ' . t('Try %1$s or %2$s.', "\e[93mcat .\e[39m", "\e[93mls\e[39m")
                    : t('Current page: %s.', $where) . ' ';
            } else {
                $state->cwd = '/';
                $landing = "\e[31m" . ($context->snark->level() > 0 ? t('%s: No such file or directory. The internet is a big place, and you chose a wrong spot in it.', $path) : t('%s: No such file or directory', $path)) . "\e[39m";
            }
            $state->save();
        }

        return new JsonResponse([
            'token' => $this->app->make('token')->generate(self::TOKEN),
            'prompt' => $this->prompt($context),
            'banner' => $banner ?? (new Welcome($context, $this->request))->banner(),
            'landing' => $landing,
            'notfound' => $context->notFound,
            'history' => $state->history,
            'forced' => $context->forced,
            'labels' => Settings::labels(),
            // the inline terminal shows no banner, only its greeting: it prints the hint on its own
            'hint' => (new Welcome($context, $this->request))->languageHint(),
        ]);
    }

    public function exec(): JsonResponse
    {
        $context = $this->context();
        if (!$context) {
            return $this->denied();
        }
        if (!$this->app->make('token')->validate(self::TOKEN, $this->request->request->get('token'))) {
            return new JsonResponse(['error' => t('Your session went stale. Close and reopen the terminal.')], 403);
        }
        if ($this->tooFast()) {
            return new JsonResponse([
                'output' => "\e[31m" . ($context->snark->level() > 0 ? t('Easy there, speed typist. Even my patience has a rate limit.') : t('Too many commands, slow down.')) . "\n" . "\e[39m",
                'code' => 1,
                'actions' => [],
                'prompt' => $this->prompt($context),
            ]);
        }
        $result = (new Shell($context, $context->registry))->run((string) $this->request->request->get('line', ''));
        $result['prompt'] = $this->prompt($context);

        return new JsonResponse($result);
    }

    /**
     * The login prompt of the terminal. The browser asks for the user name and the password and sends both here,
     * not through exec: a password must not end up in the history, the command line or the saved screen. Answers like exec,
     * plus a new token (the CSRF token is bound to the user, so it changes with the login). When required profile details
     * are missing the answer carries "profile" (the fields to ask for) and the browser posts the answers back as profile[handle].
     */
    public function login(): JsonResponse
    {
        $context = $this->context();
        if (!$context) {
            return $this->denied();
        }
        if (!$this->app->make('token')->validate(self::TOKEN, $this->request->request->get('token'))) {
            return new JsonResponse(['error' => t('Your session went stale. Close and reopen the terminal.')], 403);
        }
        $reply = static function (Context $c, string $text, int $code, array $actions = []) {
            return ['output' => "\e[" . ($code === 0 ? '92' : '31') . 'm' . $text . "\e[39m\n", 'code' => $code, 'actions' => $actions];
        };
        if ($context->isRegistered()) {
            return new JsonResponse($reply($context, t('You are already %s.', $context->username()), 1) + ['prompt' => $this->prompt($context)]);
        }
        if ($this->tooManyLogins()) {
            return new JsonResponse($reply($context, $context->snark->level() > 0 ? t('Slow down. Even guessing needs a break.') : t('Too many login attempts, wait a minute.'), 1) + ['prompt' => $this->prompt($context)]);
        }

        $login = new Login($this->app);
        $post = $this->request->request;
        // the second step answers the profile questions of a sign-in whose password was right
        $profile = $post->get('profile');
        $ok = is_array($profile)
            ? $login->completeProfile($profile)
            : $login->attempt(substr((string) $post->get('username', ''), 0, 255), substr((string) $post->get('password', ''), 0, 1024));
        if (!$ok || !$login->user()) {
            $text = $login->message() ?? $context->snark->say('login_failed');
            $fields = $login->fields();

            return new JsonResponse($reply($context, $text, $fields && !is_array($profile) ? 0 : 1) + ['prompt' => $this->prompt($context)] + ($fields ? ['profile' => $fields] : []));
        }

        // the container's User is the one from before the login: replace it, then everything below sees the member
        $this->app->instance(User::class, $login->user());
        $wasLocked = $context->forced || $context->notFound;
        $context = $this->context();
        if (!$context) {
            return $this->denied();
        }
        $result = $reply($context, $context->snark->say('login_welcome', $context->username()), 0, [
            // a mandatory terminal may be none any more for this user, and the page behind it knows nothing yet
            ['type' => 'reload', 'data' => ['now' => $wasLocked]],
        ]);
        $result['prompt'] = $this->prompt($context);
        $result['token'] = $this->app->make('token')->generate(self::TOKEN);

        return new JsonResponse($result);
    }

    public function complete(): JsonResponse
    {
        $context = $this->context();
        if (!$context) {
            return $this->denied();
        }
        if (!$this->app->make('token')->validate(self::TOKEN, $this->request->request->get('token'))) {
            return new JsonResponse(['error' => 'stale'], 403);
        }
        $line = substr((string) $this->request->request->get('line', ''), 0, Shell::MAX_LINE);

        return new JsonResponse((new Shell($context, $context->registry))->complete($line));
    }

    protected function context(): ?Context
    {
        $settings = $this->app->make(Settings::class);
        $user = $this->app->make(User::class);
        $notFoundRequested = $settings->replacesNotFound() && (bool) $this->request->get('notfound');
        if (!$settings->mayUse($user, $notFoundRequested)) {
            return null;
        }

        $state = new State($this->app->make('session'));
        // everything the terminal says (commands, help, messages) in the session's language, English by default
        Localization::changeLocale(Settings::locale($state->lang));
        $attitudes = $this->app->make(AttitudeRegistry::class);
        // the attitude this session switched to with the "attitude" command, else the site's
        $handle = $state->attitude !== null && isset($attitudes->all()[$state->attitude]) ? $state->attitude : $settings->getAttitude();
        $context = new Context(
            $this->app,
            $user,
            $state,
            new Snark($attitudes->get($handle)),
            $settings,
            $this->app->make(CommandRegistry::class)
        );
        $context->forced = $settings->isForcedFor($user, (bool) $this->request->get('preview'));
        if ($notFoundRequested && !$context->forced) {
            // believe nothing the browser says: the address must really lead nowhere
            $path = (string) $this->request->get('path', '/');
            $context->notFound = !Settings::isBypassPath($path) && (new SiteTree($context))->page($path) === null;
            if (!$context->notFound && !$settings->mayUse($user)) {
                return null;
            }
        }

        return $context;
    }

    protected function denied(): JsonResponse
    {
        return new JsonResponse(['error' => t('Permission denied.')], 403);
    }

    /**
     * @return array{user: string, host: string, cwd: string, admin: bool}
     */
    protected function prompt(Context $context): array
    {
        return ['user' => $context->username(), 'host' => $context->hostname(), 'cwd' => $context->state->cwd, 'admin' => $context->isAdmin()];
    }

    /**
     * The welcome message of the replaced 404 page: the 404 as ASCII art, the remark, similar pages, how to go on.
     *
     * @return array{0: string, 1: string} banner (ANSI) and the directory the shell starts in
     */
    protected function notFoundBanner(Context $context, string $path): array
    {
        $tree = new SiteTree($context);
        $path = $tree->normalize($path === '' ? '/' : $path, '/');
        // the closest page that does exist: walk up until there is one
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        $missing = (string) array_pop($parts);
        $closest = '/';
        $parent = null;
        while ($parts) {
            $try = '/' . implode('/', $parts);
            if ($parent = $tree->page($try)) {
                $closest = $tree->path($parent);
                break;
            }
            array_pop($parts);
        }
        $parent = $parent ?: $tree->page('/');

        $suggestions = [];
        $needle = strtolower($missing);
        foreach ($parent ? $tree->children($parent) : [] as $child) {
            $name = $tree->name($child);
            $distance = levenshtein($needle, strtolower($name));
            if ($needle !== '' && ($distance <= 3 || strpos(strtolower($name), $needle) !== false || strpos($needle, strtolower($name)) !== false)) {
                $suggestions[$name] = $tree->path($child);
            }
        }

        $art = $context->settings->getBanner404() ?: implode("\n", [
            ' _  _    ___  _  _   ',
            '| || |  / _ \\| || |  ',
            '| || |_| | | | || |_ ',
            '|__   _| |_| |__   _|',
            '   |_|  \\___/   |_|  ',
        ]);
        $lines = [];
        foreach (explode("\n", $art) as $row) {
            $lines[] = "\e[91m" . $row . "\e[39m";
        }
        $lines[] = '';
        $lines[] = "\e[93m" . t('PAGE NOT FOUND') . "\e[39m";
        $lines[] = '';
        $lines[] = "\e[31m" . $context->snark->missingPage($path) . "\e[39m";
        $lines[] = '';
        if ($suggestions) {
            $lines[] = t('Did you mean one of these?');
            foreach ($suggestions as $name => $target) {
                $lines[] = '  ' . "\e[96m" . $target . "\e[39m";
            }
            $lines[] = '';
        }
        $lines[] = t('You are now in %1$s, the closest thing that exists. Try %2$s, %3$s or %4$s.', "\e[96m" . $closest . "\e[39m", "\e[93mls\e[39m", "\e[93mcd /\e[39m", "\e[93mfind <word>\e[39m") . ($context->snark->level() > 1 ? ' ' . t('Or admit defeat with %s and I will take you home.', "\e[93mexit\e[39m") : '');
        $lines[] = t('Type %1$s for all commands, %2$s for the tour.', "\e[93mhelp\e[39m", "\e[93mguide\e[39m");
        $motd = $context->settings->getMotd();
        if ($motd !== '') {
            $lines[] = '';
            $lines[] = $motd;
        }

        return [implode("\n", $lines) . "\n", $closest];
    }

    /**
     * Commands in the last minute; a runaway script should not be able to hammer the site through the terminal.
     */
    /**
     * At most five tries a minute per session, on top of the failed login limit Concrete keeps per address.
     */
    protected function tooManyLogins(): bool
    {
        $session = $this->app->make('session');
        $now = time();
        $tries = array_filter((array) $session->get('cli_mode.logins', []), static function ($t) use ($now) {
            return $t > $now - 60;
        });
        $tries[] = $now;
        $session->set('cli_mode.logins', array_values($tries));

        return count($tries) > 5;
    }

    protected function tooFast(): bool
    {
        $session = $this->app->make('session');
        $now = time();
        $hits = array_filter((array) $session->get('cli_mode.hits', []), static function ($t) use ($now) {
            return $t > $now - 60;
        });
        $hits[] = $now;
        $session->set('cli_mode.hits', array_values($hits));

        return count($hits) > self::RATE_LIMIT;
    }
}
