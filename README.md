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

Create the restaurant, the rundown term, and the restaurant-to-rundown assignment in WordPress before calling this endpoint. The request updates content on that existing assignment.

### Airtable-managed fields

| JSON field | WordPress meta key |
| --- | --- |
| `title` | `title` |
| `blurb` | `blurb` |
| `availability` | `availability` |
| `price` | `price` |
| `cta_text` | `cta_text` |
| `cta_url` | `cta_url` |

Values are written to the existing `rundown_{term_id}` post meta array on the restaurant. WordPress serializes that array. The Airtable record ID is stored separately as `_bc_airtable_rundown_{term_id}` so a later edit in WordPress admin does not drop it.

### WordPress-managed fields

These stay under WordPress control. Sending any of them returns an error and changes nothing:

- `image` (Rundown Image)
- `menu` (Associated Menu)
- `show_reservation_url` (Show Reservation URL)
- `reservation_url` (Alternative Reservation URL)

### Value rules

- Field omitted → leave the current WordPress value unchanged
- Field `null` → clear the WordPress value
- Field blank or whitespace → leave the current WordPress value unchanged
- Field has a value → update the WordPress value

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
  "cta_url": "https://example.com/reserve"
}
```

Success:

```json
{
  "success": true,
  "restaurant_id": 123,
  "rundown_term_id": 456,
  "airtable_record_id": "recABCDEFGHIJKLMN",
  "meta_key": "rundown_456",
  "updated_fields": ["title", "blurb", "availability", "price", "cta_text", "cta_url"],
  "url": "https://example.com/holiday/christmas/#restaurant-slug"
}
```

`url` is the public rundown page, with the restaurant slug as the anchor. Airtable stores it in WP URL.

Errors use the WordPress REST error shape (`code`, `message`, `data.status`). `data.field` names the payload key when the failure belongs to one field. Airtable stores `message` in WP Sync Error.

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
