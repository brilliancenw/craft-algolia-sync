# Algolia Sync Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.2.4-beta - 2026-04-30
### Fixed
- Fixed "Undefined array key 0" error when saving elements not configured for Algolia sync (e.g., nested entries in matrix fields)
- Added empty array check before accessing index in `algoliaElementSynced()` and `prepareAlgoliaSyncElement()`

## 1.0.0 - 2018-11-23
### Added
- Initial release
