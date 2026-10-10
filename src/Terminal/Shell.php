<?php

namespace CliMode\Terminal;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Logging\LoggerFactory;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Runs a line the way a shell does: quoting, $VARIABLES, aliases, pipes (|) and command lists (;, &&, ||), then
 * hands each command to the Symfony application. Output is ANSI text, the browser turns it into colors.
 */
class Shell
{
    public const MAX_LINE = 1000;
    protected const MAX_COMMANDS = 12;
    protected const MAX_OUTPUT = 120000;

    /** @var Context */
    protected $context;

    /** @var CommandRegistry */
    protected $registry;

    /** @var Application|null */
    protected $application;

    public function __construct(Context $context, CommandRegistry $registry)
    {
        $this->context = $context;
        $this->registry = $registry;
    }

    /**
     * @return array{output: string, code: int, actions: array, ask: array|null}
     */
    public function run(string $line): array
    {
        $this->context->actions = [];
        // a line typed as a command ends a dialog that was left waiting
        $this->context->state->dialog = null;
        $line = trim($line);
        $output = '';
        $code = $this->context->state->lastCode;
        if ($line !== '') {
            if (strlen($line) > self::MAX_LINE) {
                $output = $this->context->snark->parseError(t('line too long (%d characters, and none of them helpful)', strlen($line))) . "\n";
                $code = 2;
            } else {
                $this->context->state->remember($line);
                [$output, $code] = $this->runList($line);
            }
            $this->context->state->lastCode = $code;
        }
        $ask = null;
        if ($this->context->pendingDialog) {
            [$more, $ask] = $this->startPending();
            $output .= $more;
        }
        $this->context->state->save();
        if (strlen($output) > self::MAX_OUTPUT) {
            $output = substr($output, 0, self::MAX_OUTPUT) . "\n[" . t('output truncated') . "]\n";
        }

        return ['output' => $output, 'code' => $code, 'actions' => $this->context->actions, 'ask' => $ask ? $ask->toArray() : null];
    }

    /**
     * An answer to the question of the waiting dialog (see Dialog), or its cancellation. Never part of the history.
     *
     * @return array{output: string, code: int, actions: array, ask: array|null}
     */
    public function answer(string $value, bool $cancel = false): array
    {
        $this->context->actions = [];
        $state = $this->context->state;
        $current = $state->dialog();
        $output = $this->newOutput();
        $ask = null;
        $code = 0;
        if ($current === null) {
            $output->writeln('<dim>' . t('(That question has expired. Just start over.)') . '</dim>');
            $code = 1;
        } else {
            $dialog = $current[0];
            try {
                if ($cancel) {
                    $dialog->cancel($this->context, $output);
                    $code = 130;
                } else {
                    [$dialog, $ask] = $this->follow($dialog, $dialog->answer($this->context, $value, $output), $output);
                }
            } catch (\Throwable $e) {
                $this->log('dialog', $e);
                $output->writeln('<error> ' . OutputFormatter::escape($this->context->snark->failure()) . ' </error>');
                $ask = null;
                $code = 1;
            }
            $state->setDialog($ask ? $dialog : null, $ask);
        }
        $state->save();

        return ['output' => $output->fetch(), 'code' => $code, 'actions' => $this->context->actions, 'ask' => $ask ? $ask->toArray() : null];
    }

    /**
     * One command (no lists, no pipes) for a dialog that acts through the regular commands.
     *
     * @return array{0: string, 1: int} output and exit code
     */
    public function command(string $text): array
    {
        return $this->runCommand($text);
    }

    /**
     * Starts the dialog the last command asked for.
     *
     * @return array{0: string, 1: Question|null}
     */
    protected function startPending(): array
    {
        $dialog = $this->context->pendingDialog;
        $this->context->pendingDialog = null;
        $output = $this->newOutput();
        $ask = null;
        try {
            [$dialog, $ask] = $this->follow($dialog, $dialog->start($this->context, $output), $output);
        } catch (\Throwable $e) {
            $this->log('dialog', $e);
            $output->writeln('<error> ' . OutputFormatter::escape($this->context->snark->failure()) . ' </error>');
            $ask = null;
        }
        $this->context->state->setDialog($ask ? $dialog : null, $ask);

        return [$output->fetch(), $ask];
    }

    /**
     * A dialog that ended may hand over to the next one.
     *
     * @return array{0: Dialog, 1: Question|null}
     */
    protected function follow(Dialog $dialog, ?Question $ask, OutputInterface $output): array
    {
        for ($hops = 0; $ask === null && $dialog->next() && $hops < 5; ++$hops) {
            $dialog = $dialog->next();
            $ask = $dialog->start($this->context, $output);
        }

        return [$dialog, $ask];
    }

