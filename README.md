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
