# Algolia Sync plugin for Craft CMS

Syncing elements with Algolia using their API

![Screenshot](./docs/img/algolia-sync-banner.jpg)

## Requirements

This plugin requires Craft CMS 5.0 or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require brilliancenw/craft-algolia-sync

3. In the Control Panel, go to Settings → Plugins and click the "Install" button for Algolia Sync.

4. In your .env file, add the Algolia Application ID, the Search Only API Key and the Admin API Key:

        ALGOLIA_APP=your_application_id
        ALGOLIA_SEARCH=your_search_only_api_key
        ALGOLIA_ADMIN=your_admin_api_key

5. Configure which element types should be synced with Algolia in Settings → Algolia Sync. If the default index names do not work for you, enter custom index names. These values can use environment variables.

## How Algolia Sync Works

When any record is added, edited, deleted, or expires, the Algolia Sync plugin will automatically sync changes to Algolia. If that element type is configured to be synced, it will package the data and send it to the associated Algolia Index.

### Automatic Syncing

- **Save/Update**: When an element is saved, its data is synced to Algolia
- **Delete**: When an element is deleted, it is removed from Algolia
- **Disable**: When an element is disabled, it is removed from Algolia
- **Expiry**: When an element's expiry date passes, it is automatically removed from Algolia

### Event System

Prior to sending data to Algolia, an event is fired that allows you to intercept and modify the data without changing the actual Craft element.

Here's an example of updating a field value before sync:

```php
Event::on(
    \brilliance\algoliasync\services\AlgoliaSyncService::class,
    \brilliance\algoliasync\services\AlgoliaSyncService::EVENT_BEFORE_ALGOLIA_SYNC,
    function (\brilliance\algoliasync\events\beforeAlgoliaSyncEvent $event) {
        // Modify field data
        $event->recordUpdate['attributes']['myCustomFieldHandle'] = "Updated content";

        // Prevent a record from syncing
        // $event->recordUpdate['processAlgoliaSync'] = false;
    }
);
```

## Multi-Site Support

**Note:** Multi-site functionality is available in plugin version 5.x for Craft CMS 5 only. Earlier versions do not include multi-site support.

### How Multi-Site Indexing Works

Each element in a multi-site setup is indexed separately per site with a unique `objectID`. This allows each site's version of an element to have different content, fields, or enabled status.

**ObjectID Format:**
- Default site (with setting OFF): `123`
- Default site (with setting ON): `123-1`
- Other sites: `123-2`, `123-3`, etc.

Each record includes these site-specific attributes:
- `objectID`: Unique identifier per site
- `siteId`: The site ID (e.g., 1, 2, 3)
- `siteHandle`: The site handle (e.g., "default", "french", "german")
- `siteLanguage`: The site language (e.g., "en-US", "fr-CA")

### Multi-Site Options Setting

**Append Site ID to Default Site Object IDs** (Settings → Algolia Sync → Multi-Site Options)

This setting controls backward compatibility for existing single-site installations:

- **OFF (default)**: Default site uses objectID format `123` (backward compatible)
- **ON**: Default site uses objectID format `123-1` (consistent with other sites)

**Important:** Changing this setting after initial setup may create duplicate records in Algolia. Use the Cleanup Stale Records utility to remove old records.

### Filtering by Site

To filter search results for a specific site:

```js
// Filter by site handle
index.search(query, {
    filters: 'siteHandle:default'
});

// Filter by site ID
index.search(query, {
    filters: 'siteId:1'
});

// Filter by language
index.search(query, {
    filters: 'siteLanguage:en-US'
});
```

### Per-Site Content Management

- **Saving**: When you save an element, only that site's version is synced to Algolia
- **Deleting**: When you delete an element, it is removed from Algolia for all sites
- **Per-Site Enable/Disable**: You can enable an element on one site and disable it on another. The disabled site's record will be removed from Algolia.

## Using One Index for Multiple Content Types

You can configure multiple element types (entries, products, categories, etc.) to use the same Algolia index. This powerful feature enables:

### Universal Search

Index all your content types into one index for a site-wide search:

```
Index Name: site_universal_search

Configured Elements:
- Entries: Blog Posts → site_universal_search
- Entries: News Articles → site_universal_search
- Products: Equipment → site_universal_search
- Categories: Topics → site_universal_search
```

### Filtered Search by Type

Even with all content in one index, you can filter by `elementType` to show only specific content:

```js
// Search only blog posts in universal index
index.search(query, {
    filters: 'elementType:Entry AND sectionHandle:blog'
});

// Search only products
index.search(query, {
    filters: 'elementType:Product'
});

// Universal search (no filter)
index.search(query);
```

### Use Case Example

**Site-wide search bar** (top navigation):
- Uses universal index with no filters
- Shows all content types in results

**Blog page search**:
- Uses same universal index
- Adds filter: `filters: 'elementType:Entry AND sectionHandle:blog'`
- Shows only blog posts

This approach reduces the number of Algolia indexes needed and simplifies your search implementation while maintaining flexibility.

## Cleanup Stale Records

Over time, Algolia indexes may contain stale records from deleted, disabled, or expired elements. The plugin provides cleanup tools to maintain index accuracy.

### Using the Cleanup Utility

1. Navigate to **Utilities → Algolia Sync Utility**
2. Click **Cleanup Stale Records** button
3. The system will scan all configured indexes and queue deletions for:
   - Deleted elements
   - Disabled elements
   - Expired elements (past their expiry date)

