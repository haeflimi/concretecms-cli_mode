<?php

namespace CliMode\Dialog;

use CliMode\Terminal\Context;
use CliMode\Terminal\Dialog;
use CliMode\Terminal\Figlet;
use CliMode\Terminal\Login;
use CliMode\Terminal\Question;
use Concrete\Core\Attribute\Key\UserKey as UserAttributeKey;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfo;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * "register": creates an account the way the registration page does, one question at a time. The same validators
 * (user name, email, password), the required profile details, the site's registration type (email validation, admin
 * approval) and notification mail. The image captcha of the page becomes a word in big letters. The password is
 * asked last and only its hash is kept until the repeat confirms it.
 *
 * With "wizard" in its data it was started by the easy mode, which takes over again once the new member is signed in.
 */
class RegisterDialog extends Dialog
{
    /** accounts one session may create per hour */
    protected const PER_HOUR = 3;

    /** letters that do not look alike in the big font */
    protected const CAPTCHA_LETTERS = 'ABCDEFHKLMNPRSTUVWXYZ';

    /**
     * Why the terminal cannot register anybody right now, null when it can.
     */
    public static function unavailable(Context $c): ?string
    {
        $config = $c->app->make('config');
        if (!in_array($config->get('concrete.user.registration.type'), ['validate_email', 'enabled'], true)) {
            return t('This website does not take new registrations at the moment.');
        }
        $ip = $c->app->make('helper/validation/ip');
        if ($ip->isDenylisted()) {
            return (string) $ip->getErrorMessage();
        }
        $created = array_filter((array) $c->app->make('session')->get('cli_mode.registrations', []), static function ($t) {
            return $t > time() - 3600;
        });
        if (count($created) >= self::PER_HOUR) {
            return t('That is enough new accounts for one hour.');
        }
        foreach ((new Login($c->app))->requiredKeys() as $key) {
            if (!in_array($key->getAttributeTypeHandle(), Login::ASKABLE, true)) {
                return t('The registration asks for details the terminal cannot ask for. Please use the registration page: register --web');
            }
        }

        return null;
    }

    public function start(Context $c, OutputInterface $out): ?Question
    {
        if ($c->isRegistered()) {
            $out->writeln(t('You already have an account and you are signed in as %s.', '<info>' . OutputFormatter::escape($c->username()) . '</info>'));

            return null;
        }
        $problem = self::unavailable($c);
        if ($problem !== null) {
            $out->writeln('<error> ' . OutputFormatter::escape($problem) . ' </error>');

            return null;
        }
        $out->writeln('<accent>' . t('New account') . '</accent>');
        $out->writeln(t('I will ask you a few questions, one at a time. Take your time: if something does not fit, I will tell you and simply ask again.'));
        $out->writeln('<dim>' . t('Ctrl+C or Esc stops at any point, and nothing is saved before you confirm at the end.') . '</dim>');
        $out->writeln('');
        $this->data['step'] = $this->firstStep($c);

        return $this->ask($c, $out);
    }

