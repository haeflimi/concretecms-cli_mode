<?php

namespace CliMode\Command\System;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\AttitudeRegistry;
use CliMode\Terminal\Snark;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * attitude: lists the attitudes and switches between them for the current session. The site's setting in the
 * dashboard stays what it is; it is what everybody else (and this session after --reset) gets.
 */
class AttitudeCommand extends TerminalCommand
{
    protected static $defaultName = 'attitude';
    protected $category = 'system';

    protected function configure(): void
    {
        $this->setDescription(t('Shows or changes how the terminal talks to you (this session only)'))
            ->setAliases(['mood'])
            ->addArgument('name', InputArgument::OPTIONAL, t('The attitude to switch to'))
            ->addOption('reset', 'r', InputOption::VALUE_NONE, t("Back to the site's attitude"))
            ->setHelp(t("Without an argument: the attitudes, the one you have marked.\nThe change lasts as long as your session and affects nobody else.") . "\n\n" . t('Examples:') . "\n  attitude\n  attitude super_snarky\n  attitude --reset");
    }

    public function completeWords(string $word, array $words): array
    {
        $handles = array_keys($this->context()->app->make(AttitudeRegistry::class)->all());

        return array_values(array_filter($handles, static function ($h) use ($word) {
            return $word === '' || strpos($h, $word) === 0;
        }));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();
        $registry = $c->app->make(AttitudeRegistry::class);
        $site = $registry->get($c->settings->getAttitude())->handle();
        $name = trim((string) $input->getArgument('name'));

        if ($input->getOption('reset')) {
            $c->state->attitude = null;
            $name = $site;
        } elseif ($name === '') {
            $current = $c->snark->attitude()->handle();
            foreach ($registry->all() as $handle => $attitude) {
                $output->writeln(sprintf(
                    '%s <info>%-16s</info> %s%s',
                    $handle === $current ? '<accent>*</accent>' : ' ',
                    $handle,
                    OutputFormatter::escape($attitude->name()),
                    $attitude->description() !== '' ? ' <dim>' . OutputFormatter::escape($attitude->description()) . ($handle === $site ? ' ' . t('(site default)') : '') . '</dim>' : ($handle === $site ? ' <dim>' . t('(site default)') . '</dim>' : '')
                ));
            }
            $output->writeln('<dim>' . t('attitude <name> switches, attitude --reset goes back to the site default.') . '</dim>');

            return 0;
        }

        $attitudes = $registry->all();
        if (!isset($attitudes[$name])) {
            $output->writeln('<error> ' . $c->snark->noSuchPath('attitude', $name) . ' </error>');
            $output->writeln('<dim>' . t('Known: %s', OutputFormatter::escape(implode(', ', array_keys($attitudes)))) . '</dim>');

            return 1;
        }
        if (!$input->getOption('reset')) {
            $c->state->attitude = $name === $site ? null : $name;
        }
        // the rest of the line (after && or a pipe) already speaks the new attitude
        $c->snark = new Snark($attitudes[$name]);
        $output->writeln('<ok>' . t('Attitude: %s', OutputFormatter::escape($attitudes[$name]->name())) . '</ok>');
        if (($remark = $c->snark->success()) !== null) {
            $output->writeln('<snark>' . OutputFormatter::escape($remark) . '</snark>');
        }

        return 0;
    }
}
