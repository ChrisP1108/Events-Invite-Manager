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
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\CategoriesPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\GiftsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\NewslettersPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\MessagesPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\RequestedInviteesPage;
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

    /** @var CategoriesPage Categories sub-page handler. */
    private CategoriesPage       $categoriesPage;

    /** @var GiftsPage Gifts sub-page handler. */
    private GiftsPage               $giftsPage;

    /** @var RequestedInviteesPage Requested Invitee Add-Ons sub-page handler. */
    private RequestedInviteesPage   $requestedInviteesPage;

    /** @var MessagesPage Global Messages sub-page handler. */
    private MessagesPage            $messagesPage;

    public function __construct(
        EventsPage             $eventsPage,
        InviteesPage           $inviteesPage,
        ConnectionGroupsPage   $connectionGroupsPage,
        LocationsPage          $locationsPage,
        MenuItemsPage          $menuItemsPage,
        BudgetPage             $budgetPage,
        NewslettersPage        $newslettersPage,
        VendorsPage            $vendorsPage,
        CategoriesPage         $categoriesPage,
        GiftsPage              $giftsPage,
        RequestedInviteesPage  $requestedInviteesPage,
        MessagesPage           $messagesPage
    ) {
        $this->eventsPage             = $eventsPage;
        $this->inviteesPage           = $inviteesPage;
        $this->connectionGroupsPage   = $connectionGroupsPage;
        $this->locationsPage          = $locationsPage;
        $this->menuItemsPage          = $menuItemsPage;
        $this->budgetPage             = $budgetPage;
        $this->newslettersPage        = $newslettersPage;
        $this->vendorsPage            = $vendorsPage;
        $this->categoriesPage         = $categoriesPage;
        $this->giftsPage              = $giftsPage;
        $this->requestedInviteesPage  = $requestedInviteesPage;
        $this->messagesPage           = $messagesPage;
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
            AdminMenu::TAB_EVENTS               => 'Events',
            AdminMenu::TAB_INVITEES             => 'Invitees',
            AdminMenu::TAB_REQUESTED_INVITEES   => 'Requested Add-Ons',
            AdminMenu::TAB_MESSAGES             => 'Messages',
            AdminMenu::TAB_CONNECTION_GROUPS    => 'Connection Groups',
            AdminMenu::TAB_LOCATIONS         => 'Locations',
            AdminMenu::TAB_MENU_ITEMS        => 'Food &amp; Beverages',
            AdminMenu::TAB_BUDGET            => 'Budget',
            AdminMenu::TAB_VENDORS           => 'Vendors',
            AdminMenu::TAB_NEWSLETTERS       => 'Newsletters',
            AdminMenu::TAB_CATEGORIES        => 'Categories',
            AdminMenu::TAB_GIFTS             => 'Gifts &amp; Registry',
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
            AdminMenu::TAB_INVITEES             => $this->inviteesPage,
            AdminMenu::TAB_REQUESTED_INVITEES   => $this->requestedInviteesPage,
            AdminMenu::TAB_MESSAGES             => $this->messagesPage,
            AdminMenu::TAB_CONNECTION_GROUPS    => $this->connectionGroupsPage,
            AdminMenu::TAB_LOCATIONS         => $this->locationsPage,
            AdminMenu::TAB_MENU_ITEMS        => $this->menuItemsPage,
            AdminMenu::TAB_BUDGET            => $this->budgetPage,
            AdminMenu::TAB_VENDORS           => $this->vendorsPage,
            AdminMenu::TAB_NEWSLETTERS       => $this->newslettersPage,
            AdminMenu::TAB_CATEGORIES        => $this->categoriesPage,
            AdminMenu::TAB_GIFTS             => $this->giftsPage,
            default                          => $this->eventsPage,
        };

        $subPage->renderPage();
    }
}
