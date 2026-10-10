<?php

namespace CliMode\Terminal;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What a dialog asks next: the prompt the browser shows in front of the input, and whether the answer is secret (a
 * password field, nothing of it echoed).
 */
class Question
{
    /** @var string */
    public $prompt;

    /** @var bool */
    public $secret;

    public function __construct(string $prompt, bool $secret = false)
    {
        $this->prompt = $prompt;
        $this->secret = $secret;
    }

    public static function fromArray(array $data): self
    {
        return new self((string) ($data['prompt'] ?? '> '), !empty($data['secret']));
    }

    /**
     * @return array{prompt: string, secret: bool}
     */
    public function toArray(): array
    {
        return ['prompt' => $this->prompt, 'secret' => $this->secret];
    }
}
