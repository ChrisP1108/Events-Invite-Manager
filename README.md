# Events Invite Manager

A WordPress plugin for managing private event invitations, grouped RSVPs, attendee registration, venue and lodging assignments, a global food & beverage menu library with vendor linkage, budget tracking, newsletter posts, a unified category taxonomy, and automated email workflows — built for events where a curated guest list and QR code RSVP flow are required.

---

## Features

### Vendor Library
A centralised global library of vendors — caterers, photographers, venues, florists, and any other service provider. Vendors are created once and referenced from both food & beverage menu items and budget line items. Each record stores company name, street address, email address, phone number, and free-text notes. The Vendors admin table supports AJAX live search with a **column-filter dropdown** (Company, Email, Phone, Notes) and sortable columns. Vendors can be tagged with **categories**.

### Categories & Taxonomy
A unified category taxonomy that spans every entity in the plugin. Categories support one level of parent → child hierarchy and can be assigned to events, invitees, connection groups, locations, food & beverage items, budget plans, vendors, and newsletters — all from the add or edit form for that entity. Every list table in the plugin shows a **Categories column** where assigned categories appear as teal chip links; clicking a chip opens the category editor directly. In the category picker widget on edit forms, each chip label is also a clickable link to the category editor, while the × button still removes the assignment.

### Location Library
A centralised library of reusable locations (venues, hotels, Airbnbs, etc.) maintained independently of any specific event. Locations are created once and selected by name across as many events as needed via a live autocomplete search field. The Locations admin table supports AJAX live search with a **column-filter dropdown** (Name, Type, Lodging, Address, Used In) and sortable columns. Free-text entries are not allowed — every venue or lodging assignment must reference a validated library entry.

Each location can be marked as offering **lodging** (`has_lodging`), which makes it available in the lodging autocomplete on event forms. An optional **booking URL** can be attached so invitees can access the reservation page directly from the RSVP experience.

### Food & Beverages Library
A global library of food and beverage menu items, managed from the dedicated **Food & Beverages** admin page. The page displays two independent scrollable tables — one for food, one for beverages — each with its own AJAX live search and column-filter dropdown. Items are created once and assigned to individual events; the same item can be reused across multiple events.

Each item has a **label** (required), optional **description**, an optional **vendor** linked from the vendor library via an autocomplete picker, an optional per-person **price** (used in budget calculations), and a **categories** assignment. Categories can be assigned both when creating an item inline and on the standalone edit screen.

### Events
Create and manage events with full details: name, description, date, start and end time, time zone, a linked WordPress RSVP page, and an optional **maximum invitee cap**. A calendar view in the admin shows all dated events at a glance with month navigation and a jump-to-event dropdown.

Below the calendar, the events list supports **AJAX live search** with a **column-filter dropdown** (Name, Description), sortable columns (Name, Date/Time), and pagination — filtering and sorting happen without a page reload.

The event **edit screen uses a tabbed interface** with seven tabs — Details, Venue/Location, Invite Email, QR Code & RSVP, Lodging, Food & Beverage, and Invited Invitees — so each concern has its own dedicated panel. The active tab is persisted via `localStorage` and restored via URL hash after redirect actions (e.g. adding lodging returns to the Lodging tab, invitee actions return to the Invited Invitees tab).

### Venue Assignment
Each event can have a single venue selected from the location library. Typing in the venue field triggers a live search and shows matching locations with their addresses. Selecting a location auto-fills the hidden address fields and displays the formatted address below the input as read-only confirmation text.

### Lodging Locations
Events can offer multiple lodging options to invitees, each selected from library entries with `has_lodging` enabled. Lodging assignments are stored in a dedicated pivot table (`eim_event_lodging`) and exposed via the REST API.

### Food & Beverage Options (per event)
When **food options** and/or **beverage options** are enabled on an event, the event edit screen presents an autocomplete search that pulls from the global menu item library. Selected items are stored in the `eim_event_menu_items` pivot table. These options are returned by the RSVP REST API and can be selected per invitee during registration (stored as `food_option_id` and `beverage_option_id` on each group member). When both food and beverage options are enabled, their assignment tables are displayed **side-by-side** in a two-column grid.

### Invitees
Add and manage invitees globally, each with a first name, last name, email address, phone number, and optional postal address. The Invitees admin table supports AJAX live search with a **column-filter dropdown** (First Name, Last Name, Email, Phone, Invited Events, Connection Groups), sortable columns, event tags, connection group tags, and a **Categories column** showing assigned category chips.

