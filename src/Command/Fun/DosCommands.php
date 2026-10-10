<?php

namespace CliMode\Command\Fun;

use CliMode\Command\Builtin;
use CliMode\Command\ClosureCommand;
use CliMode\Command\TerminalCommand;
use CliMode\Terminal\Context;
use CliMode\Terminal\SiteTree;
use Concrete\Core\Page\Page;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * DOS commands for visitors whose fingers still type "dir". Hidden, and only with an attitude: the polite shell does
 * not know them (plain "command not found"). dir, type and cd.. work on the site tree, the rest are jokes; every one
 * ends with a hint that Linux exists. Each name also answers in capitals (DIR, CLS), as DOS does.
 */
class DosCommands
{
    /**
     * @return TerminalCommand[]
     */
    public static function all(): array
    {
        $commands = [
            self::make('dir', 'ls -l', t('Lists the pages, the DOS way'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                return self::dir($c, $out, self::argument($in));
            }),
            self::make('type', 'cat', t('Reads a page, the DOS way'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $path = self::argument($in);
                $page = $path === '' ? null : (new SiteTree($c))->page(self::path($path));
                if (!$page) {
                    $out->writeln($path === '' ? t('The syntax of the command is incorrect.') : t('The system cannot find the file specified.'));

                    return 1;
                }
                Builtin::readPage($c, $out, $page);

                return 0;
            }),
            self::make('cd..', 'cd ..', t('Goes up a page, the DOS way'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $tree = new SiteTree($c);
                $page = $tree->page('..');
                if ($page) {
                    $c->state->cwd = $tree->path($page);
                }

                return 0;
            }),
            self::make('ver', 'uname -a', t('DOS version'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln("\nMS-DOS Version 6.22 (snarkbash " . t('in a trench coat') . ")\n", OutputInterface::OUTPUT_RAW);

                return 0;
            }),
            self::make('mem', 'free -h', t('Memory report'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln(implode("\n", [
                    '',
                    t('Memory Type        Total  =   Used   +   Free'),
                    '----------------  -------   -------   -------',
                    t('Conventional          640K      639K        1K'),
                    t('Upper                   0K        0K        0K'),
                    '',
                    t('Largest executable program size        1K (1 024 bytes)'),
                    t('640K ought to be enough for anybody. It was not.'),
                ]), OutputInterface::OUTPUT_RAW);

                return 0;
            }),
            self::make('ipconfig', 'ip a', t('Network configuration'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln(implode("\n", [
                    '',
                    t('Windows IP Configuration'),
                    '',
                    t('Ethernet adapter LAN-Party:'),
                    '',
                    '   IPv4 Address. . . . . . . . . . . : 127.0.0.1',
                    '   Subnet Mask . . . . . . . . . . . : 255.0.0.0',
                    '   Default Gateway . . . . . . . . . : ' . t('somewhere in the cable salad'),
                ]), OutputInterface::OUTPUT_RAW);

                return 0;
            }),
            self::make('tasklist', 'ps aux', t('Running programs'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln(implode("\n", [
                    '',
                    'Image Name                     PID   Mem Usage',
                    '========================= ======== ============',
                    'Telemetry.exe                    4    812 345 K',
                    'AdsInStartMenu.exe            1337    420 000 K',
                    'UpdateAndRestartNow.exe       6666  1 048 576 K',
                    'Copilot.exe                   9001  2 097 152 K',
                    'snarkbash.exe                   42          1 K',
                ]), OutputInterface::OUTPUT_RAW);

                return 0;
            }),
            self::make('chkdsk', 'fsck', t('Checks the disk'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln(implode("\n", [
                    t('The type of the file system is WEBSITE.'),
                    t('Checking pages... 100 percent completed.'),
                    t('0 bad sectors. 1 bad operating system found: yours.'),
                ]), OutputInterface::OUTPUT_RAW);

                return 0;
            }, ['scandisk']),
            self::make('format', 'mkfs', t('Formats a disk'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln(t('WARNING: ALL DATA ON NON-REMOVABLE DISK DRIVE C: WILL BE LOST!'), OutputInterface::OUTPUT_RAW);
                $out->writeln(t('Proceed with Format (Y/N)? N'), OutputInterface::OUTPUT_RAW);
                $out->writeln(t('(I answered for you. You are welcome.)'), OutputInterface::OUTPUT_RAW);

                return 1;
            }, ['fdisk']),
            self::make('del', 'rm', t('Deletes a page'), self::refuse(t('Access is denied. The pages are staying.')), ['erase', 'deltree']),
            self::make('copy', 'cp', t('Copies a page'), self::refuse(t('Access is denied. 0 file(s) copied.')), ['xcopy']),
            self::make('move', 'mv', t('Moves a page'), self::refuse(t('Access is denied. 0 file(s) moved.')), ['ren']),
            self::make('md', 'mkdir', t('Makes a page'), self::refuse(t('Access is denied. New pages are made in the dashboard.'))),
            self::make('rd', 'rmdir', t('Removes a page'), self::refuse(t('Access is denied. The pages are staying.'))),
            self::make('attrib', 'chmod', t('File attributes'), self::refuse(t('Access is denied. Your attributes: R (read only).'))),
            self::make('edit', 'nano', t('Opens an editor'), self::refuse(t('EDIT.COM is missing. So is the point.')), ['notepad']),
            self::make('defrag', 'fstrim', t('Defragments the disk'), static function (Context $c, InputInterface $in, OutputInterface $out) {
                $out->writeln('[' . str_repeat('#', 20) . str_repeat('.', 20) . '] 50%', OutputInterface::OUTPUT_RAW);
                $out->writeln(t('Defragmenting a website. Please wait until the heat death of the universe.'), OutputInterface::OUTPUT_RAW);

                return 0;
            }),
            self::make('regedit', 'nano /etc/*', t('Edits the registry'), self::refuse(t('There is no registry. There is a sitemap, and it is not yours.'))),
            self::make('win', 'startx', t('Starts Windows'), self::refuse(t('Starting Windows... Updating 1 of 214... Do not turn off your computer.'))),
        ];

        return $commands;
    }

