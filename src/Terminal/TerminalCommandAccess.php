<?php

namespace CliMode\Terminal;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Who may run a command.
 */
final class TerminalCommandAccess
{
    public const PUBLIC = 'public';
    public const REGISTERED = 'registered';
    public const ADMIN = 'admin';

    private function __construct()
    {
    }
}
