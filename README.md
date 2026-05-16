# Events Invite Manager

A WordPress plugin for managing private event invitations, grouped RSVPs, attendee registration, venue and lodging assignments, a global food & beverage menu library, and automated email workflows — built for events where a curated guest list and QR code RSVP flow are required.

---

## Features

### Location Library
A centralised library of reusable locations (venues, hotels, Airbnbs, etc.) maintained independently of any specific event. Locations are created once and selected by name across as many events as needed via a live autocomplete search field. The Locations admin table supports AJAX live search with a **column-filter dropdown** (Name, Type, Lodging, Address, Used In) and sortable columns. Free-text entries are not allowed — every venue or lodging assignment must reference a validated library entry.

Each location can be marked as offering **lodging** (`has_lodging`), which makes it available in the lodging autocomplete on event forms. An optional **booking URL** can be attached so invitees can access the reservation page directly from the RSVP experience.

### Food & Beverages Library
A global library of food and beverage menu items, managed from the dedicated **Food & Beverages** admin page. The page displays two independent scrollable tables — one for food, one for beverages — each with its own AJAX live search and column-filter dropdown. Items are created once and assigned to individual events; the same item can be reused across multiple events.

Each item has a **label**, optional **description**, **sort order**, and an **active flag**. When food or beverage options are enabled on an event, items are searched and selected from this global library via an autocomplete picker on the event edit screen — the same pattern used for venue and lodging selection.

### Events
Create and manage events with full details: name, description, date, start and end time, time zone, a linked WordPress RSVP page, and an optional **maximum invitee cap**. A calendar view in the admin shows all dated events at a glance with month navigation and a jump-to-event dropdown.

### Venue Assignment
Each event can have a single venue selected from the location library. Typing in the venue field triggers a live search and shows matching locations with their addresses. Selecting a location auto-fills the hidden address fields and displays the formatted address below the input as read-only confirmation text.

### Lodging Locations
Events can offer multiple lodging options to invitees, each selected from library entries with `has_lodging` enabled. Lodging assignments are stored in a dedicated pivot table (`eim_event_lodging`) and exposed via the REST API.

### Food & Beverage Options (per event)
When **food options** and/or **beverage options** are enabled on an event, the event edit screen presents an autocomplete search that pulls from the global menu item library. Selected items are stored in the `eim_event_menu_items` pivot table. These options are returned by the RSVP REST API and can be selected per invitee during registration (stored as `food_option_id` and `beverage_option_id` on each group member).

### Invitees
Add and manage invitees globally, each with a first name, last name, email address, phone number, and optional postal address. The Invitees admin table supports AJAX live search with a **column-filter dropdown** (First Name, Last Name, Email, Phone, Invited Events, Connection Groups), sortable columns, event tags, and connection group tags.

### Connection Groups
Create reusable groups of related invitees — couples, families, households, or custom groupings. These groups are independent of any one event and appear as checkbox suggestions when adding invitees to an event. The Connection Groups page supports the shared live search bar with a **column-filter dropdown** (Name, Type, Members).

### Email Invites
From an event edit screen, add existing invitees to that event. When a selected invitee belongs to connection groups, connected people appear as checkboxes so the admin can include them in one invitation group. One invite email is sent per invitation group to the primary invitee, who can RSVP for everyone in the group. Email subject lines and body content are fully customisable using template tags, including `{{ qr_code }}`, `{{ invite_url }}`, and group-aware tags.

### QR Code RSVP
When an invite is sent, a unique 16-character confirmation code is generated for the invitation group and a QR code PNG is produced and stored in the WordPress uploads directory (`wp-uploads/eim-qr-codes/`). The QR code encodes a URL of the form `{site}/?eim_confirmation={code}`. When scanned, the plugin intercepts the request via `template_redirect` and forwards the visitor to the configured RSVP page. The RSVP API returns all members so each person can be marked as `attending`, `declined`, or `pending`. QR codes are automatically removed from disk and the database when their invitation group is deleted.

### Invited Invitees — search & sort
The **Invited Invitees** list on every event edit screen supports AJAX live search with a **column-filter dropdown** (Group Members, Email, Invite Sent, Registered) and sortable column headers (Group Members, Email, Invite Sent, Registered). Sorting and filtering occur without a page reload; both operate simultaneously.

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
3. On activation, the plugin automatically creates all required database tables (see **Database Tables** below).
4. Deactivating the plugin **preserves all data**. Tables are only removed by uninstalling or deleting the plugin manually.

> **Note:** Database schema changes are applied automatically on plugin load via `DatabaseManager::maybeUpgrade()`, which uses `dbDelta` for safe, idempotent updates.

---

## Getting Started

### 1 — Build your location library

Navigate to **Events Invite Manager → Locations** and add every venue, hotel, and lodging option you plan to use. At minimum, add the event venue.

