<?php

namespace CliMode\Command\Site;

use CliMode\Command\TerminalCommand;
use CliMode\Terminal\SiteTree;
use Concrete\Core\Page\PageList;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * search <words>: the website's own search (page names, descriptions and content), one line per page.
 */
class SearchCommand extends TerminalCommand
{
    protected static $defaultName = 'search';
    protected $category = 'site';

    protected function configure(): void
    {
        $this->setDescription(t('Searches the pages of the site'))
            ->addArgument('words', InputArgument::IS_ARRAY | InputArgument::REQUIRED, t('What to look for (the words are searched as one phrase)'))
            ->addOption('limit', 'n', InputOption::VALUE_REQUIRED, t('How many pages at most (1 to 100)'), 20)
            ->setHelp(t('Looks in names, descriptions and the text of pages you may see. Every result is a path, so it works with the rest of the shell.') . "\n\n" . t('Examples:') . "\n  search lan party\n  search seats -n 5\n  search pizza | head -n 3");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();
        $phrase = trim(implode(' ', (array) $input->getArgument('words')));
        if (mb_strlen($phrase) < 2) {
            $output->writeln('<error> ' . $c->snark->usage('search', t('give me at least two characters to work with')) . ' </error>');

            return 2;
        }
        $limit = max(1, min(100, (int) $input->getOption('limit')));

        $list = new PageList();
        $list->filterByKeywords($phrase);
        $list->setItemsPerPage($limit);
        $tree = new SiteTree($c);
        $hits = 0;
        foreach ($list->getResults() as $page) {
            // the list checks "view page" itself, the tree is the final word on what exists for this user
            if (!$tree->page($tree->path($page))) {
                continue;
            }
            ++$hits;
            $description = trim((string) $page->getCollectionDescription());
            $output->writeln(sprintf(
                '<dir>%s</dir>  %s%s',
                OutputFormatter::escape($tree->path($page)),
                OutputFormatter::escape((string) $page->getCollectionName()),
                $description !== '' ? '  <dim>' . OutputFormatter::escape(mb_strimwidth($description, 0, 60, '...')) . '</dim>' : ''
            ));
        }
        if ($hits === 0) {
            $output->writeln('<dim>' . t('Nothing for "%s".', OutputFormatter::escape($phrase)) . ($c->snark->level() > 0 ? ' ' . t('The index has looked at itself in the mirror and shrugged.') : '') . '</dim>');

            return 1;
        }

        return 0;
    }
}
