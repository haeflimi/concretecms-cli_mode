<?php

namespace CliMode\Command;

use CliMode\Terminal\Context;
use CliMode\Terminal\TerminalCommandAccess;
use Symfony\Component\Console\Command\Command;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Base class of the terminal's commands. It is a Symfony Console command, written like a Concrete CLI command:
 *
 *   class HelloCommand extends TerminalCommand
 *   {
 *       protected static $defaultName = 'hello';
 *       protected $category = 'fun';
 *
 *       protected function configure(): void
 *       {
 *           $this->setDescription('Says hello')->addArgument('who', InputArgument::OPTIONAL, 'Whom', 'world');
 *       }
 *
 *       protected function execute(InputInterface $input, OutputInterface $output): int
 *       {
 *           $output->writeln('Hello ' . $input->getArgument('who') . ' from ' . $this->context()->username());
 *
 *           return self::SUCCESS;
 *       }
 *   }
 *
 * and registered with $app->make(CommandRegistry::class)->register(HelloCommand::class) (see README).
 */
abstract class TerminalCommand extends Command
{
    /** @var string who may run it: one of TerminalCommandAccess::* */
    protected $access = TerminalCommandAccess::PUBLIC;

    /** @var string group in the output of "help" */
    protected $category = 'misc';

    /** @var bool the arguments are page paths (Tab completes them) */
    protected $completesPaths = false;

    /** @var Context|null */
    private $context;

    public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    public function context(): Context
    {
        if ($this->context === null) {
            throw new \LogicException('The command has no terminal context yet.');
        }

        return $this->context;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getAccess(): string
    {
        return $this->access;
    }

    /**
     * Whether the context's user may run the command; override for rules beyond public / registered / admin.
     */
    public function isAllowedFor(Context $context): bool
    {
        return $context->hasAccess($this->access);
    }

    /**
     * Tab completion of an argument. The default completes page paths when $completesPaths is set.
     *
     * @param string $word the word being completed (may be empty)
     * @param string[] $words the words before it, the command name excluded
     *
     * @return string[] full words that could replace $word
     */
    public function completeWords(string $word, array $words): array
    {
        return $this->completesPaths ? (new \CliMode\Terminal\SiteTree($this->context()))->complete($word) : [];
    }

    /**
     * Lines of the piped input (empty list without pipe).
     *
     * @return string[]
     */
    protected function stdinLines(): array
    {
        $stdin = $this->context()->stdin;
        if ($stdin === null || $stdin === '') {
            return [];
        }

        return preg_split('/\r\n|\n|\r/', rtrim($stdin, "\r\n"));
    }

    /**
     * A table in box drawing characters.
     *
     * @param string[] $headers
     * @param array<int, array<int, string>> $rows
     */
    protected function table(\Symfony\Component\Console\Output\OutputInterface $output, array $headers, array $rows): void
    {
        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setStyle('box')->setHeaders($headers)->setRows($rows);
        $table->render();
    }
}
