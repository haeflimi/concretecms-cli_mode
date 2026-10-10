<?php

namespace CliMode\Terminal;

use Concrete\Core\Application\Application;
use Concrete\Core\Application\UserInterface\Dashboard\Navigation\NavigationCache;
use Concrete\Core\Attribute\Key\UserKey as UserAttributeKey;
use Concrete\Core\Authentication\AuthenticationType;
use Concrete\Core\User\Exception\FailedLoginThresholdExceededException;
use Concrete\Core\User\Exception\InvalidCredentialsException;
use Concrete\Core\User\Exception\UserDeactivatedException;
use Concrete\Core\User\Exception\UserException;
use Concrete\Core\User\Exception\UserPasswordExpiredException;
use Concrete\Core\User\Exception\UserPasswordResetException;
use Concrete\Core\User\Login\LoginService;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Signs somebody in with a username and password, the way the password form of the login page does: the same
 * LoginService, the same failed login throttling and deny list, the same "on_user_login" event. What the form does
 * with the browser (redirects, forms for required attributes and password changes) is left to the real login page:
 * those cases end with a message that points there.
 */
class Login
{
    /** @var Application */
    protected $app;

    /** @var User|null the signed-in user after a successful attempt() */
    protected $user;

    /** @var string|null why it failed, null when the credentials were simply wrong */
    protected $message;

    /** @var array<int, array{handle: string, prompt: string, type: string}> required profile details the terminal asks for before the sign-in */
    protected $fields = [];

    /** session key of a sign-in that waits for those details: [uID, username, expires] */
    const PENDING = 'cli_mode.pending_login';

    /** minutes the details may take */
    const PENDING_TTL = 10;

