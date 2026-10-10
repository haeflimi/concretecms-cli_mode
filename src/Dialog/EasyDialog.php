<?php

namespace CliMode\Dialog;

use CliMode\Command\Lan\CreditCommands;
use CliMode\Command\Lan\EventCommands;
use CliMode\Terminal\Context;
use CliMode\Terminal\Dialog;
use CliMode\Terminal\Login;
use CliMode\Terminal\PasswordReset;
use CliMode\Terminal\Question;
use CommunityEventManager\Entity\Event;
use CommunityEventManager\Event\EventService;
use CommunityEventManager\Event\LocationFormatter;
use CommunityEventManager\Participation\ParticipationService;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * "easy": the easy mode. A patient wizard that takes somebody who has never seen a terminal through the whole signup
 * for an event: sign in (or create an account), pick the event, sign up, pick a seat, pay. It asks before it does
 * anything, explains every step and never minds a wrong answer. The actual work is done by the regular commands
 * (signup, seat, pay), and the summary at the end shows them, for next time.
 *
 * It always speaks kindly, whatever the attitude of the shell.
 */
class EasyDialog extends Dialog
{
    public function start(Context $c, OutputInterface $out): ?Question
    {
        if (($this->data['step'] ?? '') === 'resume') {
            $out->writeln('');
            $out->writeln(t('Great, let us continue with the signup.'));
        } else {
            $out->writeln('<accent>' . t('Easy mode') . '</accent>');
            $out->writeln(t('Hello! I will help you sign up for an event, one small step at a time.'));
            $out->writeln(t('There are no wrong answers: if I do not understand something, I will simply ask again.'));
            $out->writeln(t('I will not do anything without asking you first. You can stop at any time with Ctrl+C or Esc.'));
        }
        $this->data['done'] = $this->data['done'] ?? [];

        return $this->route($c, $out);
    }