    /**
     * A DOS command: hidden, also in capitals, unknown to a polite shell, and with a Linux hint at the end.
     */
    protected static function make(string $name, string $linux, string $description, callable $run, array $aliases = []): ClosureCommand
    {
        $names = array_merge([$name], $aliases);
        $aliases = array_values(array_diff(array_unique(array_merge($names, array_map('strtoupper', $names))), [$name]));

        return ClosureCommand::make($name, $description, static function (Context $c, InputInterface $in, OutputInterface $out) use ($run, $linux) {
            $typed = (string) $in->getFirstArgument();
            if ($c->snark->level() === 0) {
                $out->writeln('<fg=red>' . OutputFormatter::escape($c->snark->notFound($typed)) . '</>');

                return 127;
            }
            $code = (int) $run($c, $in, $out);
            // raw in the snark colour: escaping would eat the backslash of "C:\>"
            $out->writeln("\e[95m" . self::remark($c->snark->level(), $linux) . "\e[39m", OutputInterface::OUTPUT_RAW);

            return $code;
        }, ['category' => 'fun', 'hidden' => true, 'lenient' => true, 'aliases' => $aliases, 'arguments' => [['args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, t('Whatever')]]]);
    }

    protected static function refuse(string $message): callable
    {
        return static function (Context $c, InputInterface $in, OutputInterface $out) use ($message) {
            $out->writeln($message, OutputInterface::OUTPUT_RAW);

            return 1;
        };
    }

    /**
     * The hint that there is a better way: kind at level 1, a roast from level 2 on.
     */
    protected static function remark(int $level, string $linux): string
    {
        $pool = $level > 1 ? [
            t('Microslop called, they want their command back. On Linux: %s', $linux),
            t('DOS commands in %1$s. Bold. Linux has said "%2$s" for decades, without a reboot.', date('Y'), $linux),
            t('C:\> is not a prompt, it is a cry for help. Get well soon: install Linux and type %s', $linux),
            t('Next you will ask me to restart to apply the changes. Linux: %s, no restart, no ads in the start menu.', $linux),
            t('Microslop Windows: now with telemetry, ads and an AI you did not ask for. Linux: %s, and none of that.', $linux),
            t('Every time someone types a DOS command, a penguin cries. Make it stop: %s', $linux),
        ] : [
            t('(Psst: on Linux this is "%s". It is free, and it does not restart on its own.)', $linux),
            t('(Old habits. The Linux way would be: %s)', $linux),
            t('(This is a bash. Here we say: %s)', $linux),
        ];

        return $pool[array_rand($pool)];
    }

    /**
     * The first argument that is not a switch (/W, /P, /?).
     */
    protected static function argument(InputInterface $in): string
    {
        foreach ((array) $in->getArgument('args') as $arg) {
            if (!preg_match('~^/[a-z?]$~i', (string) $arg)) {
                return (string) $arg;
            }
        }

        return '';
    }

    /**
     * DOS path to site path: no drive letter, slashes instead of backslashes, lowercase.
     */
    protected static function path(string $path): string
    {
        $path = strtolower(str_replace('\\', '/', preg_replace('/^[a-z]:/i', '', $path)));

        return $path === '' ? '/' : $path;
    }

    protected static function dir(Context $c, OutputInterface $out, string $path): int
    {
        $tree = new SiteTree($c);
        $page = $tree->page($path === '' ? '.' : self::path($path));
        if (!$page) {
            $out->writeln(t('File Not Found'), OutputInterface::OUTPUT_RAW);

            return 1;
        }
        $line = static function (?Page $p, string $name, bool $dir) {
            $date = $p && $p->getCollectionDatePublic() ? strtotime((string) $p->getCollectionDatePublic()) : time();

            return sprintf('%s    %-14s %s', date('d.m.Y  H:i', $date), $dir ? '<DIR>' : str_pad(number_format(crc32($name) % 90000 + 512, 0, '.', "'"), 14, ' ', STR_PAD_LEFT), $name);
        };
        $lines = [
            ' ' . t('Volume in drive C is %s', 'TURICANE'),
            ' ' . t('Volume Serial Number is %s', '1337-C0DE'),
            '',
            ' ' . t('Directory of %s', 'C:' . strtoupper(str_replace('/', '\\', $tree->path($page)))),
            '',
            $line($page, '.', true),
            $line($page, '..', true),
        ];
        $files = $dirs = 0;
        foreach (array_slice($tree->children($page), 0, 100) as $child) {
            $isDir = $tree->isDirectory($child);
            $isDir ? ++$dirs : ++$files;
            $lines[] = $line($child, $tree->name($child), $isDir);
        }
        $lines[] = sprintf('%16d %s', $files, t('File(s)'));
        $lines[] = sprintf('%16d %s', $dirs + 2, t('Dir(s)   640K bytes free'));
        $out->writeln(implode("\n", $lines), OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
