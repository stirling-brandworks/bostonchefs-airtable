// ======================================================
// WordPress → Airtable Rundown Reference Sync
// ======================================================
//
// Reads GET /wp-json/bostonchefs/v1/rundown-terms and creates or updates one
// record per WordPress rundown in the Rundowns table.
//
// Archived years (from "Archive & Start New Year" in WordPress) come back
// as their own rundowns, such as "Valentine's Day 2026", with WP Status
// "Archived". WP Active still means "exists in WordPress", so archived
// rundowns stay active. Filter on WP Status = Current wherever editors pick
// a rundown for a new item. A sync to an archived rundown is refused by
// WordPress.
//
// Rundowns table fields this script writes:
//
//   Rundown             Rundown name (first column)
//   WP Rundown Term ID  Number
//   WP Slug             Text
//   WP Active           Checkbox
//   WP Last Synced      Date
//   WP Status           Single select: Current, Archived
//   WP Year             Number (integer)
//   Rundown Type        Single select: Holiday, Feature, Standard
//   WP URL              URL
//   WP Restaurant Count Number (integer). Published restaurants on the
//                       rundown in WordPress
//                       (not the Airtable "Published Restaurants" field)
//   Current Rundown ID  On an archived year, the live rundown it came from.
//                       Number, text, or a link to this Rundowns table:
//                       a number or text gets the WordPress term ID, a link
//                       points at the live rundown's record. Empty on
//                       current rundowns.
//
// Option names must match exactly, including capitals.
// Every other field in the table is left alone.
//
// Automation secrets: wpUser, wpPassword, rundownsApiUrl
// (rundownsApiUrl is .../wp-json/bostonchefs/v1/rundown-terms)

const TABLE_NAME = "Rundowns";

const FIELD_NAME = "Rundown";
const FIELD_WP_ID = "WP Rundown Term ID";
const FIELD_WP_SLUG = "WP Slug";
const FIELD_ACTIVE = "WP Active";
const FIELD_LAST_SYNCED = "WP Last Synced";

const FIELD_STATUS = "WP Status";
const FIELD_YEAR = "WP Year";
const FIELD_TYPE = "Rundown Type";
const FIELD_URL = "WP URL";
const FIELD_RESTAURANT_COUNT = "WP Restaurant Count";
const FIELD_CURRENT_ID = "Current Rundown ID";

// WordPress value → Airtable single-select option name
const STATUS_OPTIONS = { current: "Current", archived: "Archived" };
const TYPE_OPTIONS = { holiday: "Holiday", feature: "Feature", standard: "Standard" };


// ------------------------------------------------------
// Check the table before calling WordPress
// ------------------------------------------------------

const table = base.getTable(TABLE_NAME);

const requiredFields = [
    FIELD_NAME, FIELD_WP_ID, FIELD_WP_SLUG, FIELD_ACTIVE, FIELD_LAST_SYNCED,
    FIELD_STATUS, FIELD_YEAR, FIELD_TYPE, FIELD_URL,
    FIELD_RESTAURANT_COUNT, FIELD_CURRENT_ID
];

const fieldNames = new Set(table.fields.map(field => field.name));
const missingFields = requiredFields.filter(name => !fieldNames.has(name));

if (missingFields.length > 0) {
    throw new Error(
        `The ${TABLE_NAME} table is missing: ${missingFields.join(", ")}`
    );
}

// Single-select options must already exist; the script can't add them.
function checkOptions(fieldName, optionNames) {
    const field = table.getField(fieldName);
    const existing = new Set(
        (field.options?.choices || []).map(choice => choice.name)
    );
    const missing = optionNames.filter(name => !existing.has(name));

    if (missing.length > 0) {
        throw new Error(
            `${fieldName} needs these options: ${missing.join(", ")}`
        );
    }
}

checkOptions(FIELD_STATUS, Object.values(STATUS_OPTIONS));
checkOptions(FIELD_TYPE, Object.values(TYPE_OPTIONS));