    /** attribute types the terminal can ask for on one line */
    const ASKABLE = ['text', 'textarea', 'telephone', 'email', 'url', 'number', 'boolean'];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @return bool whether the visitor is signed in now
     */
    public function attempt(string $username, string $password): bool
    {
        $this->user = null;
        $this->message = null;
        $username = trim($username);
        if ($username === '' || $password === '') {
            return false;
        }

        try {
            $username = $this->identifier($username);
            $denylist = $this->app->make('failed_login');
            if ($denylist->isDenylisted()) {
                $this->message = (string) $denylist->getErrorMessage();

                return false;
            }
            $service = $this->app->make(LoginService::class);
            try {
                $user = $service->login($username, $password);
            } catch (UserPasswordResetException | UserPasswordExpiredException $e) {
                $this->message = $e->getMessage();

                return false;
            } catch (UserException $e) {
                return $this->failed($service, $username, $password, $e);
            }
            if ($user->isError()) {
                $this->message = t('Unknown login error.');

                return false;
            }

            // required profile details the login page would ask for: the terminal asks for them itself, the sign-in waits
            $missing = $this->missingKeys(UserInfo::getByID($user->getUserID()));
            if ($missing) {
                $user->logout(false);
                foreach ($missing as $key) {
                    if (!in_array($key->getAttributeTypeHandle(), self::ASKABLE, true)) {
                        $this->message = t('Your profile is missing some required details. Use the login page for the first sign-in: login --web');

                        return false;
                    }
                }
                $this->fields = $this->describe($missing);
                $this->app->make('session')->set(self::PENDING, [(int) $user->getUserID(), $username, time() + self::PENDING_TTL * 60]);
                $this->message = t('Your profile is missing some details. Fill them in to finish the sign-in (Ctrl+C cancels).');

                return false;
            }

            return $this->finish($user, $username);
        } catch (\Throwable $e) {
            // whatever it was, the terminal does not explain Concrete's internals to a stranger
            $this->message = t('Login failed.');
            try {
                $this->app->make(\Concrete\Core\Logging\LoggerFactory::class)->createLogger('cli_mode')->error('login: ' . $e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $ignored) {
            }

            return false;
        }
    }

    /**
     * The second half of a sign-in that waited for profile details: saves the answers of the terminal, then signs in
     * the user whose password was checked a moment ago. Answers that do not fit come back as fields() with a message.
     *
     * @param array<string, mixed> $values attribute handle => typed answer
     *
     * @return bool whether the visitor is signed in now
     */
    public function completeProfile(array $values): bool
    {
        $this->user = null;
        $this->message = null;
        $this->fields = [];
        $session = $this->app->make('session');
        $pending = $session->get(self::PENDING);
        if (!is_array($pending) || count($pending) !== 3 || $pending[2] < time()) {
            $session->remove(self::PENDING);
            $this->message = t('That took too long. Start over with: login');

            return false;
        }
        [$uID, $username] = $pending;

        try {
            $info = UserInfo::getByID($uID);
            if (!$info || !$info->isActive()) {
                $session->remove(self::PENDING);
                $this->message = t('Login failed.');

                return false;
            }
            foreach ($this->missingKeys($info) as $key) {
                $handle = $key->getAttributeKeyHandle();
                $value = $this->parse($key->getAttributeTypeHandle(), trim(substr((string) ($values[$handle] ?? ''), 0, 1000)));
                if ($value !== null) {
                    $info->setAttribute($key, $value);
                }
            }
            $info = UserInfo::getByID($uID);
            $missing = $this->missingKeys($info);
            if ($missing) {
                $this->fields = $this->describe($missing);
                $this->message = t('Not quite: %s. Once more, please.', implode(', ', array_map(static function ($key) {
                    return $key->getAttributeKeyDisplayName('text');
                }, $missing)));

                return false;
            }

            $session->remove(self::PENDING);
            $user = User::getByUserID($uID, true);
            if (!$user || $user->isError() || !$user->isRegistered()) {
                $this->message = t('Login failed.');

                return false;
            }

            return $this->finish($user, $username);
        } catch (\Throwable $e) {
            $this->message = t('Login failed.');
            try {
                $this->app->make(\Concrete\Core\Logging\LoggerFactory::class)->createLogger('cli_mode')->error('login profile: ' . $e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $ignored) {
            }

            return false;
        }
    }

    /**
     * What the password form does after a good login.
     */
    protected function finish(User $user, string $username): bool
    {
        $this->app->make(LoginService::class)->logLoginAttempt($username);
        if ($type = AuthenticationType::getByHandle('concrete')) {
            $user->setLastAuthType($type);
        }
        $this->app->make('director')->dispatch('on_user_login', new \Concrete\Core\User\Event\User($user));
        $this->app->make(NavigationCache::class)->clear();
        $this->user = $user;

        return true;
    }

    /**
     * @return UserAttributeKey[] the attributes required on registration that the user has no value for
     */
    protected function missingKeys(UserInfo $info): array
    {
        $missing = [];
        foreach (UserAttributeKey::getRegistrationList() as $key) {
            if ($key->isAttributeKeyRequiredOnRegister() && !$this->hasValue($info, $key)) {
                $missing[] = $key;
            }
        }
        // in the order of the profile (attribute sets), the yes/no questions (privacy policy and the like) at the end
        $order = [];
        if ($missing) {
            foreach ($this->app->make('database')->connection()->fetchAllAssociative(
                'SELECT sk.akID FROM AttributeSetKeys sk INNER JOIN AttributeSets s ON s.asID = sk.asID ORDER BY s.asDisplayOrder, s.asID, sk.asDisplayOrder'
            ) as $i => $row) {
                $order[(int) $row['akID']] = $order[(int) $row['akID']] ?? $i;
            }
        }
        usort($missing, static function ($a, $b) use ($order) {
            return [$a->getAttributeTypeHandle() === 'boolean', $order[$a->getAttributeKeyID()] ?? PHP_INT_MAX]
                <=> [$b->getAttributeTypeHandle() === 'boolean', $order[$b->getAttributeKeyID()] ?? PHP_INT_MAX];
        });

        return $missing;
    }

    /**
     * A value object alone is not enough: an empty text or an unticked checkbox counts as missing, like on the form.
     */
    protected function hasValue(UserInfo $info, $key): bool
    {
        if (!is_object($info->getAttributeValueObject($key))) {
            return false;
        }
        $value = $info->getAttribute($key);
        if ($key->getAttributeTypeHandle() === 'boolean') {
            return (bool) $value;
        }

        return is_object($value) || trim((string) $value) !== '';
    }

    /**
     * @param UserAttributeKey[] $keys
     *
     * @return array<int, array{handle: string, prompt: string, type: string}>
     */
    protected function describe(array $keys): array
    {
        return array_map(static function ($key) {
            $type = $key->getAttributeTypeHandle();
            $name = $key->getAttributeKeyDisplayName('text');

            return [
                'handle' => $key->getAttributeKeyHandle(),
                'prompt' => $type === 'boolean' ? t('%s? [y/n]', $name) . ' ' : $name . ': ',
                'type' => $type,
            ];
        }, $keys);
    }

    /**
     * @return mixed the value to store, null when the answer does not do
     */
    protected function parse(string $type, string $answer)
    {
        if ($type === 'boolean') {
            // a required checkbox (privacy policy and the like) only counts when ticked
            return in_array(mb_strtolower($answer), ['y', 'yes', 'j', 'ja', 'o', 'oui'], true) ? true : null;
        }
        if ($answer === '') {
            return null;
        }
        switch ($type) {
            case 'email':
                return filter_var($answer, FILTER_VALIDATE_EMAIL) ? $answer : null;
            case 'url':
                return filter_var(preg_match('~^https?://~i', $answer) ? $answer : 'https://' . $answer, FILTER_VALIDATE_URL) ?: null;
            case 'telephone':
                return preg_match('/^\+?[0-9 ()\/.-]{6,30}$/', $answer) ? $answer : null;
            case 'number':
                return is_numeric($answer) ? $answer : null;
        }

        return mb_substr($answer, 0, 255);
    }

    /**
     * @return array<int, array{handle: string, prompt: string, type: string}> what the terminal must ask for, empty when nothing
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * What Concrete's login wants: the email address when the site has email registration, else the user name. The
     * terminal takes either, so the typed name is looked up as both. Somebody unknown is passed on as typed and fails
     * like any wrong login, the message does not tell which half was wrong.
     */
    protected function identifier(string $typed): string
    {
        $byEmail = (bool) $this->app->make('config')->get('concrete.user.registration.email_registration');
        $users = $this->app->make(UserInfoRepository::class);
        $info = $byEmail ? ($users->getByEmail($typed) ?: $users->getByName($typed)) : ($users->getByName($typed) ?: $users->getByEmail($typed));
        if (!$info) {
            return $typed;
        }

        return (string) ($byEmail ? $info->getUserEmail() : $info->getUserName()) ?: $typed;
    }

    protected function failed(LoginService $service, string $username, string $password, UserException $e): bool
    {
        if ($e instanceof InvalidCredentialsException) {
            try {
                $service->failLogin($username, $password);
            } catch (FailedLoginThresholdExceededException | UserDeactivatedException $limit) {
                $service->logLoginAttempt($username, ['Failed Login Threshold Exceeded or User Deactivated', $limit->getMessage()]);
                $this->message = $limit->getMessage();

                return false;
            }
            $service->logLoginAttempt($username, ['Invalid Credentials', $e->getMessage()]);

            return false;
        }
        $service->logLoginAttempt($username, ['Login refused', $e->getMessage()]);
        $this->message = $e->getMessage();

        return false;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Why the login failed, or null when the credentials were wrong (the terminal says that in its own voice).
     */
    public function message(): ?string
    {
        return $this->message;
    }
}