    public function answer(Context $c, string $answer, OutputInterface $out): ?Question
    {
        $step = (string) ($this->data['step'] ?? '');
        $value = trim($answer);
        switch ($step) {
            case 'name':
                $value = (string) preg_replace('/ +/', ' ', $value);
                if (($error = $this->invalid($c, 'validator/user/name', $value)) !== null) {
                    $out->writeln('<warn>' . OutputFormatter::escape($error) . '</warn>');

                    return $this->ask($c, $out);
                }
                $this->data['name'] = $value;
                $this->data['step'] = 'email';
                break;
            case 'email':
                if (($error = $this->invalid($c, 'validator/user/email', $value)) !== null) {
                    $out->writeln('<warn>' . OutputFormatter::escape($error) . '</warn>');
                    if ($value !== '' && $c->app->make(\Concrete\Core\User\UserInfoRepository::class)->getByEmail($value)) {
                        $out->writeln(t('Is it yours and you forgot the password? Press Ctrl+C and type: %s', '<info>passwd ' . OutputFormatter::escape($value) . '</info>'));
                    }

                    return $this->ask($c, $out);
                }
                $this->data['email'] = $value;
                $this->data['step'] = 'field';
                $this->data['field'] = 0;
                break;
            case 'field':
                $keys = $this->keys($c);
                $key = $keys[(int) $this->data['field']] ?? null;
                if ($key) {
                    $parsed = (new Login($c->app))->parse($key->getAttributeTypeHandle(), mb_substr($value, 0, 1000));
                    if ($parsed === null) {
                        $out->writeln('<warn>' . OutputFormatter::escape($this->fieldHelp($key, $value)) . '</warn>');

                        return $this->ask($c, $out);
                    }
                    $this->data['values'][$key->getAttributeKeyHandle()] = $key->getAttributeTypeHandle() === 'boolean' ? 'y' : $value;
                }
                $this->data['field'] = (int) $this->data['field'] + 1;
                break;
            case 'captcha':
                if (strtoupper(preg_replace('/\s+/', '', $value)) !== (string) ($this->data['captcha'] ?? '')) {
                    $this->data['captchaTries'] = (int) ($this->data['captchaTries'] ?? 0) + 1;
                    if ($this->data['captchaTries'] >= 5) {
                        $out->writeln('<error> ' . t('That did not work out. Please try the registration page: register --web') . ' </error>');

                        return null;
                    }
                    $out->writeln('<warn>' . t('Not quite. Here is a new word, no rush.') . '</warn>');

                    return $this->ask($c, $out);
                }
                $this->data['human'] = true;
                $this->data['step'] = 'summary';
                break;
            case 'summary':
                $yes = self::yesNo($value);
                if ($yes === null) {
                    $out->writeln('<warn>' . t('Please type y for yes or n for no.') . '</warn>');

                    return $this->ask($c, $out);
                }
                if (!$yes) {
                    $out->writeln(t('No problem, let us go through it again from the top.'));
                    $this->data = ['wizard' => $this->data['wizard'] ?? false, 'human' => $this->data['human'] ?? false, 'step' => $this->firstStep($c)];

                    return $this->ask($c, $out);
                }
                $this->data['step'] = 'password';
                break;
            case 'password':
                if (($error = $this->invalid($c, 'validator/password', $answer)) !== null) {
                    $out->writeln('<warn>' . OutputFormatter::escape($error) . '</warn>');

                    return $this->ask($c, $out);
                }
                if (!$c->app->make('config')->get('concrete.user.registration.display_confirm_password_field')) {
                    return $this->create($c, $out, $answer);
                }
                // only a hash waits for the repeat
                $this->data['hash'] = password_hash($answer, PASSWORD_DEFAULT);
                $this->data['step'] = 'repeat';
                break;
            case 'repeat':
                if (!password_verify($answer, (string) ($this->data['hash'] ?? ''))) {
                    unset($this->data['hash']);
                    $out->writeln('<warn>' . t('The two passwords were not the same. That happens! Let us do the password once more.') . '</warn>');
                    $this->data['step'] = 'password';

                    return $this->ask($c, $out);
                }
                unset($this->data['hash']);

                return $this->create($c, $out, $answer);
            default:
                return null;
        }

        return $this->ask($c, $out);
    }

    public function cancel(Context $c, OutputInterface $out): void
    {
        $out->writeln('<dim>' . t('Stopped. No account was created. Type %s to start again whenever you like.', empty($this->data['wizard']) ? 'register' : 'easy') . '</dim>');
    }