    public function answer(Context $c, string $answer, OutputInterface $out): ?Question
    {
        $value = trim($answer);
        switch ((string) ($this->data['step'] ?? '')) {
            case 'account':
                $yes = self::yesNo($value);
                if ($yes === null) {
                    return $this->yesNoAgain($out);
                }
                if (!$yes) {
                    $out->writeln(t('No problem, we will create one together. It takes about two minutes.'));
                    $out->writeln('');

                    return $this->handOver(new RegisterDialog(['wizard' => true]));
                }

                return $this->askLoginName($out);
            case 'login_name':
                if ($value === '') {
                    $out->writeln(t('I did not get anything. Please type your email address or your username and press Enter.'));

                    return new Question(t('Email or username: '));
                }
                $this->data['login'] = mb_substr($value, 0, 255);
                $this->data['step'] = 'login_password';
                $out->writeln(t('Thank you. Now your password.'));
                $out->writeln('<dim>' . t('You will not see what you type, not even dots. That is on purpose: just type it and press Enter.') . '</dim>');

                return new Question(t('Password: '), true);
            case 'login_password':
                return $this->login($c, $out, $answer);
            case 'reset':
                $yes = self::yesNo($value);
                if ($yes === null) {
                    return $this->yesNoAgain($out);
                }
                if ($yes) {
                    $error = (new PasswordReset($c->app))->request((string) ($this->data['login'] ?? ''));
                    if ($error !== null) {
                        $out->writeln('<warn>' . OutputFormatter::escape($error) . '</warn>');
                    } else {
                        $out->writeln('<ok>' . t('Done. If there is an account for "%s", an email with a link is on its way.', OutputFormatter::escape((string) $this->data['login'])) . '</ok>');
                        $out->writeln(t('Click the link in the email and choose a new password. Then come back and type %s, and we continue right here.', '<info>easy</info>'));
                        $out->writeln('<dim>' . t('No mail after a few minutes? Have a look in the spam folder.') . '</dim>');
                    }

                    return null;
                }
                $out->writeln(t('Okay, let us try once more.'));
                $this->data['tries'] = 0;

                return $this->askLoginName($out);
            case 'profile':
                return $this->profile($c, $out, $value);
            case 'pick':
                $events = EventCommands::openEvents($c);
                $n = ctype_digit($value) ? (int) $value : 0;
                if ($n < 1 || $n > count($events)) {
                    $out->writeln(t('Please type one of the numbers from the list, from 1 to %d.', count($events)));

                    return new Question(t('Number: '));
                }

                return $this->chosen($c, $out, $events[$n - 1]);
            case 'confirm':
                $yes = self::yesNo($value);
                if ($yes === null) {
                    return $this->yesNoAgain($out);
                }
                if (!$yes) {
                    $out->writeln(t('Okay, I did not sign you up. If you change your mind, just type %s again.', '<info>easy</info>'));

                    return null;
                }

                return $this->signup($c, $out);
            case 'seat_want':
                $yes = self::yesNo($value);
                if ($yes === null) {
                    return $this->yesNoAgain($out);
                }
                if (!$yes) {
                    $out->writeln(t('Okay. You can choose a seat later with: %s', '<info>seat ' . $this->eventID() . '</info>'));

                    return $this->payStep($c, $out);
                }
                $this->run($c, $out, 'seat ' . $this->eventID());
                $this->data['step'] = 'seat';
                $out->writeln(t('The green seats are free. Type the name of the one you would like, e.g. %s. Or just press Enter to skip.', $this->exampleSeat($c)));

                return new Question(t('Seat: '));
            case 'seat':
                if ($value === '') {
                    $out->writeln(t('Okay, no seat for now. You can choose one later with: %s', '<info>seat ' . $this->eventID() . '</info>'));

                    return $this->payStep($c, $out);
                }
                if (preg_match('/[^A-Za-z0-9 _.-]/', $value) || $this->run($c, $out, 'seat ' . $this->eventID() . ' ' . $value) !== 0) {
                    $out->writeln(t('That one did not work. Please pick another one that is green in the plan above (or press Enter to skip).'));

                    return new Question(t('Seat: '));
                }
                $this->data['done'][] = 'seat ' . $this->eventID() . ' ' . $value;

                return $this->payStep($c, $out);
            case 'pay_want':
                $yes = self::yesNo($value);
                if ($yes === null) {
                    return $this->yesNoAgain($out);
                }
                if (!$yes) {
                    $out->writeln(t('Okay. You can pay later with %s, or at the event.', '<info>pay</info>'));

                    return $this->finish($c, $out);
                }
                $bills = CreditCommands::payableBills($c);
                if (count($bills) === 1) {
                    return $this->pay($c, $out, (int) $bills[0]['id']);
                }
                $this->data['step'] = 'pay_pick';
                $out->writeln(t('Which bill would you like to pay? Type its number:'));
                foreach ($bills as $bill) {
                    $out->writeln('  <accent>' . $bill['id'] . '</accent>  ' . OutputFormatter::escape($bill['what']) . '  <info>' . CreditCommands::money($bill['total']) . '</info>');
                }

                return new Question(t('Number: '));
            case 'pay_pick':
                foreach (CreditCommands::payableBills($c) as $bill) {
                    if ((string) $bill['id'] === ltrim($value, '#')) {
                        return $this->pay($c, $out, (int) $bill['id']);
                    }
                }
                $out->writeln(t('Please type one of the numbers from the list (or press Ctrl+C to stop).'));

                return new Question(t('Number: '));
        }

        return null;
    }

    public function cancel(Context $c, OutputInterface $out): void
    {
        $out->writeln('<dim>' . t('Okay, we stop here. Whatever is done stays done. Type %s whenever you want to continue.', 'easy') . '</dim>');
    }

    /**
     * The next thing to do, from what is true right now: sign in, else pick an event.
     */
    protected function route(Context $c, OutputInterface $out): ?Question
    {
        if (!$c->isRegistered()) {
            $this->data['step'] = 'account';
            $out->writeln('');
            $out->writeln('<comment>' . t('Step 1: your account') . '</comment>');
            $out->writeln(t('To sign up, you need an account on this website. Do you already have one?'));

            return new Question(t('[y/n]') . ' > ');
        }
        $out->writeln('');
        $out->writeln('<comment>' . t('Step 2: the event') . '</comment>');
        $events = EventCommands::openEvents($c);
        if ($events === []) {
            $out->writeln(t('Right now no event is open for signups. Sorry! Have a look at what is coming with %s, or come back later.', '<info>events</info>'));
            $out->writeln(t('The good news: your account is ready, so next time it will be quick.'));

            return null;
        }
        if (count($events) === 1) {
            return $this->chosen($c, $out, $events[0]);
        }
        $this->data['step'] = 'pick';
        $out->writeln(t('These events are open for signups:'));
        foreach ($events as $i => $event) {
            $out->writeln(sprintf('  <accent>%d</accent>  %s  <dim>%s</dim>', $i + 1, OutputFormatter::escape($event->getName()), $event->getStartsAt() ? $event->getStartsAt()->format('d.m.Y') : ''));
        }
        $out->writeln(t('Which one would you like to join? Type its number.'));

        return new Question(t('Number: '));
    }

