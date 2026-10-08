<?php

namespace CliMode\Command\Site;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\SiteTree;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * stat [page]: the facts about a page, in the layout of stat(1).
 */
class StatCommand extends TerminalCommand
{
    protected static $defaultName = 'stat';
    protected $category = 'site';
    protected $completesPaths = true;

    protected function configure(): void
    {
        $this->setDescription('Shows the details of a page')
            ->addArgument('path', InputArgument::OPTIONAL, 'A page path (the current page by default)')
            ->setHelp("Examples:\n  stat\n  stat events\n  stat /events/lan-party");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();
        $tree = new SiteTree($c);
        $path = (string) $input->getArgument('path');
        $page = $tree->page($path === '' ? '.' : $path);
        if (!$page) {
            $output->writeln('<error> ' . $c->snark->noSuchPath('stat', $path === '' ? '.' : $path) . ' </error>');

            return 1;
        }

        $rows = [
            'Page' => $tree->path($page),
            'Name' => (string) $page->getCollectionName(),
            'ID' => (string) $page->getCollectionID(),
            'Type' => (string) $page->getPageTypeHandle() ?: '-',
            'Template' => (string) $page->getPageTemplateHandle() ?: '-',
            'Children' => (string) count($tree->children($page)),
            'Blocks' => (string) count($page->getBlocks()),
            'Status' => $page->isActive() ? 'published' : 'inactive',
            'Version' => (string) $page->getVersionID(),
            'Public' => $this->date($page->getCollectionDatePublic()),
            'Modify' => $this->date($page->getCollectionDateLastModified()),
            'Created' => $this->date($page->getCollectionDateAdded()),
        ];
        // names of members are for administrators only
        if ($c->isAdmin()) {
            $author = $c->app->make(UserInfoRepository::class)->getByID((int) $page->getCollectionUserID());
            $rows['Author'] = $author ? (string) $author->getUserName() : '-';
        }
        $rows['URL'] = (string) $page->getCollectionLink();

        foreach ($rows as $label => $value) {
            $output->writeln(sprintf('<comment>%8s</comment>: %s', $label, OutputFormatter::escape($value)));
        }

        return 0;
    }

    protected function date($date): string
    {
        $time = $date ? strtotime((string) $date) : false;

        return $time ? date('Y-m-d H:i:s', $time) : '-';
    }
}
