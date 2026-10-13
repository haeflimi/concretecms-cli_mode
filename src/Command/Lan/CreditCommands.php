<?php

namespace CliMode\Command\Lan;

use CliMode\Command\ClosureCommand;
use CliMode\Command\TerminalCommand;
use CliMode\Terminal\Context;
use CliMode\Terminal\TerminalCommandAccess;
use CommunityStoreOpenOrders\OpenOrders;
use CommunityStoreOpenOrders\PayrexxCheckout;
use Concrete\Core\Error\UserMessageException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Bill commands, only with the Community Store Open Orders package installed: "balance" shows the member's open
 * bills (with Community Store Credit also the credit and the net of both, what /account/orders shows), "pay" sends
 * them to the Payrexx page for an open bill. Both exist so members can settle up while the terminal is the only way
 * through the site.
 */
class CreditCommands
{
    /**
     * @return TerminalCommand[]
     */
    public static function all(): array
    {
        $cat = ['category' => 'lan', 'access' => TerminalCommandAccess::REGISTERED];

        $balance = ClosureCommand::make('balance', t('Your open bills and what you owe'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $overview = self::overview($c);
            if ($overview['credit']) {
                $out->writeln(t('Credit: %s', '<info>' . self::money($overview['balance']) . '</info>'));
            }
            $bills = self::openBills($c, $overview);
            if ($bills === []) {
                $out->writeln('<ok>' . t('No open bills.') . '</ok>');
            } else {
                $rows = [];
                foreach ($bills as $bill) {
                    $rows[] = ['#' . $bill['id'], $bill['date'], $bill['what'], self::money($bill['total'])];
                }
                $out->writeln(t('Open bills:'));
                (new TableHelper())->render($out, [t('Order'), t('Date'), t('What'), t('Total')], $rows);
                $out->writeln(t('Owed: %s', '<warn>' . self::money($overview['debt']) . '</warn>'));
            }
            $net = (float) $overview['net'];
            if ($overview['credit']) {
                $out->writeln(t('Net: %s', $net < 0 ? '<error> ' . self::money($net) . ' </error>' : '<ok>' . self::money($net) . '</ok>'));
            }
            if ($net < 0) {
                $out->writeln('<dim>' . t('Run "pay" to settle up online.') . '</dim>');
            }

            return 0;
        }, $cat + ['help' => t('Shows your open bills (orders and event tabs), with store credit also the credit and the net of both, like the orders page of your account.')]);

        $pay = ClosureCommand::make('pay', t('Pay an open bill online'), static function (Context $c, InputInterface $in, OutputInterface $out) {
            $payrexx = $c->app->make(PayrexxCheckout::class);
            $overview = self::overview($c);
            $bills = self::openBills($c, $overview);
            if ($bills === []) {
                $out->writeln('<ok>' . t('No open bills. Nothing to pay.') . '</ok>');

                return 0;
            }
            if (!$payrexx->isAvailable()) {
                $out->writeln('<error> ' . t('Online payment is not available at the moment. Please pay at the event checkout.') . ' </error>');

                return 1;
            }
            $payable = array_filter($bills, static fn (array $bill) => $payrexx->canPay($bill['order']));
            if ($payable === []) {
                $out->writeln('<error> ' . t('Your open bills can\'t be paid online. Please pay at the event checkout.') . ' </error>');

                return 1;
            }
            $wanted = trim((string) $in->getArgument('order'), '# ');
            if ($wanted === '') {
                if (count($payable) > 1) {
                    $out->writeln(t('You have several open bills. Run "pay <order>" with one of them:'));
                    foreach ($payable as $bill) {
                        $out->writeln('  <accent>#' . $bill['id'] . '</accent>  ' . OutputFormatter::escape($bill['what']) . '  <info>' . self::money($bill['total']) . '</info>');
                    }

                    return 1;
                }
                $bill = reset($payable);
            } else {
                $bill = null;
                foreach ($payable as $candidate) {
                    if ((string) $candidate['id'] === $wanted) {
                        $bill = $candidate;
                    }
                }
                if ($bill === null) {
                    $out->writeln('<error> ' . t('pay: no open bill #%s. Run "balance" to see what is open.', OutputFormatter::escape($wanted)) . ' </error>');

                    return 1;
                }
            }
            try {
                $link = $payrexx->getPaymentLink($bill['order'], $c->user->getUserInfoObject());
            } catch (UserMessageException $e) {
                $out->writeln('<error> ' . OutputFormatter::escape($e->getMessage()) . ' </error>');

                return 1;
            }
            $out->writeln('<ok>' . t('Taking you to the payment page for #%1$s (%2$s). Your account page shows the result when you are back.', $bill['id'], self::money($bill['total'])) . '</ok>');
            $c->addAction('redirect', ['url' => $link]);

            return 0;
        }, $cat + [
            'arguments' => [['order', InputArgument::OPTIONAL, t('Order number (default: your only open bill)')]],
            'help' => t('Opens the Payrexx payment page for an open bill (an order or an event tab). Without a number it takes your only open bill; "balance" lists them.'),
        ]);
        $pay->setCompleter(static function (Context $c): array {
            $ids = [];
            foreach (self::openBills($c, self::overview($c)) as $bill) {
                $ids[] = (string) $bill['id'];
            }

            return $ids;
        });

        return [$balance, $pay];
    }