    protected function askLoginName(OutputInterface $out): Question
    {
        $this->data['step'] = 'login_name';
        $out->writeln(t('Fine, let us sign you in. Type your email address (or your username) and press Enter.'));

        return new Question(t('Email or username: '));
    }

    protected function login(Context $c, OutputInterface $out, string $password): ?Question
    {
        $login = new Login($c->app);
        if ($login->throttled()) {
            $out->writeln(t('That was a lot of tries in a short time. Let us take a short break: wait a minute, then type %s again.', '<info>easy</info>'));

            return null;
        }
        if ($login->attempt((string) ($this->data['login'] ?? ''), substr($password, 0, 1024))) {
            return $this->signedIn($c, $out, $login);
        }
        if ($login->fields()) {
            // right password, but the profile misses required details: ask for them, then the sign-in happens
            $out->writeln(t('The password is right! Your profile is just missing a few details. Please fill them in:'));
            $this->data['step'] = 'profile';
            $this->data['fields'] = $login->fields();
            $this->data['field'] = 0;
            $this->data['values'] = [];

            return new Question($this->data['fields'][0]['prompt']);
        }
        if ($login->message() !== null) {
            $out->writeln('<warn>' . OutputFormatter::escape($login->message()) . '</warn>');

            return null;
        }
        $this->data['tries'] = (int) ($this->data['tries'] ?? 0) + 1;
        if ($this->data['tries'] >= 3) {
            $this->data['step'] = 'reset';
            $out->writeln(t('Hmm, that did not work three times. Maybe the password is not the one you remember?'));
            $out->writeln(t('Shall I send you an email with a link to set a new password?'));

            return new Question(t('[y/n]') . ' > ');
        }
        $out->writeln(t('That did not work. Maybe a little typo? Upper and lower case matter in the password. Let us try again.'));

        return $this->askLoginName($out);
    }

    protected function profile(Context $c, OutputInterface $out, string $value): ?Question
    {
        $fields = (array) ($this->data['fields'] ?? []);
        $index = (int) ($this->data['field'] ?? 0);
        if (isset($fields[$index])) {
            $this->data['values'][$fields[$index]['handle']] = mb_substr($value, 0, 1000);
        }
        if (isset($fields[$index + 1])) {
            $this->data['field'] = $index + 1;

            return new Question($fields[$index + 1]['prompt']);
        }
        $login = new Login($c->app);
        if ($login->completeProfile((array) ($this->data['values'] ?? []))) {
            return $this->signedIn($c, $out, $login);
        }
        $out->writeln('<warn>' . OutputFormatter::escape((string) $login->message()) . '</warn>');
        if (!$login->fields()) {
            return null;
        }
        $this->data['fields'] = $login->fields();
        $this->data['field'] = 0;
        $this->data['values'] = [];

        return new Question($this->data['fields'][0]['prompt']);
    }

    protected function signedIn(Context $c, OutputInterface $out, Login $login): ?Question
    {
        $c->switchUser($login->user());
        $out->writeln('<ok>' . t('You are signed in. Welcome, %s!', OutputFormatter::escape((string) $login->user()->getUserName())) . '</ok>');
        $this->data['done'][] = 'login';

        return $this->route($c, $out);
    }

