<?php

namespace CliMode\Terminal;

use Concrete\Core\Session\SessionValidator;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What a shell session remembers between two requests (working directory, variables, aliases, history, last exit
 * code), kept in the PHP session.
 */
class State
{
    protected const KEY = 'cli_mode.state';
    protected const MAX_HISTORY = 200;

    /** @var string */
    public $cwd = '/';

    /** @var array<string, string> */
    public $env = [];

    /** @var array<string, string> */
    public $aliases = ['ll' => 'ls -l', 'la' => 'ls -la', '..' => 'cd ..'];

    /** @var string[] */
    public $history = [];

    /** @var int */
    public $lastCode = 0;

    /** @var string|null handle of the attitude this session switched to, null = the site's */
    public $attitude;

    /** @var string|null locale this session switched to with "lang", null = never chose (English) */
    public $lang;

    /** @var int */
    public $startedAt;

    /** @var array{class: string, data: array, ask: array, at: int}|null the dialog that waits for an answer */
    public $dialog;

    /** @var SessionInterface */
    protected $session;

    public function __construct(SessionInterface $session)
    {
        $this->session = $session;
        $data = $session->get(self::KEY);
        $this->startedAt = time();
        if (is_array($data)) {
            foreach (['cwd', 'env', 'aliases', 'history', 'lastCode', 'startedAt', 'attitude', 'lang', 'dialog'] as $field) {
                if (array_key_exists($field, $data)) {
                    $this->$field = $data[$field];
                }
            }
        }
    }

    public function remember(string $line): void
    {
        $this->history[] = $line;
        $this->history = array_slice($this->history, -self::MAX_HISTORY);
    }

    public function reset(): void
    {
        $this->cwd = '/';
        $this->env = [];
        $this->history = [];
        $this->lastCode = 0;
    }

    /**
     * The dialog that waits for an answer, unless it was left alone for half an hour.
     *
     * @return array{0: Dialog, 1: Question}|null
     */
    public function dialog(): ?array
    {
        $d = $this->dialog;
        if (!is_array($d) || ($d['at'] ?? 0) < time() - 1800 || !is_subclass_of((string) ($d['class'] ?? ''), Dialog::class)) {
            $this->dialog = null;

            return null;
        }

        return [new $d['class']((array) $d['data']), Question::fromArray((array) $d['ask'])];
    }

    public function setDialog(?Dialog $dialog, ?Question $ask): void
    {
        $this->dialog = $dialog && $ask ? ['class' => get_class($dialog), 'data' => $dialog->data(), 'ask' => $ask->toArray(), 'at' => time()] : null;
    }

    public function save(): void
    {
        $this->session->set(self::KEY, [
            'cwd' => $this->cwd,
            'env' => $this->env,
            'aliases' => $this->aliases,
            'history' => $this->history,
            'lastCode' => $this->lastCode,
            'startedAt' => $this->startedAt,
            'attitude' => $this->attitude,
            'lang' => $this->lang,
            'dialog' => $this->dialog,
        ]);
    }
}
