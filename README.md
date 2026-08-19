# Substack Sync for WordPress

A WordPress plugin that automatically syncs Substack newsletter content to a self-hosted WordPress site.

This is a fork of the original [Substack Sync](https://www.christopherspenn.com/2025/08/substack-sync-for-wordpress/) by Christopher S. Penn, with additional bug fixes and hardening. It is maintained for use on a single site.

- **Original author:** Christopher S. Penn (https://www.christopherspenn.com/)
- **Fork maintainer:** Noah Welch
- **Version:** 1.3.1
- **License:** Apache-2.0

## Description

Substack Sync imports posts from a Substack RSS feed into WordPress and keeps existing posts updated, giving you a self-hosted archive of your newsletter content with true ownership.

### Key Features

- **Automated Synchronization:** Hourly cron job fetches new content from the Substack RSS feed
- **Intelligent Content Management:** Imports new posts and updates existing ones with GUID-based tracking
- **Image Localization:** Sideloads each post image into the Media Library once (deduped by source URL), rewrites post content to serve the local copies, and sets the first as the featured image
- **Video Embeds:** Rewrites YouTube embeds into a linked thumbnail before sanitization, so video posts keep an image instead of losing the iframe to `wp_kses_post()`. Embeds are matched on the embed host rather than Substack's wrapper markup. The thumbnail takes the featured slot on the same terms as any other image: first in the post, and only when no featured image is set yet
- **Video Featured-Image Repair:** Video posts imported before the embed rewrite existed had picked an unrelated body photo as their featured image. A one-time pass re-points them at the video frame on the next sync, only where the video leads the post, and only when the image it replaces is one the plugin sideloaded, so a featured image uploaded from anywhere else survives. Note the limit of that test: an editor who picked a different Substack image out of the media library looks identical to the plugin having set it, and the pass will override that choice. The pass waits while work is still outstanding, then stops after five hourly syncs that repaired nothing, so neither a pass still making progress nor a few clicks of Sync Now can cut it off. Giving up lists the posts it could not repair on the Logs & Statistics tab, with a button to run it again. That list names only posts the pass would actually have repaired, and drops entries as you fix them. A post that has aged out of the feed is never rewritten, so no sync will reach it and its featured image has to be set by hand: clearing the thumbnail only leaves the post without one
- **Batch Processing:** Progressive sync system with detailed progress tracking and real-time status updates
- **Error Handling and Retry Logic:** Automatic retry system for failed imports (up to 3 attempts) with detailed error logging. A post that exhausts its attempts stops being retried automatically but stays listed and stays resettable, since those are the ones that need a person
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
- **Retry Failed Posts:** Reset and retry every post that encountered errors during sync, including those past the retry ceiling. This also re-arms the one-time video featured-image repair, since a reset is what puts those posts back in reach of it. The repair's existing list of posts it could not fix stays on screen, since that list is a worklist and this button is not about it
- **Statistics Dashboard:** Visual overview of total synced, imported, updated, and error counts

#### Manage Posts Tab
- **Rollback Options:** Remove all synced posts, failed posts only, or posts within a date range
- **Destructive Action Warnings:** Clear confirmation dialogs for all destructive operations

#### Logs & Statistics Tab
- **Failed Posts List:** Detailed view of posts with sync errors and retry counts, newest first, including the ones past the retry ceiling
- **Activity Log:** Real-time sync activity with color-coded status indicators
- **Sync Statistics:** Metrics including last sync date and performance data

## Requirements

- WordPress 6.5 or higher
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
6. **Content Processing:** Removes Substack-specific elements (subscription boxes, like buttons), adds custom subscription links, and swaps YouTube embeds for a linked thumbnail
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
