# Events Invite Manager

A WordPress plugin for managing private event invitations, grouped RSVPs, attendee registration, venue and lodging assignments, a global food & beverage menu library with vendor linkage, a gifts & registry system, an invitee messaging system, a guest request workflow, budget tracking, newsletter posts, a unified category taxonomy, and automated email workflows — built for events where a curated guest list and QR code RSVP flow are required.

---

## Features

### Vendor Library
A centralised global library of vendors — caterers, photographers, venues, florists, and any other service provider. Vendors are created once and referenced from both food & beverage menu items and budget line items. Each record stores company name, street address, email address, phone number, and free-text notes. The Vendors admin table supports AJAX live search with a **column-filter dropdown** (Company, Email, Phone, Notes) and sortable columns. Vendors can be tagged with **categories**.

### Categories & Taxonomy
A unified category taxonomy that spans every entity in the plugin. Categories support one level of parent → child hierarchy and can be assigned to events, invitees, connection groups, locations, food & beverage items, budget plans, vendors, and newsletters — all from the add or edit form for that entity. Every list table in the plugin shows a **Categories column** where assigned categories appear as teal chip links; clicking a chip opens the category editor directly. In the category picker widget on edit forms, each chip label is also a clickable link to the category editor, while the × button still removes the assignment.

### Location Library
A centralised library of reusable locations (venues, hotels, Airbnbs, etc.) maintained independently of any specific event. Locations are created once and selected by name across as many events as needed via a live autocomplete search field. The Locations admin table supports AJAX live search with a **column-filter dropdown** (Name, Type, Lodging, Address, Used In) and sortable columns. Free-text entries are not allowed — every venue or lodging assignment must reference a validated library entry.

Each location can be marked as offering **lodging** (`has_lodging`), which makes it available in the lodging autocomplete on event forms. An optional **booking URL** can be attached so invitees can access the reservation page directly from the RSVP experience.

Each location also supports an optional **thumbnail image** selected from the WordPress Media Library. The thumbnail is shown in the Locations list table's Image column, and wherever the location appears on an event edit screen — in the Venue/Location tab and in the Lodging tab's location list.

### Food & Beverages Library
A global library of food and beverage menu items, managed from the dedicated **Food & Beverages** admin page. The page displays two independent scrollable tables — one for food, one for beverages — each with its own AJAX live search and column-filter dropdown. Items are created once and assigned to individual events; the same item can be reused across multiple events.

Each item has a **label** (required), optional **description**, an optional **vendor** linked from the vendor library via an autocomplete picker, an optional per-person **price** (used in budget calculations), and a **categories** assignment. Categories can be assigned both when creating an item inline and on the standalone edit screen.

### Events
Create and manage events with full details: name, description, date, start and end time, time zone, RSVP start/deadline windows, linked WordPress RSVP and before-start pages, and an optional **maximum invitee cap**. A calendar view in the admin shows all dated events at a glance with month navigation and a jump-to-event dropdown.

Below the calendar, the events list supports **AJAX live search** with a **column-filter dropdown** (Name, Description), sortable columns (Name, Date/Time), and pagination — filtering and sorting happen without a page reload.

The event **edit screen uses a tabbed interface** with eight tabs — Details, Venue/Location, Invite Email, QR Code & RSVP, Lodging, Food & Beverage, Gifts & Registry, and Invited Invitees — so each concern has its own dedicated panel. The active tab is persisted via `localStorage` and restored via URL hash after redirect actions.

### Venue Assignment
Each event can have a single venue selected from the location library. Typing in the venue field triggers a live search and shows matching locations with their addresses. Selecting a location auto-fills the hidden address fields and displays the formatted address below the input as read-only confirmation text.

### Lodging Locations
Events can offer multiple lodging options to invitees, each selected from library entries with `has_lodging` enabled. Lodging assignments are stored in a dedicated pivot table (`eim_event_lodging`) and exposed via the REST API. Invitees can select a lodging option, choose "Other," or mark their preference as undisclosed. Lodging is stored as a shared group-wide choice rather than per-member.

