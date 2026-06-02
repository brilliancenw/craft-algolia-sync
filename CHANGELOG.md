# Algolia Sync Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.4.0 - 2026-06-02

### Added
- **Matrix field support**: Matrix field content is now indexed automatically, with no per-field configuration, including nested Matrix fields at unlimited depth. Each Matrix field produces a flattened searchable text attribute (`<handle>_text`) and a de-duplicated list of block types (`<handle>_blockTypes`) for faceting.
- **Include Structured Matrix Payload** setting (off by default) to also store the full nested block structure on the record for front-ends that render results directly from Algolia.
- **Sync Matrix Fields**, **Matrix Maximum Depth**, and **Record Size Budget** settings to control Matrix indexing behavior.

### Changed
- Records that exceed the configured size budget are now gracefully degraded (structured payloads dropped, then long text trimmed) instead of failing, keeping records under Algolia's size limit.

### Fixed
- Date fields that are empty no longer generate derived date attributes from a null value.

## 5.3.0 - 2026-06-02

First stable release for Craft CMS 5, consolidating the 5.x beta line.

> {warning} Upgrading from 4.x: This release requires Craft CMS 5.0+ and PHP 8.2+, and upgrades the underlying Algolia PHP API client to v4. Multi-site installs now index each element once per site using a per-site `objectID` (for example `123-2`, `123-3`), and the default site keeps the legacy `123` format unless **Append Site ID to Default Site Object IDs** is enabled. After upgrading, re-index your content and run the Cleanup Stale Records utility to remove any records left behind by the previous object ID format.

### Added
- **Multi-site support**: Each element is indexed separately per site with a unique `objectID`, plus `siteId`, `siteHandle`, and `siteLanguage` attributes for filtering. Elements can be enabled on one site and disabled on another.
- **Append Site ID to Default Site Object IDs** setting to opt the default site into the `123-1` object ID format for consistency across sites.
- **Stale record cleanup**: A queue-based cleanup system that removes records for elements that have been deleted, disabled, or expired in Craft. Available as the `algolia-sync/default/cleanup-stale-records` console command and as a control panel utility.
- **Expiry date handling**: Elements are automatically removed from Algolia when their expiry date passes.
- **Configurable queue priority**: Control where Algolia Sync jobs sit in Craft's queue (Settings > Plugins > Algolia Sync). Default is 50; lower values run sooner. Valid range is 1-9999.
- **Universal indexing**: Multiple element types can be configured to use a single shared index, with an `elementType` attribute for filtered searches.
- Support for Craft Commerce 5 products and variants, including variant attributes.
- Support for the CKEditor field type.
- Environment variable support for custom index name overrides (for example `$ORGANIZATIONS_INDEX_NAME`).

### Changed
- Updated for Craft CMS 5 (API method calls now use property accessors and the `entries` service).
- Upgraded to the Algolia PHP API client v4.
- Drafts and revisions are no longer synced; only canonical elements are sent to Algolia, on both save and delete.
- Custom index overrides that reference an undefined environment variable are now skipped with a log message instead of being sent to Algolia as an empty index. This prevents queue jobs from failing with a `indexName is required` error and avoids misrouting data to a default index.

### Fixed
- Fixed multi-site sync issues including duplicate record syncing, missing content for some enabled sites, and incorrect handling of bulk enable/disable operations.
- Fixed the cleanup process when multiple element types share a single universal index.
- Fixed "Undefined array key 0" error when saving elements not configured for Algolia sync (for example, nested entries in Matrix fields).

## 1.0.0 - 2018-11-23
### Added
- Initial release
