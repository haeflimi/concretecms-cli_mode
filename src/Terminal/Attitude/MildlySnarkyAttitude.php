<?php

namespace CliMode\Terminal\Attitude;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The occasional remark: friendly teasing, nothing that stings.
 */
class MildlySnarkyAttitude extends Attitude
{
    public function handle(): string
    {
        return 'mildly_snarky';
    }

    public function name(): string
    {
        return t('Mildly snarky');
    }

    public function description(): string
    {
        return t('The occasional remark.');
    }

    public function messages(): array
    {
        return [
            'not_found' => [
                "%1\$s: command not found. It's not you, it's the command.",
                "%1\$s: command not found. Have you tried 'help'?",
                '%1$s: command not found. Typos happen to the best of us.',
            ],
            'did_you_mean' => "Did you mean '%1\$s'? I'd not bet my uptime on it, but still.",
            'denied' => [
                '%1$s: Permission denied. Rank has its privileges.',
                '%1$s: Permission denied. Try logging in first.',
            ],
            'usage' => "%1\$s: %2\$s\nThe arguments are not optional, even though your enthusiasm is.",
            'no_such_path' => '%1$s: %2$s: No such file or directory. Are you sure it exists?',
            'not_directory' => "%1\$s: %2\$s: Not a directory. It's a page without children.",
            'missing_page' => [
                '%1$s: No such file or directory. It was here a minute ago. Probably.',
                '%1$s: 404. The page has left the building.',
            ],
            'parse_error' => 'bash: syntax error: %1$s. Quotes come in pairs, like socks.',
            'failure' => 'Something broke. It was probably not your fault. Probably.',
            'success' => ['Done.', 'Sorted.', 'There you go.'],
            'exit' => ['logout. Come back soon.', 'logout. The terminal will miss you. A bit.'],
            'trapped' => 'exit: you cannot leave. There is no other way around this website.',
            'sudo' => 'sudo: you are not in the sudoers file. This incident will be reported.',
            'rm' => "rm: it is dangerous to operate recursively on '/'. Use --no-preserve-root if you really mean it (you do not).",
            'help_footer' => 'Tip: Tab completes, the arrow keys remember.',
            'login_failed' => ['Login incorrect. Caps lock, perhaps?', 'Login incorrect. Take a breath and try again.'],
            'login_welcome' => ['Welcome back, %1$s.', 'Welcome, %1$s. Good to see a face with a name.'],
        ];
    }
}
