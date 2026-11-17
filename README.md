# BostonChefs Airtable Integration

Custom WordPress plugin for syncing BostonChefs Rundown data from Airtable into WordPress.

The integration provides a secure REST API endpoint that allows Airtable automations to update supported Restaurant Rundown fields in WordPress while preserving fields that continue to be managed directly within WordPress.

---

## Purpose

BostonChefs manages Holiday Rundown information in Airtable and WordPress.

This plugin reduces duplicate data entry by allowing Airtable to act as the source for selected Rundown content and push approved updates into WordPress.

The integration is intentionally designed as a **one-way sync**:

Airtable → WordPress

WordPress does not automatically push changes back into Airtable.

---

## Integration Flow

```text
Airtable Rundown Record
        ↓
Airtable Automation
        ↓
Authenticated REST API Request
        ↓
BostonChefs Airtable Integration Plugin
        ↓
Validate Restaurant + Rundown
        ↓
Assign the Rundown when the restaurant is not on it
        ↓
Read Existing WordPress Rundown Data
        ↓
Merge Airtable-Managed Fields
        ↓
Preserve WordPress-Managed Fields
        ↓
Save Updated Rundown
        ↓
Return Success / Error Response
```

---

## Endpoint

`POST /wp-json/bostonchefs/v1/rundowns`

Authenticate as the `airtable-sync` user with an Application Password over HTTP Basic auth. Send `Content-Type: application/json`. That user has the `bc_sync_rundowns` capability only.

Create the restaurant and the rundown term in WordPress before calling this endpoint. If the restaurant is not yet assigned to that rundown, the request assigns it and then writes the rundown content. That assignment is additive: other rundown terms on the restaurant stay in place, and this endpoint never removes a rundown assignment.

### Airtable-managed fields

| JSON field | WordPress meta key |
| --- | --- |
| `title` | `title` |
| `blurb` | `blurb` |
| `availability` | `availability` |
| `price` | `price` |
| `cta_text` | `cta_text` |
| `cta_url` | `cta_url` |
| `show_reservation_url` | `show_reservation_url` |
| `alternative_reservation_url` | `reservation_url` |

Values are written to the existing `rundown_{term_id}` post meta array on the restaurant. WordPress serializes that array. The Airtable record ID is stored separately as `_bc_airtable_rundown_{term_id}` so a later edit in WordPress admin does not drop it.

### WordPress-managed fields

These stay under WordPress control. Sending any of them returns an error and changes nothing:

- `image` (Rundown Image)
- `menu` (Associated Menu)

`alternative_reservation_url` is the Airtable name for the WordPress field `reservation_url`. Sending `reservation_url` writes that same field. Send only one of those keys.

### Value rules

- Field omitted → leave the current WordPress value unchanged
- Field `null` → clear the WordPress value. For `show_reservation_url`, null stores `false`
- Field blank or whitespace → leave the current WordPress value unchanged
- Field has a value → update the WordPress value

`show_reservation_url` accepts only `true`, `false`, or `null`.

Sending the same payload again is safe. A repeat with no differences returns success and an empty `updated_fields` list.

### Example

```json
{
  "restaurant_id": 123,
  "rundown_term_id": 456,
  "airtable_record_id": "recABCDEFGHIJKLMN",
  "title": "Christmas Eve Prix Fixe",
  "blurb": "Four courses.",
  "availability": "December 24",
  "price": "$95",
  "cta_text": "Reserve a table",
  "cta_url": "https://example.com/reserve",
  "show_reservation_url": true,
  "alternative_reservation_url": "https://example.com/reserve-alt"
}
```

Success:

```json
{
  "success": true,
  "restaurant_id": 123,
  "rundown_term_id": 456,
  "rundown_assigned": false,
  "airtable_record_id": "recABCDEFGHIJKLMN",
  "meta_key": "rundown_456",
  "updated_fields": ["title", "blurb", "availability", "price", "cta_text", "cta_url", "show_reservation_url", "reservation_url"],
  "message": "Rundown successfully synced.",
  "url": "https://example.com/holiday/christmas/#restaurant-slug"
}
```

