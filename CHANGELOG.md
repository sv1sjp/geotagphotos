# Changelog

All notable changes to GeoTag Photos are documented here.

## [1.0.7] — June 2026

### Added
- File action "Add Geolocation Tag" for JPEG files in the Nextcloud Files app
- Modal dialog to add or replace GPS coordinates (latitude, longitude, optional altitude)
- Pre-loads existing GPS data for single-file action (shows current coords)
- "Clear GPS" button to remove all GPS EXIF tags from a photo
- Smart coordinate paste button that normalizes copied map coordinates into the expected decimal format (latitude, longitude) for faster paste-in from external map apps such as OpenStreetMap or Google Maps
- Batch mode: select multiple JPEGs and apply the same coordinates to all
- exiftool-based EXIF writing (only GPS tags, all other metadata preserved)
- Nextcloud file-cache refresh after each write so Maps can detect changes
- Input validation for coordinate ranges (lat: −90…+90, lon: −180…+180)
- Security: proc_open array execution (no shell, no injection risk)
- Security: MIME type guard on the PHP side (JPEG only)
- Security: user-scoped file access via IRootFolder
