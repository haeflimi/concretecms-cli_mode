<?php

namespace CliMode\Terminal\Attitude;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * An attitude: the way the shell talks when something goes wrong (and a bit when nothing does). Extend this class,
 * list the messages you want to change and register it (see examples/PirateAttitude.php and the README).
 *
 * messages() maps a message key to a sprintf() format or to a pool of formats (one is picked at random). Arguments are
 * positional (%1$s, %2$s), so a message may leave one out. A key you leave out falls back to the polite message, so
 * an attitude can be as small as one line. The keys, with their arguments:
 *
 *   not_found     %1$s command            command not found
 *   did_you_mean  %1$s suggestion         second line after not_found, when there is a close match
 *   denied        %1$s command            permission denied
 *   usage         %1$s command, %2$s what wrong arguments or options (a second line shows the usage)
 *   no_such_path  %1$s command, %2$s path no such page
 *   not_directory %1$s command, %2$s path a page without children
 *   missing_page  %1$s path               the 404 welcome message
 *   parse_error   %1$s detail             the line could not be parsed
 *   failure       -                       a command crashed
 *   success       -                       after commands that have nothing to say (empty = say nothing)
 *   exit          -                       leaving the terminal
 *   trapped       -                       leaving a mandatory terminal
 *   sudo, rm      -                       the jokes
 *   help_footer   -                       last line of "help" (empty = none)
 */
abstract class Attitude
{
    /**
     * Unique and stable: it is what the dashboard saves. Lowercase letters, digits and underscores.
     */
    abstract public function handle(): string;

    /**
     * Name in the dashboard's list.
     */
    abstract public function name(): string;

    /**
     * One line under the name in the dashboard's list.
     */
    public function description(): string
    {
        return '';
    }

    /**
     * How much the commands may add on their own: 0 = nothing but plain shell messages, 1 = the occasional remark,
     * 2 = every chance for a roast. Commands with remarks of their own (guest greetings, "uptime" and so on) look at it.
     */
    public function level(): int
    {
        return 1;
    }

    /**
     * @return array<string, string|string[]> message key => format, or pool of formats
     */
    abstract public function messages(): array;

    /**
     * Messages of two attitudes in one: pools of the same key are joined, anything else is replaced. Handy for an
     * attitude that builds on another one.
     *
     * @param array<string, string|string[]> $base
     * @param array<string, string|string[]> $more
     *
     * @return array<string, string|string[]>
     */
    protected static function merge(array $base, array $more): array
    {
        foreach ($more as $key => $messages) {
            $base[$key] = array_merge((array) ($base[$key] ?? []), (array) $messages);
        }

        return $base;
    }
}
