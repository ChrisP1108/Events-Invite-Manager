# Events Invite Manager — Claude Instructions

## Plugin Overview
A WordPress plugin for managing private event invitations, grouped RSVPs, venue/lodging assignments, food & beverage menus, and budgeting. PSR-4 autoloaded under `EventsInviteManager\` → `src/`.

## Admin UI Standard: Table Lists

**Every admin list table in this plugin MUST follow this pattern without being asked:**

### Search Bar
- Use `AbstractAdminPage::renderSearchBar()` above every list table
- Show whenever the initial unfiltered row count is **≥ 2** (the method hides itself at < 2 automatically)
- Provide a **"Search in column" dropdown** with one option per meaningful column (never include Actions)
- The search bar drives AJAX — do not use full-page form submissions for filtering

### Sortable Columns
- Every column header **except Actions** must be click-sortable
- Simple DB columns (strings, integers stored directly): use `AbstractAdminPage::sortLink()` with the `PAGE_EVENTS_MANAGER` slug and `['tab' => AdminMenu::TAB_*]` extra args
- Computed/relational columns (e.g. "Estimated", "Events", "Paid"): sort in PHP after the DB fetch using a private `sortRows()` / `maybePhpSort()` helper on the model
- Client-side-only tables (small, already fully loaded): use `data-val` attributes on `<td>` elements and a JS class that sorts DOM rows (see `CategoryTable` in `admin-budget.js`)

### Leading Columns (row number + bulk select)
Every standalone list table starts with two shared columns: a `#` row-number cell and the bulk-select checkbox. **Never hand-write these** — use the `AbstractAdminPage` helpers so a future cross-cutting change touches one file instead of every page:
- **Header:** `<?= $this->renderLeadingHeaderCells($group); ?>` as the first `<th>` group in the `<thead>` (replaces the old `#` `<th>` + bulk-select `<th>`)
- **Row:** `<?= $this->renderLeadingCells($formId, $group, $id, $label, $offset + $i + 1); ?>` as the first cells in each row (replaces the old `eim-row-num` `<td>` + `renderBulkSelectCell()` call). Requires the row loop to use `foreach ($rows as $i => $row)` and the `render*Rows()` method to accept `int $offset = 0`, passed as `($page - 1) * $perPage` from the AJAX handler so numbers stay correct across pages.
- **No results:** `echo $this->renderNoResultsRow($msg);` — uses `colspan="100"` (browser-clamped to the real column count) so colspan never needs bumping when columns change. Only exception: empty states containing their own HTML (e.g. an "Add the first one" link) or custom styling keep their inline `<tr>` block but should still use `colspan="100"`.
- These helpers apply only to **standalone** list tables, not sub-tables inside edit forms (event lodging/gifts/groups, connection-group members, budget line items/payments).

### AJAX Pattern
Every list table that is **server-rendered and filterable** needs:
1. A public `handleAjaxSearch*()` method on the page class (nonce-checked, returns `{html, count}` JSON)
2. A private `render*Rows()` method called by both the initial page render and the AJAX handler
3. The AJAX action registered in `AdminMenu::register()` via `add_action('wp_ajax_eim_search_*', ...)`
4. A JS file (or class within an existing JS file) that:
   - Debounces the search input (250 ms)
   - Listens for field-dropdown changes
   - Listens for sort-link clicks (prevents default, updates `data-sort`/`data-order`, fires AJAX)
   - Replaces `<tbody>` innerHTML and updates the count span and sort-link indicators
5. The JS localized via `wp_localize_script` in `AdminMenu::enqueueScripts()` with at minimum `searchNonce` and `table.enabled`

### Reference Implementations
Look at these files as the canonical examples before building a new list page:
- **PHP page:** `src/Admin/Pages/EventsManager/SubPages/LocationsPage.php`
- **PHP model:** `src/Models/Location.php` → `listForAdmin()`
- **JS:** `assets/js/admin-locations.js` → `LocationTable` class

## Tab & URL Conventions
- All pages live under `?page=eim-events-manager&tab=<slug>`
- Tab slugs are constants on `AdminMenu`: `TAB_EVENTS`, `TAB_INVITEES`, `TAB_CONNECTION_GROUPS`, `TAB_LOCATIONS`, `TAB_MENU_ITEMS`, `TAB_BUDGET`
- Always use `AdminMenu::tabUrl(AdminMenu::TAB_*, [...params])` to build admin URLs — never construct them manually
- Add new tabs by: adding a constant to `AdminMenu`, adding the sub-page class under `src/Admin/Pages/EventsManager/SubPages/`, registering it in `EventsManagerPage` and `AdminMenu`

## Database Conventions
- Schema version lives in `DatabaseManager::SCHEMA_VERSION` — bump it for any schema change
- All tables go in `DatabaseManager::createTables()` via `dbDelta()`
- Add a static accessor method for every new table (e.g. `budgetPlansTable()`)
- For new feature areas, also add a `maybeCreate*Tables()` method that creates just those tables idempotently (checked at the start of the feature's page class) — this guards against silent `dbDelta` failures on upgrade

## Notice Strings
All admin success/error notice strings live in `AbstractAdminPage::renderNotice()`. Add new keys there whenever a new action is added — never hardcode notice text in page classes.
