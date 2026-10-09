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
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The LAN party commands on top of community_event_manager: the first of what could become a full management CLI.
 * Public ones read what the event pages show anyway, the others check the admin group.
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
            $event = self::find($c, (string) $in->getArgument('event'));
            if (!$event) {
                $out->writeln('<error> ' . t('participants: no such event.') . ' </error>');

                return 1;
            }
            $statuses = $in->getOption('status') ? [(string) $in->getOption('status')] : [Participant::STATUS_REGISTERED, Participant::STATUS_WAITLISTED];
            $users = $c->app->make(UserInfoRepository::class);
            $rows = [];
            foreach ($c->app->make(ParticipationService::class)->getParticipants($event, $statuses) as $participant) {
                $ui = $users->getByID($participant->getUserID());
                $rows[] = [
                    OutputFormatter::escape($ui ? (string) $ui->getUserName() : '#' . $participant->getUserID()),
                    $participant->getStatus(),
                    $participant->getSeatID() ?: '-',
                    $participant->getCheckedInAt() ? $participant->getCheckedInAt()->format('d.m. H:i') : '-',
                ];
            }
            if (!$rows) {
                $out->writeln('<dim>' . t('Nobody. Crickets. Maybe advertise?') . '</dim>');

                return 0;
            }
            (new TableHelper())->render($out, [t('Nick'), t('Status'), t('Seat'), t('Checked in')], $rows);
            $out->writeln('<dim>' . t2('%d person', '%d people', count($rows), count($rows)) . '</dim>');

            return 0;
        }, ['category' => 'lan', 'access' => TerminalCommandAccess::ADMIN, 'arguments' => [['event', InputArgument::REQUIRED, t('Event ID or a part of its name')]], 'options' => [['status', 's', InputOption::VALUE_REQUIRED, t('registered, waitlisted, interested or cancelled')]], 'help' => t('Admins only. Pipe it: participants lan | grep waitlisted')]);
        $participants->setCompleter($eventNames);

        return [$events, $event, $next, $participants];
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