### Food & Beverage Options (per event)
When **food options** and/or **beverage options** are enabled on an event, the event edit screen presents an autocomplete search that pulls from the global menu item library. Selected items are stored in the `eim_event_menu_items` pivot table. These options are returned by the RSVP REST API and can be selected per invitee during registration (stored as `food_option_id` and `beverage_option_id` on each group member). When both food and beverage options are enabled, their assignment tables are displayed **side-by-side** in a two-column grid.

### Gifts & Registry
A full gifts and registry system, managed from the **Gifts & Registry** admin tab. Each gift has a name, description, price, optional website URL, and an optional image. Gifts are global library items — created once and linked to one or more events. The event edit screen's **Gifts & Registry** tab lets admins assign gifts from the global library to that event. From the RSVP dashboard, invitees can view the registry and mark gifts as purchased. Purchase records track which invitation group claimed the gift, and only the claiming group can unmark it.

The Gifts admin table supports AJAX live search, sortable columns, and bulk operations.

### Invitee Messaging
A conversation thread system between invitees and the admin, scoped per event and per connection group. Invitees can send messages through the frontend REST API; admins read and reply from the **Messages** admin tab. Each thread is grouped by connection group and event. Admin replies are flagged as `is_admin_reply` and are always marked read. Unread message counts are surfaced in the admin list so nothing is missed.

### Requested Invitee Add-Ons
Invitees can request additional guests be added to their invitation group via the `POST /request-guest` REST endpoint. Each request stores the proposed guest's contact details and is queued for admin review in the **Requested Invitees** admin tab. Admins can **approve** (creating the invitee, adding them to the connection group and invitation group, and auto-RSVPing them as attending) or **deny** the request. The full approve workflow runs inside a database transaction.

### Invitees
Add and manage invitees globally, each with a first name, last name, email address, phone number, and optional postal address. The Invitees admin table supports AJAX live search with a **column-filter dropdown** (First Name, Last Name, Email, Phone, Invited Events, Connection Groups), sortable columns, event tags, connection group tags, and a **Categories column** showing assigned category chips.

### Connection Groups
Create reusable groups of related invitees — couples, families, households, or custom groupings. These groups are independent of any one event and appear as checkbox suggestions when adding invitees to an event. The Connection Groups page supports the shared live search bar with a **column-filter dropdown** (Name, Type, Members, Invited To). The **Invited To** column shows which events each group has been invited to as clickable event tags. A **Categories column** shows assigned category chips.

### Email Invites
From an event edit screen, add existing invitees to that event. When a selected invitee belongs to connection groups, connected people appear as checkboxes so the admin can include them in one invitation group. One invite email is sent per invitation group to the primary invitee, who can RSVP for everyone in the group. Email subject lines and body content are fully customisable using template tags, including `{{ qr_code }}`, `{{ invite_url }}`, and group-aware tags.

### QR Code RSVP
When an invite is sent, a unique 16-character confirmation code is generated for the invitation group and matching SVG/PNG QR codes are produced in the WordPress uploads directory (`wp-uploads/eim-qr-codes/event_{event_id}_group_{group_id}/`). The QR code encodes a URL of the form `{site}/?eim_confirmation={code}`. When scanned, the plugin intercepts the request via `template_redirect` and forwards the visitor to the configured RSVP page, or to the configured before-start page when RSVP has not opened yet. The RSVP API drives a multi-step flow: `rsvp_form` → `menu_required` → `lodging_required` → `dashboard_redirect`, with each step gated by the event's configuration. The `next_action` field in every response tells the frontend exactly what to present next. RSVP submissions before the configured start or after the configured deadline are rejected. QR codes are automatically removed from disk and the database when their invitation group is deleted.