    /**
     * The member's open orders and tabs; with Community Store Credit also the credit and the net of both.
     *
     * @return array{tabs: array, open: array, debt: float, credit: bool, balance: float, net: float}
     */
    protected static function overview(Context $c): array
    {
        $uID = (int) $c->user->getUserID();
        $overview = $c->app->make(OpenOrders::class)->build($uID, 0);
        $overview['credit'] = class_exists(\CommunityStoreCredit\Service\CreditService::class);
        $overview['balance'] = $overview['credit'] ? round((float) $c->app->make(\CommunityStoreCredit\Service\CreditService::class)->getBalance($uID), 2) : 0.0;
        $overview['net'] = round($overview['balance'] - $overview['debt'], 2);

        return $overview;
    }

    /**
     * The open bills of the signed-in member that can be paid online (empty when online payment is not available).
     *
     * @return array<int, array{id: int, date: string, what: string, total: float, order: object}>
     */
    public static function payableBills(Context $c): array
    {
        $payrexx = $c->app->make(PayrexxCheckout::class);
        if (!$payrexx->isAvailable()) {
            return [];
        }
        $bills = self::openBills($c, self::overview($c));

        return array_values(array_filter($bills, static fn (array $bill) => $payrexx->canPay($bill['order'])));
    }

    /**
     * The open orders and open event tabs of the overview as flat rows.
     *
     * @return array<int, array{id: int, date: string, what: string, total: float, order: object}>
     */
    protected static function openBills(Context $c, array $overview): array
    {
        $dates = $c->app->make('date');
        $bills = [];
        foreach ($overview['tabs'] as $tab) {
            if ($tab['open'] && $tab['order']) {
                $bills[] = self::bill($dates, $tab['describe'], t('%s (event tab)', $tab['event']->getName()));
            }
        }
        foreach ($overview['open'] as $row) {
            $bills[] = self::bill($dates, $row, '');
        }

        return $bills;
    }

    protected static function bill($dates, array $described, string $what): array
    {
        $order = $described['order'];
        if ($what === '') {
            $names = array_map(static fn (array $item) => ($item['qty'] > 1 ? $item['qty'] . '× ' : '') . $item['name'], $described['items']);
            $what = implode(', ', $names);
            if (mb_strlen($what) > 44) {
                $what = mb_substr($what, 0, 41) . '…';
            }
        }
        $date = $order->getOrderDate();

        return [
            'id' => (int) $order->getOrderID(),
            'date' => $date ? $dates->formatDate($date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : (string) $date) : '',
            'what' => $what,
            'total' => (float) $order->getTotal(),
            'order' => $order,
        ];
    }

    public static function money(float $amount): string
    {
        if (class_exists(\Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price::class)) {
            return trim(strip_tags((string) \Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price::format($amount)));
        }

        return number_format($amount, 2);
    }
}
