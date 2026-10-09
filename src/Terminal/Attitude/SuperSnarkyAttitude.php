<?php

namespace CliMode\Terminal\Attitude;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Every error is a roast. Builds on the mildly snarky messages: both pools are mixed.
 */
class SuperSnarkyAttitude extends Attitude
{
    public function handle(): string
    {
        return 'super_snarky';
    }

    public function name(): string
    {
        return t('Super snarky');
    }

    public function description(): string
    {
        return t('Every error is a roast.');
    }

    public function level(): int
    {
        return 2;
    }

    public function messages(): array
    {
        return self::merge((new MildlySnarkyAttitude())->messages(), [
            'not_found' => [
                t('%1$s: command not found. Bold of you to assume that exists.'),
                t('%1$s: command not found. Did a cat walk across the keyboard?'),
                t('%1$s: command not found. I looked everywhere. Twice. Mostly out of pity.'),
                t("%1\$s: command not found. My therapist says I should stop pretending it's my fault."),
                t('%1$s: command not found. That sounds like a problem for future you.'),
            ],
            'denied' => [
                t("%1\$s: Permission denied. Nice try, though. I'm proud of the audacity."),
                t('%1$s: Permission denied. This incident will be reported. To nobody, but still.'),
                t("%1\$s: Permission denied. You are not in the sudoers file. Not even in the kids' table."),
            ],
            'usage' => [
                t("%1\$s: %2\$s\nRTFM. It's right there. `help %1\$s`. It even has pictures. (It does not have pictures.)"),
                t("%1\$s: %2\$s\nI'd explain it again but I used up my patience on your last command."),
            ],
            'no_such_path' => [
                t('%1$s: %2$s: No such file or directory. Neither does your sense of direction.'),
                t('%1$s: %2$s: No such file or directory. I checked under the couch, too.'),
            ],
            'not_directory' => t("%1\$s: %2\$s: Not a directory. It's a dead end. Like most career plans."),
            'missing_page' => [
                t('%1$s: No such file or directory. The internet is a big place, and you chose a wrong spot in it.'),
                t('%1$s: 404. We looked everywhere. Under the couch, behind the router, in the recycle bin. Nothing.'),
                t('%1$s: 404. Somebody typed this address with confidence. That somebody was wrong.'),
                t('%1$s: 404. This page is in another castle.'),
            ],
            'parse_error' => t('bash: syntax error: %1$s. Even the shell is embarrassed for you.'),
            'failure' => [
                t('Segmentation fault (core dumped). Okay, it was me. I will deny everything.'),
                t('Kernel panic. Just kidding. But something went wrong, and I am blaming the intern.'),
            ],
            'success' => [
                t('Done. You are welcome.'),
                t('Done. Try not to break it.'),
                t('Done. Another triumph of man over machine.'),
                t('Executed. The machine did not even complain. Yet.'),
            ],
            'exit' => [
                t('logout. Leaving already? We were just getting passive-aggressive.'),
                t('logout. Do not let the door hit you on the way out. It is virtual. It will not.'),
            ],
            'trapped' => [
                t('exit: you can check out any time you like, but you can never leave.'),
                t('exit: there is no door. The mouse is decorative. Accept it.'),
                t('exit: the terminal is the website. You are the product.'),
            ],
            'sudo' => [
                t("sudo: you are not in the sudoers file. This incident will be reported.\n(To whom? Good question. Possibly Linus.)"),
                t('sudo: nice try. Great power requires great credentials, and you brought neither.'),
            ],
            'rm' => [
                t('rm: refusing to delete the whole website. We have a LAN to run on December 10th, genius.'),
                t('rm: no. Just no. Think of the children. And the seat map.'),
            ],
            'help_footer' => t('Tip: Tab completes, the arrow keys remember. You, on the other hand, apparently do not.'),
            'login_failed' => [
                t('Login incorrect. Either the password is wrong or you are. I am not saying which.'),
                t('Login incorrect. Was that your cat on the keyboard again?'),
            ],
            'login_welcome' => t('Welcome, %1$s. I would say I missed you, but I do not have the hardware.'),
        ]);
    }
}
