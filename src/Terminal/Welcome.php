<?php

namespace CliMode\Terminal;

use Concrete\Core\Localization\Localization;
use Symfony\Component\HttpFoundation\Request;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The welcome message of the terminal (banner, greeting, message of the day) and the hint to switch to the browser's
 * language. Shown at boot and again by "lang", in the language the terminal speaks at that moment.
 */
class Welcome
{
    /** @var Context */
    protected $context;

    /** @var Request */
    protected $request;

    public function __construct(Context $context, Request $request)
    {
        $this->context = $context;
        $this->request = $request;
    }

    public function banner(): string
    {
        $snark = $this->context->snark->level();
        $ascii = $this->context->settings->getBanner() ?: implode("\n", [
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
            "\e[90m" . t('%1$s on %2$s (%3$s)', 'snarkbash 1.0', $this->context->hostname(), $this->context->isAdmin() ? t('admin') : ($this->context->isRegistered() ? t('member') : t('visitor'))) . "\e[39m",
            '',
        ]);
        $hint = $this->languageHint();
        if ($hint !== '') {
            $lines[] = $hint;
            $lines[] = '';
        }
        if ($this->context->isRegistered()) {
            $lines[] = t('Welcome back, %s.', "\e[92m" . $this->context->username() . "\e[39m") . ($snark > 1 ? ' ' . t('Your last command was probably a mistake.') : '');
        } else {
            $lines[] = t('Welcome, %s.', "\e[92m" . t('guest') . "\e[39m") . ($snark > 1 ? ' ' . t('Rank: nobody. Type %s to fix that.', "\e[93mlogin\e[39m") : '');
        }
        $lines[] = t('Type %1$s for commands, %2$s for the tour.', "\e[93mhelp\e[39m", "\e[93mguide\e[39m") . ($snark > 1 ? ' ' . t("Or just mash keys. I'm used to it.") : '');
        if ($this->context->registry->resolve('easy') !== null) {
            // for everybody who came for the signup and not for a shell
            $lines[] = t('New to terminals? Type %s and I guide you through the signup, step by step.', "\e[93measy\e[39m");
        }
        if ($this->context->forced) {
            $lines[] = "\e[90m" . ($snark > 0 ? t('This terminal is mandatory on this site. The mouse is decorative.') : t('This terminal is the only way to browse this site.')) . "\e[39m";
        }
        $motd = $this->context->settings->getMotd();
        if ($motd !== '') {
            $lines[] = '';
            $lines[] = $motd;
        }
        if ($hint !== '') {
            // once more right above the prompt: on a phone the top of a long welcome has scrolled away
            $lines[] = '';
            $lines[] = $hint;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A box in the browser's language telling how to switch to it, as long as the session never chose a language and
     * the browser prefers one the terminal speaks but is not speaking. Empty otherwise.
     */
    public function languageHint(): string
    {
        if ($this->context->state->lang !== null) {
            return '';
        }
        $site = (string) $this->context->app->make('site')->getSite()->getDefaultLocale()->getLocale();
        $wanted = Settings::preferredLocale($this->request->getLanguages(), $site);
        $current = Localization::activeLocale();
        if ($wanted === null || $wanted === $current) {
            return '';
        }
        // "lang de" picks the site's variant: name the full locale only when that is not the one the browser wants
        $command = 'lang ' . (Settings::preferredLocale([strtok($wanted, '_')], $site) === $wanted ? strtok($wanted, '_') : $wanted);
        Localization::changeLocale($wanted);
        $text = t('Prefer English? Type: %s', $command);
        Localization::changeLocale($current);
        $width = mb_strlen($text) + 2;

        // plain ASCII: not every monospace font draws the box characters
        $line = '+' . str_repeat('-', $width) . '+';

        return "\e[93m" . $line . "\n| \e[1m" . str_replace($command, "\e[96m" . $command . "\e[93m", $text) . "\e[22m |\n" . $line . "\e[39m";
    }
}
