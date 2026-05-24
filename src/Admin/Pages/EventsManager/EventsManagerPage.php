<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\BudgetPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\ConnectionGroupsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\EventsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\InviteesPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\LocationsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\MenuItemsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\NewslettersPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\VendorsPage;

/**
 * Renders the tabbed Events Manager admin page.
 *
 * Hosts Events, Invitees, Connection Groups, Locations, Food & Beverages,
 * and Budget as URL-based tabs under a single WordPress admin menu entry.
 */
final class EventsManagerPage
{
    /** @var EventsPage Events sub-page handler. */
    private EventsPage           $eventsPage;

    /** @var InviteesPage Invitees sub-page handler. */
    private InviteesPage         $inviteesPage;

    /** @var ConnectionGroupsPage Connection Groups sub-page handler. */
    private ConnectionGroupsPage $connectionGroupsPage;

    /** @var LocationsPage Locations sub-page handler. */
    private LocationsPage        $locationsPage;

    /** @var MenuItemsPage Food &amp; Beverages sub-page handler. */
    private MenuItemsPage        $menuItemsPage;

    /** @var BudgetPage Budget sub-page handler. */
    private BudgetPage           $budgetPage;

    /** @var NewslettersPage Newsletters sub-page handler. */
    private NewslettersPage      $newslettersPage;

    /** @var VendorsPage Vendors sub-page handler. */
    private VendorsPage          $vendorsPage;

    public function __construct(
        EventsPage           $eventsPage,
        InviteesPage         $inviteesPage,
        ConnectionGroupsPage $connectionGroupsPage,
        LocationsPage        $locationsPage,
        MenuItemsPage        $menuItemsPage,
        BudgetPage           $budgetPage,
        NewslettersPage      $newslettersPage,
        VendorsPage          $vendorsPage
    ) {
        $this->eventsPage           = $eventsPage;
        $this->inviteesPage         = $inviteesPage;
        $this->connectionGroupsPage = $connectionGroupsPage;
        $this->locationsPage        = $locationsPage;
        $this->menuItemsPage        = $menuItemsPage;
        $this->budgetPage           = $budgetPage;
        $this->newslettersPage      = $newslettersPage;
        $this->vendorsPage          = $vendorsPage;
    }

    /**
     * Renders the tabbed Events Manager page, delegating to the active sub-page.
     *
     * @return void
     */
    public function renderPage(): void
    {
        $tab = sanitize_key($_GET['tab'] ?? AdminMenu::TAB_EVENTS);

        $tabs = [
            AdminMenu::TAB_EVENTS            => 'Events',
            AdminMenu::TAB_INVITEES          => 'Invitees',
            AdminMenu::TAB_CONNECTION_GROUPS => 'Connection Groups',
            AdminMenu::TAB_LOCATIONS         => 'Locations',
            AdminMenu::TAB_MENU_ITEMS        => 'Food &amp; Beverages',
            AdminMenu::TAB_BUDGET            => 'Budget',
            AdminMenu::TAB_VENDORS           => 'Vendors',
            AdminMenu::TAB_NEWSLETTERS       => 'Newsletters',
        ];

        if (!array_key_exists($tab, $tabs)) {
            $tab = AdminMenu::TAB_EVENTS;
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Events Manager</h1>
            <hr class="wp-header-end">
        </div>
        <nav class="nav-tab-wrapper eim-manager-tabs">
            <?php foreach ($tabs as $tabSlug => $label): ?>
                <?php
                $url   = AdminMenu::tabUrl($tabSlug);
                $class = 'nav-tab' . ($tab === $tabSlug ? ' nav-tab-active' : '');
                ?>
                <a href="<?= esc_url($url); ?>" class="<?= esc_attr($class); ?>"><?= $label; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php

        $subPage = match ($tab) {
            AdminMenu::TAB_INVITEES          => $this->inviteesPage,
            AdminMenu::TAB_CONNECTION_GROUPS => $this->connectionGroupsPage,
            AdminMenu::TAB_LOCATIONS         => $this->locationsPage,
            AdminMenu::TAB_MENU_ITEMS        => $this->menuItemsPage,
            AdminMenu::TAB_BUDGET            => $this->budgetPage,
            AdminMenu::TAB_VENDORS           => $this->vendorsPage,
            AdminMenu::TAB_NEWSLETTERS       => $this->newslettersPage,
            default                          => $this->eventsPage,
        };

        $subPage->renderPage();
    }
}
