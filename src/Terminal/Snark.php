<?php

namespace CliMode\Terminal;

use CliMode\Terminal\Attitude\Attitude;
use CliMode\Terminal\Attitude\PoliteAttitude;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The shell's voice. Every error and feedback message of the built-in commands goes through here; the words come
 * from the Attitude (polite, mildly snarky, super snarky or one of your own, see Attitude\Attitude). A message the
 * attitude does not have is the polite one.
 */
class Snark
{
    /** @var Attitude */
    protected $attitude;

    /** @var array<string, string|string[]> */
    protected $messages;

    /** @var array<string, string|string[]> */
    protected $fallback;

    /** @var callable|null picks a message: function (array $pool): string */
    protected $picker;

    public function __construct(?Attitude $attitude = null, ?callable $picker = null)
    {
        $this->attitude = $attitude ?: new PoliteAttitude();
        $this->messages = $this->attitude->messages();
        $this->fallback = (new PoliteAttitude())->messages();
        $this->picker = $picker;
    }

    public function attitude(): Attitude
    {
        return $this->attitude;
    }

    /**
     * 0 = plain, 1 = the occasional remark, 2 = full sass: what commands with remarks of their own go by.
     */
    public function level(): int
    {
        return $this->attitude->level();
    }

    public function notFound(string $command, ?string $suggestion = null): string
    {
        $text = $this->say('not_found', $command);
        if ($suggestion !== null) {
            $text .= "\n" . $this->say('did_you_mean', $suggestion);
        }

        return $text;
    }

    public function denied(string $command): string
    {
        return $this->say('denied', $command);
    }

    public function usage(string $command, string $detail): string
    {
        return $this->say('usage', $command, $detail);
    }

    public function noSuchPath(string $command, string $path): string
    {
        return $this->say('no_such_path', $command, $path);
    }

    public function notDirectory(string $command, string $path): string
    {
        return $this->say('not_directory', $command, $path);
    }

    public function failure(): string
    {
        return $this->say('failure');
    }

    public function parseError(string $detail): string
    {
        return $this->say('parse_error', $detail);
    }

    /**
     * @return string|null null when the attitude has nothing to add
     */
    public function success(): ?string
    {
        $text = $this->say('success');

        return $text === '' ? null : $text;
    }

    public function exit(): string
    {
        return $this->say('exit');
    }

    public function missingPage(string $path): string
    {
        return $this->say('missing_page', $path);
    }

    public function trapped(): string
    {
        return $this->say('trapped');
    }

    public function sudo(): string
    {
        return $this->say('sudo');
    }

    public function rm(): string
    {
        return $this->say('rm');
    }

    public function emptyHelpFooter(): string
    {
        return $this->say('help_footer');
    }

    /**
     * A message of the attitude by key (the keys are listed in Attitude\Attitude), for commands that bring their own:
     * $c->snark->say('my_key', $arg) works when the attitude has a "my_key" message, and gives an empty string when it
     * has not.
     *
     * @param string|int ...$args fill %1$s, %2$s, ... of the message
     */
    public function say(string $key, ...$args): string
    {
        $messages = $this->messages[$key] ?? $this->fallback[$key] ?? '';
        $pool = [];
        foreach ((array) $messages as $format) {
            $pool[] = vsprintf((string) $format, $args);
        }
        if ($pool === []) {
            return '';
        }
        if ($this->picker) {
            return (string) call_user_func($this->picker, $pool);
        }

        return $pool[array_rand($pool)];
    }
}
