<?php

namespace CliMode\Terminal\Attitude;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Plain shell messages, no remarks. The default, and the fallback for every message another attitude does not have.
 */
class PoliteAttitude extends Attitude
{
    public function handle(): string
    {
        return 'polite';
    }

    public function name(): string
    {
        return t('Polite');
    }

    public function description(): string
    {
        return t('Plain shell messages, no remarks.');
    }

    public function level(): int
    {
        return 0;
    }

    public function messages(): array
    {
        return [
            'not_found' => t('bash: %1$s: command not found'),
            'did_you_mean' => t("Did you mean '%1\$s'?"),
            'denied' => t('bash: %1$s: Permission denied'),
            'usage' => '%1$s: %2$s',
            'no_such_path' => t('%1$s: %2$s: No such file or directory'),
            'not_directory' => t('%1$s: %2$s: Not a directory'),
            'missing_page' => t('bash: %1$s: No such file or directory'),
            'parse_error' => t('bash: syntax error: %1$s'),
            'failure' => t('Segmentation fault (core dumped)'),
            'success' => '',
            'exit' => t('logout'),
            'trapped' => t('exit: not available in this terminal'),
            'sudo' => t('sudo: sorry, you are not allowed to use sudo'),
            'rm' => t("rm: it is dangerous to operate recursively on '/'"),
            'help_footer' => '',
            'login_failed' => t('Login incorrect'),
            'login_welcome' => t('Welcome, %1$s.'),
        ];
    }
}
