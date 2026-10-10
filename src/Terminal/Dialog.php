<?php

namespace CliMode\Terminal;

use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * A conversation in the terminal: a command starts it with $context->startDialog(), then every line the visitor
 * types goes to answer() instead of the shell, until it returns no further Question. Answers are posted with
 * "answer" set and never end up in the history (so a password may be one). Ctrl+C or Esc calls cancel().
 *
 * Between two requests the dialog lives in the session: only $data is kept, so put everything you need to remember
 * there (and nothing secret you do not need: a password is better kept as a hash).
 */
abstract class Dialog
{
    /** @var array<string, mixed> */
    protected $data = [];

    /** @var Dialog|null the dialog that takes over when this one ends */
    protected $next;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Says hello and asks the first question (null: nothing to ask, the dialog is over at once).
     */
    abstract public function start(Context $c, OutputInterface $out): ?Question;

    /**
     * Takes an answer and asks the next question (null: done).
     */
    abstract public function answer(Context $c, string $answer, OutputInterface $out): ?Question;

    public function cancel(Context $c, OutputInterface $out): void
    {
        $out->writeln('<dim>' . t('Cancelled. Nothing was changed.') . '</dim>');
    }

    public function data(): array
    {
        return $this->data;
    }

    public function next(): ?Dialog
    {
        return $this->next;
    }

    /**
     * Ends this dialog and lets another one continue (its start() is called right away).
     */
    protected function handOver(Dialog $dialog): ?Question
    {
        $this->next = $dialog;

        return null;
    }

    /**
     * true for yes, false for no, null when the answer is neither (in English, German or French).
     */
    public static function yesNo(string $answer): ?bool
    {
        $answer = mb_strtolower(trim($answer));
        if (in_array($answer, ['y', 'yes', 'j', 'ja', 'jo', 'o', 'oui', 'ok', 'sure', 'klar', 'gern', 'gerne'], true)) {
            return true;
        }
        if (in_array($answer, ['n', 'no', 'nein', 'nö', 'non', 'nope'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Runs a command line the way the visitor would type it and prints what it says.
     *
     * @return int its exit code
     */
    protected function run(Context $c, OutputInterface $out, string $line): int
    {
        [$text, $code] = (new Shell($c, $c->registry))->command($line);
        if ($text !== '') {
            $out->write($text, false, OutputInterface::OUTPUT_RAW);
        }

        return $code;
    }
}