Each location has:
- **Name** — displayed in autocomplete results and on the event
- **Type** — check "Other" for flexible options with no fixed address (e.g. Airbnb, personal arrangement)
- **Lodging** — check "This location offers lodging" to make it available in the event lodging autocomplete. Optionally add a **Booking Website** URL.
- **Address** — street, city, state, ZIP (hidden when "Other" is checked)

### 2 — Build your menu item library (optional)

Navigate to **Events Invite Manager → Food & Beverages** and add the food and beverage items you want to offer at your events. Items are global — create them once and assign them to as many events as needed.

Each item has a **label** (required), optional **description**, and a **sort order** for display sequencing.

### 3 — Create an event

Navigate to **Events Invite Manager → Events → Add New Event** and fill in:

- **Event Name** *(required)*
- **Description** — free-text description shown to admins
- **Event Date / Start Time / End Time / Time Zone**
- **QR Code RSVP Page** — the WordPress page recipients land on after scanning their QR code
- **Maximum Invitees** — optional cap on the total number of invitees for this event
- **Venue** — start typing to search the location library
- **Food Options / Beverage Options** — check these to enable per-person menu selections; items are then assigned from the global library on the event edit screen
- **Invite Email** — From name, From email, subject line, and body template (use `{{ qr_code }}` to embed the scannable image and `{{ invite_url }}` for the matching RSVP link)
- **Lodging** — enable the lodging toggle and optionally add initial lodging locations from the library

After saving, the event edit screen allows adding/removing lodging locations and assigning food/beverage items from the global library.

### 4 — Add invitees

Navigate to **Events Invite Manager → Invitees** and add each guest with their name, email address, phone number, and optional postal address.

### 5 — Create connection groups

Navigate to **Events Invite Manager → Connection Groups** and create reusable groups for people who commonly RSVP together (couples, families, households). Connection groups provide checkbox suggestions when adding invitees to an event.

### 6 — Send invites

Open the event edit screen, add existing invitees to the **Invited Invitees** list, and optionally check connected people to include them in the same invitation group. Use **Send Invite** on an individual group row or **Send All Unsent Invites** to dispatch one email per unsent group.

### 7 — Build your RSVP page

Create a WordPress page and set it as the event's **QR Code RSVP Page**. When an invitee scans their QR code the plugin redirects them to that page with `?eim_confirmation={code}` appended. On that page:

1. Read `eim_confirmation` from the query string.
2. Call `GET /wp-json/eim/v1/rsvp?confirmation_code={code}` to load the primary invitee, all group members, event details, food/beverage options, and lodging options.
3. Display the group member list and allow the recipient to choose attending/declining and select menu preferences.
4. Call `POST /wp-json/eim/v1/register` with the confirmation code (and optionally a `members` array with per-person RSVP status, `food_option_id`, `beverage_option_id`, and `dietary_notes`).

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
        },
        {
            "invitee_id": 13,
            "first_name": "Chris",
            "last_name": "Smith",
            "email": "chris@example.com",
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
| `{prefix}eim_menu_items` | Global food and beverage menu item library |
| `{prefix}eim_event_menu_items` | Pivot table linking events to their assigned menu items from the global library |

---

## Developer Notes

- **PHP 8.1+ features in use:** readonly constructor properties, named arguments, `match` expressions, `str_contains`, `str_starts_with`.
- **JavaScript:** ES2022 classes with private fields (`#field`), async/await, dynamic `import()`. Separate IIFE scripts handle each admin page: `admin-invitees.js` (invitee table, event invitee picker, connection group list/member picker, event groups search/sort, menu item pickers), `admin-locations.js` (locations table search/sort), `admin-menu-items.js` (food and beverage tables on the Food & Beverages page). The location autocomplete system is split into ES modules under `assets/js/modules/`.
- **No build tool required:** JS is authored in native ES2022 and loaded directly — no bundler or transpilation step.
- **Autoloading:** PSR-4 via Composer. All classes live under the `EventsInviteManager\` namespace in `src/`.
- **QR code storage:** PNGs are written to `{wp-uploads}/eim-qr-codes/` via `wp_upload_dir()` — not inside the plugin directory — so they survive plugin updates. Files are deleted from disk whenever the associated DB record is removed.
- **Referential integrity:** No database-level foreign keys are used. Deletion cascades are handled in PHP: deleting an event removes its invitee assignments, lodging assignments, menu item assignments, invitation groups, invitation group members, and QR codes; deleting a location nulls `events.venue_id` and removes its lodging pivot rows; deleting a menu item removes its event pivot rows; deleting an invitee removes them from connection groups and invitation groups.

---

## Author

**Chris Paschall** — built for the Chris & Jamie Wedding  
License: GPL-2.0-or-later
