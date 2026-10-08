<?php

namespace CliMode\Command;

use CliMode\Command\System\HelpCommand;
use CliMode\Terminal\Context;
use CliMode\Terminal\SiteTree;
use CliMode\Terminal\TerminalCommandAccess;
use Concrete\Core\Page\Page;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The commands that ship with the terminal. Most are small closures (see ClosureCommand), the bigger ones are
 * classes of their own.
 */
class Builtin
{
    /**
     * @return TerminalCommand[]
     */
    public static function all(): array
    {
        return array_merge([new HelpCommand(), new Site\ShowCommand()], self::system(), self::filters(), self::site(), self::fun(), self::jokes(), self::lan());
    }

    /**
     * A page as text: title, description, the text of its content blocks and (when asked for, or when the terminal
     * is the only way through the site) the pages below it.
     */
    public static function readPage(Context $c, OutputInterface $out, Page $page): void
    {
        $tree = new SiteTree($c);
        $out->writeln('<accent>' . OutputFormatter::escape((string) $page->getCollectionName()) . '</accent>  <dim>' . OutputFormatter::escape($tree->path($page)) . '</dim>');
        if ((string) $page->getCollectionDescription() !== '') {
            $out->writeln('<dim>' . OutputFormatter::escape((string) $page->getCollectionDescription()) . '</dim>');
        }
        $out->writeln('');
        $text = '';
        foreach ($page->getBlocks() as $block) {
            $handle = $block->getBlockTypeHandle();
            if ($handle === 'content') {
                // the stored content has <concrete-picture fID="..."> (an ID or a UUID); the rendered one only an anonymous <img>
                $html = (string) $block->getController()->content;
                $text .= ' ' . preg_replace_callback('/<concrete-picture\b[^>]*?\bfID\s*=\s*[\'"]([^\'"]+)[\'"][^>]*>(?:\s*<\/concrete-picture>)?/i', static function ($m) {
                    return "\n" . self::imageMarker($m[1]) . "\n";
                }, $html);
            } elseif ($handle === 'image') {
                $fID = (string) $block->getController()->getFileID();
                if ($fID !== '') {
                    $text .= '</p>' . self::imageMarker($fID, (string) $block->getController()->getAltText()) . '</p>';
                }
            }
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', html_entity_decode(strip_tags(preg_replace('/<\/(p|h[1-6]|li|div)>/i', "\n", $text)), ENT_QUOTES)));
        $text = preg_replace("/\n\s*\n+/", "\n", $text);
        $lines = $text === '' ? [] : array_slice(explode("\n", $text), 0, 40);
        $out->writeln($lines ? implode("\n", $lines) : '<dim>(no text on this page. It lets its blocks do the talking.)</dim>', OutputInterface::OUTPUT_RAW);
        if ($c->forced) {
            $children = $tree->children($page);
            if ($children) {
                $out->writeln('');
                $out->writeln('<comment>Pages here</comment>');
                foreach ($children as $child) {
                    $out->writeln('  <dir>' . OutputFormatter::escape($tree->name($child)) . '</dir>  <dim>' . OutputFormatter::escape((string) $child->getCollectionName()) . '</dim>');
                }
                $out->writeln('<dim>cd <name> goes in, cat <name> reads, cd .. goes back.</dim>');
            }
        }
    }

    /**
     * The text that stands for an image in a page: "[image: Title (id 12) - show 12]". It goes through strip_tags
     * and html_entity_decode with the rest of the page text, so the title is encoded here.
     */
    protected static function imageMarker(string $fID, string $fallback = ''): string
    {
        // getByUUIDOrID() refuses numeric IDs of files that have a UUID, which is every file uploaded lately
        $file = uuid_is_valid($fID) ? \Concrete\Core\File\File::getByUUID($fID) : (ctype_digit($fID) ? \Concrete\Core\File\File::getByID((int) $fID) : null);
        if (!$file || $file->isError() || !(new \Concrete\Core\Permission\Checker($file))->canViewFile()) {
            return '';
        }
        $version = $file->getApprovedVersion();
        $title = $version ? trim((string) $version->getTitle()) : '';
        if ($title === '') {
            $title = $version ? (string) $version->getFileName() : '';
        }
        if ($title === '') {
            $title = $fallback !== '' ? $fallback : 'untitled';
        }

        return htmlspecialchars('[image: ' . $title . ' (id ' . $file->getFileID() . ') - show ' . $file->getFileID() . ']', ENT_QUOTES);
    }

    /**
     * @return TerminalCommand[]
     */
    protected static function system(): array
    {
        $cat = ['category' => 'system'];

        return [
            ClosureCommand::make('true', 'Does nothing, successfully', static function () {
                return 0;
            }, $cat + ['hidden' => true]),
            ClosureCommand::make('false', 'Does nothing, unsuccessfully', static function () {
                return 1;
            }, $cat + ['hidden' => true]),
            ClosureCommand::make('guide', 'A short tour of the terminal', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $lines = [
                    '<accent>Welcome to snarkbash.</accent> A shell for a website. Yes, really.',
                    '',
                    '<comment>1. Move around</comment>  The site is a file system: pages are folders.',
                    '   <info>ls</info>  lists the pages here · <info>cd events</info>  walks in · <info>cd ..</info>  walks out · <info>tree</info>  shows it all',
                    '   <info>cat events</info>  reads a page · <info>open events</info>  actually goes there',
                    '',
                    '<comment>2. Be lazy</comment>  <info>Tab</info> completes commands and page names. <info>↑</info> / <info>↓</info> bring back what you typed.',
                    '   <info>Ctrl+L</info> clears, <info>Ctrl+C</info> drops the line, <info>Esc</info> leaves.',
                    '',
                    '<comment>3. Combine</comment>  Pipes and lists work: <info>ls -l | grep events</info>, <info>cd events && ls</info>, <info>echo hi; whoami</info>',
                    '   Variables too: <info>export GREETING=hello</info> then <info>echo $GREETING $USER</info>. Shortcuts: <info>alias ll="ls -l"</info>',
                    '',
                    '<comment>4. Get help</comment>  <info>help</info> lists everything you may run, <info>help <command></info> explains one.',
                    '   Commands that are not for you answer with "Permission denied". Log in and they may change their mind.',
                    '',
                    '<comment>5. LAN stuff</comment>  <info>events</info>, <info>next</info>, <info>event <name></info>' . ($c->isAdmin() ? ', <info>participants <event></info>' : '') . '. More will come.',
                    '',
                    '<comment>6. Look</comment>  <info>theme amber</info>, <info>theme green</info>, <info>theme matrix</info>, <info>theme blackout</info>. <info>neofetch</info> for show-off.',
                    '',
                    '<dim>Want to write your own commands? It works like Concrete CLI commands: see the package README.</dim>',
                ];
                $out->writeln($lines);

                return 0;
            }, $cat),
            ClosureCommand::make('clear', 'Clears the screen', static function (Context $c) {
                $c->addAction('clear');

                return 0;
            }, $cat + ['aliases' => ['cls']]),
            ClosureCommand::make('echo', 'Prints its arguments', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $text = implode(' ', (array) $in->getArgument('text'));
                $out->write($text . ($in->getOption('no-newline') ? '' : "\n"), false, OutputInterface::OUTPUT_RAW);

                return 0;
            }, $cat + ['arguments' => [['text', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Words to print']], 'options' => [['no-newline', 'n', InputOption::VALUE_NONE, 'No trailing newline']]]),
            ClosureCommand::make('whoami', 'Who are you, anyway?', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln($c->username());
                if ($c->snark->level() > 1 && !$c->isRegistered()) {
                    $out->writeln('<snark>(a guest. How mysterious. Log in and we will talk.)</snark>');
                }

                return 0;
            }, $cat),
            ClosureCommand::make('id', 'Your user and groups', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $groups = [];
                if ($c->isRegistered()) {
                    foreach ($c->user->getUserGroupObjects() as $group) {
                        $groups[] = $group->getGroupID() . '(' . $group->getGroupDisplayName(false) . ')';
                    }
                }
                if (!$groups && $c->isAdmin()) {
                    $groups = ['2(Registered Users)', '3(Administrators)'];
                }
                $out->writeln(sprintf('uid=%d(%s) groups=%s', (int) $c->user->getUserID(), $c->username(), $groups ? implode(',', $groups) : '0(guest)'));

                return 0;
            }, $cat),
            ClosureCommand::make('hostname', 'The name of this machine', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln($c->hostname());

                return 0;
            }, $cat),
            ClosureCommand::make('uname', 'System information', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln($in->getOption('all') ? sprintf('Concrete %s %s snarkbash PHP/%s #1 SMP %s', 'CMS', $c->hostname(), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, date('D M j Y')) : 'Concrete');

                return 0;
            }, $cat + ['options' => [['all', 'a', InputOption::VALUE_NONE, 'Everything we dare to say']]]),
            ClosureCommand::make('date', 'The current date and time', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln(date('D M j H:i:s T Y'));

                return 0;
            }, $cat),
            ClosureCommand::make('uptime', 'How long this session has been running', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $seconds = time() - (int) $c->state->startedAt;
                $out->writeln(sprintf(' %s up %d min, 1 user, load average: 0.%02d, 0.%02d, 0.%02d', date('H:i:s'), intdiv($seconds, 60), random_int(1, 99), random_int(1, 99), random_int(1, 99)));

                return 0;
            }, $cat),
            ClosureCommand::make('env', 'Prints the shell variables', static function (Context $c, InputInterface $in, OutputInterface $out) {
                foreach ($c->allEnv() as $name => $value) {
                    $out->writeln($name . '=' . $value, OutputInterface::OUTPUT_RAW);
                }

                return 0;
            }, $cat + ['aliases' => ['printenv']]),
            ClosureCommand::make('export', 'Sets a variable: export NAME=value', static function (Context $c, InputInterface $in, OutputInterface $out) {
                foreach ((array) $in->getArgument('assignments') as $assignment) {
                    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', (string) $assignment, $m)) {
                        $out->writeln('<error> export: `' . $assignment . "': not a valid identifier. Try NAME=value. </error>");

                        return 1;
                    }
                    $c->state->env[$m[1]] = $m[2];
                }

                return 0;
            }, $cat + ['arguments' => [['assignments', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'NAME=value']]]),
            ClosureCommand::make('alias', 'Defines or lists aliases: alias ll="ls -l"', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $definitions = (array) $in->getArgument('definition');
                if ($definitions === []) {
                    foreach ($c->state->aliases as $name => $value) {
                        $out->writeln(sprintf("alias %s='%s'", $name, $value), OutputInterface::OUTPUT_RAW);
                    }

                    return 0;
                }
                if (count($definitions) === 1 && strpos((string) $definitions[0], '=') === false) {
                    $name = (string) $definitions[0];
                    if (!isset($c->state->aliases[$name])) {
                        $out->writeln('<error> alias: ' . OutputFormatter::escape($name) . ': not found </error>');

                        return 1;
                    }
                    $out->writeln(sprintf("alias %s='%s'", $name, $c->state->aliases[$name]), OutputInterface::OUTPUT_RAW);

                    return 0;
                }
                if (!preg_match('/^([^=\s]+)=(.*)$/s', implode(' ', $definitions), $m)) {
                    $out->writeln('<error> alias: use alias name="command". </error>');

                    return 1;
                }
                $c->state->aliases[$m[1]] = trim($m[2], "\"'");

                return 0;
            }, $cat + ['arguments' => [['definition', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'name="command"']]]),
            ClosureCommand::make('unalias', 'Removes an alias', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $name = (string) $in->getArgument('name');
                if (!isset($c->state->aliases[$name])) {
                    $out->writeln('<error> unalias: ' . $name . ': not found </error>');

                    return 1;
                }
                unset($c->state->aliases[$name]);

                return 0;
            }, $cat + ['arguments' => [['name', InputArgument::REQUIRED, 'The alias']]]),
            ClosureCommand::make('history', 'What you typed before', static function (Context $c, InputInterface $in, OutputInterface $out) {
                if ($in->getOption('clear')) {
                    $c->state->history = [];

                    return 0;
                }
                foreach ($c->state->history as $i => $line) {
                    $out->writeln(sprintf('%5d  %s', $i + 1, $line), OutputInterface::OUTPUT_RAW);
                }
                if ($c->snark->level() > 1 && count($c->state->history) > 20) {
                    $out->writeln('<snark>That is a lot of typing for one website.</snark>');
                }

                return 0;
            }, $cat + ['options' => [['clear', 'c', InputOption::VALUE_NONE, 'Forget everything']]]),
            ClosureCommand::make('which', 'Where a command comes from', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $name = (string) $in->getArgument('name');
                $command = $c->registry->resolve($name);
                if (isset($c->state->aliases[$name])) {
                    $out->writeln($name . ": aliased to '" . $c->state->aliases[$name] . "'", OutputInterface::OUTPUT_RAW);

                    return 0;
                }
                if (!$command || !$command->isAllowedFor($c)) {
                    $out->writeln('<error> ' . $name . ' not found </error>');

                    return 1;
                }
                $out->writeln('/usr/bin/' . $command->getName() . ' <dim>(' . get_class($command) . ')</dim>');

                return 0;
            }, $cat + ['arguments' => [['name', InputArgument::REQUIRED, 'The command']]]),
            ClosureCommand::make('theme', 'Changes the terminal colors', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $themes = ['blackout', 'green', 'amber', 'matrix', 'paper'];
                $name = (string) $in->getArgument('name');
                if ($name === '') {
                    $out->writeln('Themes: ' . implode(', ', $themes));

                    return 0;
                }
                if (!in_array($name, $themes, true)) {
                    $out->writeln('<error> theme: ' . $name . ': unknown. Taste is a personal matter, but pick one of: ' . implode(', ', $themes) . ' </error>');

                    return 1;
                }
                $c->addAction('theme', ['name' => $name]);
                $out->writeln('<ok>Theme: ' . $name . '</ok>');

                return 0;
            }, $cat + ['arguments' => [['name', InputArgument::OPTIONAL, 'blackout, green, amber, matrix or paper']]]),
            ClosureCommand::make('exit', 'Leaves the terminal', static function (Context $c, InputInterface $in, OutputInterface $out) {
                if ($c->forced) {
                    $out->writeln('<error> ' . $c->snark->trapped() . ' </error>');

                    return 1;
                }
                $out->writeln('<snark>' . $c->snark->exit() . '</snark>');
                if ($c->notFound) {
                    // the terminal is the 404 page: leaving it means going home
                    $c->addAction('redirect', ['url' => (string) $c->app->make('url/manager')->resolve(['/'])]);

                    return 0;
                }
                $c->addAction('exit');

                return 0;
            }, $cat + ['aliases' => ['quit']]),
            ClosureCommand::make('logout', 'Signs you out of the website', static function (Context $c, InputInterface $in, OutputInterface $out) {
                if (!$c->isRegistered()) {
                    $out->writeln($c->snark->level() > 0 ? 'You are not logged in. You cannot leave what you never entered.' : 'logout: not logged in');

                    return 1;
                }
                $out->writeln('<snark>' . $c->snark->exit() . '</snark>');
                $c->addAction('redirect', ['url' => (string) $c->app->make('url/manager')->resolve(['/login', 'do_logout', $c->app->make('token')->generate('do_logout')])]);

                return 0;
            }, $cat),
            ClosureCommand::make('login', 'Sign in to the website', static function (Context $c, InputInterface $in, OutputInterface $out) {
                if ($c->isRegistered()) {
                    $out->writeln('You are already ' . $c->username() . '. Identity crises are for Tuesdays.');

                    return 0;
                }
                $out->writeln('Redirecting to the login page. Passwords are typed in a form, not here: this is a toy, not a keylogger.');
                $c->addAction('redirect', ['url' => (string) $c->app->make('url/manager')->resolve(['/login'])]);

                return 0;
            }, $cat),
        ];
    }

    /**
     * @return TerminalCommand[]
     */
    protected static function filters(): array
    {
        $cat = ['category' => 'text'];
        $lines = static function (Context $c): array {
            $stdin = $c->stdin;

            return $stdin === null || $stdin === '' ? [] : preg_split('/\r\n|\n|\r/', rtrim($stdin, "\r\n"));
        };

        return [
            ClosureCommand::make('grep', 'Keeps the lines that match (use it after a pipe)', static function (Context $c, InputInterface $in, OutputInterface $out) use ($lines) {
                $pattern = (string) $in->getArgument('pattern');
                $count = 0;
                foreach ($lines($c) as $line) {
                    $hit = stripos($line, $pattern) !== false;
                    if ($in->getOption('ignore-case') === false) {
                        $hit = strpos($line, $pattern) !== false;
                    }
                    if ($in->getOption('invert') ? !$hit : $hit) {
                        ++$count;
                        if (!$in->getOption('count')) {
                            $out->writeln($line, OutputInterface::OUTPUT_RAW);
                        }
                    }
                }
                if ($in->getOption('count')) {
                    $out->writeln((string) $count);
                }

                return $count > 0 ? 0 : 1;
            }, $cat + ['arguments' => [['pattern', InputArgument::REQUIRED, 'Text to look for']], 'options' => [['ignore-case', 'i', InputOption::VALUE_NONE, 'Ignore case'], ['invert', 'v', InputOption::VALUE_NONE, 'Lines that do not match'], ['count', 'c', InputOption::VALUE_NONE, 'Only count']]]),
            ClosureCommand::make('head', 'The first lines of the input', static function (Context $c, InputInterface $in, OutputInterface $out) use ($lines) {
                foreach (array_slice($lines($c), 0, max(0, (int) $in->getOption('lines'))) as $line) {
                    $out->writeln($line, OutputInterface::OUTPUT_RAW);
                }

                return 0;
            }, $cat + ['options' => [['lines', 'n', InputOption::VALUE_REQUIRED, 'How many', 10]]]),
            ClosureCommand::make('tail', 'The last lines of the input', static function (Context $c, InputInterface $in, OutputInterface $out) use ($lines) {
                $n = max(0, (int) $in->getOption('lines'));
                foreach ($n === 0 ? [] : array_slice($lines($c), -$n) as $line) {
                    $out->writeln($line, OutputInterface::OUTPUT_RAW);
                }

                return 0;
            }, $cat + ['options' => [['lines', 'n', InputOption::VALUE_REQUIRED, 'How many', 10]]]),
            ClosureCommand::make('wc', 'Counts lines, words and characters of the input', static function (Context $c, InputInterface $in, OutputInterface $out) use ($lines) {
                $all = $lines($c);
                $text = implode("\n", $all);
                if ($in->getOption('lines')) {
                    $out->writeln((string) count($all));
                } else {
                    $out->writeln(sprintf('%7d %7d %7d', count($all), str_word_count($text, 0, '0123456789_-'), strlen($text)), OutputInterface::OUTPUT_RAW);
                }

                return 0;
            }, $cat + ['options' => [['lines', 'l', InputOption::VALUE_NONE, 'Only lines']]]),
            ClosureCommand::make('sort', 'Sorts the lines of the input', static function (Context $c, InputInterface $in, OutputInterface $out) use ($lines) {
                $all = $lines($c);
                sort($all, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($in->getOption('reverse') ? array_reverse($all) : $all as $line) {
                    $out->writeln($line, OutputInterface::OUTPUT_RAW);
                }

                return 0;
            }, $cat + ['options' => [['reverse', 'r', InputOption::VALUE_NONE, 'Backwards']]]),
            ClosureCommand::make('uniq', 'Drops repeated neighbouring lines', static function (Context $c, InputInterface $in, OutputInterface $out) use ($lines) {
                $last = null;
                foreach ($lines($c) as $line) {
                    if ($line !== $last) {
                        $out->writeln($line, OutputInterface::OUTPUT_RAW);
                    }
                    $last = $line;
                }

                return 0;
            }, $cat),
        ];
    }

    /**
     * @return TerminalCommand[]
     */
    protected static function site(): array
    {
        $cat = ['category' => 'site', 'paths' => true];

        return [
            ClosureCommand::make('pwd', 'Where you are on the site', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln($c->state->cwd, OutputInterface::OUTPUT_RAW);

                return 0;
            }, ['category' => 'site']),
            ClosureCommand::make('ls', 'Lists the pages below the current one', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $tree = new SiteTree($c);
                $path = (string) $in->getArgument('path');
                $page = $tree->page($path === '' ? '.' : $path);
                if (!$page) {
                    $out->writeln('<error> ' . $c->snark->noSuchPath('ls', $path) . ' </error>');

                    return 2;
                }
                $children = $tree->children($page, (bool) $in->getOption('all'));
                if ($children === []) {
                    $out->writeln($c->snark->level() > 0 ? '<dim>(nothing here. Echo... echo... echo...)</dim>' : '');
                } elseif ($in->getOption('long')) {
                    foreach ($children as $child) {
                        $date = $child->getCollectionDatePublic() ? date('M d H:i', strtotime((string) $child->getCollectionDatePublic())) : '            ';
                        $out->writeln(sprintf(
                            '%s %3d %-8s %s <%s>%s</%s>',
                            $tree->isDirectory($child) ? 'drwxr-xr-x' : '-rw-r--r--',
                            count($child->getCollectionChildrenArray(true)),
                            substr((string) $child->getPageTemplateHandle() ?: 'page', 0, 8),
                            $date,
                            $tree->isDirectory($child) ? 'dir' : 'info',
                            OutputFormatter::escape($tree->name($child)) . ($tree->isDirectory($child) ? '/' : ''),
                            $tree->isDirectory($child) ? 'dir' : 'info'
                        ));
                    }
                } else {
                    $names = array_map(static function ($child) use ($tree) {
                        $n = OutputFormatter::escape($tree->name($child));

                        return $tree->isDirectory($child) ? '<dir>' . $n . '/</dir>' : $n;
                    }, $children);
                    $out->writeln(implode('  ', $names));
                }

                return 0;
            }, $cat + ['arguments' => [['path', InputArgument::OPTIONAL, 'A page path']], 'options' => [['long', 'l', InputOption::VALUE_NONE, 'Details'], ['all', 'a', InputOption::VALUE_NONE, 'Include hidden and system pages']]]),
            ClosureCommand::make('cd', 'Walks to another page', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $tree = new SiteTree($c);
                $path = (string) $in->getArgument('path');
                $page = $tree->page($path === '' ? '~' : $path);
                if (!$page) {
                    $out->writeln('<error> ' . $c->snark->noSuchPath('cd', $path) . ' </error>');

                    return 1;
                }
                $c->state->cwd = $tree->path($page);

                return 0;
            }, $cat + ['arguments' => [['path', InputArgument::OPTIONAL, 'A page path, ".." goes up, "-" is not supported (we are not that fancy)']]]),
            ClosureCommand::make('tree', 'Shows the page tree', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $tree = new SiteTree($c);
                $path = (string) $in->getArgument('path');
                $page = $tree->page($path === '' ? '.' : $path);
                if (!$page) {
                    $out->writeln('<error> ' . $c->snark->noSuchPath('tree', $path) . ' </error>');

                    return 2;
                }
                $depth = max(1, (int) $in->getOption('level'));
                $count = 0;
                $out->writeln('<dir>' . OutputFormatter::escape($tree->path($page)) . '</dir>');
                $walk = static function (Page $parent, string $prefix, int $level) use (&$walk, $tree, $out, $depth, &$count) {
                    $children = $tree->children($parent);
                    foreach ($children as $i => $child) {
                        if (++$count > 200) {
                            return;
                        }
                        $last = $i === count($children) - 1;
                        $name = OutputFormatter::escape($tree->name($child));
                        $out->writeln($prefix . ($last ? '└── ' : '├── ') . ($tree->isDirectory($child) ? '<dir>' . $name . '</dir>' : $name));
                        if ($level < $depth) {
                            $walk($child, $prefix . ($last ? '    ' : '│   '), $level + 1);
                        }
                    }
                };
                $walk($page, '', 1);
                if ($count > 200) {
                    $out->writeln('<dim>... and more. I am not a sitemap.</dim>');
                }

                return 0;
            }, $cat + ['arguments' => [['path', InputArgument::OPTIONAL, 'Where to start']], 'options' => [['level', 'L', InputOption::VALUE_REQUIRED, 'How deep', 2]]]),
            ClosureCommand::make('cat', 'Reads a page (or prints the piped input)', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $path = (string) $in->getArgument('path');
                if ($path === '') {
                    if ($c->stdin !== null && $c->stdin !== '') {
                        $out->write($c->stdin, false, OutputInterface::OUTPUT_RAW);

                        return 0;
                    }
                    $out->writeln('<error> ' . $c->snark->usage('cat', 'missing page path') . ' </error>');

                    return 1;
                }
                $tree = new SiteTree($c);
                $page = $tree->page($path);
                if (!$page) {
                    $out->writeln('<error> ' . $c->snark->noSuchPath('cat', $path) . ' </error>');

                    return 1;
                }
                self::readPage($c, $out, $page);

                return 0;
            }, $cat + ['arguments' => [['path', InputArgument::OPTIONAL, 'A page path']]]),
            ClosureCommand::make('open', 'Opens a page (in the browser, or right here when the terminal is mandatory)', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $path = (string) $in->getArgument('path');
                $page = (new SiteTree($c))->page($path === '' ? '.' : $path);
                if (!$page) {
                    $out->writeln('<error> ' . $c->snark->noSuchPath('open', $path) . ' </error>');

                    return 1;
                }
                if ($c->forced) {
                    // there is no other browser than this terminal: opening a page means going there and reading it
                    $c->state->cwd = (new SiteTree($c))->path($page);
                    self::readPage($c, $out, $page);

                    return 0;
                }
                $out->writeln('<ok>Opening ' . OutputFormatter::escape((string) $page->getCollectionName()) . '...</ok> <dim>(using a graphical browser, like it is 1998)</dim>');
                $c->addAction('redirect', ['url' => (string) $page->getCollectionLink()]);

                return 0;
            }, $cat + ['arguments' => [['path', InputArgument::OPTIONAL, 'A page path']]]),
            ClosureCommand::make('find', 'Looks for pages by name', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $needle = mb_strtolower((string) $in->getArgument('name'));
                $tree = new SiteTree($c);
                $start = $tree->page('.');
                $hits = 0;
                $walk = static function (Page $parent) use (&$walk, $tree, $out, $needle, &$hits) {
                    foreach ($tree->children($parent) as $child) {
                        if ($hits >= 50) {
                            return;
                        }
                        if (mb_strpos(mb_strtolower($tree->name($child) . ' ' . $child->getCollectionName()), $needle) !== false) {
                            ++$hits;
                            $out->writeln($tree->path($child), OutputInterface::OUTPUT_RAW);
                        }
                        $walk($child);
                    }
                };
                if ($start) {
                    $walk($start);
                }
                if ($hits === 0) {
                    $out->writeln('<dim>Nothing called "' . $needle . '". Looked everywhere. Twice.</dim>');
                }

                return $hits ? 0 : 1;
            }, ['category' => 'site', 'arguments' => [['name', InputArgument::REQUIRED, 'Part of a page name or handle']]]),
        ];
    }

    /**
     * @return TerminalCommand[]
     */
    protected static function fun(): array
    {
        $cat = ['category' => 'fun'];

        return [
            ClosureCommand::make('neofetch', 'Shows off this website', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $db = $c->app->make(\Concrete\Core\Database\Connection\Connection::class);
                $pages = (int) $db->fetchOne('SELECT COUNT(*) FROM Pages WHERE cIsActive = 1');
                $users = (int) $db->fetchOne('SELECT COUNT(*) FROM Users WHERE uIsActive = 1');
                $theme = \Concrete\Core\Page\Theme\Theme::getSiteTheme();
                $logo = ['<accent>    _____    </accent>', '<accent>   /  T  \\   </accent>', '<accent>  |   |   |  </accent>', '<accent>  |___|___|  </accent>', '<accent>   \\_____/   </accent>', '<accent>    GAME     </accent>'];
                $facts = [
                    '<info>' . $c->username() . '</info>@<info>' . $c->hostname() . '</info>',
                    str_repeat('-', strlen($c->username() . $c->hostname()) + 1),
                    '<comment>OS</comment>: Concrete CMS' . ($c->isAdmin() ? ' ' . \Config::get('concrete.version') : ''),
                    '<comment>Shell</comment>: snarkbash 1.0',
                    '<comment>Theme</comment>: ' . ($theme ? $theme->getThemeName() : 'none'),
                    '<comment>Pages</comment>: ' . $pages,
                    '<comment>Members</comment>: ' . $users,
                    '<comment>PHP</comment>: ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                    '<comment>Uptime</comment>: since the last time somebody touched it',
                ];
                $rows = max(count($logo), count($facts));
                for ($i = 0; $i < $rows; ++$i) {
                    $out->writeln(($logo[$i] ?? str_repeat(' ', 13)) . '  ' . ($facts[$i] ?? ''));
                }

                return 0;
            }, $cat),
            ClosureCommand::make('cowsay', 'A cow says it', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $text = trim(implode(' ', (array) $in->getArgument('text'))) ?: 'Moo. Say something next time.';
                $text = wordwrap($text, 40, "\n", true);
                $lines = explode("\n", $text);
                $width = max(array_map('strlen', $lines));
                $out->writeln(' ' . str_repeat('_', $width + 2), OutputInterface::OUTPUT_RAW);
                foreach ($lines as $i => $line) {
                    [$l, $r] = count($lines) === 1 ? ['<', '>'] : ($i === 0 ? ['/', '\\'] : ($i === count($lines) - 1 ? ['\\', '/'] : ['|', '|']));
                    $out->writeln($l . ' ' . str_pad($line, $width) . ' ' . $r, OutputInterface::OUTPUT_RAW);
                }
                $out->writeln(' ' . str_repeat('-', $width + 2) . "\n        \\   ^__^\n         \\  (oo)\\_______\n            (__)\\       )\\/\\\n                ||----w |\n                ||     ||", OutputInterface::OUTPUT_RAW);

                return 0;
            }, $cat + ['arguments' => [['text', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'What the cow says']]]),
            ClosureCommand::make('fortune', 'Wisdom of dubious quality', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $fortunes = [
                    'A true gamer never rage quits. He lets the router do it for him.',
                    'There is no cloud. There is just someone else\'s LAN party.',
                    'It works on my machine. The machine is also on fire.',
                    'The best network cable is the one you did not trip over.',
                    'Latency is just the universe telling you to touch grass.',
                    'First rule of LAN club: bring your own extension cord.',
                    'Today is a good day to back up your save games.',
                    'You will soon meet a stranger. He will ask for your Wi-Fi password.',
                    'The seat you pick is the seat somebody else wanted. This is the way.',
                ];
                $out->writeln($fortunes[array_rand($fortunes)], OutputInterface::OUTPUT_RAW);

                return 0;
            }, $cat),
            ClosureCommand::make('ping', 'Pretends to ping a host', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $host = (string) $in->getArgument('host');
                $out->writeln('PING ' . $host . ' (127.0.0.1) 56(84) bytes of data.', OutputInterface::OUTPUT_RAW);
                $total = 0;
                for ($i = 1; $i <= 4; ++$i) {
                    $ms = random_int(8, 140) / 10;
                    $total += $ms;
                    $out->writeln(sprintf('64 bytes from %s: icmp_seq=%d ttl=64 time=%.1f ms', $host, $i, $ms), OutputInterface::OUTPUT_RAW);
                }
                $out->writeln(sprintf("\n--- %s ping statistics ---\n4 packets transmitted, 4 received, 0%% packet loss, time %dms", $host, (int) ($total * 100)), OutputInterface::OUTPUT_RAW);
                $out->writeln('<snark>(None of this is real. The packets are made up. The latency is also made up. So is your internet.)</snark>');

                return 0;
            }, $cat + ['arguments' => [['host', InputArgument::OPTIONAL, 'A host', 'localhost']]]),
            ClosureCommand::make('sudo', 'Do it as somebody important', static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln('<error> ' . $c->snark->sudo() . ' </error>');

                return 1;
            }, $cat + ['lenient' => true, 'arguments' => [['what', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'What to run']]]),
        ];
    }

    /**
     * Commands every shell has that a website will not let you use.
     *
     * @return TerminalCommand[]
     */
    protected static function jokes(): array
    {
        $jokes = [
            'rm' => null,
            'mv' => 'mv: cannot move pages. They are attached to the site tree with emotional bonds.',
            'cp' => 'cp: copy-pasting a website is called "a competitor".',
            'chmod' => 'chmod: nice try. Permissions are managed by grown-ups in the dashboard.',
            'chown' => "chown: you can't own the site. You can only visit it. Forever.",
            'kill' => 'kill: no processes were harmed. There are none. We are a PHP site.',
            'shutdown' => 'shutdown: the LAN party must go on. Request denied.',
            'reboot' => 'reboot: have you tried turning yourself off and on again?',
            'dd' => 'dd: sure, let me just overwrite the production database. No. Go away.',
            'vim' => 'vim: you can open it. You just can not leave. Neither can we. Press Esc to exit the terminal instead.',
            'nano' => 'nano: a gentle editor for a gentle soul. Sadly there is nothing here to edit.',
            'emacs' => 'emacs: it would take longer to load than the website. We have a limit.',
            'apt' => 'apt: Reading package lists... Done. Nothing to install. Everything installed itself years ago.',
            'git' => 'git: not a git repository (or any of the parent directories): the history is better left unexamined.',
            'ssh' => 'ssh: connect to host localhost port 22: Connection refused (you are already inside, genius).',
            'curl' => 'curl: (6) Could not resolve host. You are the host. Also you are the problem.',
            'make' => "make: *** No rule to make target 'me a sandwich'. Stop.",
            'python' => 'python: you cannot just invoke a snake in a PHP site. The zoo has rules.',
            'su' => 'su: Authentication failure. You are not that special. (Yet.)',
        ];
        $commands = [];
        foreach ($jokes as $name => $message) {
            $commands[] = ClosureCommand::make($name, 'Not available on a website', static function (Context $c, InputInterface $in, OutputInterface $out) use ($name, $message) {
                $out->writeln('<error> ' . ($name === 'rm' ? $c->snark->rm() : ($c->snark->level() === 0 ? $name . ': command not available' : $message)) . ' </error>');

                return 1;
            }, ['category' => 'fun', 'hidden' => true, 'lenient' => true, 'arguments' => [['args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Whatever']]]);
        }

        return $commands;
    }

    /**
     * LAN party commands, only with the event manager installed.
     *
     * @return TerminalCommand[]
     */
    protected static function lan(): array
    {
        return class_exists(\CommunityEventManager\Event\EventService::class) ? Lan\EventCommands::all() : [];
    }
}
