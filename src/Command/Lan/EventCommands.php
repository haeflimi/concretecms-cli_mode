<?php

namespace CliMode\Command\Lan;

use CliMode\Command\ClosureCommand;
use CliMode\Command\TerminalCommand;
use CliMode\Terminal\Context;
use CliMode\Terminal\TerminalCommandAccess;
use CommunityEventManager\Entity\Event;
use CommunityEventManager\Entity\Participant;
use CommunityEventManager\Event\EventService;
use CommunityEventManager\Event\LocationFormatter;
use CommunityEventManager\Participation\ParticipationService;
use CommunityEventManager\Ticket\TicketService;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The LAN party commands on top of community_event_manager: the first of what could become a full management CLI.
 * Public ones read what the event pages show anyway, signup and seat act for the signed-in member through the same
 * services as the signup block, the others check the admin group.
 */
class EventCommands
{
    /**
     * @return TerminalCommand[]
     */
    public static function all(): array
    {
        $cat = ['category' => 'lan'];
        $eventNames = static function (Context $c): array {
            $names = [];
            foreach (self::published($c) as $event) {
                $names[] = (string) $event->getID();
                $names[] = self::slug($event->getName());
            }

            return $names;
        };

        $events = ClosureCommand::make('events', t('The upcoming events'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $list = self::published($c);
            usort($list, static function (Event $a, Event $b) {
                return $a->getStartsAt() <=> $b->getStartsAt();
            });
            $now = new \DateTime();
            $rows = [];
            foreach ($list as $event) {
                $over = $event->isOver($now);
                if ($over && !$in->getOption('all')) {
                    continue;
                }
                $counts = $c->app->make(ParticipationService::class)->getCounts($event);
                $registered = (int) ($counts[Participant::STATUS_REGISTERED] ?? 0);
                $rows[] = [
                    $event->getID(),
                    $event->getStartsAt() ? $event->getStartsAt()->format('d.m.Y H:i') : '-',
                    OutputFormatter::escape($event->getName()),
                    OutputFormatter::escape($event->getType()->getName()),
                    $event->getCapacity() ? $registered . '/' . $event->getCapacity() : ($registered ?: '-'),
                    $over ? t('over') : ($event->isSignupOpen() ? t('open') : $event->getPhase()),
                ];
            }
            if ($rows === []) {
                $out->writeln($c->snark->level() > 0 ? '<dim>' . t('Nothing coming up. The calendar is as empty as a LAN fridge on Sunday.') . '</dim>' : t('No events.'));

                return 0;
            }
            (new TableHelper())->render($out, [t('ID'), t('When'), t('Event'), t('Type'), t('Places'), t('Signup')], $rows);

            return 0;
        }, $cat + ['options' => [['all', 'a', InputOption::VALUE_NONE, t('Include past events')]], 'help' => t('Examples:') . "\n  events\n  events --all | grep LAN"]);

        $event = ClosureCommand::make('event', t('Everything about one event'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $event = self::find($c, (string) $in->getArgument('event'));
            if (!$event) {
                $out->writeln('<error> ' . t('event: no such event. Run "events --all" to see what exists.') . ' </error>');

                return 1;
            }
            $counts = $c->app->make(ParticipationService::class)->getCounts($event);
            $page = $c->app->make(EventService::class)->getPage($event);
            $version = $c->app->make(EventService::class)->getRecentVersion($event);
            $location = $version ? $c->app->make(LocationFormatter::class)->parse((string) $version->getAttribute('event_location_name')) : null;
            $out->writeln('<accent>' . OutputFormatter::escape($event->getName()) . '</accent>  <dim>#' . $event->getID() . ' · ' . OutputFormatter::escape($event->getType()->getName()) . '</dim>');
            $facts = [
                t('When') => $event->getStartsAt() ? $event->getStartsAt()->format('D d.m.Y H:i') . ($event->getEndsAt() ? ' → ' . $event->getEndsAt()->format('D d.m.Y H:i') : '') : '-',
                t('Where') => $location ? $location['text'] : '-',
                t('Phase') => $event->getPhase(),
                t('Signup') => $event->isSignupEnabled() ? ($event->isSignupOpen() ? t('open') : t('closed')) : t('not needed'),
                t('Places') => $event->getCapacity() ? t('%1$d / %2$d taken', (int) ($counts[Participant::STATUS_REGISTERED] ?? 0), $event->getCapacity()) : t('unlimited'),
                t('Waitlist') => (string) (int) ($counts[Participant::STATUS_WAITLISTED] ?? 0),
                t('Page') => $page ? (string) $page->getCollectionLink() : '-',
            ];
            // padded by characters, not bytes: translated labels may have umlauts
            $width = max(9, ...array_map('mb_strlen', array_keys($facts)));
            foreach ($facts as $label => $value) {
                $out->writeln('  <comment>' . $label . str_repeat(' ', $width - mb_strlen($label)) . '</comment> ' . OutputFormatter::escape((string) $value));
            }
            if ($page) {
                $out->writeln('<dim>' . t('open %s  takes you there.', OutputFormatter::escape((string) $page->getCollectionPath())) . '</dim>');
            }

            return 0;
        }, $cat + ['arguments' => [['event', InputArgument::REQUIRED, t('Event ID or a part of its name')]]]);
        $event->setCompleter($eventNames);

        $next = ClosureCommand::make('next', t('Countdown to the next event'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $now = new \DateTime();
            $upcoming = array_filter(self::published($c), static function (Event $e) use ($now) {
                return $e->getStartsAt() && $e->getStartsAt() > $now;
            });
            usort($upcoming, static function (Event $a, Event $b) {
                return $a->getStartsAt() <=> $b->getStartsAt();
            });
            if (!$upcoming) {
                $out->writeln('<dim>' . t('No event ahead. Go outside, I guess.') . '</dim>');

                return 1;
            }
            $event = reset($upcoming);
            $diff = $now->diff($event->getStartsAt());
            $out->writeln(t('%1$s starts in %2$s', '<accent>' . OutputFormatter::escape($event->getName()) . '</accent>', '<info>' . implode(', ', [t2('%d day', '%d days', $diff->days, $diff->days), t2('%d hour', '%d hours', $diff->h, $diff->h), t2('%d minute', '%d minutes', $diff->i, $diff->i)]) . '</info>'));
            if ($c->snark->level() > 1 && $diff->days < 3) {
                $out->writeln('<snark>' . t('Have you updated your drivers? You have not. I can tell.') . '</snark>');
            }

            return 0;
        }, $cat);

        $participants = ClosureCommand::make('participants', t('Who signed up for an event'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $event = self::target($c, (string) $in->getArgument('event'), true);
            if (!$event) {
                $out->writeln('<error> ' . t('participants: no such event.') . ' </error>');

                return 1;
            }
            // everybody sees nicknames, seats and the waitlist; real names, check-ins and the other statuses are for admins
            $admin = $c->isAdmin();
            $statuses = [Participant::STATUS_REGISTERED, Participant::STATUS_WAITLISTED];
            if ($in->getOption('status')) {
                if (!$admin) {
                    $out->writeln('<error> ' . t('participants: --status is for admins.') . ' </error>');

                    return 1;
                }
                $statuses = [(string) $in->getOption('status')];
            }
            $labels = [
                Participant::STATUS_REGISTERED => t('signed up'),
                Participant::STATUS_WAITLISTED => t('waitlist'),
                Participant::STATUS_INTERESTED => t('interested'),
                Participant::STATUS_CANCELLED => t('cancelled'),
            ];
            $users = $c->app->make(UserInfoRepository::class);
            $rows = [];
            foreach ($c->app->make(ParticipationService::class)->getParticipants($event, $statuses) as $participant) {
                $ui = $users->getByID($participant->getUserID());
                $row = [OutputFormatter::escape($ui ? (string) $ui->getUserName() : '#' . $participant->getUserID())];
                if ($admin) {
                    $name = $ui ? trim($ui->getAttribute('billing_first_name') . ' ' . $ui->getAttribute('billing_last_name')) : '';
                    $row[] = $name !== '' ? OutputFormatter::escape($name) : '-';
                }
                $row[] = $labels[$participant->getStatus()] ?? $participant->getStatus();
                $row[] = $participant->getSeatID() ? OutputFormatter::escape($participant->getSeatID()) : '-';
                if ($admin) {
                    $row[] = $participant->getCheckedInAt() ? $participant->getCheckedInAt()->format('d.m. H:i') : '-';
                }
                $rows[] = $row;
            }
            $out->writeln('<accent>' . OutputFormatter::escape($event->getName()) . '</accent>');
            if (!$rows) {
                $out->writeln('<dim>' . t('Nobody. Crickets. Maybe advertise?') . '</dim>');

                return 0;
            }
            $headers = $admin ? [t('Nick'), t('Name'), t('Status'), t('Seat'), t('Checked in')] : [t('Nick'), t('Status'), t('Seat')];
            (new TableHelper())->render($out, $headers, $rows);
            $out->writeln('<dim>' . t2('%d person', '%d people', count($rows), count($rows)) . '</dim>');

            return 0;
        }, [
            'category' => 'lan',
            'arguments' => [['event', InputArgument::OPTIONAL, t('Event ID or a part of its name (default: the next event)')]],
            'options' => [['status', 's', InputOption::VALUE_REQUIRED, t('Admins: registered, waitlisted, interested or cancelled')]],
            'help' => t('Lists the nicknames, status and seats of who signed up. Admins also see real names and check-ins.') . "\n\n" . t('Examples:') . "\n  participants\n  participants 35\n  participants | grep waitlist",
        ]);
        $participants->setCompleter($eventNames);

        $signup = ClosureCommand::make('signup', t('Sign up for an event'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            // deliberately no guessing: only the exact ID of the event signs anybody up (or out)
            $id = trim((string) $in->getArgument('event'));
            $event = null;
            if (ctype_digit($id)) {
                foreach (self::published($c) as $candidate) {
                    if ($candidate->getID() === (int) $id) {
                        $event = $candidate;
                    }
                }
            }
            if (!$event) {
                if ($id === '') {
                    $message = t('signup: which event? Give its ID: signup <id>');
                } elseif (!ctype_digit($id)) {
                    $message = t('signup: give the ID of the event (a number), not its name.');
                } else {
                    $message = t('signup: there is no event with the ID %s.', $id);
                }
                $out->writeln('<error> ' . OutputFormatter::escape($message) . ' </error>');
                $open = array_filter(self::published($c), static function (Event $e) {
                    return $e->isSignupOpen();
                });
                usort($open, static function (Event $a, Event $b) {
                    return $a->getStartsAt() <=> $b->getStartsAt();
                });
                if ($open) {
                    $out->writeln(t('Open for signups:'));
                    foreach ($open as $e) {
                        $out->writeln(sprintf('  <info>signup %d</info>  %s <dim>%s</dim>', $e->getID(), OutputFormatter::escape($e->getName()), $e->getStartsAt() ? $e->getStartsAt()->format('d.m.Y') : ''));
                    }
                } else {
                    $out->writeln('<dim>' . t('No event is open for signups right now.') . '</dim>');
                }

                return 1;
            }
            $name = '<accent>' . OutputFormatter::escape($event->getName()) . '</accent>';
            $participation = $c->app->make(ParticipationService::class);
            try {
                if ($in->getOption('cancel')) {
                    $participation->cancel($event, $c->user);
                    $out->writeln(t('Your signup for %s has been cancelled.', $name));

                    return 0;
                }
                $check = $participation->checkSignup($event, $c->user);
                if (($check['reason'] ?? null) === ParticipationService::REASON_BALANCE) {
                    $out->writeln('<error> ' . OutputFormatter::escape($participation->describeReason($event, ParticipationService::REASON_BALANCE, $check)) . ' </error>');
                    if (class_exists(\CommunityStoreOpenOrders\OpenOrders::class)) {
                        $out->writeln('<dim>' . t('Run "balance" to see what is open and "pay" to settle up online.') . '</dim>');
                    }

                    return 1;
                }
                $participant = $participation->getParticipant($event, (int) $c->user->getUserID());
                if ($participant && $participant->isSignedUp()) {
                    $out->writeln($participant->isWaitlisted() ? t('You are on the waitlist for %s.', $name) : t('You are already signed up for %s.', $name) . ($participant->getSeatID() ? ' ' . t('Seat: %s', '<info>' . OutputFormatter::escape($participant->getSeatID()) . '</info>') : ''));
                    self::seatHint($c, $out, $event, $participant);

                    return 0;
                }
                if ($event->isTicketsEnabled()) {
                    $url = $c->app->make(TicketService::class)->startCheckout($event, $c->user, []);
                    if ($url === '') {
                        $out->writeln('<ok>' . t('You are in: your ticket for %s is reserved. You pay at the event.', $name) . '</ok>');
                        self::seatHint($c, $out, $event, $participation->getParticipant($event, (int) $c->user->getUserID()));

                        return 0;
                    }
                    $out->writeln(t('%s needs a ticket. Taking you to the checkout, your place is yours once it is paid.', $name));
                    $c->addAction('redirect', ['url' => $url]);

                    return 0;
                }
                $participant = $participation->signup($event, $c->user);
            } catch (UserMessageException $e) {
                $out->writeln('<error> ' . OutputFormatter::escape($e->getMessage()) . ' </error>');

                return 1;
            }
            if ($participant->isWaitlisted()) {
                $out->writeln('<warn>' . t('%s is full, you are on the waitlist. You move up when somebody cancels.', $name) . '</warn>');

                return 0;
            }
            $out->writeln('<ok>' . t('You are signed up for %s!', $name) . '</ok>');
            self::seatHint($c, $out, $event, $participant);

            return 0;
        }, $cat + [
            'access' => TerminalCommandAccess::REGISTERED,
            'arguments' => [['event', InputArgument::OPTIONAL, t('The ID of the event (see "events")')]],
            'options' => [['cancel', 'c', InputOption::VALUE_NONE, t('Cancel your signup')]],
            'help' => t('Signs you up like the signup form on the event page. It takes the ID of the event, nothing else: "signup" alone lists the events open for signups with their IDs. When the event sells tickets, the ticket is reserved on your event tab or you are taken to the checkout.') . "\n\n" . t('Examples:') . "\n  signup\n  signup 33\n  signup 33 --cancel",
        ]);
        // Tab completes IDs only, the open events first
        $signup->setCompleter(static function (Context $c): array {
            $ids = [];
            foreach (self::published($c) as $event) {
                if (!$event->isOver()) {
                    $ids[] = (string) $event->getID();
                }
            }

            return $ids;
        });

        $seat = ClosureCommand::make('seat', t('Show the seats or take one'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $event = self::target($c, (string) $in->getArgument('event'), true);
            if (!$event) {
                $out->writeln('<error> ' . t('seat: no such event. Run "events" to see what is coming.') . ' </error>');

                return 1;
            }
            $participation = $c->app->make(ParticipationService::class);
            if (!$event->isSeatsEnabled() || $event->getSeatIDs() === []) {
                $out->writeln(t('%s has no seat plan.', OutputFormatter::escape($event->getName())));

                return 1;
            }
            $participant = $participation->getParticipant($event, (int) $c->user->getUserID());
            $wanted = trim((string) $in->getArgument('seat'));
            try {
                if ($in->getOption('release')) {
                    if (!$participant || !$participant->getSeatID()) {
                        $out->writeln(t('You have no seat to release.'));

                        return 1;
                    }
                    $participation->assignSeat($participant, null);
                    $out->writeln(t('Your seat has been released.'));

                    return 0;
                }
                if ($wanted !== '') {
                    if (!$participant || !$participant->isRegistered()) {
                        $out->writeln('<error> ' . t('Sign up first: signup %s', $event->getID()) . ' </error>');

                        return 1;
                    }
                    $seatID = self::matchSeat($event->getSeatIDs(), $wanted);
                    if ($seatID === null) {
                        $out->writeln('<error> ' . t('The seat "%s" doesn\'t exist.', OutputFormatter::escape($wanted)) . ' </error>');

                        return 1;
                    }
                    $participation->assignSeat($participant, $seatID);
                    $out->writeln('<ok>' . t('Seat %s is yours.', $seatID) . '</ok>');

                    return 0;
                }
            } catch (UserMessageException $e) {
                $out->writeln('<error> ' . OutputFormatter::escape($e->getMessage()) . ' </error>');

                return 1;
            }

            // the plan: one line per row of seats (A, B, ...), mine highlighted, taken ones dimmed
            $taken = $participation->getSeatAssignments($event);
            $mine = $participant ? $participant->getSeatID() : null;
            $rows = [];
            $ids = $event->getSeatIDs();
            natsort($ids);
            foreach ($ids as $id) {
                $rows[preg_match('/^\D*/', $id, $m) && $m[0] !== '' ? rtrim($m[0], '-_ ') : '#'][] = $id;
            }
            $width = max(array_map('mb_strlen', $ids));
            $out->writeln('<accent>' . OutputFormatter::escape($event->getName()) . '</accent>  ' . t('%1$d of %2$d seats free', count($ids) - count($taken), count($ids)));
            foreach ($rows as $label => $seats) {
                $cells = array_map(static function ($id) use ($taken, $mine, $width) {
                    $cell = OutputFormatter::escape(str_pad($id, $width));
                    if ($id === $mine) {
                        return '<accent>' . $cell . '</accent>';
                    }

                    return isset($taken[$id]) ? '<dim>' . $cell . '</dim>' : '<ok>' . $cell . '</ok>';
                }, $seats);
                foreach (array_chunk($cells, 12) as $chunk) {
                    $out->writeln('  ' . implode(' ', $chunk));
                }
            }
            $out->writeln('<dim>' . t('green = free, grey = taken') . ($mine ? ', ' . t('highlighted = yours') : '') . '</dim>');
            if ($participant && $participant->isRegistered() && $participation->canChooseSeat($event, $participant)) {
                $out->writeln(OutputFormatter::escape($mine ? t('Move with: seat %1$s <seat>   give it back: seat %1$s --release', $event->getID()) : t('Take one with: seat %s <seat>', $event->getID())));
            } elseif (!$participant || !$participant->isSignedUp()) {
                $out->writeln('<dim>' . t('Sign up first: signup %s', $event->getID()) . '</dim>');
            }

            return 0;
        }, $cat + [
            'access' => TerminalCommandAccess::REGISTERED,
            'arguments' => [
                ['event', InputArgument::OPTIONAL, t('Event ID or a part of its name (default: the next event)')],
                ['seat', InputArgument::OPTIONAL, t('The seat to take, e.g. A12')],
            ],
            'options' => [['release', 'r', InputOption::VALUE_NONE, t('Give your seat back')]],
            'help' => t('Without a seat it shows the seat plan of the event.') . "\n\n" . t('Examples:') . "\n  seat\n  seat 35\n  seat 35 B14\n  seat 35 --release",
        ]);
        $seat->setCompleter($eventNames);

        $signups = ClosureCommand::make('signups', t('Your signups'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $participation = $c->app->make(ParticipationService::class);
            $now = new \DateTime();
            $rows = [];
            $list = array_filter($participation->getParticipationsOfUser((int) $c->user->getUserID()), static function (Participant $p) use ($in, $now) {
                $event = $p->getEvent();

                return $event->getStatus() !== Event::STATUS_DRAFT && ($in->getOption('all') || !$event->isOver($now));
            });
            usort($list, static function (Participant $a, Participant $b) {
                return $a->getEvent()->getStartsAt() <=> $b->getEvent()->getStartsAt();
            });
            foreach ($list as $p) {
                $event = $p->getEvent();
                if ($event->isCancelled()) {
                    $status = '<error>' . t('event cancelled') . '</error>';
                } elseif ($p->isWaitlisted()) {
                    $position = 1 + (int) array_search($p->getID(), array_map(static function (Participant $w) {
                        return $w->getID();
                    }, $participation->getParticipants($event, [Participant::STATUS_WAITLISTED])), true);
                    $status = '<warn>' . t('waitlist #%d', $position) . '</warn>';
                } elseif ($p->isInterested()) {
                    $status = '<dim>' . t('interested') . '</dim>';
                } elseif ($p->getCheckedInAt() || $event->isOver($now)) {
                    $status = '<ok>' . ($p->getCheckedOutAt() || $event->isOver($now) ? t('was there') : t('checked in')) . '</ok>';
                } else {
                    $status = '<ok>' . t('signed up') . '</ok>';
                }
                $tickets = [
                    Participant::TICKET_PENDING => t('reserved, unpaid'),
                    Participant::TICKET_PAID => t('paid'),
                    Participant::TICKET_CANCELLED => t('cancelled'),
                ];
                $rows[] = [
                    $event->getID(),
                    $event->getStartsAt() ? $event->getStartsAt()->format('d.m.Y H:i') : '-',
                    OutputFormatter::escape($event->getName()),
                    $status,
                    $p->getSeatID() ? OutputFormatter::escape($p->getSeatID()) : '-',
                    $tickets[$p->getTicketStatus()] ?? '-',
                ];
            }
            if ($rows === []) {
                $out->writeln($in->getOption('all') ? t('You never signed up for anything.') : t('No signups for upcoming events.') . ' ' . t('See what is coming: %s', '<info>events</info>'));

                return 0;
            }
            (new TableHelper())->render($out, [t('ID'), t('When'), t('Event'), t('Status'), t('Seat'), t('Ticket')], $rows);

            return 0;
        }, $cat + [
            'access' => TerminalCommandAccess::REGISTERED,
            'options' => [['all', 'a', InputOption::VALUE_NONE, t('Include past events')]],
            'help' => t('Lists the events you signed up for, are waitlisted for or interested in, with your seat and ticket.') . "\n\n" . t('Examples:') . "\n  signups\n  signups --all",
        ]);

        return [$events, $event, $next, $participants, $signup, $seat, $signups];
    }