// Current Rundown ID can be a number, text, or a link to this table.
const currentIdField = table.getField(FIELD_CURRENT_ID);
const CURRENT_ID_IS_LINK = currentIdField.type === "multipleRecordLinks";
const CURRENT_ID_IS_TEXT = ["singleLineText", "multilineText"].includes(currentIdField.type);

if (
    currentIdField.type !== "number" &&
    !CURRENT_ID_IS_TEXT &&
    !CURRENT_ID_IS_LINK
) {
    throw new Error(
        `${FIELD_CURRENT_ID} must be a number, text, or a link to ${TABLE_NAME}. It is ${currentIdField.type}.`
    );
}

if (
    CURRENT_ID_IS_LINK &&
    currentIdField.options?.linkedTableId !== table.id
) {
    throw new Error(
        `${FIELD_CURRENT_ID} links to another table. It must link to ${TABLE_NAME}.`
    );
}


// ------------------------------------------------------
// WordPress credentials
// ------------------------------------------------------

const wpUsername = input.secret("wpUser");
const wpPassword = input.secret("wpPassword");
const wpApiUrl = input.secret("rundownsApiUrl");

if (!wpUsername || !wpPassword || !wpApiUrl) {
    throw new Error("Missing WordPress API credentials.");
}

const cleanPassword = wpPassword.replace(/\s+/g, "");

const auth = Buffer
    .from(`${wpUsername}:${cleanPassword}`)
    .toString("base64");


// ------------------------------------------------------
// Fetch Rundown terms from WordPress
// ------------------------------------------------------

const response = await fetch(wpApiUrl, {
    method: "GET",
    headers: {
        "Authorization": `Basic ${auth}`,
        "Content-Type": "application/json"
    }
});

if (!response.ok) {

    let errorMessage =
        `WordPress returned HTTP ${response.status}`;

    try {
        const errorData = await response.json();

        if (errorData?.message) {
            errorMessage = errorData.message;
        }
    } catch (e) {}

    throw new Error(errorMessage);
}

const wpRundowns = await response.json();

if (!Array.isArray(wpRundowns)) {
    throw new Error(
        "WordPress Rundown response was not an array."
    );
}

console.log(
    `Received ${wpRundowns.length} Rundowns from WordPress.`
);


// ------------------------------------------------------
// Load existing Airtable Rundowns
// ------------------------------------------------------

const query = await table.selectRecordsAsync({
    fields: [FIELD_WP_ID]
});


// ------------------------------------------------------
// Index existing Airtable records by WP Rundown Term ID
// ------------------------------------------------------

const existingByWpId = new Map();

for (const record of query.records) {

    const wpId = record.getCellValue(FIELD_WP_ID);

    if (wpId !== null && wpId !== undefined) {
        existingByWpId.set(
            String(wpId),
            record
        );
    }
}


// ------------------------------------------------------
// Build set of current WordPress Rundown IDs
// ------------------------------------------------------

const wpIds = new Set(
    wpRundowns.map(
        rundown => String(rundown.id)
    )
);


// ------------------------------------------------------
// Map one WordPress rundown to Airtable fields
// ------------------------------------------------------

// Number or null. WordPress sends null when there is no value.
function numberOrNull(value) {
    return value === null || value === undefined ? null : Number(value);
}

// Single-select value or null for a value Airtable has no option for.
function selectOption(options, value) {
    const name = options[value];
    return name ? { name } : null;
}

function rundownFields(rundown, now) {
    const fields = {
        [FIELD_NAME]: rundown.name,
        [FIELD_WP_ID]: Number(rundown.id),
        [FIELD_ACTIVE]: true,
        [FIELD_LAST_SYNCED]: now,
        [FIELD_STATUS]: selectOption(STATUS_OPTIONS, rundown.status),
        [FIELD_YEAR]: numberOrNull(rundown.year),
        [FIELD_TYPE]: selectOption(TYPE_OPTIONS, rundown.type),
        [FIELD_RESTAURANT_COUNT]: numberOrNull(rundown.restaurant_count),
        // Archived rundowns have no public page
        [FIELD_URL]: rundown.url || null
    };

    // A link is filled in separately, once the linked record is known.
    if (!CURRENT_ID_IS_LINK) {
        const currentId = numberOrNull(rundown.current_rundown_id);
        fields[FIELD_CURRENT_ID] =
            CURRENT_ID_IS_TEXT && currentId !== null ? String(currentId) : currentId;
    }

    if (rundown.slug) {
        fields[FIELD_WP_SLUG] = rundown.slug;
    }

    return fields;
}