### Using the Console Command

```bash
php craft algolia-sync/default/cleanup-stale-records
```

The command provides detailed output showing which records were found and queued for deletion.

### Automating with Cron

For automatic cleanup of expired records, set up a cron job:

```bash
# Run daily at 2:00 AM
0 2 * * * cd /path/to/project && php craft algolia-sync/default/cleanup-stale-records

# Run every 6 hours
0 */6 * * * cd /path/to/project && php craft algolia-sync/default/cleanup-stale-records
```

## Supported Field Types

### Asset Fields
When a record has an Asset field:
- **Single asset**: Returns the URL as a string
- **Multiple assets**: Returns an array of URLs

This makes featured images easy to use in Algolia search results.

### Date Fields
For every date field, three values are sent to Algolia:
1. Unix timestamp of the date
2. Friendly format (`m/d/Y`)
3. Unix timestamp at midnight (useful for date range searches)

Example for a field named `eventDate`:
- `eventDate`: 1609459200
- `eventDate_friendly`: "01/01/2021"
- `eventDate_midnight`: 1609459200

### Category / User / Entry Fields
For relational fields, both titles and IDs are sent:

Example with a Category field named "Genre" with 3 categories selected:
- `Genre`: ["Action", "Drama", "Comedy"]
- `GenreIds`: [1, 2, 3]

This enables complex filtering and display in InstantSearch.js.

### Categories with Hierarchy
Categories include hierarchical data for faceted navigation:

```json
{
    "categories_lvl0": "Electronics",
    "categories_lvl1": "Electronics > Computers",
    "categories_lvl2": "Electronics > Computers > Laptops"
}
```

### Commerce Products
Product records include:
- All product-level attributes (SKU, price, availability)
- All variants nested under `variants[]` array
- Sale pricing and on-sale flags
- Stock information

### Matrix Fields

Matrix field content is indexed automatically, with no per-field configuration. Because Craft 5 Matrix blocks are nested entries that can themselves contain Matrix fields, content of virtually unlimited depth is supported. The content is flattened into a search-friendly shape rather than mirrored as a deep object tree, which keeps records well within Algolia's size limit and gives reliable relevance and faceting.

For a Matrix field with the handle `documentation`, each record gets:

- `documentation_text` (string) - a flattened, searchable aggregate of the text from every block at every depth. Point your index's searchable attributes here.
- `documentation_blockTypes` (array of strings) - a de-duplicated list of every block (entry type) handle found at any depth, ideal for faceting (for example, "show entries that contain a `callToAction` block").
- `documentation` (object) - the full nested block structure. This is **optional and off by default**; enable **Include Structured Matrix Payload** in the settings only if your front-end renders results directly from the Algolia record.

Example: a product with a `documentation` Matrix field containing three `document` blocks, where each document has its own `relatedDocuments` Matrix field, produces:

```json
{
  "documentation_text": "Installation Guide. How to install the Widget Pro. Quick Start. 5 minute setup. Safety Sheet. User Manual. Complete reference. Warranty. 2 year warranty terms. Claim Form.",
  "documentation_blockTypes": ["document", "relatedDoc"]
}
```

The flattened text answers "does this entry mention X?" perfectly at any depth. The optional structured payload is what preserves which related document belongs to which parent document, when you need that association for display.

Settings (under **Settings → Algolia Sync → Matrix Fields**):

- **Sync Matrix Fields** - master toggle (on by default).
- **Matrix Maximum Depth** - how many nested levels to traverse (default 10).
- **Record Size Budget (bytes)** - records over this size are gracefully degraded (structured payload dropped, then long text trimmed) rather than failing. Default 9000, under Algolia's ~10,000-byte limit.
- **Include Structured Matrix Payload** - emit the full nested object (off by default).

For per-field or per-entry customization, use the `EVENT_BEFORE_ALGOLIA_SYNC` event (see [Event System](#event-system)) to adjust the record before it is sent to Algolia.

> Note: CKEditor fields are indexed as their rendered text. Entries embedded inside a CKEditor field are not yet recursed into; this is planned for a future release.

> After enabling Matrix syncing on existing content, run a bulk load (below) so the new attributes are populated on records that have not been re-saved.

## Bulk Loading

To initially populate or refresh your Algolia indexes:

1. Navigate to **Utilities → Algolia Sync Utility**
2. Select which element types to bulk load
3. Click **Bulk Load Records**

The system processes records in batches using Craft's queue system to avoid timeouts.

## Configuration

### Default Index Names

Default index names follow the pattern: `{environment}_{elementType}_{handle}`

Examples:
- `dev_entry_blog`
- `production_product_equipment`
- `staging_category_topics`

### Custom Index Names

You can override default index names in Settings → Algolia Sync. Custom index names support environment variables:

```
$ALGOLIA_BLOG_INDEX
```

## Roadmap

Some things to do, and ideas for potential features:

- Configuration to exclude specific fields from sync
- Support for Matrix Fields
- Support for 3rd Party Field Types (tables, etc)
- Custom date format configuration per field

Pull Requests, Feature Requests and Bug Reports are welcome - please submit to the Git repository:
https://github.com/brilliancenw/craft-algolia-sync

Brought to you by [Brilliance](https://www.brilliancenw.com/)
