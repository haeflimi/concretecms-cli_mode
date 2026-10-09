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
        $this->setDescription(t('Shows the details of a page'))
            ->addArgument('path', InputArgument::OPTIONAL, t('A page path (the current page by default)'))
            ->setHelp(t('Examples:') . "\n  stat\n  stat events\n  stat /events/lan-party");
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
            t('Page') => $tree->path($page),
            t('Name') => (string) $page->getCollectionName(),
            t('ID') => (string) $page->getCollectionID(),
            t('Type') => (string) $page->getPageTypeHandle() ?: '-',
            t('Template') => (string) $page->getPageTemplateHandle() ?: '-',
            t('Children') => (string) count($tree->children($page)),
            t('Blocks') => (string) count($page->getBlocks()),
            t('Status') => $page->isActive() ? t('published') : t('inactive'),
            t('Version') => (string) $page->getVersionID(),
            t('Public') => $this->date($page->getCollectionDatePublic()),
            t('Modify') => $this->date($page->getCollectionDateLastModified()),
            t('Created') => $this->date($page->getCollectionDateAdded()),
        ];
        // names of members are for administrators only
        if ($c->isAdmin()) {
            $author = $c->app->make(UserInfoRepository::class)->getByID((int) $page->getCollectionUserID());
            $rows[t('Author')] = $author ? (string) $author->getUserName() : '-';
        }
        $rows[t('URL')] = (string) $page->getCollectionLink();

        // right-aligned by characters, not bytes: translated labels may have umlauts
        $width = max(8, ...array_map('mb_strlen', array_keys($rows)));
        foreach ($rows as $label => $value) {
            $output->writeln('<comment>' . str_repeat(' ', $width - mb_strlen($label)) . $label . '</comment>: ' . OutputFormatter::escape($value));
        }

        return 0;
    }

    protected function date($date): string
    {
        $time = $date ? strtotime((string) $date) : false;

        return $time ? date('Y-m-d H:i:s', $time) : '-';
    }
}
