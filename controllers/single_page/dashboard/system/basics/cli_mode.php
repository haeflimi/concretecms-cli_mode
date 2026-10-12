<?php

namespace Concrete\Package\CliMode\Controller\SinglePage\Dashboard\System\Basics;

use CliMode\Terminal\AttitudeRegistry;
use CliMode\Terminal\CommandRegistry;
use CliMode\Terminal\Settings;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\Group\GroupList;

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
        // groups that may skip the mandatory mode (Guest, Registered and Administrators make no sense there)
        $list = new GroupList();
        $list->includeAllGroups();
        $groups = [];
        foreach ($list->getResults() as $group) {
            if (!in_array((int) $group->getGroupID(), [GUEST_GROUP_ID, REGISTERED_GROUP_ID, ADMIN_GROUP_ID], true)) {
                $groups[(int) $group->getGroupID()] = $group->getGroupDisplayName(false);
            }
        }
        asort($groups);
        $this->set('groups', $groups);
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
                array_map('strval', (array) $post->all('motd')),
                (string) $post->get('forced'),
                (bool) $post->get('notfound'),
                array_map('intval', (array) $post->all('exempt'))
            );
            $this->flash('success', t('The terminal settings were saved.'));

            return $this->buildRedirect($this->action(''));
        }
        $this->view();
    }
}
