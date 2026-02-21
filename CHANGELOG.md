# Changelog

All notable changes to Media Usage Inspector will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-01-21

### Added
- Initial release of Media Usage Inspector
- **Comprehensive Media Scanning**
  - Post content scanning (Classic Editor and Gutenberg)
  - Featured image detection
  - Gutenberg block parsing (image, gallery, cover, media & text)
  - ACF field support (image, gallery, file, repeater, flexible content)
  - Widget scanning (image widgets, text widgets, custom HTML)
  - Options table scanning (theme mods, plugin settings)
  - Shortcode detection
- **Background Processing**
  - WordPress Cron-based scanning
  - Browser-independent operation
  - Automatic batch size adjustment
- **Pause & Resume**
  - Pause scanning at any time
  - Resume from where you left off
  - Stop and reset functionality
- **Resource Management**
  - Auto mode with adaptive resource usage
  - Low resources mode for shared hosting
  - High performance mode for dedicated servers
  - Memory and time limit monitoring
- **Fix Unattached Media**
  - Detect "used but unattached" media
  - One-click bulk attachment
  - Automatic attachment during scan (optional)
- **Media Library Integration**
  - Usage count column in list view
  - Usage details in attachment modal
  - Mark as safe functionality
- **Reporting**
  - Dashboard with statistics
  - CSV export functionality
  - References by context breakdown
- **Developer Features**
  - REST API endpoints
  - WP-CLI commands
  - Extensible parser system
  - Action and filter hooks
- **Full Uninstall Support**
  - Clean removal of all plugin data
  - Database tables dropped
  - Options and transients removed

### Security
- Nonce verification on all AJAX requests
- Capability checks for admin actions
- Prepared SQL statements throughout
- Input sanitization and output escaping

[1.0.0]: https://github.com/developer/media-usage-inspector/releases/tag/v1.0.0
