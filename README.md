# Events Invite Manager

A WordPress plugin for managing event invitations, attendee registration, venue and lodging assignments, and automated email workflows — built for private events where a curated guest list and confirmation flow are required.

---

## Features

### Location Library
A centralized library of reusable locations (venues, hotels, Airbnbs, etc.) that is maintained independently of any specific event. Locations are created once and selected by name across as many events as needed via an autocomplete search field. Free-text entries are not allowed — every venue or lodging assignment must reference a validated library entry.

### Events
Create and manage events with full details: name, description, date, start and end time, and a linked WordPress RSVP page. A calendar view in the admin shows all dated events at a glance with month navigation and a jump-to-event dropdown.

### Venue Assignment
Each event can have a single venue selected from the location library. Typing in the venue field triggers a live search against the library and shows matching locations with their addresses. Selecting a location auto-fills the hidden address fields and displays the formatted address below the input as read-only confirmation text.

### Lodging Locations
Events can offer multiple lodging options to invitees. Lodging locations are selected from the library using the same autocomplete search. On new events, one or more initial lodging locations can be added at creation time; additional locations can be added or removed from the event edit screen.

### Invitees
Add and manage invitees per event, each with a first name, last name, email address, and optional postal address. Every invitee receives a unique cryptographically generated invite code that is embedded in their personal RSVP link.

### Email Invites
Send invite emails to individual invitees or to all invitees who have not yet been sent an invite. Email subject lines and body content are fully customizable using template tags. A separate "From Name" and "From Email" can be set per event, or the site's default WordPress sender can be used.

### RSVP Confirmation Flow
When an invitee visits their RSVP page, they enter their email address to receive a 6-digit confirmation code (valid for 15 minutes). Entering the correct code marks them as registered. This two-step flow prevents unauthorized registrations while keeping the process frictionless for genuine guests.

### Confirmation Emails
A separate email template handles the confirmation code delivery. The `{{ confirmation_code }}` tag is replaced at send time with the generated 6-digit code.