    /**
     * Shows the event and what signing up means, then asks.
     */
    protected function chosen(Context $c, OutputInterface $out, Event $event): ?Question
    {
        $this->data['event'] = $event->getID();
        $participation = $c->app->make(ParticipationService::class);
        $this->describe($c, $out, $event);
        $participant = $participation->getParticipant($event, (int) $c->user->getUserID());
        if ($participant && $participant->isSignedUp()) {
            if ($participant->isWaitlisted()) {
                $out->writeln(t('You are already on the waitlist for this event. When a place becomes free, you move up automatically.'));

                return $this->finish($c, $out);
            }
            $out->writeln('<ok>' . t('Good news: you are already signed up for this event!') . '</ok>');

            return $this->seatStep($c, $out);
        }
        $check = $participation->checkSignup($event, $c->user);
        $reason = $check['reason'] ?? null;
        if ($reason === ParticipationService::REASON_BALANCE) {
            $out->writeln('<warn>' . OutputFormatter::escape($participation->describeReason($event, $reason, $check)) . '</warn>');
            $out->writeln(t('So first the open amount needs to be paid. After that, type %s again and we continue with the signup.', '<info>easy</info>'));

            return $this->payStep($c, $out, false);
        }
        if ($reason === ParticipationService::REASON_GROUP) {
            $groups = array_filter(array_map(static function ($gID) {
                $group = \Concrete\Core\User\Group\Group::getByID((int) $gID);

                return $group ? $group->getGroupDisplayName(false) : null;
            }, $event->getRequiredGroupIDs()));
            $out->writeln('<warn>' . t('This event is only open to: %s.', OutputFormatter::escape(implode(', ', $groups))) . '</warn>');
            $out->writeln(t('Your account is not in that group (yet). If you think it should be, please contact the organizers.'));

            return null;
        }
        if ($reason !== null && $reason !== ParticipationService::REASON_TICKET) {
            $out->writeln('<warn>' . OutputFormatter::escape($participation->describeReason($event, $reason, $check)) . '</warn>');

            return null;
        }

        $out->writeln('');
        if ($reason === ParticipationService::REASON_TICKET) {
            $out->writeln(t('This event needs a ticket. If you say yes, I reserve it for you. If it has to be paid first, I take you to the checkout page, and your place is yours once it is paid.'));
        } elseif (!empty($check['waitlist'])) {
            $out->writeln(t('The event is full at the moment. If you say yes, I put you on the waitlist: when somebody cancels, you move up automatically.'));
        } else {
            $out->writeln(t('If you say yes, I sign you up as %s.', '<info>' . OutputFormatter::escape((string) $c->user->getUserName()) . '</info>'));
        }
        $out->writeln(t('Shall I sign you up for %s?', '<accent>' . OutputFormatter::escape($event->getName()) . '</accent>'));
        $this->data['step'] = 'confirm';

        return new Question(t('[y/n]') . ' > ');
    }

    protected function describe(Context $c, OutputInterface $out, Event $event): void
    {
        $service = $c->app->make(EventService::class);
        $version = $service->getRecentVersion($event);
        $location = $version ? $c->app->make(LocationFormatter::class)->parse((string) $version->getAttribute('event_location_name')) : null;
        $counts = $c->app->make(ParticipationService::class)->getCounts($event);
        $taken = (int) ($counts[\CommunityEventManager\Entity\Participant::STATUS_REGISTERED] ?? 0);
        $out->writeln('  <accent>' . OutputFormatter::escape($event->getName()) . '</accent>');
        if ($event->getStartsAt()) {
            $out->writeln('  ' . t('When:') . ' ' . $event->getStartsAt()->format('d.m.Y H:i') . ($event->getEndsAt() ? ' – ' . $event->getEndsAt()->format('d.m.Y H:i') : ''));
        }
        if ($location && $location['text'] !== '') {
            $out->writeln('  ' . t('Where:') . ' ' . OutputFormatter::escape($location['text']));
        }
        if ($event->getCapacity()) {
            $out->writeln('  ' . t('Places:') . ' ' . t('%1$d of %2$d taken', $taken, $event->getCapacity()));
        }
    }

    protected function signup(Context $c, OutputInterface $out): ?Question
    {
        $id = $this->eventID();
        $before = count($c->actions);
        $code = $this->run($c, $out, 'signup ' . $id);
        foreach (array_slice($c->actions, $before) as $action) {
            if ($action['type'] === 'redirect') {
                $out->writeln(t('After paying, come back and type %s: then we choose your seat.', '<info>easy</info>'));

                return null;
            }
        }
        if ($code !== 0) {
            $out->writeln(t('Something did not work, the message above says what. If you are not sure what to do, please contact the organizers.'));

            return null;
        }
        $this->data['done'][] = 'signup ' . $id;
        $event = $this->event($c);
        $participant = $event ? $c->app->make(ParticipationService::class)->getParticipant($event, (int) $c->user->getUserID()) : null;
        if (!$participant || !$participant->isRegistered()) {
            return $this->finish($c, $out);
        }

        return $this->seatStep($c, $out);
    }

