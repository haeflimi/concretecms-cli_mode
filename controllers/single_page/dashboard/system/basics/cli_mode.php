<?php

namespace Concrete\Package\CliMode\Controller\SinglePage\Dashboard\System\Basics;

use CliMode\Terminal\AttitudeRegistry;
use CliMode\Terminal\CommandRegistry;
use CliMode\Terminal\Settings;
use Concrete\Core\Page\Controller\DashboardPageController;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dashboard > System > Basics > CLI Mode: access, snark level, launcher button and the list of known commands.
 */
class CliMode extends DashboardPageController
{
    public function view()
    {
        $settings = $this->app->make(Settings::class);
        $commands = [];
        foreach ($this->app->make(CommandRegistry::class)->all() as $command) {
            $commands[] = [
                'name' => $command->getName(),
                'aliases' => $command->getAliases(),
                'category' => $command->getCategory(),
                'access' => $command->getAccess(),
                'description' => $command->getDescription(),
                'hidden' => $command->isHidden(),
                'class' => get_class($command),
            ];
        }
        usort($commands, static function ($a, $b) {
            return [$a['category'], $a['name']] <=> [$b['category'], $b['name']];
        });
        $registry = $this->app->make(AttitudeRegistry::class);
        $this->set('attitudes', $registry->all());
        // what the terminal falls back to when the saved attitude is gone
        $this->set('currentAttitude', $registry->get($settings->getAttitude())->handle());
        $this->set('settings', $settings);
        $this->set('commands', $commands);
    }

    public function save()
    {
        if (!$this->token->validate('cli_mode_save')) {
            $this->error->add($this->token->getErrorMessage());
        }
        if (!$this->error->has()) {
            $post = $this->request->request;
            $this->app->make(Settings::class)->save(
                (bool) $post->get('enabled'),
                (string) $post->get('access'),
                (bool) $post->get('launcher'),
                (string) $post->get('attitude'),
                (string) $post->get('motd'),
                (string) $post->get('forced'),
                (bool) $post->get('notfound')
            );
            $this->flash('success', t('The terminal settings were saved.'));

            return $this->buildRedirect($this->action(''));
        }
        $this->view();
    }
}
