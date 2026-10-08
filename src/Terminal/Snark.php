<?php

namespace CliMode\Terminal;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The shell's attitude. Every error and feedback message of the built-in commands goes through here, so the level
 * 0 setting (plain) gives ordinary shell messages and levels 1 and 2 add remarks. Message pools are plain arrays:
 * "%s" is replaced by the subject (command, path, ...).
 */
class Snark
{
    /** @var int */
    protected $level;

    /** @var callable|null picks a message: function (array $pool): string */
    protected $picker;

    public function __construct(int $level = 2, ?callable $picker = null)
    {
        $this->level = $level;
        $this->picker = $picker;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function notFound(string $command, ?string $suggestion = null): string
    {
        $plain = sprintf('bash: %s: command not found', $command);
        $mild = [
            sprintf("%s: command not found. It's not you, it's the command.", $command),
            sprintf("%s: command not found. Have you tried 'help'?", $command),
            sprintf("%s: command not found. Typos happen to the best of us.", $command),
        ];
        $savage = [
            sprintf("%s: command not found. Bold of you to assume that exists.", $command),
            sprintf("%s: command not found. Did a cat walk across the keyboard?", $command),
            sprintf("%s: command not found. I looked everywhere. Twice. Mostly out of pity.", $command),
            sprintf("%s: command not found. My therapist says I should stop pretending it's my fault.", $command),
            sprintf("%s: command not found. That sounds like a problem for future you.", $command),
        ];
        $text = $this->pick($plain, $mild, $savage);
        if ($suggestion !== null) {
            $text .= $this->level > 0 ? sprintf("\nDid you mean '%s'? I'd not bet my uptime on it, but still.", $suggestion) : sprintf("\nDid you mean '%s'?", $suggestion);
        }

        return $text;
    }

    public function denied(string $command): string
    {
        return $this->pick(
            sprintf('bash: %s: Permission denied', $command),
            [sprintf('%s: Permission denied. Rank has its privileges.', $command), sprintf("%s: Permission denied. Try logging in first.", $command)],
            [sprintf("%s: Permission denied. Nice try, though. I'm proud of the audacity.", $command), sprintf("%s: Permission denied. This incident will be reported. To nobody, but still.", $command), sprintf("%s: Permission denied. You are not in the sudoers file. Not even in the kids' table.", $command)]
        );
    }

    public function usage(string $command, string $detail): string
    {
        $plain = sprintf('%s: %s', $command, $detail);

        return $this->pick(
            $plain,
            [$plain . "\nThe arguments are not optional, even though your enthusiasm is."],
            [$plain . "\nRTFM. It's right there. `help " . $command . '`. It even has pictures. (It does not have pictures.)', $plain . "\nI'd explain it again but I used up my patience on your last command."]
        );
    }

    public function noSuchPath(string $command, string $path): string
    {
        return $this->pick(
            sprintf('%s: %s: No such file or directory', $command, $path),
            [sprintf("%s: %s: No such file or directory. Are you sure it exists?", $command, $path)],
            [sprintf("%s: %s: No such file or directory. Neither does your sense of direction.", $command, $path), sprintf("%s: %s: No such file or directory. I checked under the couch, too.", $command, $path)]
        );
    }

    public function notDirectory(string $command, string $path): string
    {
        return $this->pick(
            sprintf('%s: %s: Not a directory', $command, $path),
            [sprintf("%s: %s: Not a directory. It's a page without children.", $command, $path)],
            [sprintf("%s: %s: Not a directory. It's a dead end. Like most career plans.", $command, $path)]
        );
    }

    public function failure(): string
    {
        return $this->pick(
            'Segmentation fault (core dumped)',
            ['Something broke. It was probably not your fault. Probably.'],
            ['Segmentation fault (core dumped). Okay, it was me. I will deny everything.', 'Kernel panic. Just kidding. But something went wrong, and I am blaming the intern.']
        );
    }

    public function parseError(string $detail): string
    {
        return $this->pick(
            'bash: syntax error: ' . $detail,
            ['bash: syntax error: ' . $detail . '. Quotes come in pairs, like socks.'],
            ['bash: syntax error: ' . $detail . '. Even the shell is embarrassed for you.']
        );
    }

    public function success(): ?string
    {
        if ($this->level === 0) {
            return null;
        }

        return $this->pick('', ['Done.', 'Sorted.', 'There you go.'], ['Done. You are welcome.', 'Done. Try not to break it.', 'Done. Another triumph of man over machine.', 'Executed. The machine did not even complain. Yet.']);
    }

    public function exit(): string
    {
        return $this->pick(
            'logout',
            ['logout. Come back soon.', "logout. The terminal will miss you. A bit."],
            ["logout. Leaving already? We were just getting passive-aggressive.", 'logout. Do not let the door hit you on the way out. It is virtual. It will not.']
        );
    }

    public function missingPage(string $path): string
    {
        return $this->pick(
            'bash: ' . $path . ': No such file or directory',
            [$path . ": No such file or directory. It was here a minute ago. Probably.", $path . ': 404. The page has left the building.'],
            [$path . ': No such file or directory. The internet is a big place, and you chose a wrong spot in it.', $path . ": 404. We looked everywhere. Under the couch, behind the router, in the recycle bin. Nothing.", $path . ': 404. Somebody typed this address with confidence. That somebody was wrong.', $path . ": 404. This page is in another castle."]
        );
    }

    public function trapped(): string
    {
        return $this->pick(
            'exit: not available in this terminal',
            ["exit: you cannot leave. There is no other way around this website."],
            ["exit: you can check out any time you like, but you can never leave.", 'exit: there is no door. The mouse is decorative. Accept it.', "exit: the terminal is the website. You are the product."]
        );
    }

    public function sudo(): string
    {
        return $this->pick(
            'sudo: sorry, you are not allowed to use sudo',
            ['sudo: you are not in the sudoers file. This incident will be reported.'],
            ["sudo: you are not in the sudoers file. This incident will be reported.\n(To whom? Good question. Possibly Linus.)", 'sudo: nice try. Great power requires great credentials, and you brought neither.']
        );
    }

    public function rm(): string
    {
        return $this->pick(
            "rm: it is dangerous to operate recursively on '/'",
            ["rm: it is dangerous to operate recursively on '/'. Use --no-preserve-root if you really mean it (you do not)."],
            ["rm: refusing to delete the whole website. We have a LAN to run on December 10th, genius.", "rm: no. Just no. Think of the children. And the seat map."]
        );
    }

    public function emptyHelpFooter(): string
    {
        return $this->pick('', ['Tip: Tab completes, the arrow keys remember.'], ['Tip: Tab completes, the arrow keys remember. You, on the other hand, apparently do not.']);
    }

    /**
     * @param string $plain message at level 0
     * @param string[] $mild pool at level 1
     * @param string[] $savage pool at level 2
     */
    protected function pick(string $plain, array $mild, array $savage): string
    {
        if ($this->level === 0) {
            return $plain;
        }
        $pool = $this->level === 1 ? $mild : array_merge($mild, $savage);
        if ($this->picker) {
            return (string) call_user_func($this->picker, $pool);
        }

        return $pool[array_rand($pool)];
    }
}