### Invited Invitees — search & sort
The **Invited Invitees** list on every event edit screen supports AJAX live search with a **column-filter dropdown** (Group Members, Email, Invite Sent, Registered) and sortable column headers (Group Members, Email, Invite Sent, Registered). Sorting and filtering occur without a page reload; both operate simultaneously.

A **Confirmation Code** column is displayed between the Registered and RSVP Notes columns, showing each invitation group's unique 16-character QR confirmation code at a glance — useful for cross-referencing exports or diagnosing RSVP issues without opening the export.

### Newsletters
A **Newsletters** tab provides a full editorial workspace for newsletter posts intended for email blasts and website display. Each newsletter has a **title**, rich HTML **content** (authored in the WordPress TinyMCE editor), **status** (Draft or Published), and a **publish date**. Newsletters support:

- **Many-to-many event association** — link a newsletter to one or more events; events appear as clickable tags in the list table.
- **Managed categories and tags** — a taxonomy-style system where categories and tags are maintained from a collapsible inline panel on the list page. Categories use a checkbox picker on the edit form; tags use the same.
- **AJAX live search** with a column-filter dropdown (Title, Events, Categories, Tags, Status) and sortable columns for all fields.
- **Content preview** — a "Preview Content" button below the editor renders the newsletter HTML in an isolated `<iframe>` so admin CSS cannot interfere. On desktop the preview opens **side-by-side** with the editor; on tablet and mobile it stacks below. Closing the preview returns the editor to full width. The preview **live-refreshes 1 second after typing stops** (debounced, covers both TinyMCE visual mode and raw HTML text mode).

### Budget
A **Budget** tab provides financial planning and tracking across one or more events. Budget plans contain:

- **Plan details** — name, description, target total amount, and currency.
- **Event associations** — a plan can span multiple events (many-to-many).
- **Line items** — each item has a label, vendor name (from the vendor library), quantity (fixed or per-attending-guest), unit cost, optional total override, amount paid, and free-text notes. Line items can be linked to existing menu items or budget entries via `source_type` / `source_id`. Drag-to-reorder is supported on the line-items table.
- **Summaries** — estimated total, total paid, and remaining balance are computed and displayed in a totals row.
- The budget plan list supports AJAX live search, sortable columns, and a **Categories column** showing assigned category chips.

### Data Exports
Both events and budget plans can be exported directly from the admin in two formats. Export buttons appear above the tab navigation on each event and budget plan edit screen — no separate page needed.

**Event export** includes:
- **Event details** — name, description, start/end date & time, timezone, RSVP start/deadline, max invitees, venue name and address
- **Invited invitees** — one row per group member with group ID, QR confirmation code, QR image URL, is-primary flag, full contact details (name, email, phone, address), RSVP status, registration timestamp, food and beverage selections, dietary notes, and lodging selection
- **Messages** — every invitee message and admin reply for the event, with group ID, confirmation code, direction (invitee/admin), message text, read status, and timestamp
- **Registry: claimed items** — gift name, description, price, the purchasing group's ID and confirmation code, and purchase timestamp
- **Registry: available items** — all unclaimed gifts with name, description, price, and website URL

**Budget plan export** includes:
- **Plan details** — name, description, currency, target amount, estimated total, amount paid, and remaining balance, plus linked event names
- **Vendors** — a deduplicated list of every vendor referenced by the plan's line items, each with their database ID, company name, contact name, email, phone, and website URL
- **Line items** — label, `vendor_id` (referencing the vendors section), vendor name, quantity, quantity mode, unit cost, total override, estimated cost, paid amount, remaining balance, payment deadline, and notes

**CSV format** — multi-section document with a clearly labelled `SECTION` header row before each data block, suitable for opening in Excel or Google Sheets.

**JSON format** — fully structured document with nested objects and arrays; line items include a `vendor_id` key that references the top-level `vendors` array by the vendor's database ID.

### Contextual search bars
All list tables use a shared search control that includes a text input and a column-filter dropdown. The search bar is only displayed when there is at least one item in the list. When a search returns no results it shows "No results found based upon search criteria." rather than the empty-state message.