### REST API
Two JSON endpoints power the front-end RSVP experience:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/wp-json/eim/v1/request-code` | Validates the invitee's email against the event and sends a confirmation code |
| `POST` | `/wp-json/eim/v1/register`      | Verifies the confirmation code and marks the invitee as registered            |

### Admin Calendar
The events list includes a monthly calendar grid. Events with a date appear as linked blocks on their respective days. Month navigation arrows and a jump-to-event dropdown are provided for quick navigation across the full events timeline.

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
3. On activation, the plugin automatically creates the required database tables (`eim_events`, `eim_invitees`, `eim_locations`, `eim_location_library`).
4. Deactivating the plugin **preserves all data**. Tables are only removed by uninstalling or deleting the plugin manually.

> **Note:** If you add new features that require database schema changes, deactivate and reactivate the plugin to trigger `DatabaseManager::createTables()`, which uses `dbDelta` for safe, idempotent updates.

---

## Getting Started

### 1 — Build your location library

Navigate to **Events Invite Manager → Locations** and add every venue, hotel, and lodging option you plan to use. At minimum, add the event venue. Locations created here are the only ones that can be assigned to events — the admin cannot type free text into venue or lodging fields.

Each location has:
- **Name** — displayed in autocomplete results and on the event
- **Address** — street, city, state, ZIP (leave blank and check "Other" for flexible options like Airbnbs)
- **"Other" flag** — marks a generic option with no fixed address (e.g. "Personal Arrangement / Airbnb")

### 2 — Create an event

Navigate to **Events Invite Manager → Events → Add New Event** and fill in:

- **Event Name** *(required)*
- **Description** — free-text description shown to admins
- **Event Date / Start Time / End Time**
- **RSVP Page** — select the WordPress page where invitees will register
- **Venue / Location** — start typing to search the location library
- **Invite Email** — From name, From email, subject line, and body template
- **Confirmation Code Email** — subject and body for the code delivery email
- **Lodging** — enable the lodging toggle and optionally add initial lodging locations

After saving, you are taken to the event edit screen where additional lodging locations can be added or removed.

### 3 — Add invitees

Open the event and click through to **Invitees**, or navigate to **Events Invite Manager → Invitees** and select the event. Add each guest with their name and email address. Each invitee automatically receives a unique invite code stored in the database.

### 4 — Send invites

From the Invitees list, use **Send Invite** on an individual row, or click **Send All Unsent** to dispatch invites to every invitee who has not yet received one. Each email contains the invitee's personal RSVP link with their invite code embedded as a query parameter.

### 5 — Build your RSVP page

Create a WordPress page and assign it to the event's **RSVP Page** field. On that page, use the REST API to:
1. Accept the invitee's email address and call `POST /wp-json/eim/v1/request-code` (passing `email` and `event_id`).
2. Accept the 6-digit code and call `POST /wp-json/eim/v1/register` (passing `email`, `code`, and `event_id`).

A successful registration response includes the invitee's first and last name so the page can display a personalised confirmation message.

---

## Email Template Tags

Template tags are replaced with live values at send time. Tags are case-insensitive and tolerate spaces around the variable name (e.g. `{{ event_name }}` and `{{event_name}}` are equivalent).

### Invite email

| Tag | Replaced with |
|-----|---------------|
| `{{ event_name }}`  | The event's name |
| `{{ first_name }}`  | Invitee's first name |
| `{{ last_name }}`   | Invitee's last name |
| `{{ full_name }}`   | First and last name combined |
| `{{ email }}`       | Invitee's email address |
| `{{ invite_code }}` | The invitee's unique invite code |
| `{{ rsvp_url }}`    | Full RSVP page URL with `?invite_code=…&event_id=…` appended |

### Confirmation code email

| Tag | Replaced with |
|-----|---------------|
| `{{ confirmation_code }}` | The 6-digit code sent to the invitee |

### From Email field (both emails)

| Tag | Replaced with |
|-----|---------------|
| `{{ current_domain }}` | The site's domain at send time (e.g. `example.com`) — useful for `noreply@{{current_domain}}` |

---

## REST API Reference

All endpoints are under the `eim/v1` namespace.

### `POST /wp-json/eim/v1/request-code`

Validates that the supplied email belongs to an invitee for the given event, generates a 6-digit confirmation code, stores it as a transient for 15 minutes, and sends it to the invitee's email address.

**Request body (JSON or form-data)**

| Field      | Type    | Required | Description |
|------------|---------|----------|-------------|
| `email`    | string  | Yes      | Invitee's email address |
| `event_id` | integer | Yes      | Event ID |

**Response**

```json
{ "success": true, "message": "Confirmation code sent." }
```

---

### `POST /wp-json/eim/v1/register`

Verifies the confirmation code against the stored transient and marks the invitee as registered. The transient is deleted immediately after a successful match to prevent replay.

**Request body (JSON or form-data)**

| Field      | Type    | Required | Description |
|------------|---------|----------|-------------|
| `email`    | string  | Yes      | Invitee's email address |
| `code`     | string  | Yes      | 6-digit confirmation code |
| `event_id` | integer | Yes      | Event ID |

**Response**

```json
{
    "success": true,
    "message": "Registration confirmed.",
    "already_registered": false,
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
| `{prefix}eim_events`           | Event records |
| `{prefix}eim_invitees`         | Invitee records with unique invite codes |
| `{prefix}eim_locations`        | Venue and lodging location assignments per event |
| `{prefix}eim_location_library` | Centralized location library (source of truth for autocomplete) |

---

## Developer Notes

- **PHP 8.1+ features in use:** readonly constructor properties, enums (via match expressions), fibers (not used), `never` return type (not used), named arguments.
- **JavaScript:** ES2022 classes with private fields (`#field`), async/await, dynamic `import()`. The autocomplete system is split into ES modules under `assets/js/modules/`.
- **No build tool required:** The JS is authored in native ES2022 and loaded via dynamic `import()` from the enqueued entry-point script.
- **Autoloading:** PSR-4 via Composer. All classes live under the `EventsInviteManager\` namespace in `src/`.

---

## Author

**Chris Paschall** — built for the Chris & Jamie Wedding  
License: GPL-2.0-or-later