    /**
     * The events open for signups right now, the soonest first.
     *
     * @return Event[]
     */
    public static function openEvents(Context $c): array
    {
        $now = new \DateTime();
        $open = array_values(array_filter(self::published($c), static function (Event $e) use ($now) {
            return $e->isSignupOpen() && !$e->isOver($now);
        }));
        usort($open, static function (Event $a, Event $b) {
            return $a->getStartsAt() <=> $b->getStartsAt();
        });

        return $open;
    }

    /**
     * The event a member command is about: the given one, else the next event open for signups (or, for seat, the
     * next one the member is registered for, then the next one at all).
     */
    protected static function target(Context $c, string $needle, bool $preferMine = false): ?Event
    {
        if (trim($needle) !== '') {
            return self::find($c, $needle);
        }
        $now = new \DateTime();
        $upcoming = array_filter(self::published($c), static function (Event $e) use ($now) {
            return !$e->isOver($now);
        });
        usort($upcoming, static function (Event $a, Event $b) {
            return $a->getStartsAt() <=> $b->getStartsAt();
        });
        if ($preferMine) {
            $participation = $c->app->make(ParticipationService::class);
            foreach ($upcoming as $event) {
                $participant = $participation->getParticipant($event, (int) $c->user->getUserID());
                if ($participant && $participant->isRegistered()) {
                    return $event;
                }
            }
        }
        foreach ($upcoming as $event) {
            if ($event->isSignupOpen()) {
                return $event;
            }
        }

        return $preferMine && $upcoming ? reset($upcoming) : null;
    }