### `eim_change` WordPress Action Hook
Every create, edit, and delete operation across all plugin entities fires the `eim_change` WordPress action, allowing external code snippets to react to any data change without modifying the plugin's files.

```php
add_action('eim_change', function (EimChangeEvent $e): void {
    // $e->type        — entity type, e.g. EimChangeEvent::TYPE_EVENT
    // $e->change_type — one of 'added', 'edited', or 'deleted'
    // $e->data        — the model object after the write (or a snapshot before deletion)
});
```

**Type constants** (`EimChangeEvent::TYPE_*`): `event`, `invitee`, `requested_add_on`, `message`, `connection_group`, `location`, `menu_item`, `budget_plan`, `budget_line_item`, `vendor`, `newsletter`, `category`, `gift`.

**Change type constants** (`EimChangeEvent::ADDED`, `EimChangeEvent::EDITED`, `EimChangeEvent::DELETED`).

The hook lives in `EventsInviteManager\Hooks\EimChangeEvent` and fires only on successful writes — failed DB operations never dispatch it. For delete operations, `$e->data` contains a snapshot of the record captured immediately before deletion.

### REST API
JSON endpoints powering the front-end RSVP experience, invitee dashboard, registry, messaging, and guest-request features:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/wp-json/eim/v1/rsvp`              | Loads the current RSVP flow state — event details, food/beverage options, lodging, all group members, and the `next_action` step |
| `POST` | `/wp-json/eim/v1/register`          | Submits RSVP status, food/beverage selections, dietary notes, and lodging choice for the invitation group |
| `GET`  | `/wp-json/eim/v1/dashboard`         | Returns all upcoming registered events for the invitation group, with RSVP details, newsletters, and registry per event |
| `GET`  | `/wp-json/eim/v1/newsletters`       | Returns published newsletters for all events the group is registered for; supports single-newsletter detail view |
| `GET`  | `/wp-json/eim/v1/registry`          | Returns registry gifts for all complete, upcoming events accessible from the QR code |
| `POST` | `/wp-json/eim/v1/registry/purchase` | Marks or unmarks a registry gift as purchased by the invitation group |
| `POST` | `/wp-json/eim/v1/request-guest`     | Submits a pending request to add an additional guest to the invitation group |
| `GET`  | `/wp-json/eim/v1/messages`          | Returns all messages in the event/group conversation thread |
| `POST` | `/wp-json/eim/v1/messages`          | Sends a new message from the invitee to the admin for a specific event |

### Admin Calendar
The events list includes a monthly calendar grid. Events with a date appear as linked blocks on their respective days. Month navigation arrows and a jump-to-event dropdown are provided for quick navigation.

---

## Requirements

| Requirement | Minimum version |
|-------------|-----------------|
| PHP         | 8.1             |
| WordPress   | 5.9             |

---

## Installation

1. Upload the `events-invite-manager` directory to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins** in the WordPress admin.
3. On activation, the plugin automatically creates all required database tables using `dbDelta` (see **Database Tables** below). Tables are created idempotently on every plugin load — no manual migration steps are needed.
4. Deactivating the plugin **preserves all data**. Tables are only removed by uninstalling or deleting the plugin manually.

---

## Getting Started

### 1 — Build your location library

Navigate to **Events Invite Manager → Locations** and add every venue, hotel, and lodging option you plan to use. At minimum, add the event venue.

Each location has:
- **Name** — displayed in autocomplete results and on the event
- **Type** — check "Other" for flexible options with no fixed address (e.g. Airbnb, personal arrangement)
- **Lodging** — check "This location offers lodging" to make it available in the event lodging autocomplete. Optionally add a **Booking Website** URL.
- **Image** — optional thumbnail from the WordPress Media Library; shown in the Locations list and on the event edit screen wherever the location appears (venue and lodging panels)
- **Address** — street, city, state, ZIP (hidden when "Other" is checked)

### 2 — Build your vendor library (optional)

Navigate to **Events Invite Manager → Vendors** and add the service providers involved in your event (caterers, photographers, florists, etc.). Vendors are linked to food & beverage items and budget line items.

Each vendor has a **company name** (required), street address, email, phone, and optional notes.

### 3 — Set up categories (optional)

Navigate to **Events Invite Manager → Categories** to create your category taxonomy. Categories support one level of parent → child hierarchy and can be applied to any entity in the plugin — events, invitees, connection groups, locations, menu items, budget plans, vendors, and newsletters.

### 4 — Build your menu item library (optional)

Navigate to **Events Invite Manager → Food & Beverages** and add the food and beverage items you want to offer at your events. Items are global — create them once and assign them to as many events as needed.

Each item has a **label** (required), optional **description**, an optional **vendor** (from the vendor library), an optional per-person **price** (used in budget calculations), and optional **categories**.

### 5 — Build your gifts & registry (optional)

Navigate to **Events Invite Manager → Gifts & Registry** and add the gifts you'd like invitees to be able to purchase. Each gift has a name, description, price, optional website URL, and an optional image. After creating gifts globally, link them to specific events on the event's **Gifts & Registry** tab.

### 6 — Create an event

Navigate to **Events Invite Manager → Events → Add New Event** and fill in the fields across the tabbed interface:

- **Details tab** — Event Name *(required)*, Description, Start/End Date & Time, Time Zone, Maximum Invitees, RSVP Deadline
- **Venue/Location tab** — search the location library to assign a venue
- **Invite Email tab** — From name, From email, subject line, and body template (use `{{ qr_code }}` and `{{ invite_url }}` to embed the QR image or RSVP link)
- **QR Code & RSVP tab** — select the WordPress RSVP page and Dashboard page for recipients
- **Lodging tab** — enable lodging and optionally pre-assign locations from the library
- **Food & Beverage tab** — enable food/beverage option flags; after saving, assign items from the global library
- **Gifts & Registry tab** — after saving, link gifts from the global library to this event

### 7 — Add invitees

Navigate to **Events Invite Manager → Invitees** and add each guest with their name, email address, phone number, and optional postal address.

### 8 — Create connection groups

Navigate to **Events Invite Manager → Connection Groups** and create reusable groups for people who commonly RSVP together (couples, families, households). Connection groups provide checkbox suggestions when adding invitees to an event.

### 9 — Send invites

Open the event edit screen, navigate to the **Invited Invitees** tab, add existing invitees, and optionally check connected people to include them in the same invitation group. Use **Send Invite** on an individual group row or **Send All Unsent Invites** to dispatch one email per unsent group.

### 10 — Build your RSVP page

Create a WordPress page and set it as the event's **QR Code RSVP Page**. Optionally set an **RSVP Start** time and **Before RSVP Start Page** to send early visitors to a holding page. When an invitee scans their QR code the plugin redirects them to the RSVP page with `?eim_confirmation={code}` appended once RSVPs are open. The RSVP flow is driven by the `next_action` field returned by the API:

1. Call `GET /wp-json/eim/v1/rsvp?confirmation_code={code}` to get the current state.
2. Present the appropriate step based on `next_action` (`rsvp_form`, `menu_required`, `lodging_required`, `dashboard_redirect`).
3. Submit via `POST /wp-json/eim/v1/register` with per-member RSVP statuses, food/beverage selections, dietary notes, and lodging choice.
4. Repeat until `next_action` is `dashboard_redirect`, then redirect to the dashboard page.

### 11 — Build your dashboard page (optional)

Create a WordPress page and set it as the event's **Dashboard Page**. After completing the RSVP flow, invitees land here. Call `GET /wp-json/eim/v1/dashboard?confirmation_code={code}` to load all upcoming events for the group, along with RSVP summaries, published newsletters, and registry data.

### 12 — Write newsletters (optional)

Navigate to **Events Invite Manager → Newsletters** to create newsletter posts for email blasts or website display. Associate each newsletter with one or more events, assign categories and tags, and use the TinyMCE editor to write HTML content. Click **Preview Content** to see a live side-by-side preview of the rendered HTML that updates automatically as you type.

### 13 — Track your budget (optional)

Navigate to **Events Invite Manager → Budget** to create a budget plan. Add line items with vendor, quantity, unit cost, and optional total overrides. Plans can span multiple events and the totals row shows estimated, paid, and remaining amounts at a glance.

---

## Email Template Tags

Template tags are replaced with live values at send time. Tags are case-insensitive and tolerate spaces around the variable name (e.g. `{{ event_name }}` and `{{event_name}}` are equivalent).

### Invite email body

| Tag | Replaced with |
|-----|---------------|
| `{{ event_name }}` | The event's name |
| `{{ first_name }}` | Invitee's first name |
| `{{ last_name }}`  | Invitee's last name |
| `{{ full_name }}`  | First and last name combined |
| `{{ email }}`      | Invitee's email address |
| `{{ qr_code }}`       | An `<img>` tag containing the invitation group's unique PNG QR code, displayed at 480 × 480 px |
| `{{ invite_url }}`    | The same personalized RSVP URL encoded in the invitation group's QR code |
| `{{ group_names }}`   | Comma-separated names of every invitee in the invitation group |
| `{{ invitee_names }}` | Alias of `{{ group_names }}` |
| `{{ invitee_count }}` | Number of people in the invitation group |

### From Email field

| Tag | Replaced with |
|-----|---------------|
| `{{ current_domain }}` | The site's domain at send time (e.g. `example.com`) — useful for `noreply@{{current_domain}}` |

---

## REST API Reference

All endpoints are under the `eim/v1` namespace. All are publicly accessible — they are gated by the 16-character confirmation code embedded in the QR code URL rather than WordPress authentication.

### `GET /wp-json/eim/v1/rsvp`

Returns the current RSVP flow state for a confirmation code: event details, food/beverage options, lodging options, all group members, and a `next_action` field indicating what the frontend should present next. The event payload includes `rsvp_start_datetime`, `rsvp_start_pending`, `rsvp_deadline`, `rsvp_deadline_passed`, and `can_rsvp`; a configured early holding page is exposed as `rsvp_before_start_url`.

**`next_action` values**

| Value | Meaning |
|-------|---------|
| `rsvp_form` | The invitee hasn't RSVP'd yet — show the RSVP form |
| `menu_required` | RSVP complete; attending members still need food/beverage selections |
| `lodging_required` | RSVP and menu complete; group still needs to choose lodging |
| `dashboard_redirect` | All steps complete — redirect to the dashboard |
| `declined` | All members declined |

### `POST /wp-json/eim/v1/register`

Validates the confirmation code and updates RSVP status for the invitation group. With a `members` array, each listed member can be updated individually. Members omitted from the payload when pending are auto-declined. Accepts group-level and member-level lodging selections, shared RSVP notes, and lodging booking notes.

**Key fields**

| Field | Type | Description |
|-------|------|-------------|
| `confirmation_code` | string | 16-character code |
| `members` | array | Per-member objects: `invitee_id`, `rsvp_status`, `food_option_id`, `beverage_option_id`, `dietary_notes`, `lodging_id`, `lodging_is_other`, `lodging_undisclosed` |
| `rsvp_notes` | string | Shared group notes |
| `lodging_id` | integer | Group-level lodging assignment |
| `lodging_is_other` | boolean | Group chose "Other" for lodging |
| `lodging_undisclosed` | boolean | Group prefers not to disclose |
| `lodging_booked` | boolean | Group has booked their lodging |
| `lodging_notes` | string | Free-text lodging notes |

### `GET /wp-json/eim/v1/dashboard`

Returns all upcoming events the invitation group is registered for (at least one member attending), along with RSVP details, published newsletters, and registry data per event. Requires the RSVP flow to be fully complete.

### `GET /wp-json/eim/v1/newsletters`

Returns published newsletters for all complete, upcoming events accessible from the confirmation code. Pass `newsletter_id` to fetch a single newsletter's full content. Requires the RSVP flow to be fully complete.

### `GET /wp-json/eim/v1/registry`

Returns registry gifts for all complete, upcoming events accessible from the confirmation code. Pass `event_id` to filter to one event. Includes purchase status and whether the current group has claimed each gift.

### `POST /wp-json/eim/v1/registry/purchase`

Marks or unmarks a gift as purchased for a specific event. A group can only unmark a gift it previously marked. Returns the updated gift object.

**Fields:** `confirmation_code`, `event_id`, `gift_id`, `is_purchased` (boolean, defaults to `true`).

### `POST /wp-json/eim/v1/request-guest`

Submits a request for an additional guest to be added to the invitation group. The request is stored for admin review and approval.

**Fields:** `confirmation_code`, `first_name`, `last_name`, `email` (required), `phone`, `street_address`, `city`, `state`, `zip_code`, `notes`.

### `GET /wp-json/eim/v1/messages`

Returns all messages in the event/group conversation thread. The `event_id` must match the QR code's event.

**Fields:** `confirmation_code`, `event_id`.

### `POST /wp-json/eim/v1/messages`

Sends a new invitee message for a specific event/group thread.

**Fields:** `confirmation_code`, `event_id`, `message`.

---

## Database Tables

| Table | Description |
|-------|-------------|
| `{prefix}eim_events` | Event records including venue FK, RSVP page ID, before-start page ID, dashboard page ID, date/time, food/beverage flags, RSVP start/deadline, and invite email template |
| `{prefix}eim_invitees` | Global invitee profile records |
| `{prefix}eim_event_invitees` | Event membership assignments for individual invitees |
| `{prefix}eim_locations` | Global location catalogue — venues and lodging options shared across all events; includes an optional `image_attachment_id` for a WordPress Media Library thumbnail |
| `{prefix}eim_event_lodging` | Pivot table linking events to their lodging location options |
| `{prefix}eim_qr_codes` | QR code records: confirmation code, event/group FKs, and uploads-relative SVG path with companion PNG beside it |
| `{prefix}eim_invitee_connection_groups` | Reusable global relationship groups (couples, families, households, custom) |
| `{prefix}eim_invitee_connection_group_members` | Pivot table linking global invitees to reusable connection groups |
| `{prefix}eim_event_invitation_groups` | Event-specific invitation groups; each group has one primary invitee and one email/QR code |
| `{prefix}eim_event_invitation_group_members` | Per-person RSVP status, registration timestamp, food/beverage/lodging selections, and dietary notes |
| `{prefix}eim_menu_items` | Global food and beverage menu item library — label, description, price, vendor FK |
| `{prefix}eim_event_menu_items` | Pivot table linking events to their assigned menu items from the global library |
| `{prefix}eim_vendors` | Global vendor library — company name, contact details, and free-text notes |
| `{prefix}eim_budget_plans` | Budget plan records with name, description, target amount, and currency |
| `{prefix}eim_budget_plan_events` | Pivot table linking budget plans to one or more events |
| `{prefix}eim_budget_items` | Global budget item library — label, vendor FK, unit cost, and notes |
| `{prefix}eim_budget_line_items` | Budget line items linking a global item to a plan, with quantity, paid amount, and notes |
| `{prefix}eim_gifts` | Global gift library — name, description, price, website URL, and image attachment |
| `{prefix}eim_gift_events` | Pivot table linking gifts to events |
| `{prefix}eim_gift_purchases` | Purchase status per gift+event: is_purchased, purchased_at, purchased_by_group_id |
| `{prefix}eim_event_messages` | Invitee/admin message threads, scoped per event and connection group |
| `{prefix}eim_requested_invitee_add_ons` | Pending guest requests submitted by invitees via the REST API, awaiting admin approval |
| `{prefix}eim_newsletters` | Newsletter post records with title, HTML content, status, and publish date |
| `{prefix}eim_newsletter_events` | Pivot table linking newsletters to one or more events |
| `{prefix}eim_newsletter_tags` | Managed newsletter tag list (name, slug) |
| `{prefix}eim_newsletter_tag_map` | Pivot table linking newsletters to their tags |
| `{prefix}eim_categories` | Unified category taxonomy — name, slug, optional parent ID for one-level hierarchy |
| `{prefix}eim_category_map` | Pivot table linking categories to any entity type (event, invitee, vendor, menu_item, etc.) |

---

## Developer Notes

- **PHP 8.1+ features in use:** readonly constructor properties, named arguments, `match` expressions, `str_contains`, `str_starts_with`.
- **JavaScript:** ES2022 classes with private fields (`#field`), async/await, dynamic `import()`. Separate IIFE scripts handle each admin page: `admin-events.js` (events list search/sort/pagination), `admin-invitees.js` (invitee table, event invitee picker, connection group list/member picker, event groups search/sort, menu item pickers), `admin-locations.js` (locations table search/sort), `admin-menu-items.js` (food and beverage tables search/sort/pagination), `admin-budget.js` (budget plans and line items), `admin-newsletters.js` (newsletter list search/sort), `admin-categories.js` (category picker widget and category list). The location autocomplete system is split into ES modules under `assets/js/modules/`. Tab switching on the event edit screen and the newsletter content preview are implemented as inline IIFE scripts. No build tool or transpilation step is required.
- **No build tool required:** JS is authored in native ES2022 and loaded directly — no bundler or transpilation step.
- **Autoloading:** PSR-4 via Composer. All classes live under the `EventsInviteManager\` namespace in `src/`.
- **QR code storage:** SVG and PNG files are written to `{wp-uploads}/eim-qr-codes/event_{event_id}_group_{group_id}/` via `wp_upload_dir()` — not inside the plugin directory — so they survive plugin updates. Files are deleted from disk whenever the associated DB record is removed.
- **Referential integrity:** No database-level foreign keys are used. Deletion cascades are handled in PHP: deleting an event removes its invitee assignments, lodging assignments, menu item assignments, invitation groups, invitation group members, messages, requested add-ons, gift links, and QR codes; deleting a location nulls `events.venue_id` and removes its lodging pivot rows; deleting a menu item removes its event pivot rows; deleting a vendor nulls `menu_items.vendor_id` and `budget_line_items.vendor_id`; deleting an invitee removes them from connection groups and invitation groups; deleting a gift removes its event links and purchase records; deleting any entity removes its `eim_category_map` rows.
- **Category system:** `Category::forEntities(string $entityType, array $entityIds)` bulk-loads categories for an entire list in one JOIN query (keyed by entity ID), avoiding N+1 lookups in list-table renders. `Category::forEntity()` loads for a single entity. `Category::syncToEntity()` atomically replaces all category assignments for an entity.
- **`eim_change` hook:** `EimChangeEvent::dispatch(string $type, string $changeType, mixed $data)` fires `do_action('eim_change', $event)` after every successful create, update, or delete. The `EimChangeEvent` value object (in `EventsInviteManager\Hooks\EimChangeEvent`) carries `TYPE_*` and `ADDED`/`EDITED`/`DELETED` constants. For deletes, the data snapshot is captured before the record is removed.
- **REST API architecture:** `RestController` is a slim router. Each feature area has its own controller class (`RsvpController`, `DashboardController`, `NewsletterController`, `RegistryController`, `GuestRequestController`, `MessagesController`) extending `AbstractApiController`, which holds all shared payload-building helpers.

---

## Author

**Chris Paschall** — built for the Chris & Jamie Wedding  
License: GPL-2.0-or-later
