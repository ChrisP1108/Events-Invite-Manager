# Events Invite Manager

A WordPress plugin for managing event invitations, attendee registration, venue and lodging assignments, and automated email workflows — built for private events where a curated guest list and QR code RSVP flow are required.

---

## Features

### Location Library
A centralized library of reusable locations (venues, hotels, Airbnbs, etc.) maintained independently of any specific event. Locations are created once and selected by name across as many events as needed via a live autocomplete search field. The Locations admin table supports AJAX search and sortable columns. Free-text entries are not allowed — every venue or lodging assignment must reference a validated library entry.

Each location can be marked as offering **lodging** (`has_lodging`), which makes it available in the lodging autocomplete on event forms. An optional **booking URL** can be attached so invitees can access the reservation page directly from the RSVP experience.

### Events
Create and manage events with full details: name, description, date, start and end time, time zone, and a linked WordPress RSVP page. A calendar view in the admin shows all dated events at a glance with month navigation and a jump-to-event dropdown. An optional **maximum invitee cap** can be set per event.

### Venue Assignment
Each event can have a single venue selected from the location library. Typing in the venue field triggers a live search and shows matching locations with their addresses. Selecting a location auto-fills the hidden address fields and displays the formatted address below the input as read-only confirmation text.

### Lodging Locations
Events can offer multiple lodging options to invitees. Lodging locations are selected from library entries that have `has_lodging` enabled. On new events, one or more initial lodging locations can be added at creation time; additional locations can be added or removed from the event edit screen. Lodging assignments are stored in a dedicated pivot table (`eim_event_lodging`) and exposed via the REST API.

### Invitees
Add and manage invitees globally, each with a first name, last name, email address, phone number, and optional postal address. The Invitees admin table supports AJAX search, sortable columns, and event tags linking to every event the person has been invited to.

### Email Invites
From an event edit screen, add existing invitees to that event and send invite emails to individual invitees or to all invitees who have not yet been sent one. Email subject lines and body content are fully customizable using template tags, including a `{{ qr_code }}` tag that embeds a personalized scannable QR code image and a `{{ invite_url }}` tag for the matching RSVP link. A separate **From Name** and **From Email** can be set per event.

### QR Code RSVP
When an invite is sent, a unique 16-character confirmation code is generated for each invitee and a QR code PNG is produced and stored in the WordPress uploads directory (`wp-uploads/eim-qr-codes/`). The QR code encodes a URL of the form `{site}/?eim_confirmation={code}`. When scanned, the plugin intercepts the request via `template_redirect` and forwards the visitor to the configured RSVP page with the code preserved in the query string. QR codes are automatically removed from disk and the database when an invitee is deleted or removed from an event.