    protected static function seatHint(Context $c, OutputInterface $out, Event $event, ?Participant $participant): void
    {
        if ($participant && !$participant->getSeatID() && $c->app->make(ParticipationService::class)->canChooseSeat($event, $participant)) {
            $out->writeln(t('Grab a seat: %s', '<info>seat ' . $event->getID() . '</info>'));
        }
    }

    /**
     * A typed seat to one of the plan: case and separators do not matter, nor leading zeros ("b14" = "B-14", "a1" = "A01").
     */
    protected static function matchSeat(array $seatIDs, string $typed): ?string
    {
        $key = static function (string $id): string {
            return (string) preg_replace_callback('/\d+/', static function ($m) {
                return (string) (int) $m[0];
            }, preg_replace('/[^A-Z0-9]/', '', strtoupper($id)));
        };
        foreach ($seatIDs as $id) {
            if (strcasecmp($id, $typed) === 0) {
                return $id;
            }
        }
        foreach ($seatIDs as $id) {
            if ($key($id) === $key($typed)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return Event[]
     */
    protected static function published(Context $c): array
    {
        return $c->app->make(EventService::class)->getList(['status' => [Event::STATUS_PUBLISHED]]);
    }

    protected static function find(Context $c, string $needle): ?Event
    {
        $needle = trim($needle);
        $all = self::published($c);
        foreach ($all as $event) {
            if ((string) $event->getID() === $needle) {
                return $event;
            }
        }
        $needle = self::slug($needle);
        foreach ($all as $event) {
            if ($needle !== '' && strpos(self::slug($event->getName()), $needle) !== false) {
                return $event;
            }
        }

        return null;
    }

    protected static function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }
}
