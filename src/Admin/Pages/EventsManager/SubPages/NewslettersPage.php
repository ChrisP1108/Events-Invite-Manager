<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Newsletter;
use EventsInviteManager\Models\NewsletterCategory;
use EventsInviteManager\Models\NewsletterTag;

/**
 * Admin CRUD page for newsletter posts, including category and tag management.
 *
 * Actions handled:
 *   save_newsletter            — create or update a newsletter
 *   delete_newsletter          — delete a newsletter
 *   add_newsletter_category    — create a category
 *   delete_newsletter_category — delete a category
 *   add_newsletter_tag         — create a tag
 *   delete_newsletter_tag      — delete a tag
 */
final class NewslettersPage extends AbstractAdminPage
{
    /** @var EmailService Email service used to dispatch newsletter emails. */
    private EmailService $emailService;

    /**
     * Ensures the newsletter tables exist and initialises the page.
     *
     * @param EmailService $emailService Service used to send newsletter emails.
     */
    public function __construct(EmailService $emailService)
    {
        DatabaseManager::maybeCreateNewsletterTables();
        $this->emailService = $emailService;
    }

    // ─── Action dispatch ─────────────────────────────────────────────────────

    /**
     * Dispatches newsletter form submissions and GET actions.
     *
     * @param string $action The action slug.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_newsletter'       => $this->handleSaveNewsletter(),
            'delete_newsletter'     => $this->handleDeleteNewsletter(),
            'add_newsletter_category'    => $this->handleAddCategory(),
            'delete_newsletter_category' => $this->handleDeleteCategory(),
            'add_newsletter_tag'         => $this->handleAddTag(),
            'delete_newsletter_tag'      => $this->handleDeleteTag(),
            default                 => null,
        };
    }

    // ─── AJAX ────────────────────────────────────────────────────────────────

    /**
     * Handles the wp_ajax_eim_search_newsletters AJAX action.
     *
     * Expected GET params: nonce, query, field, sort, order.
     * Returns JSON: { success: true, data: { html, count } }
     */
    public function handleAjaxSearchNewsletters(): void
    {
        check_ajax_referer('eim_search_newsletters_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort  = $this->sanitizeSortKey((string) ($_GET['sort']  ?? 'title'));
        $order = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field = $this->sanitizeFieldKey((string) ($_GET['field'] ?? ''));

        $newsletters = Newsletter::listForAdmin($query, $sort, $order, $field);

        ob_start();
        $this->renderNewsletterRows($newsletters, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => count($newsletters),
        ]);
    }

    /**
     * Handles the wp_ajax_eim_send_newsletter AJAX action.
     *
     * Sends the newsletter to all unique invitees across the newsletter's linked events.
     * Returns JSON: { success: true, data: { sent, failed, total } }
     */
    public function handleAjaxSendNewsletter(): void
    {
        check_ajax_referer('eim_send_newsletter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $id         = (int) ($_POST['newsletter_id'] ?? 0);
        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            wp_send_json_error('Newsletter not found.');
        }

        $events = Newsletter::eventsForNewsletter($id);

        if (empty($events)) {
            wp_send_json_error('No events are linked to this newsletter.');
        }

        // Collect unique invitees (deduplicated by email) across all linked events.
        $seen     = [];
        $invitees = [];
        foreach ($events as $ev) {
            foreach (Invitee::forEvent($ev['id']) as $invitee) {
                if ($invitee->email === '' || isset($seen[$invitee->email])) {
                    continue;
                }
                $seen[$invitee->email] = true;
                $invitees[]            = $invitee;
            }
        }

        if (empty($invitees)) {
            wp_send_json_error('No invitees with email addresses were found for the linked events.');
        }

        $sent   = 0;
        $failed = 0;
        foreach ($invitees as $invitee) {
            if ($this->emailService->sendNewsletterToInvitee($newsletter, $invitee)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success([
            'sent'   => $sent,
            'failed' => $failed,
            'total'  => count($invitees),
        ]);
    }

    /**
     * Handles the wp_ajax_eim_send_newsletter_test AJAX action.
     *
     * Sends the newsletter to a single test email address.
     * Returns JSON: { success: true, data: { email } }
     */
    public function handleAjaxSendNewsletterTest(): void
    {
        check_ajax_referer('eim_send_newsletter_test_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $id         = (int) ($_POST['newsletter_id'] ?? 0);
        $email      = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            wp_send_json_error('Newsletter not found.');
        }

        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address.');
        }

        if ($this->emailService->sendNewsletterTest($newsletter, $email)) {
            wp_send_json_success(['email' => $email]);
        } else {
            wp_send_json_error('Failed to send the test email. Check your server mail configuration.');
        }
    }

    // ─── Page routing ────────────────────────────────────────────────────────

    /** Renders the Newsletters admin page, routing to the list or add/edit form. */
    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'  => $this->renderNewsletterForm(null),
            'edit' => $this->renderNewsletterForm(Newsletter::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderNewslettersList(),
        };
    }

