<?php

namespace CliMode\Terminal;

use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The website as a file system: pages are directories (and files), the page tree is the directory tree, "/" is the
 * home page. Only pages the current user may view exist.
 */
class SiteTree
{
    /** @var Context */
    protected $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Absolute, normalized path ("..", ".", "~" and doubled slashes resolved) of a path typed relative to the cwd.
     */
    public function normalize(string $path, ?string $cwd = null): string
    {
        $cwd = $cwd ?? $this->context->state->cwd;
        if ($path === '' || $path === '~') {
            $path = '/';
        } elseif (strpos($path, '~/') === 0) {
            $path = substr($path, 1);
        }
        $full = $path[0] === '/' ? $path : rtrim($cwd, '/') . '/' . $path;
        $parts = [];
        foreach (explode('/', $full) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    /**
     * The page at a path, null if there is none or the user may not view it.
     */
    public function page(string $path): ?Page
    {
        $path = $this->normalize($path);
        $page = $path === '/' ? Page::getByID(Page::getHomePageID()) : Page::getByPath($path);
        if (!$page || $page->isError() || $page->isInTrash()) {
            return null;
        }

        return (new Checker($page))->canViewPage() ? $page : null;
    }

    /**
     * Children of a page the user may view. System pages (login, dashboard, account, ...) stay hidden unless asked for.
     *
     * @return Page[]
     */
    public function children(Page $page, bool $all = false): array
    {
        $children = [];
        foreach ($page->getCollectionChildrenArray(true) as $childID) {
            $child = Page::getByID((int) $childID);
            if (!$child || $child->isError() || $child->isInTrash() || !(new Checker($child))->canViewPage()) {
                continue;
            }
            if (!$all && ($child->isSystemPage() || $child->getAttribute('exclude_nav'))) {
                continue;
            }
            $children[] = $child;
        }

        return $children;
    }

    public function name(Page $page): string
    {
        $path = $this->path($page);

        return $path === '/' ? '/' : basename($path);
    }

    public function path(Page $page): string
    {
        if ((int) $page->getCollectionID() === (int) Page::getHomePageID()) {
            return '/';
        }

        return (string) $page->getCollectionPath() ?: '/';
    }

    public function isDirectory(Page $page): bool
    {
        return $this->children($page) !== [];
    }

    /**
     * Completions for a path typed so far: names of the children of the directory part, directories get a slash.
     *
     * @return string[] full words to put in place of $word
     */
    public function complete(string $word): array
    {
        $slash = strrpos($word, '/');
        $dirPart = $slash === false ? '' : substr($word, 0, $slash + 1);
        $namePart = $slash === false ? $word : substr($word, $slash + 1);
        $dir = $this->page($dirPart === '' ? '.' : $dirPart);
        if (!$dir) {
            return [];
        }
        $matches = [];
        foreach ($this->children($dir, strpos($namePart, '.') === 0) as $child) {
            $name = $this->name($child);
            if ($namePart === '' || stripos($name, $namePart) === 0) {
                $matches[] = $dirPart . $name . ($this->isDirectory($child) ? '/' : '');
            }
        }
        sort($matches);

        return $matches;
    }
}
