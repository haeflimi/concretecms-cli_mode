<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var Concrete\Core\Form\Service\Form $form
 * @var CliMode\Terminal\Settings $settings
 * @var array<int, array<string, mixed>> $commands
 * @var array<string, CliMode\Terminal\Attitude\Attitude> $attitudes
 * @var string $currentAttitude
 */
?>
<form method="post" action="<?= h($view->action('save')) ?>">
    <?php $token->output('cli_mode_save') ?>
    <fieldset>
        <legend><?= t('Terminal') ?></legend>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="enabled" id="enabled" value="1"<?= $settings->isEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="enabled"><?= t('CLI Mode is on') ?></label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="access"><?= t('Who may open the terminal') ?></label>
            <select class="form-select" name="access" id="access">
                <?php foreach (['everyone' => t('Everybody, guests included'), 'registered' => t('Signed-in members'), 'admins' => t('Administrators only')] as $value => $label) { ?>
                    <option value="<?= $value ?>"<?= $settings->getAccess() === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php } ?>
            </select>
            <div class="form-text"><?= t('Individual commands have their own rules on top of this: "participants" is for admins only, for instance.') ?></div>
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="launcher" id="launcher" value="1"<?= $settings->showLauncher() ? ' checked' : '' ?>>
            <label class="form-check-label" for="launcher"><?= t('Show the small ">_" button in the corner of every page') ?></label>
            <div class="form-text"><?= t('Without it the terminal opens with the ` key or by adding #cli to a page address.') ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="forced"><?= t('Mandatory mode') ?></label>
            <select class="form-select" name="forced" id="forced">
                <?php foreach (['off' => t('Off: the terminal is an extra'), 'visitors' => t('Visitors who are not signed in must use the terminal'), 'everyone' => t('Everybody except administrators must use the terminal')] as $value => $label) { ?>
                    <option value="<?= $value ?>"<?= $settings->getForced() === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php } ?>
            </select>
            <div class="form-text"><?= t('The website is replaced by a full-screen terminal that cannot be closed: pages are read with "cat" and "open", navigation is "ls" and "cd". With JavaScript switched off visitors still get the normal website, so search engines and screen readers are not locked out. Login, registration and account pages stay normal, so people can still sign in. Administrators are never forced (add ?forcecli to any page address to preview it) and the dashboard and edit mode are not affected. Careful: if the terminal is the only way in, a broken command is a broken website.') ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="exempt"><?= t('Exempt groups') ?></label>
            <select class="form-select" name="exempt[]" id="exempt" multiple size="<?= min(8, max(3, count($groups))) ?>">
                <?php foreach ($groups as $gID => $name) { ?>
                    <option value="<?= $gID ?>"<?= in_array($gID, $settings->getExemptGroupIDs(), true) ? ' selected' : '' ?>><?= h($name) ?></option>
                <?php } ?>
            </select>
            <div class="form-text"><?= t('Members of these groups always get the normal website, like administrators but without their rights. Hold Ctrl (Cmd on a Mac) to pick several or to remove a group.') ?></div>
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="notfound" id="notfound" value="1"<?= $settings->isNotFoundSaved() ? ' checked' : '' ?>>
            <label class="form-check-label" for="notfound"><?= t('Replace the 404 page with the terminal') ?></label>
            <div class="form-text"><?= t('Whoever lands on an address that does not exist gets a full-screen terminal with the 404 as ASCII art in the welcome message, a snarky remark and suggestions for similar pages. The shell starts at the closest page that does exist; "exit" takes visitors back to the home page. It works whatever the access setting says.') ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="attitude"><?= t('Attitude') ?></label>
            <select class="form-select" name="attitude" id="attitude">
                <?php foreach ($attitudes as $handle => $attitude) { ?>
                    <option value="<?= h($handle) ?>"<?= $currentAttitude === $handle ? ' selected' : '' ?>><?= h($attitude->name() . ($attitude->description() !== '' ? ': ' . $attitude->description() : '')) ?></option>
                <?php } ?>
            </select>
            <div class="form-text"><?= t('How the terminal talks when something goes wrong. Packages add attitudes by listening to the on_cli_mode_register_attitudes event, the site can list classes in application/config/cli_mode.php (key "attitudes").') ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label"><?= t('Message of the day') ?></label>
            <div class="form-text mt-0 mb-2"><?= t('Shown under the banner when the terminal opens, in the language the terminal speaks (lang command). A language without a message gets the English one.') ?></div>
            <?php $motds = $settings->getMotds(); ?>
            <?php foreach (CliMode\Terminal\Settings::languages() as $locale => $language) { ?>
                <label class="form-label small text-muted mb-1" for="motd_<?= h($locale) ?>"><?= h($language) ?> <code><?= h($locale) ?></code></label>
                <textarea class="form-control font-monospace mb-3" name="motd[<?= h($locale) ?>]" id="motd_<?= h($locale) ?>" rows="8" maxlength="5000" style="font-size: .85rem"><?= h($motds[$locale] ?? '') ?></textarea>
            <?php } ?>
        </div>
    </fieldset>
    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <button type="submit" class="btn btn-primary float-end"><?= t('Save') ?></button>
        </div>
    </div>
</form>

<h3 class="mt-5"><?= t('Commands') ?></h3>
<p class="text-muted"><?= t('Everything the terminal knows. Hidden commands are jokes that do not show up in "help". Packages add commands in on_start() or by listening to the on_cli_mode_register_commands event, the site can list classes in application/config/cli_mode.php.') ?></p>
<table class="table table-sm table-striped">
    <thead><tr><th><?= t('Command') ?></th><th><?= t('Category') ?></th><th><?= t('Access') ?></th><th><?= t('Description') ?></th><th><?= t('Class') ?></th></tr></thead>
    <tbody>
    <?php $accessLabels = ['public' => t('Everybody'), 'registered' => t('Members'), 'admin' => t('Admins')]; ?>
    <?php foreach ($commands as $c) { ?>
        <tr<?= $c['hidden'] ? ' class="text-muted"' : '' ?>>
            <td><code><?= h($c['name']) ?></code><?= $c['aliases'] ? ' <small class="text-muted">(' . h(implode(', ', $c['aliases'])) . ')</small>' : '' ?></td>
            <td><?= h(CliMode\Command\System\HelpCommand::categoryLabel((string) $c['category'])) ?></td>
            <td><?= h($accessLabels[$c['access']] ?? $c['access']) ?></td>
            <td><?= h($c['description']) ?></td>
            <td><small><code><?= h($c['class']) ?></code></small></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