    protected function newOutput(): BufferedOutput
    {
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        foreach (['dim' => ['gray'], 'accent' => ['bright-yellow', null, ['bold']], 'snark' => ['bright-magenta'], 'dir' => ['bright-blue', null, ['bold']], 'ok' => ['bright-green'], 'warn' => ['yellow']] as $style => $def) {
            $output->getFormatter()->setStyle($style, new \Symfony\Component\Console\Formatter\OutputFormatterStyle($def[0], $def[1] ?? null, $def[2] ?? []));
        }

        return $output;
    }

    protected function log(string $what, \Throwable $e): void
    {
        try {
            $this->context->app->make(LoggerFactory::class)->createLogger('cli_mode')->error($what . ': ' . $e->getMessage(), ['exception' => $e]);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Command names (and aliases, variables) or arguments that complete the last word of the line.
     *
     * @return array{word: string, matches: string[]}
     */
    public function complete(string $line): array
    {
        $segments = $this->splitLine($line, true);
        $last = $segments ? end($segments) : ['text' => ''];
        $text = ltrim($last['text']);
        $endsWithSpace = $line !== '' && ctype_space(substr($line, -1)) && $text !== '';
        $words = $text === '' ? [] : preg_split('/\s+/', $text);
        if ($endsWithSpace || $text === '') {
            $words[] = '';
        }
        $word = (string) array_pop($words);
        if ($words === []) {
            $names = array_merge($this->registry->names($this->context), array_keys($this->context->state->aliases));
            $matches = array_values(array_unique(array_filter($names, static function ($n) use ($word) {
                return $word === '' || strpos($n, $word) === 0;
            })));
            sort($matches);

            return ['word' => $word, 'matches' => $matches];
        }
        $name = $this->expandAlias($words[0]);
        $command = $this->registry->resolve(explode(' ', $name)[0]);
        $matches = [];
        if ($command && $command->isAllowedFor($this->context)) {
            $command->setContext($this->context);
            if (strpos($word, '-') === 0) {
                foreach ($command->getDefinition()->getOptions() as $option) {
                    $matches[] = '--' . $option->getName();
                }
                $matches[] = '--help';
                $matches = array_values(array_unique(array_filter($matches, static function ($m) use ($word) {
                    return strpos($m, $word) === 0;
                })));
            } else {
                $matches = $command->completeWords($word, array_slice($words, 1));
            }
        }
        sort($matches);

        return ['word' => $word, 'matches' => array_values($matches)];
    }

    /**
     * @return array{0: string, 1: int} output and exit code of a whole line
     */
    protected function runList(string $line): array
    {
        try {
            $segments = $this->splitLine($line);
        } catch (\InvalidArgumentException $e) {
            return [$this->red($this->context->snark->parseError($e->getMessage())) . "\n", 2];
        }
        if (count($segments) > self::MAX_COMMANDS) {
            return [$this->red($this->context->snark->parseError(t('too many commands in one line, this is a shell, not a novel'))) . "\n", 2];
        }

        // groups of piped commands, each with the operator that connects it to the previous group
        $groups = [];
        foreach ($segments as $segment) {
            if ($segment['sep'] === '|' && $groups) {
                $groups[count($groups) - 1]['commands'][] = $segment['text'];
            } else {
                $groups[] = ['sep' => $segment['sep'], 'commands' => [$segment['text']]];
            }
        }

        $output = '';
        $code = $this->context->state->lastCode;
        foreach ($groups as $group) {
            if (($group['sep'] === '&&' && $code !== 0) || ($group['sep'] === '||' && $code === 0)) {
                continue;
            }
            [$out, $code] = $this->runPipeline($group['commands']);
            $output .= $out;
            $this->context->state->lastCode = $code;
        }

        return [$output, $code];
    }

    /**
     * @param string[] $commands
     *
     * @return array{0: string, 1: int}
     */
    protected function runPipeline(array $commands): array
    {
        $stdin = null;
        $out = '';
        $code = 0;
        foreach ($commands as $i => $text) {
            $this->context->stdin = $stdin;
            [$out, $code] = $this->runCommand($text);
            if ($i < count($commands) - 1) {
                if ($code !== 0) {
                    break;
                }
                $stdin = preg_replace('/\e\[[0-9;]*m/', '', $out);
                $out = '';
            }
        }
        $this->context->stdin = null;

        return [$out, $code];
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function runCommand(string $text): array
    {
        $snark = $this->context->snark;
        $text = trim($this->expandVariables($text));
        if ($text === '') {
            return ['', 0];
        }
        $text = $this->expandAlias($text);
        $name = (string) preg_split('/\s+/', $text, 2)[0];

        $command = $this->registry->resolve($name);
        if ($command === null) {
            return [$this->red($snark->notFound($name, $this->suggest($name))) . "\n", 127];
        }
        if (!$command->isAllowedFor($this->context)) {
            return [$this->red($snark->denied($name)) . "\n", 126];
        }

        $output = $this->newOutput();
        try {
            $code = $this->application()->doRun(new StringInput($text), $output);
        } catch (ConsoleException | \InvalidArgumentException $e) {
            $usage = $command->getSynopsis(true);

            return [$this->red($snark->usage($name, $e->getMessage())) . "\n" . t('Usage: %s', $usage) . "\n", 2];
        } catch (UserMessageException $e) {
            return [$output->fetch() . $this->red($name . ': ' . $e->getMessage()) . "\n", 1];
        } catch (\Throwable $e) {
            $this->log($name, $e);

            return [$output->fetch() . $this->red($snark->failure()) . "\n", 139];
        }

        return [$output->fetch(), (int) $code];
    }

    protected function application(): Application
    {
        if ($this->application === null) {
            $this->application = new Application($this->context, $this->registry);
        }

        return $this->application;
    }

    protected function red(string $text): string
    {
        return "\e[31m" . $text . "\e[39m";
    }

    /**
     * The closest command name, if one is close enough to be a typo.
     */
    protected function suggest(string $name): ?string
    {
        $best = null;
        $bestDistance = 3;
        foreach ($this->registry->names($this->context) as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance && $distance < max(2, strlen($name) - 1)) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    protected function expandAlias(string $text): string
    {
        $parts = preg_split('/\s+/', ltrim($text), 2);
        $alias = $this->context->state->aliases[$parts[0]] ?? null;

        return $alias !== null ? $alias . (isset($parts[1]) ? ' ' . $parts[1] : '') : $text;
    }

    /**
     * $NAME, ${NAME} and ~ outside of single quotes.
     */
    protected function expandVariables(string $text): string
    {
        $out = '';
        $quote = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $c = $text[$i];
            if ($c === '\\' && $quote !== "'" && $i + 1 < $length) {
                $out .= $c . $text[++$i];
                continue;
            }
            if ($quote === null && ($c === "'" || $c === '"')) {
                $quote = $c;
            } elseif ($c === $quote) {
                $quote = null;
            } elseif ($c === '$' && $quote !== "'" && preg_match('/\G(?:\{([A-Za-z_][A-Za-z0-9_]*|\?)\}|([A-Za-z_][A-Za-z0-9_]*|\?))/', $text, $m, 0, $i + 1)) {
                $name = $m[1] !== '' ? $m[1] : $m[2];
                $out .= (string) $this->context->env($name);
                $i += strlen($m[0]);
                continue;
            } elseif ($c === '~' && $quote === null && ($i === 0 || ctype_space($text[$i - 1])) && ($i + 1 === $length || $text[$i + 1] === '/' || ctype_space($text[$i + 1]))) {
                $out .= '/';
                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    /**
     * Splits a line at ; && || | outside of quotes. Each part carries the operator in front of it.
     *
     * @return array<int, array{sep: string, text: string}>
     */
    protected function splitLine(string $line, bool $lenient = false): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $sep = '';
        $length = strlen($line);
        $flush = static function () use (&$parts, &$buffer, &$sep) {
            $parts[] = ['sep' => $sep, 'text' => $buffer];
            $buffer = '';
        };
        for ($i = 0; $i < $length; ++$i) {
            $c = $line[$i];
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $quote === '"' && $i + 1 < $length) {
                    $buffer .= $line[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '\\' && $i + 1 < $length) {
                $buffer .= $c . $line[++$i];
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $buffer .= $c;
                continue;
            }
            if ($c === '#' && ($buffer === '' || ctype_space($line[$i - 1]))) {
                break;
            }
            $next = $line[$i + 1] ?? '';
            if ($c === ';' || ($c === '&' && $next === '&') || ($c === '|')) {
                $flush();
                $sep = $c === ';' ? ';' : ($c === '&' ? '&&' : ($next === '|' ? '||' : '|'));
                $i += strlen($sep) - 1;
                continue;
            }
            $buffer .= $c;
        }
        if ($quote !== null && !$lenient) {
            throw new \InvalidArgumentException(t("unexpected EOF while looking for matching `%s'", $quote));
        }
        if (trim($buffer) !== '' || $parts === [] || $sep !== '') {
            $flush();
        }

        return array_values(array_filter($parts, static function ($p) use ($lenient) {
            return $lenient || trim($p['text']) !== '';
        }));
    }
}