### Connection Groups
Create reusable groups of related invitees — couples, families, households, or custom groupings. These groups are independent of any one event and appear as checkbox suggestions when adding invitees to an event. The Connection Groups page supports the shared live search bar with a **column-filter dropdown** (Name, Type, Members, Invited To). The **Invited To** column shows which events each group has been invited to as clickable event tags. A **Categories column** shows assigned category chips.

### Email Invites
From an event edit screen, add existing invitees to that event. When a selected invitee belongs to connection groups, connected people appear as checkboxes so the admin can include them in one invitation group. One invite email is sent per invitation group to the primary invitee, who can RSVP for everyone in the group. Email subject lines and body content are fully customisable using template tags, including `{{ qr_code }}`, `{{ invite_url }}`, and group-aware tags.

### QR Code RSVP
When an invite is sent, a unique 16-character confirmation code is generated for the invitation group and a QR code PNG is produced and stored in the WordPress uploads directory (`wp-uploads/eim-qr-codes/`). The QR code encodes a URL of the form `{site}/?eim_confirmation={code}`. When scanned, the plugin intercepts the request via `template_redirect` and forwards the visitor to the configured RSVP page. The RSVP API returns all members so each person can be marked as `attending`, `declined`, or `pending`. QR codes are automatically removed from disk and the database when their invitation group is deleted.

### Invited Invitees — search & sort
The **Invited Invitees** list on every event edit screen supports AJAX live search with a **column-filter dropdown** (Group Members, Email, Invite Sent, Registered) and sortable column headers (Group Members, Email, Invite Sent, Registered). Sorting and filtering occur without a page reload; both operate simultaneously.

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

### Contextual search bars
All list tables use a shared search control that includes a text input and a column-filter dropdown. The search bar is only displayed when there is at least one item in the list. When a search returns no results it shows "No results found based upon search criteria." rather than the empty-state message.