`rundown_assigned` is `true` when this request added the rundown term. The message is then `Restaurant assigned and Rundown successfully synced.`

`url` is the public rundown page, with the restaurant slug as the anchor. Airtable stores it in WP URL.

Errors use the WordPress REST error shape (`code`, `message`, `data.status`). `data.field` names the payload key when the failure belongs to one field. Airtable stores `message` in WP Sync Error.

### Archived rundowns

The theme's **Archive & Start New Year** copies a rundown into an archived term, such as "Valentine's Day 2026", and empties the live rundown. An archived rundown is a closed year, so a sync to one is refused before anything is written:

```json
{
  "code": "bc_airtable_rundown_archived",
  "message": "Valentine's Day 2026 is an archived rundown and cannot be changed. Sync to the current rundown (term ID 214) instead.",
  "data": { "status": 409, "field": "rundown_term_id", "current_rundown_id": 214 }
}
```

Point the Airtable item at the current rundown and sync again.

---

## Reference endpoints

Same authentication as the sync endpoint. Both return a JSON array.

### `GET /wp-json/bostonchefs/v1/restaurants`

Published restaurants, ordered by name: `id`, `name`, `url`, `content`.

### `GET /wp-json/bostonchefs/v1/rundown-terms`

Every rundown term, including empty and archived ones, ordered by name.

| Field | Value |
| --- | --- |
| `id` | Rundown term ID |
| `name` | Rundown name, HTML entities decoded |
| `slug` | Rundown slug |
| `status` | `current`, or `archived` for a past year |
| `year` | Year the rundown holds. On a current rundown, the year being built. `null` until set |
| `current_rundown_id` | On an archived rundown, the term ID of the live rundown it came from. Otherwise `null` |
| `type` | `holiday`, `feature`, or `standard`, from the rundown's template |
| `restaurant_count` | Published restaurants on the rundown |
| `url` | Public rundown page. `null` for archived rundowns, which are not public |

```json
{
  "id": 214,
  "name": "Valentine's Day",
  "slug": "valentines-day-boston",
  "status": "current",
  "year": 2027,
  "current_rundown_id": null,
  "type": "holiday",
  "restaurant_count": 104,
  "url": "https://www.bostonchefs.com/holiday/valentines-day-boston/"
}
```

Without the Boston Chefs theme's archive code, every rundown is `current` with a `null` year.

---

## WordPress user

Activating the plugin creates:

- Role `Airtable Sync` (`bc_airtable_sync`) with the `bc_sync_rundowns` capability
- User `airtable-sync` on that role

The account password is random and unused. On the user’s profile, create an Application Password named for Airtable. Application Passwords require HTTPS. Until one exists, WordPress shows an admin notice with a link to that profile.

Store these Airtable secrets:

| Secret | Value |
| --- | --- |
| `WP_USERNAME` | `airtable-sync` |
| `WP_APPLICATION_PASSWORD` | The generated Application Password |
| `WP_API_URL` | `https://example.com/wp-json/bostonchefs/v1/rundowns` |

---

## Airtable automation

Holiday Rundown items are pushed by an Airtable automation, outside this plugin. The trigger is `WP Sync Requested` checked.

Suggested fields on the rundown item:

| Field | Purpose |
| --- | --- |
| WP Restaurant ID | WordPress restaurant post ID. Prefer a lookup from the linked Restaurant. |
| WP Rundown Term ID | WordPress rundown term ID. Prefer a lookup from the linked Holiday Rundown. |
| WP Sync Status | `Not Synced`, `Syncing`, `Synced`, or `Error` |
| WP Last Synced | Timestamp of the last successful request |
| WP Sync Error | Error message from the last failed request |
| WP URL | `url` from the WordPress success response |
| WP Sync Requested | Checkbox that triggers the automation |

The script sends the JSON body documented above, using Basic auth from `WP_USERNAME` and `WP_APPLICATION_PASSWORD`.

On success, set WP Sync Status to `Synced`, WP Last Synced to the current timestamp, WP Sync Error to blank, and WP URL to the returned `url`.

On failure, set WP Sync Status to `Error` and WP Sync Error to the API message. Leave WP Last Synced unchanged.