// ------------------------------------------------------
// Prepare creates + updates
// ------------------------------------------------------

const now = new Date().toISOString();

const creates = [];
const updates = [];

let createdCount = 0;
let updatedCount = 0;
let inactiveCount = 0;
let archivedCount = 0;


// ------------------------------------------------------
// Create new Rundowns or update existing Rundowns
// ------------------------------------------------------

for (const rundown of wpRundowns) {

    if (!rundown?.id || !rundown?.name) {

        console.log(
            "Skipping invalid Rundown:",
            rundown
        );

        continue;
    }

    if (rundown.status === "archived") {
        archivedCount++;
    }

    const wpId = String(rundown.id);

    const existingRecord =
        existingByWpId.get(wpId);

    const fields = rundownFields(rundown, now);

    if (existingRecord) {

        updates.push({
            id: existingRecord.id,
            fields
        });

        updatedCount++;

    } else {

        creates.push({
            fields
        });

        createdCount++;
    }
}


// ------------------------------------------------------
// Mark Rundowns missing from WordPress as inactive
// ------------------------------------------------------

for (const record of query.records) {

    const wpId =
        record.getCellValue(FIELD_WP_ID);

    if (
        wpId === null ||
        wpId === undefined
    ) {
        continue;
    }

    if (!wpIds.has(String(wpId))) {

        updates.push({
            id: record.id,
            fields: {
                [FIELD_ACTIVE]: false,
                [FIELD_LAST_SYNCED]: now
            }
        });

        inactiveCount++;
    }
}


// ------------------------------------------------------
// Write changes in batches
// ------------------------------------------------------

// Creates first, so their new record IDs are known for links below.
const recordIdByWpId = new Map(
    [...existingByWpId].map(([wpId, record]) => [wpId, record.id])
);

while (creates.length > 0) {

    const batch = creates.splice(0, 50);
    const newIds = await table.createRecordsAsync(batch);

    batch.forEach((create, i) => {
        recordIdByWpId.set(
            String(create.fields[FIELD_WP_ID]),
            newIds[i]
        );
    });
}

while (updates.length > 0) {

    await table.updateRecordsAsync(
        updates.splice(0, 50)
    );
}


// ------------------------------------------------------
// Link archived years to their live rundown
// ------------------------------------------------------

let linkedCount = 0;

if (CURRENT_ID_IS_LINK) {

    const linkUpdates = [];

    for (const rundown of wpRundowns) {

        const recordId = recordIdByWpId.get(String(rundown?.id));

        if (!recordId) {
            continue;
        }

        let link = [];

        if (rundown.current_rundown_id) {

            const liveRecordId = recordIdByWpId.get(
                String(rundown.current_rundown_id)
            );

            if (liveRecordId) {
                link = [{ id: liveRecordId }];
                linkedCount++;
            } else {
                console.log(
                    `No Airtable record for live rundown ${rundown.current_rundown_id} (from ${rundown.name}).`
                );
            }
        }

        linkUpdates.push({
            id: recordId,
            fields: { [FIELD_CURRENT_ID]: link }
        });
    }

    while (linkUpdates.length > 0) {

        await table.updateRecordsAsync(
            linkUpdates.splice(0, 50)
        );
    }
}


// ------------------------------------------------------
// Automation outputs
// ------------------------------------------------------

output.set("success", true);
output.set("rundownCount", wpRundowns.length);
output.set("createdCount", createdCount);
output.set("updatedCount", updatedCount);
output.set("inactiveCount", inactiveCount);
output.set("archivedCount", archivedCount);
output.set("linkedCount", linkedCount);

console.log("Rundown sync complete.");
console.log(`Created: ${createdCount}`);
console.log(`Updated: ${updatedCount}`);
console.log(`Marked inactive: ${inactiveCount}`);
console.log(`Archived years: ${archivedCount}`);
