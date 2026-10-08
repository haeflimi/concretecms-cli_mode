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
            'not_found' => 'bash: %1$s: command not found',
            'did_you_mean' => "Did you mean '%1\$s'?",
            'denied' => 'bash: %1$s: Permission denied',
            'usage' => '%1$s: %2$s',
            'no_such_path' => '%1$s: %2$s: No such file or directory',
            'not_directory' => '%1$s: %2$s: Not a directory',
            'missing_page' => 'bash: %1$s: No such file or directory',
            'parse_error' => 'bash: syntax error: %1$s',
            'failure' => 'Segmentation fault (core dumped)',
            'success' => '',
            'exit' => 'logout',
            'trapped' => 'exit: not available in this terminal',
            'sudo' => 'sudo: sorry, you are not allowed to use sudo',
            'rm' => "rm: it is dangerous to operate recursively on '/'",
            'help_footer' => '',
        ];
    }
}
