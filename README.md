# Substack Sync for WordPress

A WordPress plugin that automatically syncs Substack newsletter content to a self-hosted WordPress site.

This is a fork of the original [Substack Sync](https://www.christopherspenn.com/2025/08/substack-sync-for-wordpress/) by Christopher S. Penn, with additional bug fixes and hardening. It is maintained for use on a single site.

- **Original author:** Christopher S. Penn (https://www.christopherspenn.com/)
- **Fork maintainer:** Noah Welch
- **Version:** 1.1.1
- **License:** Apache-2.0

## Description

Substack Sync imports posts from a Substack RSS feed into WordPress and keeps existing posts updated, giving you a self-hosted archive of your newsletter content with true ownership.

### Key Features

- **Automated Synchronization:** Hourly cron job fetches new content from the Substack RSS feed
- **Intelligent Content Management:** Imports new posts and updates existing ones with GUID-based tracking
- **Image Localization:** Sideloads each post image into the Media Library once (deduped by source URL), rewrites post content to serve the local copies, and sets the first as the featured image
- **Batch Processing:** Progressive sync system with detailed progress tracking and real-time status updates
- **Error Handling and Retry Logic:** Automatic retry system for failed imports (up to 3 attempts) with detailed error logging
- **Content Processing:** Removes Substack-specific elements and replaces them with customizable subscription links
- **Category Mapping:** Keyword-based automatic category assignment
- **Rollback Functionality:** Remove imported posts (all, failed only, or by date range)
- **Admin Interface:** Tabbed dashboard with statistics, manual sync controls, and activity logs
- **Custom Database Logging:** Tracking with retry counts, error messages, and modification timestamps

## Installation

1. Upload the `substack-sync` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Substack Sync to configure your RSS feed URL

## Configuration

Navigate to **Settings > Substack Sync** in your WordPress admin to configure:

#### General Settings Tab
- **RSS Feed URL:** Your Substack feed URL (e.g., https://yourname.substack.com/feed)
- **Default Author:** WordPress user to assign as author for imported posts
- **Default Post Status:** Import posts as Draft or Published
- **Category Mapping:** Keyword-based automatic category assignment with dynamic row management
- **Data Cleanup:** Option to delete plugin data on uninstall

#### Sync & Import Tab
- **Manual Sync:** Trigger immediate synchronization with real-time progress tracking
- **Batch Processing:** Process posts individually with detailed status for each item
- **Retry Failed Posts:** Reset and retry posts that encountered errors during sync
- **Statistics Dashboard:** Visual overview of total synced, imported, updated, and error counts

#### Manage Posts Tab
- **Rollback Options:** Remove all synced posts, failed posts only, or posts within a date range
- **Destructive Action Warnings:** Clear confirmation dialogs for all destructive operations

#### Logs & Statistics Tab
- **Failed Posts List:** Detailed view of posts with sync errors and retry counts
- **Activity Log:** Real-time sync activity with color-coded status indicators
- **Sync Statistics:** Metrics including last sync date and performance data

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Tested up to WordPress 7.0

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test
```

## File Structure

```
substack-sync/
├── substack-sync.php                 # Main plugin file
├── uninstall.php                     # Uninstallation handler
├── admin/
│   └── class-substack-sync-admin.php # Admin interface
└── includes/
    ├── class-substack-sync-activator.php   # Plugin activation
    ├── class-substack-sync-deactivator.php # Plugin deactivation
    ├── class-substack-sync-cron.php        # Cron job management
    └── class-substack-sync-processor.php   # Core sync logic
```

## How It Works

### Automated Synchronization Process
1. **Scheduled Sync:** WordPress cron runs hourly to check for new content
2. **Feed Processing:** Fetches and parses the Substack RSS feed using WordPress core functions
3. **GUID Tracking:** Compares Substack post GUIDs against the database to identify new/updated content
4. **Content Import:** Creates new WordPress posts or updates existing ones based on GUID matching
5. **Media Handling:** Sideloads each image once via `media_sideload_image()` (deduped by source URL), rewrites content to serve the local copies, and sets the first as the featured image
6. **Content Processing:** Removes Substack-specific elements (subscription boxes, like buttons) and adds custom subscription links
7. **Category Assignment:** Applies keyword-based category mapping if configured
8. **Error Handling:** Logs failures with detailed error messages and retry tracking

### Manual Sync Process
1. **AJAX-Powered Interface:** Real-time progress tracking with post-by-post status updates
2. **Progressive Processing:** Handles large feeds without timeout issues using batch processing
3. **Error Recovery:** Retry failed posts with reset retry counts

### Rollback & Management
1. **Flexible Rollback:** Remove all posts, failed posts only, or posts within date ranges
2. **Safe Deletion:** Confirmation dialogs prevent accidental data loss
3. **Database Cleanup:** Removes both WordPress posts and sync log entries

## Database Schema

The plugin creates a custom table `wp_substack_sync_log` with the following structure:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT AUTO_INCREMENT | Primary key |
| `post_id` | INT | WordPress post ID (0 for failed imports) |
| `substack_guid` | VARCHAR(255) | Unique Substack post identifier |
| `substack_title` | TEXT | Post title for reference and error reporting |
| `sync_date` | DATETIME | Initial sync timestamp |
| `last_modified` | DATETIME | Last update timestamp |
| `status` | VARCHAR(20) | Sync status: 'imported', 'updated', 'error' |
| `retry_count` | INT | Number of retry attempts (max 3) |
| `error_message` | TEXT | Detailed error information for troubleshooting |

**Indexes:**
- Primary key on `id`
- Unique index on `substack_guid`
- Index on `status` for efficient filtering
- Index on `sync_date` for chronological queries

## License

Apache License Version 2.0. See the LICENSE file for details.
