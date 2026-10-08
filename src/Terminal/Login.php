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
                $this->message = 'Unknown login error.';

                return false;
            }

            // required profile attributes are asked for on the login page, which this cannot do
            $info = UserInfo::getByID($user->getUserID());
            foreach (UserAttributeKey::getRegistrationList() as $key) {
                if ($key->isAttributeKeyRequiredOnRegister() && !is_object($info->getAttributeValueObject($key))) {
                    $user->logout(false);
                    $this->message = 'Your profile is missing some required details. Use the login page for the first sign-in: login --web';

                    return false;
                }
            }

            $service->logLoginAttempt($username);
            if ($type = AuthenticationType::getByHandle('concrete')) {
                $user->setLastAuthType($type);
            }
            $this->app->make('director')->dispatch('on_user_login', new \Concrete\Core\User\Event\User($user));
            $this->app->make(NavigationCache::class)->clear();
            $this->user = $user;

            return true;
        } catch (\Throwable $e) {
            // whatever it was, the terminal does not explain Concrete's internals to a stranger
            $this->message = 'Login failed.';
            try {
                $this->app->make(\Concrete\Core\Logging\LoggerFactory::class)->createLogger('cli_mode')->error('login: ' . $e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $ignored) {
            }

            return false;
        }
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
