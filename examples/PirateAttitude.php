<?php

namespace Concrete\Package\MyPackage\Terminal;

use CliMode\Terminal\Attitude\Attitude;

/**
 * Example of an attitude for the CLI Mode terminal. Register it in your package's on_start():
 *
 *     $this->app->make(\CliMode\Terminal\AttitudeRegistry::class)->register(\Concrete\Package\MyPackage\Terminal\PirateAttitude::class);
 *
 * or, to not care about the order in which packages start, in a listener:
 *
 *     $this->app->make('director')->addListener('on_cli_mode_register_attitudes', function ($event) {
 *         $event->getRegistry()->register(PirateAttitude::class);
 *     });
 *
 * It then shows up in Dashboard > System > Basics > CLI Mode. The sites can also list it in application/config/cli_mode.php:
 * return ['attitudes' => [\Application\Terminal\PirateAttitude::class]];
 */
class PirateAttitude extends Attitude
{
    /** Unique and stable, this is what the dashboard saves */
    public function handle(): string
    {
        return 'pirate';
    }

    /** Name in the dashboard's list */
    public function name(): string
    {
        return t('Pirate');
    }

    public function description(): string
    {
        return t('Arrr.');
    }

    /** 0 = plain, 1 = the occasional remark, 2 = every chance for a roast: commands with remarks of their own look at it */
    public function level(): int
    {
        return 1;
    }

    /**
     * Only list what you want to change: any key left out is the polite message. A value is a sprintf() format, or a list
     * of them (one is picked at random). %1$s, %2$s are the arguments, the Attitude class lists every key and what it gets.
     */
    public function messages(): array
    {
        return [
            'not_found' => [
                '%1$s: command not found. Never heard of it, matey.',
                '%1$s: command not found. Ye be sailin\' in uncharted waters.',
            ],
            'denied' => '%1$s: Permission denied. Only the captain may do that.',
            'no_such_path' => '%1$s: %2$s: No such file or directory. Lost at sea, it be.',
            'exit' => 'logout. Fair winds!',
            'success' => ['Aye aye!', 'Done, cap\'n.'],
        ];
    }
}
