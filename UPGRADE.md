# MailOdds WordPress Plugin: Upgrade Guide

## Automatic Updates (Recommended)

The plugin checks the [mailodds/wordpress-plugin](https://github.com/mailodds/wordpress-plugin) GitHub repository for new releases every 12 hours. When a new version is available, WordPress shows an update notification in the Plugins screen. Click **Update Now** to apply it.

Automatic updates follow the standard WordPress plugin update flow:

1. WordPress downloads the release ZIP from GitHub
2. The old plugin directory is replaced with the new files
3. The plugin is reactivated automatically

No manual intervention is required. Settings, user meta, and cached data are preserved because they live in the database, not in plugin files.

## Manual Upgrade

If automatic updates are unavailable (firewalled environments, custom deployments), follow these steps:

1. **Deactivate** the plugin from Plugins > Installed Plugins
2. **Delete** (or rename) the existing `wp-content/plugins/mailodds/` directory
3. **Download** the latest release ZIP from [GitHub Releases](https://github.com/mailodds/wordpress-plugin/releases/latest)
4. **Extract** the ZIP and upload the `mailodds/` folder to `wp-content/plugins/`
5. **Activate** the plugin from Plugins > Installed Plugins
6. **Verify** your settings at Settings > MailOdds (they are stored in the database and survive file replacement)

## Database and Storage

The plugin does not create custom database tables. All data is stored in standard WordPress locations:

| Data | Storage | Scope |
|------|---------|-------|
| API key, settings, integrations, cron config | `wp_options` | Per-site |
| Validation cache (24h TTL) | `wp_options` (transients) | Per-site |
| User validation results | `wp_usermeta` (`_mailodds_status`, `_mailodds_action`, `_mailodds_validated_at`) | Shared |
| Daily stats | `wp_options` (`mailodds_daily_stats`) | Per-site |
| Cron job ID (in-progress jobs) | `wp_options` (`mailodds_cron_job_id`) | Per-site |
| GitHub release cache | `wp_options` (transient, 12h TTL) | Per-site |

Because there are no custom tables, there are no database migrations to run when upgrading. All existing data is preserved across version bumps.

## What Happens During Upgrade

- **Settings**: Preserved. Stored in `wp_options`, untouched by file replacement.
- **Transient cache**: Preserved. Cached validation results continue to serve until they expire (24h).
- **User meta**: Preserved. Validation status on user profiles is not affected.
- **Cron schedule**: If the plugin is deactivated during manual upgrade, the cron schedule is cleared by the deactivation hook. It is re-registered on activation if cron was enabled in settings.
- **In-progress bulk jobs**: If a bulk validation job is running on the MailOdds API when you upgrade, the job continues server-side. After reactivation, the cron check phase will pick it up if the job ID is still stored in `wp_options`.

## Version-Specific Notes

### 2.1.0

- Added dependency checks for PHP version and curl extension (admin notices)
- No settings changes, no data format changes

### 2.0.0

- Added one-click store connect (PKCE OAuth flow)
- Added product catalog sync
- New options: `mailodds_store_id`, `mailodds_connected_via`, `mailodds_pixel_uuid`, `mailodds_webhook_secret`
- No breaking changes to existing settings or user meta

### 1.x to 2.x

- The `mailodds_integrations` option format did not change
- The `_mailodds_status` and `_mailodds_action` user meta format did not change
- No data migration required

## Breaking Change Policy

The plugin follows these rules for breaking changes:

1. **Option keys** (`mailodds_*` in `wp_options`) are never renamed or removed without a migration path. If an option key must change, the new version reads both the old and new key during a transition period.

2. **User meta keys** (`_mailodds_status`, `_mailodds_action`, `_mailodds_validated_at`) are stable. Their values match the MailOdds API response format. If the API adds new status or action values, the plugin accepts them without requiring an upgrade.

3. **Transient cache format** may change between major versions. Stale transients from an older format are harmless because they expire within 24 hours. If you need to clear them immediately after upgrade, use WP-CLI:

   ```bash
   wp transient delete --all
   ```

   Or flush only MailOdds transients via the Settings page (changing the API key triggers a cache flush).

4. **Hook names** (`registration_errors`, `woocommerce_after_checkout_validation`, etc.) are WordPress core or third-party hooks and are not controlled by this plugin. The plugin's own action and filter names are considered stable and will not be renamed in minor versions.

5. **WP-CLI commands** (`wp mailodds *`) follow the same stability guarantee as the REST API. Subcommands and options are not removed in minor versions.

## Rollback

To roll back to a previous version:

1. Download the specific version from [GitHub Releases](https://github.com/mailodds/wordpress-plugin/releases)
2. Follow the manual upgrade steps above using the older release ZIP
3. Settings are compatible across all 2.x versions