### REST API
Two JSON endpoints power the front-end RSVP experience:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/wp-json/eim/v1/rsvp`     | Returns event details, food/beverage options, lodging options, the primary invitee, and all invitation group members for a confirmation code |
| `POST` | `/wp-json/eim/v1/register` | Updates RSVP status for all group members or for specific members; accepts per-person food/beverage selections and dietary notes |

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
- **Address** — street, city, state, ZIP (hidden when "Other" is checked)

### 2 — Build your vendor library (optional)

Navigate to **Events Invite Manager → Vendors** and add the service providers involved in your event (caterers, photographers, florists, etc.). Vendors are linked to food & beverage items and budget line items.

Each vendor has a **company name** (required), street address, email, phone, and optional notes.

### 3 — Set up categories (optional)

Navigate to **Events Invite Manager → Categories** to create your category taxonomy. Categories support one level of parent → child hierarchy and can be applied to any entity in the plugin — events, invitees, connection groups, locations, menu items, budget plans, vendors, and newsletters.

### 4 — Build your menu item library (optional)

Navigate to **Events Invite Manager → Food & Beverages** and add the food and beverage items you want to offer at your events. Items are global — create them once and assign them to as many events as needed.

Each item has a **label** (required), optional **description**, an optional **vendor** (from the vendor library), an optional per-person **price** (used in budget calculations), and optional **categories**.

### 5 — Create an event

Navigate to **Events Invite Manager → Events → Add New Event** and fill in the fields across the tabbed interface:

- **Details tab** — Event Name *(required)*, Description, Start/End Date & Time, Time Zone, Maximum Invitees
- **Venue/Location tab** — search the location library to assign a venue
- **Invite Email tab** — From name, From email, subject line, and body template (use `{{ qr_code }}` and `{{ invite_url }}` to embed the QR image or RSVP link)
- **QR Code & RSVP tab** — select the WordPress page recipients land on after scanning their QR code
- **Lodging tab** — enable lodging and optionally pre-assign locations from the library
- **Food & Beverage tab** — enable food/beverage option flags; after saving, assign items from the global library in the same tab

### 6 — Add invitees

Navigate to **Events Invite Manager → Invitees** and add each guest with their name, email address, phone number, and optional postal address.

### 7 — Create connection groups

Navigate to **Events Invite Manager → Connection Groups** and create reusable groups for people who commonly RSVP together (couples, families, households). Connection groups provide checkbox suggestions when adding invitees to an event. The **Invited To** column shows which events each group has been invited to.

### 8 — Send invites

Open the event edit screen, navigate to the **Invited Invitees** tab, add existing invitees, and optionally check connected people to include them in the same invitation group. Use **Send Invite** on an individual group row or **Send All Unsent Invites** to dispatch one email per unsent group.

### 9 — Build your RSVP page

Create a WordPress page and set it as the event's **QR Code RSVP Page**. When an invitee scans their QR code the plugin redirects them to that page with `?eim_confirmation={code}` appended. On that page:

1. Read `eim_confirmation` from the query string.
2. Call `GET /wp-json/eim/v1/rsvp?confirmation_code={code}` to load the primary invitee, all group members, event details, food/beverage options, and lodging options.
3. Display the group member list and allow the recipient to choose attending/declining and select menu preferences.
4. Call `POST /wp-json/eim/v1/register` with the confirmation code (and optionally a `members` array with per-person RSVP status, `food_option_id`, `beverage_option_id`, and `dietary_notes`).

### 10 — Write newsletters (optional)

Navigate to **Events Invite Manager → Newsletters** to create newsletter posts for email blasts or website display. Associate each newsletter with one or more events, assign categories and tags, and use the TinyMCE editor to write HTML content. Click **Preview Content** to see a live side-by-side preview of the rendered HTML that updates automatically as you type.

### 11 — Track your budget (optional)

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
| `{{ qr_code }}`       | An `<img>` tag containing the invitation group's unique QR code (300 × 300 px) |
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

All endpoints are under the `eim/v1` namespace. Both are publicly accessible — they are gated by the 16-character confirmation code embedded in the QR code URL rather than WordPress authentication.

### `GET /wp-json/eim/v1/rsvp`

Returns event details, food/beverage options assigned to the event, lodging options, the primary invitee, and every member in the invitation group. Call this on RSVP page load to personalise the page before the recipient confirms.

**Query parameters**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `confirmation_code` | string | Yes | 16-character code from the QR code URL |

**Successful response**

```json
{
    "success": true,
    "event": {
        "name": "Chris & Jamie's Wedding",
        "description": "Please join us to celebrate!",
        "date": "June 14, 2025, 4:00 PM – 9:00 PM",
        "venue": {
            "name": "The Grand Ballroom",
            "address": "123 Main St, Nashville, TN 37201"
        }
    },
    "rsvp_options": {
        "food": [
            { "id": 1, "label": "Chicken", "description": "Herb-roasted chicken breast", "sort_order": 1 },
            { "id": 2, "label": "Salmon",  "description": "Pan-seared Atlantic salmon",  "sort_order": 2 }
        ],
        "beverage": [
            { "id": 3, "label": "Red Wine",   "description": "", "sort_order": 1 },
            { "id": 4, "label": "White Wine",  "description": "", "sort_order": 2 }
        ]
    },
    "invitee": {
        "first_name": "Jamie",
        "last_name": "Smith",
        "email": "jamie@example.com"
    },
    "group_members": [
        {
            "invitee_id": 12,
            "first_name": "Jamie",
            "last_name": "Smith",
            "email": "jamie@example.com",
            "rsvp_status": "pending",
            "registered_at": null,
            "food_option_id": null,
            "beverage_option_id": null,
            "dietary_notes": ""
        }
    ],
    "lodging": [
        {
            "name": "The Inn at Main",
            "address": "456 Oak Ave, Nashville, TN 37202",
            "booking_url": "https://example.com/book",
            "is_other": false
        }
    ]
}
```

The `rsvp_options.food` and `rsvp_options.beverage` arrays are empty when the corresponding option is not enabled on the event. `food_option_id` and `beverage_option_id` in each group member are `null` until the member selects a preference during registration.

---

### `POST /wp-json/eim/v1/register`

Validates the confirmation code and updates RSVP status for the invitation group. If `members` is omitted, all pending group members are marked as `attending`. If `members` is provided, each listed member can be set individually with an optional food/beverage selection and dietary notes.

**Request body (JSON or form-data)**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `confirmation_code` | string | Yes | 16-character code from the QR code URL |
| `members` | array | No | List of per-member objects (see below). If omitted, all pending members are marked attending. |

**Per-member object**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `invitee_id` | integer | Yes | ID of the invitee within the group |
| `rsvp_status` | string | Yes | One of `attending`, `declined`, or `pending` |
| `food_option_id` | integer | No | ID of a food item from `rsvp_options.food`. Must be assigned to this event; invalid IDs are ignored. |
| `beverage_option_id` | integer | No | ID of a beverage item from `rsvp_options.beverage`. Must be assigned to this event; invalid IDs are ignored. |
| `dietary_notes` | string | No | Free-text dietary requirements or preferences |

**Successful response**

```json
{
    "success": true,
    "already_registered": false,
    "message": "You have successfully registered for the event!",
    "invitee": {
        "first_name": "Jamie",
        "last_name": "Smith",
        "email": "jamie@example.com"
    }
}
```

When all group members are already attending, `already_registered` is `true` and the message reflects that.

---

## Database Tables

| Table | Description |
|-------|-------------|
| `{prefix}eim_events` | Event records including venue FK, RSVP page ID, date/time, food/beverage flags, and invite email template |
| `{prefix}eim_invitees` | Global invitee profile records |
| `{prefix}eim_event_invitees` | Event membership assignments for individual invitees |
| `{prefix}eim_locations` | Global location catalogue — venues and lodging options shared across all events |
| `{prefix}eim_event_lodging` | Pivot table linking events to their lodging location options |
| `{prefix}eim_qr_codes` | QR code records: confirmation code, event/group FKs, and uploads-relative PNG path |
| `{prefix}eim_invitee_connection_groups` | Reusable global relationship groups (couples, families, households, custom) |
| `{prefix}eim_invitee_connection_group_members` | Pivot table linking global invitees to reusable connection groups |
| `{prefix}eim_event_invitation_groups` | Event-specific invitation groups; each group has one primary invitee and one email/QR code |
| `{prefix}eim_event_invitation_group_members` | Per-person RSVP status, registration timestamp, food/beverage selections, and dietary notes |
| `{prefix}eim_menu_items` | Global food and beverage menu item library — label, description, price, vendor FK |
| `{prefix}eim_event_menu_items` | Pivot table linking events to their assigned menu items from the global library |
| `{prefix}eim_vendors` | Global vendor library — company name, contact details, and free-text notes |
| `{prefix}eim_budget_plans` | Budget plan records with name, description, target amount, and currency |
| `{prefix}eim_budget_plan_events` | Pivot table linking budget plans to one or more events |
| `{prefix}eim_budget_line_items` | Budget line items with vendor FK, quantity, unit cost, paid amount, and notes |
| `{prefix}eim_newsletters` | Newsletter post records with title, HTML content, status, and publish date |
| `{prefix}eim_newsletter_events` | Pivot table linking newsletters to one or more events |
| `{prefix}eim_newsletter_categories` | Managed newsletter category list (name, slug) |
| `{prefix}eim_newsletter_tags` | Managed newsletter tag list (name, slug) |
| `{prefix}eim_newsletter_category_map` | Pivot table linking newsletters to their categories |
| `{prefix}eim_newsletter_tag_map` | Pivot table linking newsletters to their tags |
| `{prefix}eim_categories` | Unified category taxonomy — name, slug, optional parent ID for one-level hierarchy |
| `{prefix}eim_category_map` | Pivot table linking categories to any entity type (event, invitee, vendor, menu_item, etc.) |

---

## Developer Notes

- **PHP 8.1+ features in use:** readonly constructor properties, named arguments, `match` expressions, `str_contains`, `str_starts_with`.
- **JavaScript:** ES2022 classes with private fields (`#field`), async/await, dynamic `import()`. Separate IIFE scripts handle each admin page: `admin-events.js` (events list search/sort/pagination), `admin-invitees.js` (invitee table, event invitee picker, connection group list/member picker, event groups search/sort, menu item pickers), `admin-locations.js` (locations table search/sort), `admin-menu-items.js` (food and beverage tables search/sort/pagination), `admin-budget.js` (budget plans and line items), `admin-newsletters.js` (newsletter list search/sort), `admin-categories.js` (category picker widget and category list). The location autocomplete system is split into ES modules under `assets/js/modules/`. Tab switching on the event edit screen and the newsletter content preview are implemented as inline IIFE scripts. No build tool or transpilation step is required.
- **No build tool required:** JS is authored in native ES2022 and loaded directly — no bundler or transpilation step.
- **Autoloading:** PSR-4 via Composer. All classes live under the `EventsInviteManager\` namespace in `src/`.
- **QR code storage:** PNGs are written to `{wp-uploads}/eim-qr-codes/` via `wp_upload_dir()` — not inside the plugin directory — so they survive plugin updates. Files are deleted from disk whenever the associated DB record is removed.
- **Referential integrity:** No database-level foreign keys are used. Deletion cascades are handled in PHP: deleting an event removes its invitee assignments, lodging assignments, menu item assignments, invitation groups, invitation group members, and QR codes; deleting a location nulls `events.venue_id` and removes its lodging pivot rows; deleting a menu item removes its event pivot rows; deleting a vendor nulls `menu_items.vendor_id`; deleting an invitee removes them from connection groups and invitation groups; deleting a newsletter category or tag removes it from all newsletter associations; deleting any entity removes its `eim_category_map` rows.
- **Category system:** `Category::forEntities(string $entityType, array $entityIds)` bulk-loads categories for an entire list in one JOIN query (keyed by entity ID), avoiding N+1 lookups in list-table renders. `Category::forEntity()` loads for a single entity. `Category::syncToEntity()` atomically replaces all category assignments for an entity.

---

## Author

**Chris Paschall** — built for the Chris & Jamie Wedding  
License: GPL-2.0-or-later
