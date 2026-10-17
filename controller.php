<?php

namespace Concrete\Package\CliMode;

use CliMode\Command\Builtin;
use CliMode\Terminal\Attitude\MildlySnarkyAttitude;
use CliMode\Terminal\Attitude\PoliteAttitude;
use CliMode\Terminal\Attitude\SuperSnarkyAttitude;
use CliMode\Terminal\AttitudeRegistry;
use CliMode\Terminal\CommandRegistry;
use CliMode\Terminal\Settings;
use Concrete\Core\Asset\Asset;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Http\Request;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Routing\Router;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * CLI Mode: a fake terminal for the website. Press ` (or type #cli in the address) and talk to the site in a shell
 * that has opinions. Packages add their own commands (see README), the LAN party commands come with the event manager.
 */
class Controller extends Package
{
    protected $pkgHandle = 'cli_mode';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '1.18.0';
    protected $pkgAutoloaderRegistries = [
        'src' => 'CliMode',
    ];

    public function getPackageName()
    {
        return t('CLI Mode');
    }

    public function getPackageDescription()
    {
        return t('A fake Linux terminal for your website, with pipes, history, tab completion, snarky error messages and commands you can write yourself.');
    }

    public function on_start()
    {
        $this->app->singleton(CommandRegistry::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(AttitudeRegistry::class);

        $this->app->make('director')->addListener('on_cli_mode_register_attitudes', function ($event) {
            foreach ([new PoliteAttitude(), new MildlySnarkyAttitude(), new SuperSnarkyAttitude()] as $attitude) {
                $event->getRegistry()->register($attitude);
            }
        });

        // the built-in commands are created when the terminal is used first, not on every page view
        $this->app->make('director')->addListener('on_cli_mode_register_commands', function ($event) {
            foreach (Builtin::all() as $command) {
                $event->getRegistry()->register($command);
            }
        });

        $router = $this->app->make(Router::class);
        $router->get('/ccm/cli_mode/boot', 'CliMode\Controller\Terminal::boot');
        $router->post('/ccm/cli_mode/exec', 'CliMode\Controller\Terminal::exec');
        $router->post('/ccm/cli_mode/complete', 'CliMode\Controller\Terminal::complete');
        $router->post('/ccm/cli_mode/login', 'CliMode\Controller\Terminal::login');

        $assets = AssetList::getInstance();
        $options = ['version' => $this->pkgVersion, 'position' => Asset::ASSET_POSITION_FOOTER, 'minify' => false, 'combine' => false];
        $assets->register('javascript', 'cli_mode/terminal', 'js/terminal.js', $options, $this);
        $assets->register('css', 'cli_mode/terminal', 'css/terminal.css', ['version' => $this->pkgVersion, 'minify' => false, 'combine' => false], $this);

        $this->app->make('director')->addListener('on_before_render', function ($event) {
            $view = $event->getArgument('view');
            if (!$view instanceof PageView) {
                return;
            }
            $page = $view->getPageObject();
            // screens on a TV in the hall (theme_blackout big_screen) get neither the terminal nor its launcher
            if (!$page || $page->isAdminArea() || $page->isEditMode() || in_array((string) $page->getPageTypeHandle(), Settings::BYPASS_PAGE_TYPES, true)) {
                return;
            }
            $settings = $this->app->make(Settings::class);
            $user = $this->app->make(User::class);
            $request = $this->app->make(Request::class);
            $path = '/' . ltrim((string) $request->getPath(), '/');
            // administrators can look at the mandatory mode with ?forcecli, nobody else gets a preview
            $preview = $request->query->has('forcecli') && Settings::isAdmin($user);
            // pages ticked "without mandatory terminal" (and the pages below them) render normally
            $forced = $settings->isForcedFor($user, $preview) && !Settings::isBypassPath($path) && !Settings::isExemptPage($page);
            // the system 404 page, reached through an address that is not the page itself
            $notFound = !$forced && $settings->replacesNotFound() && (string) $page->getCollectionPath() === '/page_not_found' && $path !== '/page_not_found';
            if (!$forced && !$notFound && ($page->isError() || !$settings->mayUse($user))) {
                return;
            }
            $urls = $this->app->make('url/manager');
            // path only: the endpoints stay on the host the page was served from (turicane.ch vs www.), else the
            // session cookie of a login lands on the other host
            $endpoint = static fn (string $route): string => $urls->resolve([$route])->getPath()->getUriComponent();
            $config = [
                'forced' => $forced,
                'notfound' => $notFound,
                'preview' => $preview,
                'path' => $path,
                'boot' => $endpoint('/ccm/cli_mode/boot'),
                'exec' => $endpoint('/ccm/cli_mode/exec'),
                'complete' => $endpoint('/ccm/cli_mode/complete'),
                'login' => $endpoint('/ccm/cli_mode/login'),
                'launcher' => $settings->showLauncher() && !$forced && !$notFound,
                'title' => t('Open the terminal (press `)'),
                // texts of the browser side; boot sends them again in the terminal's language
                'labels' => Settings::labels(),
            ];
            $view->addFooterItem('<script>window.CliModeConfig = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';</script>');
            if ($forced || $notFound) {
                // set before the page is painted: with JavaScript the website behind the terminal never shows up
                $view->addHeaderItem('<script>document.documentElement.classList.add("clim-forced");</script>');
            }
            $view->requireAsset('javascript', 'cli_mode/terminal');
            $view->requireAsset('css', 'cli_mode/terminal');
        });
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installContent();

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installContent();
    }

    protected function installContent(): void
    {
        $page = Page::getByPath('/dashboard/system/basics/cli_mode');
        if (!$page || $page->isError()) {
            $page = SinglePage::add('/dashboard/system/basics/cli_mode', $this->getPackageEntity());
            $page->update(['cName' => t('CLI Mode'), 'cDescription' => t('The fake terminal: who may use it, how snarky it is, which commands it knows.')]);
        }
        if (!BlockType::getByHandle('cli_terminal')) {
            BlockType::installBlockType('cli_terminal', $this->getPackageEntity());
        }
        $category = $this->app->make(CategoryService::class)->getByHandle('collection')->getController();
        if (!$category->getAttributeKeyByHandle(Settings::EXEMPT_PAGE_ATTRIBUTE)) {
            $category->add('boolean', [
                'akHandle' => Settings::EXEMPT_PAGE_ATTRIBUTE,
                'akName' => t('Without mandatory terminal (incl. subpages)'),
            ], null, $this->getPackageEntity());
        }
    }
}
