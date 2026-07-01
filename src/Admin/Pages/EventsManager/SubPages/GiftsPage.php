<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Gift;

final class GiftsPage extends AbstractAdminPage
{
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_gift'         => $this->handleSaveGift(),
            'delete_gift'       => $this->handleDeleteGift(),
            'bulk_delete_gifts' => $this->handleBulkDeleteGifts(),
            default             => null,
        };
    }

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderGiftForm(null),
            'edit'  => $this->renderGiftForm(Gift::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderGiftList(),
        };
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public function handleAjaxSearchGifts(): void
    {
        check_ajax_referer('eim_search_gifts_list_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeGiftSortKey(sanitize_key($_GET['sort']  ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeGiftFieldKey(sanitize_key($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;
        $all     = Gift::listForAdmin($query, $sort, $order, $field);
        $total   = count($all);
        $gifts   = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderGiftRows($gifts, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    public function handleAjaxSuggestGifts(): void
    {
        check_ajax_referer('eim_suggest_gifts_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query          = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $excludeEventId = (int) ($_GET['exclude_event_id'] ?? 0);

        wp_send_json_success(Gift::search($query, 20, $excludeEventId));
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    private function handleSaveGift(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_gift')) {
            wp_die('Security check failed.');
        }

        $id   = (int) ($_POST['gift_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

        if (empty($name)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'gift_name_required',
            ]));
            exit;
        }

        $priceRaw    = trim((string) ($_POST['price_dollars'] ?? ''));
        $priceCents  = $priceRaw !== '' ? (int) round((float) $priceRaw * 100) : 0;
        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));
        $eventIds    = array_map('intval', (array) ($_POST['event_ids']    ?? []));
        $imageAttachmentId = $this->sanitizeGiftImageAttachmentId((int) ($_POST['image_attachment_id'] ?? 0));

        $data = [
            'name'                => $name,
            'description'         => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'price_cents'         => $priceCents,
            'website_url'         => esc_url_raw(wp_unslash($_POST['website_url'] ?? '')),
            'image_attachment_id' => $imageAttachmentId,
        ];

        if ($id > 0) {
            Gift::update($id, $data);
            Category::syncToEntity('gift', $id, $categoryIds);
            Gift::syncEvents($id, $eventIds);

            // Update purchase status for all currently-linked events based on submitted checkboxes.
            $purchaseData  = (array) ($_POST['purchase_status'] ?? []);
            $finalEventIds = Gift::eventIdsForGift($id);
            foreach ($finalEventIds as $eventId) {
                Gift::setPurchaseStatus($id, $eventId, !empty($purchaseData[(string) $eventId]));
            }

            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['eim_message' => 'gift_updated']));
        } else {
            $gift = Gift::create($data);
            if ($gift) {
                Category::syncToEntity('gift', $gift->id, $categoryIds);
                Gift::syncEvents($gift->id, $eventIds);
            }
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['eim_message' => 'gift_created']));
        }
        exit;
    }

    private function handleDeleteGift(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_gift_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::syncToEntity('gift', $id, []);
        Gift::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['eim_message' => 'gift_deleted']));
        exit;
    }

    private function handleBulkDeleteGifts(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_gifts')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('gift', $id, []);
            Gift::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    private function renderGiftList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s']     ?? ''));
        $sort    = $this->sanitizeGiftSortKey(sanitize_key($_GET['sort']  ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeGiftFieldKey(sanitize_key($_GET['field'] ?? ''));
        $all     = Gift::listForAdmin($search, $sort, $order, $field);
        $total   = count($all);
        $gifts   = array_slice($all, 0, 10);
        $addUrl  = AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['action' => 'add']);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Gifts &amp; Registry</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Gift</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Manage your gift registry. Gifts can be linked to specific events and tracked per-event to see which items have been purchased.
            </p>

            <?php $this->renderSearchBar(
                'eim-gift-search',
                'eim-gift-count',
                'eim-gift-loading',
                'Search gifts…',
                $total,
                $search,
                [
                    ['value' => 'name',        'label' => 'Name'],
                    ['value' => 'description', 'label' => 'Description'],
                    ['value' => 'website_url', 'label' => 'Website'],
                ],
                $field
            ); ?>

            <?php $this->renderBulkActions(
                'eim-gifts-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_GIFTS),
                'bulk_delete_gifts',
                'eim_bulk_delete_gifts'
            ); ?>

            <table id="eim-gifts-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:8px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('gifts'); ?>
                        <th class="eim-gift-image-column">Image</th>
                        <th style="width:22%;"><?= $this->sortLink('Name', 'name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_GIFTS]); ?></th>
                        <th style="width:9%;"><?= $this->sortLink('Price', 'price_cents', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_GIFTS]); ?></th>
                        <th style="width:14%;">Categories</th>
                        <th style="width:16%;">Events</th>
                        <th style="width:12%;">Purchased</th>
                        <th style="width:12%;"><?= $this->sortLink('Website', 'website_url', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_GIFTS]); ?></th>
                        <th style="width:9%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-gifts-table-body">
                    <?php $this->renderGiftRows($gifts, $search); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-gift-search'); ?>

            <?php if (empty($gifts) && $search === ''): ?>
                <p style="margin-top:12px;">No gifts yet. <a href="<?= esc_url($addUrl); ?>">Add the first gift.</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param Gift[] $gifts */
    private function renderGiftRows(array $gifts, string $search = '', int $offset = 0): void
    {
        if (empty($gifts)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No gifts found.';
            echo $this->renderNoResultsRow($msg);
            return;
        }

        $giftIds      = array_map(static fn(Gift $g): int => $g->id, $gifts);
        $catsByGift   = Category::forEntities('gift', $giftIds);
        $eventsByGift = Gift::eventDataForGifts($giftIds);
        $purchaseByGift = Gift::purchaseDetailsForGifts($giftIds);

        foreach ($gifts as $i => $gift) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['action' => 'edit', 'id' => $gift->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['action' => 'delete_gift', 'id' => $gift->id]),
                'eim_delete_gift_' . $gift->id
            );
            $cats   = $catsByGift[$gift->id]   ?? [];
            $events = $eventsByGift[$gift->id] ?? [];
            ?>
            <tr>
                <?= $this->renderLeadingCells('eim-gifts-bulk-form', 'gifts', $gift->id, $gift->name, $offset + $i + 1); ?>
                <td><?= $this->giftImageThumbnailMarkup($gift->imageAttachmentId, $gift->name); ?></td>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($gift->name); ?></a></strong>
                    <?php if ($gift->description): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html(wp_trim_words($gift->description, 10)); ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $gift->priceCents > 0 ? esc_html($gift->formattedPrice()) : '<span style="color:#999;">—</span>'; ?></td>
                <td>
                    <?php foreach ($cats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($cats)): ?><span style="color:#999;">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if (empty($events)): ?>
                        <span style="color:#999;font-size:12px;">None</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($events as $ev): ?>
                                <a class="eim-event-tag"
                                   href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $ev['id']])); ?>"
                                   style="font-size:11px;margin-right:3px;">
                                    <?= esc_html($ev['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $this->purchaseSummaryMarkup($events, $purchaseByGift[$gift->id] ?? []); ?>
                </td>
                <td>
                    <?php if ($gift->websiteUrl): ?>
                        <a href="<?= esc_url($gift->websiteUrl); ?>" target="_blank" rel="noopener" style="font-size:12px;">Visit</a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete gift &ldquo;<?= esc_js($gift->name); ?>&rdquo;? This will remove all event links and purchase records.');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * @param array<int,array{id:int,name:string}> $events
     * @param array<int,array<string,mixed>>       $purchaseByEvent
     */
    private function purchaseSummaryMarkup(array $events, array $purchaseByEvent): string
    {
        if (empty($events)) {
            return '<span style="color:#999;">—</span>';
        }

        $total = count($events);
        $purchased = 0;
        foreach ($events as $event) {
            if (!empty($purchaseByEvent[(int) $event['id']]['is_purchased'])) {
                $purchased++;
            }
        }

        if ($purchased === 0) {
            return '<span style="color:#d63638;">Not purchased</span>';
        }

        if ($purchased === $total) {
            return '<span style="color:#00a32a;">Purchased</span>';
        }

        return '<span style="color:#996800;">' . esc_html($purchased . ' / ' . $total . ' purchased') . '</span>';
    }

    private function renderGiftForm(?Gift $gift): void
    {
        $isNew   = $gift === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $backUrl = AdminMenu::tabUrl(AdminMenu::TAB_GIFTS);
        $title   = $isNew ? 'Add Gift' : 'Edit Gift: ' . $gift->name;

        $selCats = $isNew ? [] : array_map(static fn($c) => [
            'id'          => $c->id,
            'name'        => $c->name,
            'parent_name' => $c->parentName,
            'label'       => $c->parentName ? $c->parentName . ' › ' . $c->name : $c->name,
        ], Category::forEntity('gift', $gift->id));

        $catNonce = wp_create_nonce('eim_suggest_categories_nonce');

        $linkedEventIds = $isNew ? [] : Gift::eventIdsForGift($gift->id);
        $linkedEvents   = array_values(array_filter(
            array_map(static fn(int $id) => Event::find($id), $linkedEventIds)
        ));
        $dateFormat = (string) get_option('date_format', 'M j, Y');
        $formatDt   = static function (?string $utcDt, string $tz) use ($dateFormat): string {
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

        $purchaseStatus = $isNew ? [] : Gift::purchaseStatusForGift($gift->id);
        $priceDollars   = ($isNew || $gift->priceCents === 0) ? '' : number_format($gift->priceCents / 100, 2, '.', '');
        $imageAttachmentId = $isNew ? 0 : $gift->imageAttachmentId;
        $imageThumbUrl = $imageAttachmentId > 0 ? wp_get_attachment_image_url($imageAttachmentId, 'thumbnail') : '';
        $imageFullUrl  = $imageAttachmentId > 0 ? wp_get_attachment_image_url($imageAttachmentId, 'full') : '';
        $hasImage = is_string($imageThumbUrl) && $imageThumbUrl !== '' && is_string($imageFullUrl) && $imageFullUrl !== '';
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Gifts</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS)); ?>">
                <?php wp_nonce_field('eim_save_gift'); ?>
                <input type="hidden" name="eim_action" value="save_gift">
                <input type="hidden" name="gift_id"   value="<?= esc_attr($isNew ? 0 : $gift->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_g_name">Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_g_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $gift->name); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_g_description">Description</label></th>
                        <td><textarea id="eim_g_description" name="description" class="large-text" rows="3"><?= esc_textarea($isNew ? '' : $gift->description); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Categories</th>
                        <td>
                            <?php $this->renderCategoryPicker('eim-gift-cat-picker', $selCats, $catNonce); ?>
                            <p class="description" style="margin-top:6px;">Optional. Assign one or more categories to this gift.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_g_price">Price</label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:4px;">
                                <span>$</span>
                                <input type="number" id="eim_g_price" name="price_dollars"
                                       class="small-text" step="0.01" min="0"
                                       value="<?= esc_attr($priceDollars); ?>"
                                       placeholder="0.00">
                            </div>
                            <p class="description" style="margin-top:4px;">Optional. Leave blank or enter 0 for no price.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_g_website_url">Website URL</label></th>
                        <td><input type="url" id="eim_g_website_url" name="website_url" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $gift->websiteUrl); ?>"
                                   placeholder="https://example.com"></td>
                    </tr>
                    <tr>
                        <th scope="row">Image</th>
                        <td>
                            <input type="hidden"
                                   id="eim_g_image_attachment_id"
                                   name="image_attachment_id"
                                   value="<?= esc_attr($imageAttachmentId); ?>">
                            <div class="eim-gift-image-picker">
                                <div id="eim_g_image_preview" class="eim-gift-image-preview">
                                    <?php if ($hasImage): ?>
                                        <?= $this->giftImageThumbnailMarkup($imageAttachmentId, $isNew ? 'Gift image' : $gift->name); ?>
                                    <?php else: ?>
                                        <span class="description">No image selected.</span>
                                    <?php endif; ?>
                                </div>
                                <p class="eim-gift-image-actions">
                                    <button type="button"
                                            id="eim_g_image_select"
                                            class="button"
                                            data-select-label="Select Image"
                                            data-change-label="Change Image">
                                        <?= $hasImage ? 'Change Image' : 'Select Image'; ?>
                                    </button>
                                    <button type="button"
                                            id="eim_g_image_remove"
                                            class="button"
                                            <?= $hasImage ? '' : 'hidden'; ?>>
                                        Remove Image
                                    </button>
                                </p>
                            </div>
                            <p class="description" style="margin-top:6px;">Optional. Choose an image from the WordPress Media Library.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Events</th>
                        <td>
                            <?php $this->renderEventPicker('eim-gift-event-picker', $linkedEventData, 'event_ids[]'); ?>
                            <p class="description" style="margin-top:8px;">Link this gift to one or more events.</p>
                        </td>
                    </tr>
                    <?php if (!$isNew && !empty($linkedEvents)): ?>
                    <tr>
                        <th scope="row">Purchase Status</th>
                        <td>
                            <p class="description" style="margin-bottom:8px;">
                                Mark which events this gift has been purchased for.
                                Newly linked events will appear here after saving.
                            </p>
                            <?php foreach ($linkedEvents as $ev): ?>
                                <?php $isPurchased = $purchaseStatus[$ev->id] ?? false; ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox"
                                           name="purchase_status[<?= esc_attr($ev->id); ?>]"
                                           value="1"
                                           <?= $isPurchased ? 'checked' : ''; ?>>
                                    <?= esc_html($ev->name); ?>
                                    <?php if ($isPurchased): ?>
                                        <span style="color:#00a32a;font-size:12px;"> ✓ Purchased</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($isNew ? 'Add Gift' : 'Update Gift'); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // Sanitizers
    // =========================================================================

    private function sanitizeGiftSortKey(string $key): string
    {
        return in_array($key, ['name', 'price_cents', 'website_url'], true) ? $key : 'name';
    }

    private function sanitizeGiftFieldKey(string $field): string
    {
        return in_array($field, ['name', 'description', 'website_url'], true) ? $field : '';
    }

    private function sanitizeGiftImageAttachmentId(int $attachmentId): int
    {
        if ($attachmentId <= 0 || !wp_attachment_is_image($attachmentId)) {
            return 0;
        }

        return $attachmentId;
    }
}
