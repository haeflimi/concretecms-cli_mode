<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string|null $autorun
 * @var string|null $greeting
 * @var int $height
 */
?>
<div class="form-group">
    <label class="form-label" for="autorun"><?= t('Start command') ?></label>
    <input type="text" class="form-control font-monospace" name="autorun" id="autorun" maxlength="255" value="<?= h((string) $autorun) ?>" placeholder="next">
    <div class="form-text"><?= t('Typed and run by the terminal itself when it comes into view. Empty: nothing.') ?></div>
</div>
<div class="form-group">
    <label class="form-label" for="greeting"><?= t('First line') ?></label>
    <input type="text" class="form-control" name="greeting" id="greeting" maxlength="255" value="<?= h((string) $greeting) ?>" placeholder="<?= h(t('Type help for the commands.')) ?>">
</div>
<div class="form-group">
    <label class="form-label" for="height"><?= t('Height in pixels') ?></label>
    <input type="number" class="form-control" name="height" id="height" min="160" max="1200" value="<?= (int) $height ?>">
</div>