    /**
     * Prints the question of the current step and returns its prompt.
     */
    protected function ask(Context $c, OutputInterface $out): ?Question
    {
        $config = $c->app->make('config');
        $step = (string) ($this->data['step'] ?? '');
        if ($step === 'field') {
            $keys = $this->keys($c);
            $key = $keys[(int) ($this->data['field'] ?? 0)] ?? null;
            if ($key === null) {
                $step = $this->data['step'] = $config->get('concrete.user.registration.captcha') && empty($this->data['human']) ? 'captcha' : 'summary';
            } else {
                $name = $key->getAttributeKeyDisplayName('text');
                if ($key->getAttributeTypeHandle() === 'boolean') {
                    $privacy = $this->privacyPage($key);
                    $out->writeln(t('%s?', '<comment>' . OutputFormatter::escape($name) . '</comment>'));
                    if ($privacy !== null) {
                        $out->writeln('<dim>' . t('You can read it here: %s', OutputFormatter::escape($privacy)) . '</dim>');
                    }

                    return new Question(t('[y/n]') . ' > ');
                }
                $out->writeln('<comment>' . OutputFormatter::escape($name) . '</comment>' . $this->fieldHint($key));

                return new Question($name . ': ');
            }
        }
        switch ($step) {
            case 'name':
                $out->writeln('<comment>' . t('Your username') . '</comment>');
                // the rule comes from the config, in English; core's translations know it
                $rule = (string) $config->get('concrete.user.username.allowed_characters.requirement_string');
                $out->writeln('<dim>' . OutputFormatter::escape(t('Your nickname on the website, e.g. at the LAN party. %s', $rule !== '' ? t($rule) : '')) . '</dim>');

                return new Question(t('Username: '));
            case 'email':
                $out->writeln('<comment>' . t('Your email address') . '</comment>');
                $out->writeln('<dim>' . t('We send a confirmation link there, so please use one you can open now.') . '</dim>');

                return new Question(t('Email: '));
            case 'captcha':
                $word = '';
                for ($i = 0; $i < 5; ++$i) {
                    $word .= self::CAPTCHA_LETTERS[random_int(0, strlen(self::CAPTCHA_LETTERS) - 1)];
                }
                $this->data['captcha'] = $word;
                $out->writeln('<comment>' . t('Almost done. Please prove that you are a human: type the word in the big letters.') . '</comment>');
                $out->writeln(implode("\n", Figlet::render($word, 60)), OutputInterface::OUTPUT_RAW);

                return new Question(t('The word: '));
            case 'summary':
                $out->writeln('<comment>' . t('Please check your details:') . '</comment>');
                $rows = [];
                if ($this->displayUserName($c)) {
                    $rows[t('Username')] = (string) $this->data['name'];
                }
                $rows[t('Email')] = (string) $this->data['email'];
                foreach ($this->keys($c) as $key) {
                    $v = (string) ($this->data['values'][$key->getAttributeKeyHandle()] ?? '');
                    $rows[$key->getAttributeKeyDisplayName('text')] = $key->getAttributeTypeHandle() === 'boolean' ? t('yes') : $v;
                }
                $width = max(array_map('mb_strlen', array_keys($rows)));
                foreach ($rows as $label => $v) {
                    $out->writeln('  ' . OutputFormatter::escape($label) . str_repeat(' ', $width - mb_strlen($label)) . '  <info>' . OutputFormatter::escape($v) . '</info>');
                }
                $out->writeln(t('Is everything correct?'));

                return new Question(t('[y/n]') . ' > ');
            case 'password':
                $min = (int) $config->get('concrete.user.password.minimum');
                $out->writeln('<comment>' . t('Last step: choose a password') . '</comment>' . ($min > 0 ? ' <dim>' . t('(at least %d characters)', $min) . '</dim>' : ''));
                $out->writeln('<dim>' . t('You will not see what you type, not even dots. That is on purpose: just type it and press Enter.') . '</dim>');

                return new Question(t('Password: '), true);
            case 'repeat':
                $out->writeln(t('Please type the same password once more, to be sure there is no typo.'));

                return new Question(t('Repeat password: '), true);
        }

        return null;
    }

