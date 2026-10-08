<?php

namespace CliMode\Command\Lan;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

class TableHelper
{
    /**
     * @param string[] $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function render(OutputInterface $output, array $headers, array $rows): void
    {
        $table = new Table($output);
        $table->setStyle('box')->setHeaders($headers)->setRows($rows);
        $table->render();
    }
}
