<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var bool $available CLI Mode is on and this visitor may use it
 * @var bool $editMode
 * @var string|null $autorun
 * @var string|null $greeting
 * @var int $height
 */
if ($editMode) {
    // the terminal script is not loaded in edit mode
    ?>
    <div style="display: flex; align-items: center; justify-content: center; height: <?= (int) $height ?>px; background: #07080a; color: #c9a012; border: 1px dashed #3b3a2c; border-radius: 10px; font-family: ui-monospace, Menlo, Consolas, monospace;">
        <span>&gt;_ <?= t('Terminal') ?><?= $autorun ? ' · ' . h($autorun) : '' ?></span>
    </div>
    <?php
    return;
}
if (!$available) {
    return;
}
?>
<div class="clim-mount" data-cli-mode-inline data-autorun="<?= h((string) $autorun) ?>" data-greeting="<?= h((string) $greeting) ?>" style="--clim-height: <?= (int) $height ?>px"></div>