    /**
     * Creates the account like the registration page, then signs in, sends the validation mail or waits for approval.
     */
    protected function create(Context $c, OutputInterface $out, string $password): ?Question
    {
        $app = $c->app;
        $config = $app->make('config');
        // somebody may have taken the name or the address in the meantime
        $name = (string) ($this->data['name'] ?? '');
        foreach (['name' => ['validator/user/name', $name], 'email' => ['validator/user/email', (string) $this->data['email']]] as $step => [$validator, $value]) {
            if (($step !== 'name' || $this->displayUserName($c)) && ($error = $this->invalid($c, $validator, $value)) !== null) {
                $out->writeln('<warn>' . OutputFormatter::escape($error) . '</warn>');
                $this->data['step'] = $step;

                return $this->ask($c, $out);
            }
        }
        if (($problem = self::unavailable($c)) !== null) {
            $out->writeln('<error> ' . OutputFormatter::escape($problem) . ' </error>');

            return null;
        }
        if (!$this->displayUserName($c)) {
            $name = $app->make(\Concrete\Core\Application\Service\User::class)->generateUsernameFromEmail((string) $this->data['email']);
        }

        $process = $app->make('user/registration')->createFromPublicRegistration([
            'uName' => $name,
            'uEmail' => (string) $this->data['email'],
            'uPassword' => $password,
            'uPasswordConfirm' => $password,
        ]);
        if (!is_object($process)) {
            $out->writeln('<error> ' . t('The account could not be created. Please try the registration page: register --web') . ' </error>');

            return null;
        }
        $session = $app->make('session');
        $session->set('cli_mode.registrations', array_merge((array) $session->get('cli_mode.registrations', []), [time()]));
        $login = new Login($app);
        foreach ($this->keys($c) as $key) {
            $raw = (string) ($this->data['values'][$key->getAttributeKeyHandle()] ?? '');
            $value = $login->parse($key->getAttributeTypeHandle(), $raw);
            if ($value !== null) {
                $process->setAttribute($key, $value);
            }
        }
        $this->notify($c, $process);

        // as on the registration page: inactive first, then validation mail, approval workflow or straight in
        $process->deactivate();
        $out->writeln('');
        if ($config->get('concrete.user.registration.validate_email')) {
            $app->make('user/status')->sendEmailValidation($process);
            $out->writeln('<ok>' . t('Your account %s has been created!', OutputFormatter::escape($process->getUserName())) . '</ok>');
            $out->writeln(t('One more thing: we just sent an email to %s. Please open it and click the link inside, so we know the address is really yours.', '<info>' . OutputFormatter::escape($process->getUserEmail()) . '</info>'));
            $out->writeln(t('Then come back here and type %s.', '<info>login</info>') . (!empty($this->data['wizard']) ? ' ' . t('After that, %s takes you on to the signup.', '<info>easy</info>') : ''));
            $out->writeln('<dim>' . t('No mail after a few minutes? Have a look in the spam folder.') . '</dim>');

            return null;
        }
        $process->markValidated();
        if (!$process->triggerActivate('register_activate', USER_SUPER_ID)) {
            $out->writeln('<ok>' . t('Your account has been created.') . '</ok> ' . t('An administrator has to approve it before you can sign in. You will get an email.'));

            return null;
        }
        if ($login->signIn((int) $process->getUserID())) {
            $c->switchUser($login->user());
            $out->writeln('<ok>' . t('Your account has been created and you are signed in. Welcome, %s!', OutputFormatter::escape($process->getUserName())) . '</ok>');
            if (!empty($this->data['wizard'])) {
                return $this->handOver(new EasyDialog(['step' => 'resume']));
            }
        } else {
            $out->writeln('<ok>' . t('Your account has been created.') . '</ok> ' . t('Sign in with: %s', 'login'));
        }

        return null;
    }

