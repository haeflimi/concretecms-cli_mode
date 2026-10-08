# CLI Mode for Concrete CMS

A fake Linux terminal for your website. Press <kbd>`</kbd> (backtick) on any page, or add `#cli` to a page address, and
talk to the site in a shell that has opinions: pipes, quoting, `$VARIABLES`, aliases, history, Tab completion, a virtual
file system made of your pages, error messages with attitude, and commands you can write yourself the way you write
Concrete CLI commands.

## Using the terminal

| Key | Does |
|-----|------|
| <kbd>`</kbd> | open / close the terminal (not while typing in a form field) |
| <kbd>Tab</kbd> | completes commands, options and page names |
| <kbd>↑</kbd> <kbd>↓</kbd> | walk through the history |
| <kbd>Ctrl</kbd>+<kbd>L</kbd> | clear the screen |
| <kbd>Ctrl</kbd>+<kbd>C</kbd> | drop the current line |
| <kbd>Ctrl</kbd>+<kbd>U</kbd> | delete from the start of the line to the cursor |
| <kbd>Ctrl</kbd>+<kbd>D</kbd> | exit (on an empty line) |
| <kbd>Esc</kbd> | close |

Touch devices get a row with Tab, arrows and Esc.

Type `help` for all commands, `help <command>` (or `man <command>`) for one, `guide` for a short tour. The site is a file
system: pages are folders (`ls`, `cd`, `pwd`, `tree`, `cat`, `find`, `open`). Shell features work as expected:

```
ls -l | grep events              # pipes into text filters (grep, head, tail, sort, uniq, wc)
cd events && ls                  # &&  ||  ;
export GREETING=hi; echo $GREETING $USER
alias ll="ls -l"
events --all | sort -r | head -n 3
```

Built-in command groups: **system** (help, guide, echo, env, export, alias, history, theme, exit, ...), **site** (ls, cd,
cat, tree, find, open, ...), **text** (grep, head, tail, sort, uniq, wc), **lan** (events, event, next, participants; only
with the community_event_manager package) and **fun** (neofetch, cowsay, fortune, ping, sudo, and a few commands that
refuse to work on a website).

## Settings

Dashboard › System › Basics › **CLI Mode**: switch it off, choose who may open it (everybody / signed-in members /
administrators), show or hide the `>_` button in the corner, pick the attitude (polite, mild, snarky) and a message of the
day. The page also lists every registered command with its class.

Individual commands have their own access rules on top of that.

### Replacing the 404 page

The checkbox "Replace the 404 page with the terminal" turns the website's 404 page into a full-screen terminal. Its welcome
message shows the 404 as ASCII art, a remark about the missing page and similar pages that do exist. The shell starts in
the closest existing parent of the address that was typed, `open` navigates as usual, and `exit` leads back to the home
page. It works whatever "who may open it" says (the terminal's commands still follow their own access rules). Site-level
ASCII art: `banner404` in `application/config/cli_mode.php`.

### Mandatory mode

"Mandatory mode" makes the terminal the (apparently) only way through the website, for visitors who are not signed in or
for everybody except administrators. The page is replaced by a full-screen terminal that cannot be closed (no close
button, Esc and the backtick do nothing, `exit` refuses). Navigation is `ls` / `cd`, pages are read with `cat` or `open`
(which read inside the terminal instead of leaving it), and a link to any address lands in the terminal at that page.
Login, registration, account and download pages stay normal so people can still sign in, and `logout` signs out for real.

* It is a layer on top of the normal website, not a replacement: without JavaScript visitors (and search engines, screen
  readers) get the normal page. The website behind is hidden by a class set in `<head>`, so nothing flashes.
* Administrators are never forced, the dashboard and edit mode are not affected. Add `?forcecli` to any page address as an
  administrator to preview what visitors see.
* A forced user may use the terminal whatever "who may open it" says. A broken command is then a broken website: test
  your commands before switching this on.

## Writing commands

A command is a Symfony Console command, exactly like a Concrete CLI command: `configure()` declares the name,
arguments and options, `execute()` does the work and returns an exit code. Extend `CliMode\Command\TerminalCommand`
(see `examples/HelloCommand.php`):

```php
class EventsCommand extends TerminalCommand
{
    protected static $defaultName = 'seats';
    protected $category = 'lan';
    protected $access = TerminalCommandAccess::ADMIN;     // PUBLIC (default), REGISTERED or ADMIN

    protected function configure(): void
    {
        $this->setDescription('Free seats of an event')
             ->addArgument('event', InputArgument::REQUIRED, 'Event ID')
             ->addOption('free', 'f', InputOption::VALUE_NONE, 'Only free seats');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->context();                       // user, state, snark, the Concrete container: $c->app
        $output->writeln('<info>Seats: ...</info>'); // <info> <comment> <error> and <dim> <accent> <snark> <dir> <ok> <warn>
        $this->table($output, ['Seat', 'Who'], $rows);
        return self::SUCCESS;
    }
}
```

Piped input is in `$this->context()->stdin` (`stdinLines()`), the browser can be asked to do things with
`$c->addAction('redirect', ['url' => ...])`, `'clear'`, `'exit'` or `'theme'`. Override `isAllowedFor(Context $c)` for
rules beyond public / registered / admin, e.g. a group, and `completeWords($word, $words)` for Tab completion of your
arguments (set `$completesPaths = true` for page paths). Messages in the style of the shell come from `$c->snark`.

Small commands do not need a class:

```php
ClosureCommand::make('uptime', 'How long', function (Context $c, InputInterface $in, OutputInterface $out) {
    $out->writeln('a while');
    return 0;
}, ['category' => 'system', 'aliases' => ['up'], 'arguments' => [['name', InputArgument::OPTIONAL, 'Who']]]);
```

### Registering

In your package's `on_start()`:

```php
$this->app->make(\CliMode\Terminal\CommandRegistry::class)->register(HelloCommand::class);   // class name or instance
```

or without caring about the start order of the packages, by listening to the event the registry dispatches when the
terminal is used for the first time:

```php
$this->app->make('director')->addListener('on_cli_mode_register_commands', function ($event) {
    $event->getRegistry()->register(HelloCommand::class);
});
```

A site can also list command classes in `application/config/cli_mode.php`: `return ['commands' => [\Application\Terminal\FooCommand::class]];`.

## Security

* Commands run with the rights of the logged-in user; nothing is executed on the server's operating system. `rm`, `sudo`
  and friends are jokes. Check permissions in your own commands (`$c->isAdmin()`, `$c->isRegistered()`, Concrete's
  permission checks): anything a command does is done by the website, with all that implies.
* The endpoints need the session token that the terminal fetches when it opens, and are rate limited (90 commands a minute).
* The page tree only shows pages the user may view.

## Upgrading

* **1.2.0** optionally replaces the 404 page with the terminal (ASCII art 404 in the welcome message, suggestions, `exit` goes home).
* **1.1.0** mandatory mode (Dashboard › CLI Mode): visitors or everybody except administrators can only use the site through the terminal; `open` and `cat` read pages inside it, `logout` signs out for real.
* **1.0.1** settings are read from the database store, the dashboard page is created on upgrade.
* **1.0.0** first release.
