<?php

namespace Concrete\Package\CliMode\Block\CliTerminal;

use CliMode\Terminal\Settings;
use Concrete\Core\Block\BlockController;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The terminal inside the page instead of over it. It talks to the same shell session as the overlay (working
 * directory, history, login) and can type a start command by itself once it scrolls into view. Shown to the people
 * who may use CLI Mode; for everybody else the block renders nothing.
 */
class Controller extends BlockController
{
    protected $btTable = 'btCliModeTerminal';
    protected $btInterfaceWidth = 500;
    protected $btInterfaceHeight = 380;
    protected $btDefaultSet = 'basic';
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockRecord = true;

    /** @var string|null command typed and run when the terminal comes into view */
    public $autorun;
    /** @var string|null first line instead of the "type help" hint */
    public $greeting;
    /** @var int pixels */
    public $height;

    public function getBlockTypeName()
    {
        return t('Terminal');
    }

    public function getBlockTypeDescription()
    {
        return t('The CLI Mode terminal right in the page, optionally running a command when it comes into view.');
    }

    public function add()
    {
        $this->set('autorun', '');
        $this->set('greeting', '');
        $this->set('height', 360);
    }

    public function save($args)
    {
        parent::save([
            'autorun' => trim((string) ($args['autorun'] ?? '')),
            'greeting' => trim((string) ($args['greeting'] ?? '')),
            'height' => max(160, min(1200, (int) ($args['height'] ?? 360))),
        ]);
    }

    public function view()
    {
        $page = $this->getCollectionObject();
        $editMode = $page && $page->isEditMode();
        // the same rule as the overlay: the terminal script and its configuration are only on the page for them
        $settings = $this->app->make(Settings::class);
        $this->set('available', $settings->mayUse($this->app->make(User::class)));
        $this->set('editMode', $editMode);
        $this->set('height', max(160, (int) $this->height ?: 360));
    }
}