    /**
     * The mail to the site's admins about the new account, when the site wants one.
     */
    protected function notify(Context $c, $process): void
    {
        $config = $c->app->make('config');
        if (!$config->get('concrete.user.registration.notification')) {
            return;
        }
        try {
            $mh = $c->app->make('mail');
            $to = (string) $config->get('concrete.user.registration.notification_email');
            if ($to === '') {
                $admin = UserInfo::getByID(USER_SUPER_ID);
                $to = $admin ? (string) $admin->getUserEmail() : '';
            }
            if ($to === '') {
                return;
            }
            $mh->to($to);
            $mh->addParameter('uID', $process->getUserID());
            $mh->addParameter('user', $process);
            $mh->addParameter('uName', $process->getUserName());
            $mh->addParameter('uEmail', $process->getUserEmail());
            $values = [];
            foreach (UserAttributeKey::getRegistrationList() as $ak) {
                $values[] = $ak->getAttributeKeyDisplayName('text') . ': ' . $process->getAttribute($ak->getAttributeKeyHandle(), 'display');
            }
            $mh->addParameter('attribs', $values);
            $mh->addParameter('siteName', h(tc('SiteName', $c->app->make('site')->getSite()->getSiteName())));
            $from = (string) ($config->get('concrete.email.register_notification.address') ?: $config->get('concrete.email.default.address'));
            if ($from !== '') {
                $mh->from($from, (string) ($config->get('concrete.email.register_notification.name') ?: t('Website Registration Notification')));
            }
            $mh->load('user_register');
            $mh->sendMail();
        } catch (\Throwable $e) {
            // the account exists, a missing notification is no reason to tell the new member it failed
            $c->app->make(\Concrete\Core\Logging\LoggerFactory::class)->createLogger('cli_mode')->warning('register notification: ' . $e->getMessage());
        }
    }

    protected function firstStep(Context $c): string
    {
        return $this->displayUserName($c) ? 'name' : 'email';
    }

    protected function displayUserName(Context $c): bool
    {
        return (bool) $c->app->make('config')->get('concrete.user.registration.display_username_field');
    }

    /**
     * @return UserAttributeKey[] the required profile details, in the order the profile shows them
     */
    protected function keys(Context $c): array
    {
        return (new Login($c->app))->requiredKeys();
    }

    /**
     * The first complaint of one of Concrete's validators, null when the value passes.
     */
    protected function invalid(Context $c, string $validator, string $value): ?string
    {
        $errors = $c->app->make('error');
        $c->app->make($validator)->isValid($value, $errors);
        if (!$errors->has()) {
            return null;
        }
        $list = $errors->getList();

        return trim(strip_tags((string) reset($list)));
    }

    protected function fieldHint($key): string
    {
        switch ($key->getAttributeTypeHandle()) {
            case 'telephone':
                return ' <dim>' . t('(e.g. 079 123 45 67)') . '</dim>';
            case 'email':
                return ' <dim>' . t('(an email address)') . '</dim>';
            case 'number':
                return ' <dim>' . t('(a number)') . '</dim>';
        }

        return '';
    }

    protected function fieldHelp($key, string $answer): string
    {
        if ($key->getAttributeTypeHandle() === 'boolean') {
            return self::yesNo($answer) === false
                ? t('Without a yes here the registration cannot go on. Take your time to read it, then type y. (Ctrl+C stops.)')
                : t('Please type y for yes or n for no.');
        }
        if ($answer === '') {
            return t('This one is required, please fill it in.');
        }
        switch ($key->getAttributeTypeHandle()) {
            case 'telephone':
                return t('That does not look like a phone number. Digits, spaces and + are fine, e.g. 079 123 45 67.');
            case 'email':
                return t('That does not look like an email address.');
            case 'number':
                return t('Please type a number.');
        }

        return t('That did not work, please try again.');
    }

    /**
     * Where the privacy policy is, for the yes/no question about it.
     */
    protected function privacyPage($key): ?string
    {
        if (!preg_match('/privacy|datenschutz|dsgvo|gdpr/i', $key->getAttributeKeyHandle() . ' ' . $key->getAttributeKeyDisplayName('text'))) {
            return null;
        }
        $db = \Concrete\Core\Support\Facade\Application::getFacadeApplication()->make('database')->connection();
        $cID = $db->fetchOne("SELECT cID FROM PagePaths WHERE ppIsCanonical = 1 AND (cPath LIKE '%datenschutz%' OR cPath LIKE '%privacy%') ORDER BY LENGTH(cPath)");
        $page = $cID ? Page::getByID((int) $cID) : null;
        if (!$page || $page->isError() || !(new \Concrete\Core\Permission\Checker($page))->canViewPage()) {
            return null;
        }

        return (string) $page->getCollectionLink() . '  ·  cat ' . $page->getCollectionPath();
    }
}