    protected function seatStep(Context $c, OutputInterface $out): ?Question
    {
        $event = $this->event($c);
        $participation = $c->app->make(ParticipationService::class);
        $participant = $event ? $participation->getParticipant($event, (int) $c->user->getUserID()) : null;
        if (!$participant || $participant->getSeatID() || !$participation->canChooseSeat($event, $participant)) {
            if ($participant && $participant->getSeatID()) {
                $out->writeln(t('Your seat: %s', '<info>' . OutputFormatter::escape($participant->getSeatID()) . '</info>'));
            }

            return $this->payStep($c, $out);
        }
        $out->writeln('');
        $out->writeln('<comment>' . t('Step 3: your seat') . '</comment>');
        $out->writeln(t('This event has a seat plan, so you can choose where you sit. Would you like to choose your seat now?'));
        $this->data['step'] = 'seat_want';

        return new Question(t('[y/n]') . ' > ');
    }

    /**
     * Offers to pay the open bills online, when there are any (and the credit package is there).
     */
    protected function payStep(Context $c, OutputInterface $out, bool $thenFinish = true): ?Question
    {
        $bills = class_exists(\CommunityStoreCredit\Service\AccountOverview::class) ? CreditCommands::payableBills($c) : [];
        if ($bills === []) {
            return $thenFinish ? $this->finish($c, $out) : null;
        }
        $out->writeln('');
        $out->writeln('<comment>' . t('Step 4: paying') . '</comment>');
        $total = array_sum(array_column($bills, 'total'));
        $out->writeln(t('You have open bills of %s in total. You can pay them online now (the payment page of Payrexx opens), or later.', '<info>' . CreditCommands::money($total) . '</info>'));
        $out->writeln(t('Would you like to pay now?'));
        $this->data['step'] = 'pay_want';

        return new Question(t('[y/n]') . ' > ');
    }

    protected function pay(Context $c, OutputInterface $out, int $orderID): ?Question
    {
        if ($this->run($c, $out, 'pay ' . $orderID) !== 0) {
            return $this->finish($c, $out);
        }
        $this->data['done'][] = 'pay ' . $orderID;
        $out->writeln(t('After paying you come back to the website. Thank you, and see you at the event!'));

        return null;
    }

    /**
     * What happened, and the commands that do it without the wizard next time.
     */
    protected function finish(Context $c, OutputInterface $out): ?Question
    {
        $out->writeln('');
        $out->writeln('<ok>' . t('All done!') . '</ok>');
        $commands = array_values(array_filter((array) ($this->data['done'] ?? []), static function ($cmd) {
            return $cmd !== 'login';
        }));
        if ($commands) {
            $out->writeln(t('By the way, next time you can do the same with these commands:'));
            foreach ($commands as $cmd) {
                $out->writeln('  <info>' . OutputFormatter::escape($cmd) . '</info>');
            }
        }
        $out->writeln(t('Your signups: %s · help for everything else.', '<info>signups</info>'));
        $out->writeln(t('See you at the LAN!'));

        return null;
    }

    protected function yesNoAgain(OutputInterface $out): Question
    {
        $out->writeln(t('Sorry, I did not get that. Please type y for yes or n for no.'));

        return new Question(t('[y/n]') . ' > ');
    }

    protected function eventID(): int
    {
        return (int) ($this->data['event'] ?? 0);
    }

    protected function event(Context $c): ?Event
    {
        foreach (EventCommands::openEvents($c) as $event) {
            if ($event->getID() === $this->eventID()) {
                return $event;
            }
        }

        return null;
    }

    protected function exampleSeat(Context $c): string
    {
        $event = $this->event($c);
        if (!$event) {
            return 'A1';
        }
        $taken = $c->app->make(ParticipationService::class)->getSeatAssignments($event);
        foreach ($event->getSeatIDs() as $id) {
            if (!isset($taken[$id])) {
                return $id;
            }
        }

        return 'A1';
    }
}
