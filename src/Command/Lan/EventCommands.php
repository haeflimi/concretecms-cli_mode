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

        $events = ClosureCommand::make('events', 'The upcoming events', static function (Context $c, InputInterface $in, OutputInterface $out) {
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
                    $over ? 'over' : ($event->isSignupOpen() ? 'open' : $event->getPhase()),
                ];
            }
            if ($rows === []) {
                $out->writeln($c->snark->level() > 0 ? '<dim>Nothing coming up. The calendar is as empty as a LAN fridge on Sunday.</dim>' : 'No events.');

                return 0;
            }
            (new TableHelper())->render($out, ['ID', 'When', 'Event', 'Type', 'Places', 'Signup'], $rows);

            return 0;
        }, $cat + ['options' => [['all', 'a', InputOption::VALUE_NONE, 'Include past events']], 'help' => "Examples:\n  events\n  events --all | grep LAN"]);

        $event = ClosureCommand::make('event', 'Everything about one event', static function (Context $c, InputInterface $in, OutputInterface $out) {
            $event = self::find($c, (string) $in->getArgument('event'));
            if (!$event) {
                $out->writeln('<error> event: no such event. Run "events --all" to see what exists. </error>');

                return 1;
            }
            $counts = $c->app->make(ParticipationService::class)->getCounts($event);
            $page = $c->app->make(EventService::class)->getPage($event);
            $version = $c->app->make(EventService::class)->getRecentVersion($event);
            $location = $version ? $c->app->make(LocationFormatter::class)->parse((string) $version->getAttribute('event_location_name')) : null;
            $out->writeln('<accent>' . OutputFormatter::escape($event->getName()) . '</accent>  <dim>#' . $event->getID() . ' · ' . OutputFormatter::escape($event->getType()->getName()) . '</dim>');
            $facts = [
                'When' => $event->getStartsAt() ? $event->getStartsAt()->format('D d.m.Y H:i') . ($event->getEndsAt() ? ' → ' . $event->getEndsAt()->format('D d.m.Y H:i') : '') : '-',
                'Where' => $location ? $location['text'] : '-',
                'Phase' => $event->getPhase(),
                'Signup' => $event->isSignupEnabled() ? ($event->isSignupOpen() ? 'open' : 'closed') : 'not needed',
                'Places' => $event->getCapacity() ? ((int) ($counts[Participant::STATUS_REGISTERED] ?? 0)) . ' / ' . $event->getCapacity() . ' taken' : 'unlimited',
                'Waitlist' => (string) (int) ($counts[Participant::STATUS_WAITLISTED] ?? 0),
                'Page' => $page ? (string) $page->getCollectionLink() : '-',
            ];
            foreach ($facts as $label => $value) {
                $out->writeln(sprintf('  <comment>%-9s</comment> %s', $label, OutputFormatter::escape((string) $value)));
            }
            if ($page) {
                $out->writeln('<dim>open ' . OutputFormatter::escape((string) $page->getCollectionPath()) . '  takes you there.</dim>');
            }

            return 0;
        }, $cat + ['arguments' => [['event', InputArgument::REQUIRED, 'Event ID or a part of its name']]]);
        $event->setCompleter($eventNames);

        $next = ClosureCommand::make('next', 'Countdown to the next event', static function (Context $c, InputInterface $in, OutputInterface $out) {
            $now = new \DateTime();
            $upcoming = array_filter(self::published($c), static function (Event $e) use ($now) {
                return $e->getStartsAt() && $e->getStartsAt() > $now;
            });
            usort($upcoming, static function (Event $a, Event $b) {
                return $a->getStartsAt() <=> $b->getStartsAt();
            });
            if (!$upcoming) {
                $out->writeln('<dim>No event ahead. Go outside, I guess.</dim>');

                return 1;
            }
            $event = reset($upcoming);
            $diff = $now->diff($event->getStartsAt());
            $out->writeln(sprintf('<accent>%s</accent> starts in <info>%d days, %d hours, %d minutes</info>', OutputFormatter::escape($event->getName()), $diff->days, $diff->h, $diff->i));
            if ($c->snark->level() > 1 && $diff->days < 3) {
                $out->writeln('<snark>Have you updated your drivers? You have not. I can tell.</snark>');
            }

            return 0;
        }, $cat);

        $participants = ClosureCommand::make('participants', 'Who signed up for an event', static function (Context $c, InputInterface $in, OutputInterface $out) {
            $event = self::find($c, (string) $in->getArgument('event'));
            if (!$event) {
                $out->writeln('<error> participants: no such event. </error>');

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
                $out->writeln('<dim>Nobody. Crickets. Maybe advertise?</dim>');

                return 0;
            }
            (new TableHelper())->render($out, ['Nick', 'Status', 'Seat', 'Checked in'], $rows);
            $out->writeln('<dim>' . count($rows) . (count($rows) === 1 ? ' person' : ' people') . '</dim>');

            return 0;
        }, ['category' => 'lan', 'access' => TerminalCommandAccess::ADMIN, 'arguments' => [['event', InputArgument::REQUIRED, 'Event ID or a part of its name']], 'options' => [['status', 's', InputOption::VALUE_REQUIRED, 'registered, waitlisted, interested or cancelled']], 'help' => 'Admins only. Pipe it: participants lan | grep waitlisted']);
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
