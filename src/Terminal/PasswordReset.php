<?php

namespace CliMode\Terminal;

use Concrete\Core\Application\Application;
use Concrete\Core\Authentication\AuthenticationType;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\User\ValidationHash;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Sends the "forgot password" mail of the login page: the same template, the same link to
 * /login/callback/concrete/change_password/<hash>, the same request limit. Whether somebody with that name exists is
 * never told, like on the login page.
 */
class PasswordReset
{
    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param string $typed an email address or a user name
     *
     * @return string|null null when the request went through (a mail is on its way if the account exists), else why not
     */
    public function request(string $typed): ?string
    {
        $limit = $this->app->make('ip/access/control/forgot_password');
        if ($limit->isDenylisted()) {
            return t('Unable to request password reset: too many attempts. Please try again later.');
        }
        $reached = $limit->isThresholdReached();
        $limit->registerEvent();
        if ($reached) {
            $limit->addToDenylistForThresholdReached();

            return t('Unable to request password reset: too many attempts. Please try again later.');
        }

        $typed = trim($typed);
        $users = $this->app->make(UserInfoRepository::class);
        $info = $users->getByEmail($typed) ?: $users->getByName($typed);
        if (!$info || !$info->isActive()) {
            return null;
        }
        try {
            $this->send($info);
        } catch (\Throwable $e) {
            try {
                $this->app->make(\Concrete\Core\Logging\LoggerFactory::class)->createLogger('cli_mode')->error('passwd: ' . $e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $ignored) {
            }

            return t('The mail could not be sent. Try the login page: %s', (string) $this->app->make('url/manager')->resolve(['/login/concrete/forgot_password']));
        }

        return null;
    }

    protected function send(UserInfo $info): void
    {
        $config = $this->app->make('config');
        $type = AuthenticationType::getByHandle('concrete');
        $mail = $this->app->make('helper/mail');
        $mail->addParameter('uName', $config->get('concrete.user.registration.email_registration') ? $info->getUserEmail() : $info->getUserName());
        $mail->to($info->getUserEmail());
        $mail->addParameter('isForgotPassword', true);
        $hash = (new ValidationHash())->add($info->getUserID(), UVTYPE_CHANGE_PASSWORD, true);
        $mail->addParameter('changePassURL', (string) $this->app->make('url/manager')->resolve(['/login', 'callback', $type ? $type->getAuthenticationTypeHandle() : 'concrete', 'change_password', $hash]));
        $from = (string) $config->get('concrete.email.forgot_password.address');
        if (!strpos($from, '@')) {
            $from = (string) $config->get('concrete.email.default.address');
        }
        if ($from !== '') {
            $mail->from($from, (string) $config->get('concrete.email.forgot_password.name') ?: t('Forgot Password'));
        }
        $mail->addParameter('siteName', h(tc('SiteName', $this->app->make('site')->getSite()->getSiteName())));
        $mail->load('forgot_password');
        $mail->setIsThrowOnFailure(true);
        $mail->sendMail();
    }
}
