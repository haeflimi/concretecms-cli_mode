<?php

namespace CliMode\Controller;

use CliMode\Terminal\AttitudeRegistry;
use CliMode\Terminal\CommandRegistry;
use CliMode\Terminal\Context;
use CliMode\Terminal\Settings;
use CliMode\Terminal\Shell;
use CliMode\Terminal\SiteTree;
use CliMode\Terminal\Snark;
use CliMode\Terminal\State;
use Concrete\Core\Controller\Controller;
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
                $landing = ($context->snark->level() > 0 ? "You followed a link. Cute. Welcome to \e[96m" : 'Current page: ') . "\e[96m" . $state->cwd . "\e[39m. " . ($context->snark->level() > 0 ? "Try \e[93mcat .\e[39m or \e[93mls\e[39m." : '');
            } else {
                $state->cwd = '/';
                $landing = "\e[31m" . ($context->snark->level() > 0 ? $path . ': No such file or directory. The internet is a big place, and you chose a wrong spot in it.' : $path . ': No such file or directory') . "\e[39m";
            }
            $state->save();
        }

        return new JsonResponse([
            'token' => $this->app->make('token')->generate(self::TOKEN),
            'prompt' => $this->prompt($context),
            'banner' => $banner ?? $this->banner($context),
            'landing' => $landing,
            'notfound' => $context->notFound,
            'history' => $state->history,
            'forced' => $context->forced,
        ]);
    }

    public function exec(): JsonResponse
    {
        $context = $this->context();
        if (!$context) {
            return $this->denied();
        }
        if (!$this->app->make('token')->validate(self::TOKEN, $this->request->request->get('token'))) {
            return new JsonResponse(['error' => 'Your session went stale. Close and reopen the terminal.'], 403);
        }
        if ($this->tooFast()) {
            return new JsonResponse([
                'output' => "\e[31m" . ($context->snark->level() > 0 ? "Easy there, speed typist. Even my patience has a rate limit.\n" : "Too many commands, slow down.\n") . "\e[39m",
                'code' => 1,
                'actions' => [],
                'prompt' => $this->prompt($context),
            ]);
        }
        $result = (new Shell($context, $context->registry))->run((string) $this->request->request->get('line', ''));
        $result['prompt'] = $this->prompt($context);

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

        $context = new Context(
            $this->app,
            $user,
            new State($this->app->make('session')),
            new Snark($this->app->make(AttitudeRegistry::class)->get($settings->getAttitude())),
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
        return new JsonResponse(['error' => 'Permission denied.'], 403);
    }

    /**
     * @return array{user: string, host: string, cwd: string, admin: bool}
     */
    protected function prompt(Context $context): array
    {
        return ['user' => $context->username(), 'host' => $context->hostname(), 'cwd' => $context->state->cwd, 'admin' => $context->isAdmin()];
    }

    protected function banner(Context $context): string
    {
        $snark = $context->snark->level();
        $ascii = $context->settings->getBanner() ?: implode("\n", [
            '  ____ _     ___   __  __  ___  ____  _____',
            ' / ___| |   |_ _| |  \\/  |/ _ \\|  _ \\| ____|',
            '| |   | |    | |  | |\\/| | | | | | | |  _|',
            '| |___| |___ | |  | |  | | |_| | |_| | |___',
            ' \\____|_____|___| |_|  |_|\\___/|____/|_____|',
        ]);
        $lines = [];
        foreach (explode("\n", $ascii) as $row) {
            $lines[] = "\e[93m" . $row . "\e[39m";
        }
        $lines[] = '';
        $lines = array_merge($lines, [
            "\e[90msnarkbash 1.0 on " . $context->hostname() . " (" . ($context->isAdmin() ? 'admin' : ($context->isRegistered() ? 'member' : 'visitor')) . ')' . "\e[39m",
            '',
        ]);
        if ($context->isRegistered()) {
            $lines[] = 'Welcome back, ' . "\e[92m" . $context->username() . "\e[39m." . ($snark > 1 ? " Your last command was probably a mistake." : '');
        } else {
            $lines[] = 'Welcome, ' . "\e[92mguest\e[39m." . ($snark > 1 ? ' Rank: nobody. Type ' . "\e[93mlogin\e[39m" . ' to fix that.' : '');
        }
        $lines[] = "Type \e[93mhelp\e[39m for commands, \e[93mguide\e[39m for the tour." . ($snark > 1 ? " Or just mash keys. I'm used to it." : '');
        if ($context->forced) {
            $lines[] = "\e[90m" . ($snark > 0 ? 'This terminal is mandatory on this site. The mouse is decorative.' : 'This terminal is the only way to browse this site.') . "\e[39m";
        }
        $motd = $context->settings->getMotd();
        if ($motd !== '') {
            $lines[] = '';
            $lines[] = $motd;
        }

        return implode("\n", $lines) . "\n";
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
        $lines[] = "\e[93mPAGE NOT FOUND\e[39m";
        $lines[] = '';
        $lines[] = "\e[31m" . $context->snark->missingPage($path) . "\e[39m";
        $lines[] = '';
        if ($suggestions) {
            $lines[] = 'Did you mean one of these?';
            foreach ($suggestions as $name => $target) {
                $lines[] = '  ' . "\e[96m" . $target . "\e[39m";
            }
            $lines[] = '';
        }
        $lines[] = "You are now in \e[96m" . $closest . "\e[39m, the closest thing that exists. Try \e[93mls\e[39m, \e[93mcd /\e[39m or \e[93mfind <word>\e[39m." . ($context->snark->level() > 1 ? ' Or admit defeat with ' . "\e[93mexit\e[39m" . ' and I will take you home.' : '');
        $lines[] = "Type \e[93mhelp\e[39m for all commands, \e[93mguide\e[39m for the tour.";
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
