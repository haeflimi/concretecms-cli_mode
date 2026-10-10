<?php

namespace CliMode\Command\System;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\Settings;
use CliMode\Terminal\Welcome;
use Concrete\Core\Http\Request;
use Concrete\Core\Localization\Localization;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * lang: lists the languages the terminal speaks and switches between them for the current session. English is the
 * default, whatever language the website itself is in.
 */
class LanguageCommand extends TerminalCommand
{
    protected static $defaultName = 'lang';
    protected $category = 'system';

    protected function configure(): void
    {
        $this->setDescription(t('Shows or changes the language of the terminal (this session only)'))
            ->setAliases(['language', 'sprache'])
            ->addArgument('language', InputArgument::OPTIONAL, t('The language to switch to, e.g. en, de or de_CH'))
            ->setHelp(t('Without an argument: the languages, the current one marked.') . "\n\n" . t('Examples:') . "\n  lang\n  lang de\n  lang de_CH\n  lang en");
    }

    public function completeWords(string $word, array $words): array
    {
        $codes = [];
        foreach (array_keys(Settings::languages()) as $locale) {
            $codes[] = strtolower(substr($locale, 0, 2));
            $codes[] = $locale;
        }

        return array_values(array_filter(array_unique($codes), static function ($code) use ($word) {
            return $word === '' || stripos($code, $word) === 0;
        }));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();
        $languages = Settings::languages();
        $wanted = trim((string) $input->getArgument('language'));
        $current = Settings::locale($c->state->lang);

        if ($wanted === '') {
            foreach ($languages as $locale => $name) {
                $output->writeln(sprintf('%s <info>%-6s</info> %s', $locale === $current ? '<accent>*</accent>' : ' ', $locale, OutputFormatter::escape($name)));
            }
            $output->writeln('<dim>' . t('lang <language> switches, e.g. lang de or lang en.') . '</dim>');

            return 0;
        }

        $locale = $this->match($wanted, array_keys($languages));
        if ($locale === null) {
            $output->writeln('<error> ' . t('lang: no such language: %s', OutputFormatter::escape($wanted)) . ' </error>');
            $output->writeln('<dim>' . t('Known: %s', implode(', ', array_keys($languages))) . '</dim>');

            return 1;
        }
        // kept even for English: a choice is a choice, the hint to switch language stops after it
        $c->state->lang = $locale;
        // the rest of this answer, and every one after it, in the new language
        Localization::changeLocale($locale);
        $c->addAction('labels', Settings::labels());
        $output->writeln('<ok>' . t('Language: %s', OutputFormatter::escape($languages[$locale])) . '</ok>');
        // and the welcome once more, now in that language (raw ANSI: the formatter must not touch it)
        $output->writeln('');
        $output->write((new Welcome($c, $c->app->make(Request::class)))->banner(), false, OutputInterface::OUTPUT_RAW);

        return 0;
    }

    /**
     * "de_CH", "de-ch", or just "de": a bare language takes the website's own variant when it has one (de → de_CH on a
     * Swiss site), else the first.
     *
     * @param string[] $locales
     */
    protected function match(string $wanted, array $locales): ?string
    {
        $wanted = strtolower(str_replace('-', '_', $wanted));
        foreach ($locales as $locale) {
            if (strtolower($locale) === $wanted) {
                return $locale;
            }
        }
        $candidates = array_values(array_filter($locales, static function ($locale) use ($wanted) {
            return strtolower(strtok($locale, '_')) === $wanted;
        }));
        if ($candidates === []) {
            return null;
        }
        $site = (string) $this->context()->app->make('site')->getSite()->getDefaultLocale()->getLocale();

        return in_array($site, $candidates, true) ? $site : $candidates[0];
    }
}
