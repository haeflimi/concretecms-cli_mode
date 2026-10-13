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
cat, tree, find, open, ...), **text** (grep, head, tail, sort, uniq, wc), **lan** (events, event, next, signup, seat, signups,
participants; only with the community_event_manager package), **lan** credit commands (balance, pay; only with the
community_store_credit package) and **fun** (neofetch, cowsay, fortune, ping, sudo, and a few commands that
refuse to work on a website).

`signup [event]` and `seat [event] [seat]` act for the signed-in member through community_event_manager's
ParticipationService (and TicketService), with the same rules as the signup block: without an event they take the next
one open for signups (`seat`: the next one the member is registered for). `signup` registers or waitlists; on a ticket event
it reserves the ticket on the event tab or redirects to the store checkout; `--cancel` cancels. `seat` prints the plan one
row per seat letter (free green, taken grey, own highlighted), `seat <event> B14` takes a seat (case, separators and leading
zeros don't matter), `--release` gives it back. `signups [--all]` lists the member's own signups (status incl. waitlist position,
seat, ticket), upcoming ones only unless `--all`.

`show <file-id>` turns an image from the file manager into ASCII art (`--width` sets the characters per line, default 64,
`--invert` is for light themes). It needs PHP's GD extension and respects the file's view permission.

`search <words>` looks through the pages the user may see (`-n` limits the results), `stat [page]` shows the details of a page
(the author only to administrators), `figlet <text>` prints big letters (`--width`, `--fill`) and `lolcat` colors piped
text like a rainbow: `figlet GG | lolcat`.

`login [user]` signs in right in the terminal: a `Username or email:` and a masked `Password:` prompt (`login --web` uses the website's login
page, which you need for a password that must be changed). The credentials go to their own endpoint, not through the command
line, so they are neither in the history nor in the saved screen. It uses Concrete's login service, with its failed login
limits and deny list, on top of five attempts a minute per session. A page that was open before the login is reloaded when
the terminal closes (at once in mandatory mode).
The name is looked up as both an email address and a user name, so either works whatever Concrete's own login expects.
A wrong password ends with a pointer to `passwd`.

`passwd <email|user>` (aliases `forgot-password`, `reset-password`) sends the mail of the login page's "Forgot password?":
the core `forgot_password` mail template with a `/login/callback/concrete/change_password/<hash>` link, under the core
`forgot_password` IP limit (`Terminal\PasswordReset`). It never tells whether the account exists. Signed in, `passwd` without
an argument mails the link to the user's own address.

When the profile lacks attributes that are required on registration, the right password does not sign in yet: the terminal
asks for them one by one (in the order of the attribute sets, yes/no questions such as a privacy policy last, answered with
y/j) and posts them back as `profile[handle]`. The sign-in waits in the session for ten minutes. Text, textarea, telephone,
email, url, number and boolean attributes can be asked for; any other required type (an address, a select) still sends
the user to `login --web`.

`attitude` lists the attitudes and `attitude super_snarky` switches to one for the current session only (`attitude --reset`
goes back to the site's setting from the dashboard).

The terminal speaks English by default, whatever the website's language. `lang` lists English plus every translation in
the package's `languages/` folder, `lang de` (the site's own variant when there is one, so de_CH on a Swiss site),
`lang de_DE` or `lang en` switches for the current session. Every terminal request switches to that locale first
(`Settings::locale()`), and boot and `lang` send the browser-side texts (greeting, login prompt) along.
As long as a session has not chosen a language, a browser that prefers another language the terminal speaks
(Accept-Language) gets a box in that language at the top and bottom of the welcome ("Lieber auf Deutsch? Tippe: lang de";
the inline terminal prints it after its greeting). `lang` shows the whole welcome again in the new language
(`Terminal\Welcome`, shared with boot).

## Settings

Dashboard › System › Basics › **CLI Mode**: switch it off, choose who may open it (everybody / signed-in members /
administrators), show or hide the `>_` button in the corner, pick the attitude (polite, which is the default, mildly snarky, super snarky, or one a package adds) and a message of the
day per terminal language (one field per language `lang` offers; config `cli_mode.motd.<locale>`, a language
without one shows the English text). The page also lists every registered command with its class.

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
* "Exempt groups": members of these groups always get the normal website too, without being administrators (e.g. a
  group "CLI bypass" for the crew).
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

### Asking questions (dialogs)

A command that needs answers one after the other (a wizard, a form) starts a dialog instead of reading arguments:
extend `CliMode\Terminal\Dialog` and call `$c->startDialog(new MyDialog())` in the command. `start()` greets and returns
the first `Question`, `answer()` gets every line the visitor types next and returns the next `Question`, or null when
it is done. `new Question('Password: ', true)` asks for a secret: the browser shows a password field and echoes
nothing. Answers are posted with `answer=1`, never land in the history, and Ctrl+C/Esc calls `cancel()`.

Between two requests only `$this->data` is kept (in the session), so remember everything there, and keep a password
as a hash rather than in plain text. `$this->run($c, $out, 'signup 33')` runs a regular command and prints its output,
`$this->handOver(new OtherDialog())` passes the conversation on, `Dialog::yesNo($answer)` understands y/n/j/ja/nein/oui.
A dialog that signs somebody in calls `$c->switchUser($user)`: the endpoint then sends a new token and the page reloads.
A dialog left alone for 30 minutes expires; a reload of the page asks its open question again. Examples:
`src/Dialog/RegisterDialog.php` and `src/Dialog/EasyDialog.php`.

### Accounts and the easy mode

* `register` (also `adduser`, `useradd`) creates an account like the registration page: the same validators, the
  attributes required on registration (text, phone, email, number, yes/no; other required types send people to
  `register --web`), the registration type (email validation, approval) and the notification mail. The image captcha
  becomes a word in big letters to type, and a session may create three accounts an hour. The password is asked last.
* `easy` (also `wizard`, `hilfe`; only with the event manager) is a patient wizard for people who have never seen a
  terminal: sign in (or `register`, then back), pick an event open for signups, sign up, choose a seat, pay open bills.
  It explains every step, asks before acting, works through the regular commands and lists them at the end.

## Writing attitudes

An attitude is how the shell talks when something goes wrong. Extend `CliMode\Terminal\Attitude\Attitude` (see
`examples/PirateAttitude.php`) and list only the messages you want to change, anything left out is the polite message:

```php
class PirateAttitude extends Attitude
{
    public function handle(): string { return 'pirate'; }      // unique, stable, what the dashboard saves
    public function name(): string { return t('Pirate'); }     // name in the dashboard
    public function level(): int { return 1; }                  // 0 plain, 1 remarks, 2 roasts: for commands with remarks of their own

    public function messages(): array
    {
        return [
            'not_found' => ['%1$s: command not found. Never heard of it, matey.', '%1$s: command not found. Uncharted waters.'],
            'exit' => 'logout. Fair winds!',
        ];
    }
}
```

A message is a `sprintf()` format with positional arguments (`%1$s`), or a list of them of which one is picked at random.
The keys and their arguments are listed in the `Attitude` class. Register it like a command: in `on_start()` with
`$this->app->make(\CliMode\Terminal\AttitudeRegistry::class)->register(PirateAttitude::class)`, by listening to
`on_cli_mode_register_attitudes`, or in `application/config/cli_mode.php`: `'attitudes' => [\Application\Terminal\PirateAttitude::class]`.
It then shows up in the dashboard. Your own commands can use messages of the attitude too: `$c->snark->say('my_key', $arg)`.

### DOS commands

For visitors whose fingers still type `dir`: hidden DOS commands (`Command\Fun\DosCommands`), also in capitals.
`dir`, `type` and `cd..` work on the site tree (`C:\EVENTS` is `/events`); `ver`, `mem`, `ipconfig`, `tasklist`,
`chkdsk`, `format`, `del`, `copy`, `move`, `md`, `rd`, `attrib`, `edit`, `defrag`, `regedit`, `win` and a few aliases are
jokes. Each one ends with a hint that Linux exists: kind in a mildly snarky attitude, a roast in a super snarky one.
An attitude of level 0 (polite) does not know them: plain "command not found".

## Security

* Commands run with the rights of the logged-in user; nothing is executed on the server's operating system. `rm`, `sudo`
  and friends are jokes. Check permissions in your own commands (`$c->isAdmin()`, `$c->isRegistered()`, Concrete's
  permission checks): anything a command does is done by the website, with all that implies.
* Passwords are typed into the login prompt or a secret dialog question only and posted to `/ccm/cli_mode/login` or as a
  dialog answer, never as a command line: use HTTPS.
* The endpoints need the session token that the terminal fetches when it opens, and are rate limited (90 commands a minute).
* The page tree only shows pages the user may view.

## Upgrading

* **1.10.0** `balance` (credit, open bills and event tabs, net) and `pay [order]` (Payrexx payment page for an open bill, redirect back to the
  account page) so members can settle up while the terminal is mandatory; `signup` respects the event's "settled balance"
  setting and tells members with open bills to run `pay`.
* **1.3.0** the attitude is now a class (`Attitude`, registered in the `AttitudeRegistry`): polite is the default, "Mild" and "Snarky" are now "Mildly snarky" and "Super snarky", and packages can add their own. A saved setting of an older version keeps working. `attitude` switches the attitude for a session, `search`, `stat`, `figlet` and `lolcat` are new, `show <file-id>` turns images into ASCII art, `cat` marks the images of a page with their file ID.
* **1.2.0** optionally replaces the 404 page with the terminal (ASCII art 404 in the welcome message, suggestions, `exit` goes home).
* **1.1.0** mandatory mode (Dashboard › CLI Mode): visitors or everybody except administrators can only use the site through the terminal; `open` and `cat` read pages inside it, `logout` signs out for real.
* **1.0.1** settings are read from the database store, the dashboard page is created on upgrade.
* **1.0.0** first release.