### REST API
Two JSON endpoints power the front-end RSVP experience:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/wp-json/eim/v1/rsvp`     | Returns event details, lodging options, and invitee status for a given confirmation code |
| `POST` | `/wp-json/eim/v1/register` | Marks the invitee as registered for the event                                           |

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
3. On activation, the plugin automatically creates the required database tables: `eim_events`, `eim_invitees`, `eim_event_invitees`, `eim_locations`, `eim_event_lodging`, and `eim_qr_codes`.
4. Deactivating the plugin **preserves all data**. Tables are only removed by uninstalling or deleting the plugin manually.

> **Note:** Database schema changes are applied automatically on plugin load via `DatabaseManager::maybeUpgrade()`, which uses `dbDelta` for safe, idempotent updates.

---

## Getting Started

### 1 — Build your location library

Navigate to **Events Invite Manager → Locations** and add every venue, hotel, and lodging option you plan to use. The Locations table supports live AJAX search and sortable columns. At minimum, add the event venue.

Each location has:
- **Name** — displayed in autocomplete results and on the event
- **Type** — check "Other" for flexible options with no fixed address (e.g. Airbnb, personal arrangement)
- **Lodging** — check "This location offers lodging" to make it available in the event lodging autocomplete. Optionally add a **Booking Website** URL.
- **Address** — street, city, state, ZIP (hidden when "Other" is checked)

### 2 — Create an event

Navigate to **Events Invite Manager → Events → Add New Event** and fill in:

- **Event Name** *(required)*
- **Description** — free-text description shown to admins
- **Event Date / Start Time / End Time / Time Zone**
- **QR Code RSVP Page** — the WordPress page invitees land on after scanning their QR code
- **Maximum Invitees** — optional cap on the total number of invitees for this event
- **Venue** — start typing to search the location library
- **Invite Email** — From name, From email, subject line, and body template (use `{{ qr_code }}` to embed the scannable image and `{{ invite_url }}` for the matching RSVP link)
- **Lodging** — enable the lodging toggle and optionally add initial lodging locations from the library

After saving, you are taken to the event edit screen where additional lodging locations can be added or removed.

### 3 — Add invitees

Navigate to **Events Invite Manager → Invitees** and add each guest with their name, email address, phone number, and optional postal address. The global invitee table can be searched via AJAX, sorted by column, and shows event tags for every event the person has been invited to.

### 4 — Send invites

Open the event edit screen, add existing invitees to the event's **Invited Invitees** list, then use **Send Invite** on an individual row or **Send All Unsent** to dispatch invites to every invitee who has not yet received one.

A unique QR code is generated automatically for each invitee at send time. The QR code PNG is stored in the WordPress uploads directory and a 16-character confirmation code is saved to the database. Add `{{ qr_code }}` anywhere in your invite email body to embed the scannable image, and add `{{ invite_url }}` to include the same personalized RSVP URL as a text link fallback.

### 5 — Build your RSVP page

Create a WordPress page and set it as the event's **QR Code RSVP Page**. When an invitee scans their QR code the plugin redirects them to that page with `?eim_confirmation={code}` appended. On that page:

1. Read `eim_confirmation` from the query string.
2. Call `GET /wp-json/eim/v1/rsvp?confirmation_code={code}` to load the invitee's name, event details, and lodging options.
3. Display the information and prompt them to confirm attendance.
4. Call `POST /wp-json/eim/v1/register` with `{ "confirmation_code": "{code}" }` to mark them as registered.

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
| `{{ qr_code }}`    | An `<img>` tag containing the invitee's unique QR code (300 × 300 px) |
| `{{ invite_url }}` | The same personalized RSVP URL encoded in the invitee's QR code |

### From Email field

| Tag | Replaced with |
|-----|---------------|
| `{{ current_domain }}` | The site's domain at send time (e.g. `example.com`) — useful for `noreply@{{current_domain}}` |

---

## REST API Reference

All endpoints are under the `eim/v1` namespace. Both are publicly accessible — they are gated by the 16-character confirmation code embedded in the QR code URL rather than WordPress authentication.

### `GET /wp-json/eim/v1/rsvp`

Returns event details, lodging options, and the invitee's current registration status for a given confirmation code. Call this on RSVP page load to personalise the page before the invitee confirms.

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
    "invitee": {
        "first_name": "Jamie",
        "last_name": "Smith",
        "email": "jamie@example.com",
        "is_registered": false,
        "registered_at": null
    },
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

---

### `POST /wp-json/eim/v1/register`

Validates the confirmation code and marks the invitee as registered for the event. Safe to call multiple times — subsequent calls return `already_registered: true` without overwriting the original registration timestamp.

**Request body (JSON or form-data)**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `confirmation_code` | string | Yes | 16-character code from the QR code URL |

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

---

## Database Tables

| Table | Description |
|-------|-------------|
| `{prefix}eim_events`         | Event records including venue FK, RSVP page ID, date/time, and invite email template |
| `{prefix}eim_invitees`       | Global invitee profile records |
| `{prefix}eim_event_invitees` | Event invitation assignments with invite codes, registration status, and send timestamps |
| `{prefix}eim_locations`      | Global location catalogue — venues and lodging options shared across all events |
| `{prefix}eim_event_lodging`  | Pivot table linking events to their lodging location options |
| `{prefix}eim_qr_codes`       | QR code records: confirmation code, event/invitee FKs, and uploads-relative PNG path |

---

## Developer Notes

- **PHP 8.1+ features in use:** readonly constructor properties, named arguments, `match` expressions, `str_starts_with`.
- **JavaScript:** ES2022 classes with private fields (`#field`), async/await, dynamic `import()`. The autocomplete system is split into ES modules under `assets/js/modules/`. Separate IIFE scripts handle the invitee and location list table search/sort.
- **No build tool required:** JS is authored in native ES2022 and loaded directly — no bundler or transpilation step.
- **Autoloading:** PSR-4 via Composer. All classes live under the `EventsInviteManager\` namespace in `src/`.
- **QR code storage:** PNGs are written to `{wp-uploads}/eim-qr-codes/` via `wp_upload_dir()` — not inside the plugin directory — so they survive plugin updates. Files are deleted from disk whenever the associated DB record is removed.
- **Referential integrity:** No database-level foreign keys are used. Deletion cascades are handled in PHP: deleting an event removes its invitee assignments, lodging assignments, and QR codes; deleting a location nulls `events.venue_id` and removes its lodging pivot rows; removing an invitee from an event deletes their QR code.

---

## Author

**Chris Paschall** — built for the Chris & Jamie Wedding  
License: GPL-2.0-or-later