    // ─── Form handlers ───────────────────────────────────────────────────────

    /** Handles creating or updating a newsletter from the admin form. */
    private function handleSaveNewsletter(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_newsletter')) {
            wp_die('Security check failed.');
        }

        $id = (int) ($_POST['newsletter_id'] ?? 0);

        // Collect raw POST content — wp_editor POSTs HTML, so use wp_kses_post.
        $content = isset($_POST['newsletter_content'])
            ? wp_kses_post(wp_unslash($_POST['newsletter_content']))
            : '';

        $data = [
            'title'        => sanitize_text_field(wp_unslash($_POST['title']        ?? '')),
            'content'      => $content,
            'status'       => sanitize_key($_POST['status']       ?? 'draft'),
            'publish_date' => sanitize_text_field(wp_unslash($_POST['publish_date'] ?? '')),
            'event_ids'    => array_map('intval', (array) ($_POST['event_ids']    ?? [])),
            'category_ids' => array_map('intval', (array) ($_POST['category_ids'] ?? [])),
            'tag_ids'      => array_map('intval', (array) ($_POST['tag_ids']      ?? [])),
        ];

        if (empty($data['title'])) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'newsletter_title_required',
            ]));
            exit;
        }

        if ($id > 0) {
            Newsletter::update($id, $data);
            $message = 'newsletter_updated';
        } else {
            $id      = Newsletter::create($data);
            $message = 'newsletter_created';
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_message' => $message]));
        exit;
    }

    /** Handles deleting a newsletter via a GET nonce link. */
    private function handleDeleteNewsletter(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_newsletter_' . $id)) {
            wp_die('Security check failed.');
        }

        Newsletter::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_message' => 'newsletter_deleted']));
        exit;
    }

    /** Handles creating a new newsletter category from the taxonomy panel form. */
    private function handleAddCategory(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_newsletter_category')) {
            wp_die('Security check failed.');
        }

        $name = sanitize_text_field(wp_unslash($_POST['category_name'] ?? ''));

        if (empty($name)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_error' => 'nl_category_name_required']));
            exit;
        }

        NewsletterCategory::create($name);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_message' => 'nl_category_added', '#' => 'eim-nl-taxonomy-panel']));
        exit;
    }

    /** Handles deleting a newsletter category via a GET nonce link. */
    private function handleDeleteCategory(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_nl_category_' . $id)) {
            wp_die('Security check failed.');
        }

        NewsletterCategory::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_message' => 'nl_category_deleted']));
        exit;
    }

    /** Handles creating a new newsletter tag from the taxonomy panel form. */
    private function handleAddTag(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_newsletter_tag')) {
            wp_die('Security check failed.');
        }

        $name = sanitize_text_field(wp_unslash($_POST['tag_name'] ?? ''));

        if (empty($name)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_error' => 'nl_tag_name_required']));
            exit;
        }

        NewsletterTag::create($name);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_message' => 'nl_tag_added']));
        exit;
    }

    /** Handles deleting a newsletter tag via a GET nonce link. */
    private function handleDeleteTag(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_nl_tag_' . $id)) {
            wp_die('Security check failed.');
        }

        NewsletterTag::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['eim_message' => 'nl_tag_deleted']));
        exit;
    }

    // ─── List view ───────────────────────────────────────────────────────────

    /** Renders the newsletters list table with search bar and sortable columns. */
    private function renderNewslettersList(): void
    {
        $message     = (string) ($_GET['eim_message'] ?? '');
        $error       = (string) ($_GET['eim_error']   ?? '');
        $search      = sanitize_text_field(wp_unslash($_GET['s']     ?? ''));
        $sort        = $this->sanitizeSortKey((string) ($_GET['sort']  ?? 'title'));
        $order       = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field       = $this->sanitizeFieldKey((string) ($_GET['field'] ?? ''));
        $newsletters = Newsletter::listForAdmin($search, $sort, $order, $field);
        $addUrl      = AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['action' => 'add']);
        $categories  = NewsletterCategory::all();
        $tags        = NewsletterTag::all();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Newsletters</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Newsletter</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Manage newsletter posts here. These can be sent as email blasts and displayed on the website.
            </p>

            <?php $this->renderSearchBar(
                'eim-newsletter-search',
                'eim-newsletter-count',
                'eim-newsletter-loading',
                'Search by title, event, category, or tag…',
                count($newsletters),
                $search,
                [
                    ['value' => 'title',      'label' => 'Title'],
                    ['value' => 'events',     'label' => 'Events'],
                    ['value' => 'categories', 'label' => 'Categories'],
                    ['value' => 'tags',       'label' => 'Tags'],
                    ['value' => 'status',     'label' => 'Status'],
                ],
                $field
            ); ?>

            <table id="eim-newsletters-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>">
                <thead>
                    <tr>
                        <th style="width:26%;"><?= $this->sortLink('Title',        'title',        AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_NEWSLETTERS]); ?></th>
                        <th style="width:20%;"><?= $this->sortLink('Events',       'events',       AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_NEWSLETTERS]); ?></th>
                        <th style="width:16%;"><?= $this->sortLink('Categories',   'categories',   AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_NEWSLETTERS]); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Tags',         'tags',         AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_NEWSLETTERS]); ?></th>
                        <th style="width:12%;"><?= $this->sortLink('Publish Date', 'publish_date', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_NEWSLETTERS]); ?></th>
                        <th style="width:8%;"><?= $this->sortLink('Status',        'status',       AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_NEWSLETTERS]); ?></th>
                        <th style="width:10%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-newsletters-table-body">
                    <?php $this->renderNewsletterRows($newsletters, $search); ?>
                </tbody>
            </table>

            <?php if (empty($newsletters) && $search === ''): ?>
                <p style="margin-top:12px;">No newsletters yet. <a href="<?= esc_url($addUrl); ?>">Add the first newsletter.</a></p>
            <?php endif; ?>
        </div>

        <?php $this->renderTaxonomyPanel($categories, $tags); ?>
        <?php
    }

    /**
     * Renders newsletter table rows for both the initial page load and AJAX responses.
     *
     * @param Newsletter[] $newsletters
     */
    private function renderNewsletterRows(array $newsletters, string $search = ''): void
    {
        if (empty($newsletters)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No newsletters found.';
            echo '<tr class="eim-no-results"><td colspan="7">' . esc_html($msg) . '</td></tr>';
            return;
        }

        foreach ($newsletters as $nl) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['action' => 'edit', 'id' => $nl->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, ['action' => 'delete_newsletter', 'id' => $nl->id]),
                'eim_delete_newsletter_' . $nl->id
            );

            $publishLabel = $nl->publishDate
                ? date_i18n(get_option('date_format'), strtotime($nl->publishDate))
                : '—';

            $statusBg    = $nl->status === 'published' ? '#dff0d8' : '#f0f0f1';
            $statusLabel = $nl->status === 'published' ? 'Published' : 'Draft';
            ?>
            <tr>
                <td><strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($nl->title); ?></a></strong></td>
                <td>
                    <?php if (empty($nl->events)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($nl->events as $ev): ?>
                                <?php $evUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $ev['id']]); ?>
                                <a class="eim-event-tag" href="<?= esc_url($evUrl); ?>"><?= esc_html($ev['name']); ?></a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($nl->categories)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($nl->categories as $cat): ?>
                                <span class="eim-event-tag"><?= esc_html($cat['name']); ?></span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($nl->tags)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($nl->tags as $tag): ?>
                                <span class="eim-event-tag"><?= esc_html($tag['name']); ?></span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?= esc_html($publishLabel); ?></td>
                <td>
                    <span style="background:<?= esc_attr($statusBg); ?>;padding:2px 8px;border-radius:3px;font-size:12px;">
                        <?= esc_html($statusLabel); ?>
                    </span>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete <?= esc_js($nl->title); ?>?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    // ─── Taxonomy management panel ───────────────────────────────────────────

    /**
     * Renders the collapsible panel for managing categories and tags inline.
     *
     * @param NewsletterCategory[] $categories
     * @param NewsletterTag[]      $tags
     */
    private function renderTaxonomyPanel(array $categories, array $tags): void
    {
        $catAction = AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS);
        $tagAction = AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS);
        ?>
        <div id="eim-nl-taxonomy-panel" style="max-width:900px;margin-top:24px;">
            <details>
                <summary style="cursor:pointer;font-weight:600;font-size:14px;padding:8px 0;">
                    Manage Categories &amp; Tags
                </summary>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px;">

                    <!-- Categories -->
                    <div>
                        <h3 style="margin-top:0;">Categories</h3>
                        <?php if (empty($categories)): ?>
                            <p class="description">No categories yet.</p>
                        <?php else: ?>
                            <ul style="margin:0 0 12px;padding:0;list-style:none;">
                                <?php foreach ($categories as $cat): ?>
                                    <?php
                                    $delUrl = wp_nonce_url(
                                        AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, [
                                            'action' => 'delete_newsletter_category',
                                            'id'     => $cat->id,
                                        ]),
                                        'eim_delete_nl_category_' . $cat->id
                                    );
                                    ?>
                                    <li style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f1;">
                                        <span style="flex:1;"><?= esc_html($cat->name); ?></span>
                                        <a href="<?= esc_url($delUrl); ?>"
                                           style="color:#d63638;font-size:12px;"
                                           onclick="return confirm('Delete category &quot;<?= esc_js($cat->name); ?>&quot;? It will be removed from all newsletters.');">
                                            Delete
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <form method="post" action="<?= esc_url($catAction); ?>" style="display:flex;gap:8px;">
                            <?php wp_nonce_field('eim_add_newsletter_category'); ?>
                            <input type="hidden" name="eim_action" value="add_newsletter_category">
                            <input type="text" name="category_name" class="regular-text" placeholder="New category name" required>
                            <button type="submit" class="button">Add</button>
                        </form>
                    </div>

                    <!-- Tags -->
                    <div>
                        <h3 style="margin-top:0;">Tags</h3>
                        <?php if (empty($tags)): ?>
                            <p class="description">No tags yet.</p>
                        <?php else: ?>
                            <ul style="margin:0 0 12px;padding:0;list-style:none;">
                                <?php foreach ($tags as $tag): ?>
                                    <?php
                                    $delUrl = wp_nonce_url(
                                        AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS, [
                                            'action' => 'delete_newsletter_tag',
                                            'id'     => $tag->id,
                                        ]),
                                        'eim_delete_nl_tag_' . $tag->id
                                    );
                                    ?>
                                    <li style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f1;">
                                        <span style="flex:1;"><?= esc_html($tag->name); ?></span>
                                        <a href="<?= esc_url($delUrl); ?>"
                                           style="color:#d63638;font-size:12px;"
                                           onclick="return confirm('Delete tag &quot;<?= esc_js($tag->name); ?>&quot;? It will be removed from all newsletters.');">
                                            Delete
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <form method="post" action="<?= esc_url($tagAction); ?>" style="display:flex;gap:8px;">
                            <?php wp_nonce_field('eim_add_newsletter_tag'); ?>
                            <input type="hidden" name="eim_action" value="add_newsletter_tag">
                            <input type="text" name="tag_name" class="regular-text" placeholder="New tag name" required>
                            <button type="submit" class="button">Add</button>
                        </form>
                    </div>

                </div>
            </details>
        </div>
        <?php
    }

    // ─── Add / edit form ─────────────────────────────────────────────────────

    /**
     * Renders the add/edit form for a newsletter, including the TinyMCE editor and live preview.
     *
     * @param Newsletter|null $newsletter Existing newsletter to edit, or null when adding.
     */
    private function renderNewsletterForm(?Newsletter $newsletter): void
    {
        $isNew      = $newsletter === null;
        $message    = (string) ($_GET['eim_message'] ?? '');
        $error      = (string) ($_GET['eim_error']   ?? '');
        $backUrl    = AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS);
        $title      = $isNew ? 'Add Newsletter' : 'Edit Newsletter';

        $allCategories = NewsletterCategory::all();
        $allTags       = NewsletterTag::all();

        // For edit: fetch current associations.
        $linkedEventIds    = [];
        $linkedCategoryIds = [];
        $linkedTagIds      = [];

        if (!$isNew) {
            $linkedEventIds    = array_column(Newsletter::eventsForNewsletter($newsletter->id),     'id');
            $linkedCategoryIds = array_column(Newsletter::categoriesForNewsletter($newsletter->id), 'id');
            $linkedTagIds      = array_column(Newsletter::tagsForNewsletter($newsletter->id),       'id');
        }

        $currentStatus      = $isNew ? 'draft'  : $newsletter->status;
        $currentPublishDate = $isNew ? ''        : ($newsletter->publishDate ? date('Y-m-d\TH:i', strtotime($newsletter->publishDate)) : '');
        $currentContent     = $isNew ? ''        : $newsletter->content;
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Newsletters</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS)); ?>">
                <?php wp_nonce_field('eim_save_newsletter'); ?>
                <input type="hidden" name="eim_action" value="save_newsletter">
                <input type="hidden" name="newsletter_id" value="<?= esc_attr($isNew ? 0 : $newsletter->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_nl_title">Title <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" id="eim_nl_title" name="title" class="large-text"
                                   value="<?= esc_attr($isNew ? '' : $newsletter->title); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Status</label></th>
                        <td>
                            <select name="status">
                                <option value="draft"     <?= selected($currentStatus, 'draft',     false); ?>>Draft</option>
                                <option value="published" <?= selected($currentStatus, 'published', false); ?>>Published</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_nl_publish_date">Publish Date</label></th>
                        <td>
                            <input type="datetime-local" id="eim_nl_publish_date" name="publish_date"
                                   value="<?= esc_attr($currentPublishDate); ?>">
                            <p class="description">Leave blank to use the created date.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Events</th>
                        <td>
                            <?php
                            $linkedEvents = array_values(array_filter(
                                array_map(static fn(int $id) => Event::find($id), $linkedEventIds)
                            ));
                            $dateFormat   = (string) get_option('date_format', 'M j, Y');
                            $formatDt     = static function (?string $utcDt, string $tz) use ($dateFormat): string {
                                if (!$utcDt) return '';
                                $dt = new \DateTime($utcDt, new \DateTimeZone('UTC'));
                                if ($tz !== '') { try { $dt->setTimezone(new \DateTimeZone($tz)); } catch (\Throwable) {} }
                                return $dt->format($dateFormat . ', g:i A');
                            };
                            $linkedEventData = array_map(static fn(Event $e): array => [
                                'id'          => $e->id,
                                'name'        => $e->name,
                                'start_label' => $formatDt($e->startDatetime, $e->timezone),
                                'end_label'   => $e->endDatetime ? $formatDt($e->endDatetime, $e->timezone) : '',
                                'start_raw'   => $e->startDatetime ?? '',
                                'end_raw'     => $e->endDatetime   ?? '',
                            ], $linkedEvents);
                            $this->renderEventPicker('eim-newsletter-event-picker', $linkedEventData, 'event_ids[]');
                            ?>
                            <p class="description" style="margin-top:8px;">Associate this newsletter with one or more events.</p>
                        </td>
                    </tr>
                    <?php if (!empty($allCategories)): ?>
                    <tr>
                        <th scope="row">Categories</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Categories</legend>
                                <?php foreach ($allCategories as $cat): ?>
                                    <label style="display:inline-block;margin-right:16px;margin-bottom:4px;">
                                        <input type="checkbox" name="category_ids[]"
                                               value="<?= esc_attr($cat->id); ?>"
                                               <?= in_array($cat->id, $linkedCategoryIds, true) ? 'checked' : ''; ?>>
                                        <?= esc_html($cat->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($allTags)): ?>
                    <tr>
                        <th scope="row">Tags</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Tags</legend>
                                <?php foreach ($allTags as $tag): ?>
                                    <label style="display:inline-block;margin-right:16px;margin-bottom:4px;">
                                        <input type="checkbox" name="tag_ids[]"
                                               value="<?= esc_attr($tag->id); ?>"
                                               <?= in_array($tag->id, $linkedTagIds, true) ? 'checked' : ''; ?>>
                                        <?= esc_html($tag->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <h2 class="title">Content</h2>

                <style>
                #eim-nl-content-layout {
                    display: flex;
                    gap: 20px;
                    align-items: stretch;
                }
                #eim-nl-editor-col  { flex: 1 1 0; min-width: 0; }
                #eim-nl-preview-col { flex: 1 1 0; min-width: 0; display: none; }
                @media (max-width: 1024px) {
                    #eim-nl-content-layout { flex-direction: column; }
                    #eim-nl-editor-col,
                    #eim-nl-preview-col    { flex: none; width: 100%; }
                }
                </style>

                <div id="eim-nl-content-layout">

                    <div id="eim-nl-editor-col">
                        <?php
                        wp_editor(
                            $currentContent,
                            'newsletter_content',
                            [
                                'textarea_name' => 'newsletter_content',
                                'textarea_rows' => 20,
                                'media_buttons' => true,
                                'teeny'         => false,
                            ]
                        );
                        ?>
                        <p style="margin-top:10px;">
                            <button type="button" id="eim-nl-preview-btn" class="button">Preview Content</button>
                        </p>
                    </div>

                    <div id="eim-nl-preview-col">
                        <div style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
                            <div style="background:#f6f7f7;padding:8px 12px;border-bottom:1px solid #dcdcde;display:flex;justify-content:space-between;align-items:center;">
                                <strong style="font-size:13px;">Content Preview</strong>
                                <button type="button" id="eim-nl-preview-close"
                                        class="button-link" style="color:#d63638;">Close Preview</button>
                            </div>
                            <iframe id="eim-nl-preview-frame"
                                    style="width:100%;min-height:480px;border:none;display:block;background:#fff;"
                                    title="Newsletter Content Preview"></iframe>
                        </div>
                    </div>

                </div>

                <p style="margin-top:16px;">
                    <?php submit_button($isNew ? 'Add Newsletter' : 'Update Newsletter', 'primary', 'submit', false); ?>
                    <a href="<?= esc_url($backUrl); ?>" class="button" style="margin-left:8px;">Cancel</a>
                </p>
            </form>

            <?php if (!$isNew && $newsletter !== null): ?>
            <?php
            // Count unique invitees across all linked events (deduplicated by email).
            $seenEmails     = [];
            $uniqueInvitees = 0;
            foreach ($linkedEventIds as $evId) {
                foreach (Invitee::forEvent($evId) as $inv) {
                    if ($inv->email !== '' && !isset($seenEmails[$inv->email])) {
                        $seenEmails[$inv->email] = true;
                        $uniqueInvitees++;
                    }
                }
            }
            $nlId = $newsletter->id;
            ?>
            <div id="eim-nl-send-panel" style="max-width:900px;margin-top:32px;border-top:2px solid #dcdcde;padding-top:24px;">
                <h2 class="title">Send Newsletter</h2>

                <?php if (empty($linkedEventIds)): ?>
                    <p class="description">No events are linked to this newsletter. Add at least one event above to enable sending to invitees.</p>
                <?php else: ?>
                    <p class="description" style="margin-bottom:16px;">
                        This newsletter is linked to
                        <strong><?= count($linkedEventIds); ?></strong> event<?= count($linkedEventIds) === 1 ? '' : 's'; ?>
                        with <strong><?= $uniqueInvitees; ?></strong> unique invitee<?= $uniqueInvitees === 1 ? '' : 's'; ?>.
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
                        <button type="button"
                                id="eim-nl-send-all"
                                class="button button-primary"
                                data-newsletter-id="<?= esc_attr($nlId); ?>"
                                <?= $uniqueInvitees === 0 ? 'disabled' : ''; ?>>
                            <?= esc_html('Send to All Invitees (' . $uniqueInvitees . ')'); ?>
                        </button>
                        <span id="eim-nl-send-result" style="display:none;font-size:13px;"></span>
                    </div>
                <?php endif; ?>

                <h3 style="margin:0 0 6px;font-size:14px;">Send Test Email</h3>
                <p class="description" style="margin-bottom:8px;">
                    Send the newsletter to a single address for review before the real send. Template tags like
                    <code>{{ first_name }}</code> will be replaced with placeholder values.
                </p>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <input type="email"
                           id="eim-nl-test-email"
                           class="regular-text"
                           placeholder="test@example.com"
                           style="max-width:260px;">
                    <button type="button"
                            id="eim-nl-send-test"
                            class="button"
                            data-newsletter-id="<?= esc_attr($nlId); ?>">
                        Send Test
                    </button>
                    <span id="eim-nl-test-result" style="display:none;font-size:13px;"></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <script>
        (() => {
            'use strict';
            const btn        = document.getElementById('eim-nl-preview-btn');
            const previewCol = document.getElementById('eim-nl-preview-col');
            const frame      = document.getElementById('eim-nl-preview-frame');
            const close      = document.getElementById('eim-nl-preview-close');

            if (!btn || !previewCol || !frame) return;

            const debounce = (fn, ms) => {
                let t;
                return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
            };

            const isOpen = () => previewCol.style.display !== 'none';

            const getContent = () => {
                if (window.tinyMCE) {
                    const ed = tinyMCE.get('newsletter_content');
                    if (ed && !ed.isHidden()) return ed.getContent();
                }
                return document.querySelector('textarea[name="newsletter_content"]')?.value ?? '';
            };

            const refreshFrame = () => {
                frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                    + '<style>body{font-family:sans-serif;font-size:15px;line-height:1.7;'
                    + 'padding:24px 28px;color:#1d1d1d;}'
                    + 'img{max-width:100%;height:auto;}a{color:#0073aa;}'
                    + 'p{margin:0 0 1em;}h1,h2,h3{line-height:1.3;}</style>'
                    + '</head><body>' + getContent() + '</body></html>';
            };

            // Live refresh — fires 1 s after the last edit, only when preview is open.
            const liveRefresh = debounce(() => { if (isOpen()) refreshFrame(); }, 1000);

            // Hook TinyMCE: covers both already-initialised and not-yet-initialised editors.
            const hookEditor = (editor) => {
                if (editor.id !== 'newsletter_content') return;
                editor.on('KeyUp Change', liveRefresh);
            };

            if (window.tinyMCE) {
                const existing = tinyMCE.get('newsletter_content');
                if (existing) hookEditor(existing);
                tinyMCE.on('AddEditor', (e) => hookEditor(e.editor));
            }

            // Also cover the raw-HTML (Text) mode textarea.
            document.querySelector('textarea[name="newsletter_content"]')
                    ?.addEventListener('input', liveRefresh);

            btn.addEventListener('click', () => {
                refreshFrame();
                previewCol.style.display = 'block';
                window.dispatchEvent(new Event('resize'));
                if (window.innerWidth <= 1024) {
                    previewCol.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });

            close.addEventListener('click', () => {
                previewCol.style.display = 'none';
                window.dispatchEvent(new Event('resize'));
            });
        })();
        </script>
        <?php
    }

    // ─── Sanitizers ──────────────────────────────────────────────────────────

    /**
     * Sanitizes a newsletter list sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'title'.
     */
    private function sanitizeSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['title', 'status', 'publish_date', 'events', 'categories', 'tags'], true) ? $key : 'title';
    }

    /**
     * Sanitizes a newsletter search field key against the allowed column list.
     *
     * @param string $field Raw field key.
     * @return string Validated key, or '' for any-column search.
     */
    private function sanitizeFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['title', 'status', 'events', 'categories', 'tags'], true) ? $field : '';
    }
}
