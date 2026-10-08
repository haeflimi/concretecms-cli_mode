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

    /** @var int */
    public $startedAt;

    /** @var SessionInterface */
    protected $session;

    public function __construct(SessionInterface $session)
    {
        $this->session = $session;
        $data = $session->get(self::KEY);
        $this->startedAt = time();
        if (is_array($data)) {
            foreach (['cwd', 'env', 'aliases', 'history', 'lastCode', 'startedAt'] as $field) {
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

    public function save(): void
    {
        $this->session->set(self::KEY, [
            'cwd' => $this->cwd,
            'env' => $this->env,
            'aliases' => $this->aliases,
            'history' => $this->history,
            'lastCode' => $this->lastCode,
            'startedAt' => $this->startedAt,
        ]);
    }
}
