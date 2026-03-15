# MailOdds Email Validation for WordPress

Official WordPress plugin for the [MailOdds Email Validation API](https://mailodds.com). Validate emails on registration, WooCommerce checkout, and popular form plugins. Block fake signups, disposable emails, and invalid addresses in real time. Part of the [MailOdds email deliverability platform](https://mailodds.com/email-deliverability-platform).

[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-blue)](https://wordpress.org) [![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://www.php.net) [![License: GPL v2](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)

## Installation

1. Download the [latest release ZIP](https://github.com/mailodds/wordpress-plugin/releases/latest)
2. Upload the `mailodds` folder to `/wp-content/plugins/`
3. Activate via **Plugins** menu
4. Go to **Settings > MailOdds** and enter your API key

Get a free API key at [mailodds.com/register](https://mailodds.com/register) (includes 1,000 free validations). See [pricing](https://mailodds.com/pricing) for plan details.

Auto-updates are supported via the GitHub releases API. When a new version is published here, WordPress will notify you in the Plugins screen.

## Supported Forms

| Form | Hook Point |
|------|-----------|
| WordPress Registration | `registration_errors` |
| WooCommerce Registration | `woocommerce_registration_errors` |
| WooCommerce Checkout | `woocommerce_after_checkout_validation` |
| WPForms | `wpforms_process` |
| Gravity Forms | `gform_validation` |
| Contact Form 7 | `wpcf7_validate_email` |

Each integration is toggled individually in **Settings > MailOdds > Form Integrations**.

## How It Works

When a user submits a form with an email address, the plugin:

1. Optionally checks the suppression list (saves an API credit if the email is suppressed)
2. Checks the transient cache (24h TTL) for a previous result
3. If uncached, calls the MailOdds API with a 10-second timeout
4. Caches the result for 24 hours to save credits on retries
5. Blocks the form if the action is `reject` (configurable threshold)
6. If the API is unreachable, allows the form through (fail-open design)

## Configuration

### Settings (Settings > MailOdds)

| Setting | Options | Default |
|---------|---------|---------|
| **API Key** | `mo_live_*` or `mo_test_*` | Required |
| **Validation Depth** | Enhanced (full SMTP) / Standard (syntax + MX) | Enhanced |
| **Block Threshold** | Reject only / Reject + risky | Reject only |
| **Policy** | Dropdown populated from your MailOdds policies | None |
| **Suppression Pre-check** | Check suppression list before validating | Disabled |
| **Weekly Cron** | Validate unvalidated users weekly | Disabled |
| **Webhook Secret** | HMAC secret for async job completion webhooks | Disabled |
| **Telemetry Widget** | Show server-side telemetry in dashboard widget | Enabled |

**Reject only** blocks invalid and disposable emails. **Reject + risky** also blocks catch-all domains and role accounts (info@, admin@, etc).

### Suppression List (Tools > MailOdds Suppressions)

Manage your account suppression list directly from WordPress:

- View entries with search, type filter, and pagination
- Add or remove individual entries
- View suppression stats (total, by type)
- Enable suppression pre-check in Settings to automatically block suppressed emails before they consume API credits

### Validation Policies (Settings > MailOdds Policies)

Create and manage validation policies:

- List all policies with rule counts
- Create custom policies or use presets (strict, permissive, smtp_required)
- Add and remove individual rules
- Test a policy against any email address
- Select the active policy in the main Settings page

### Bulk Validation (Tools > MailOdds Bulk)

Validate all existing WordPress users:

- **Under 100 users**: Synchronous batch validation (immediate results)
- **100+ users**: Creates an async job via the MailOdds Jobs API with real-time progress polling
- Resume capability: reloading the page picks up where a running job left off
- Cancel button to abort in-progress jobs
- Job history table showing past bulk validation jobs

### Dashboard Widget

The dashboard widget shows validation statistics:

- **With telemetry enabled**: Server-side data from the MailOdds API (24h and 30d totals, deliverable/rejected/unknown counts, credits used, top rejection reasons)
- **With telemetry disabled**: Local stats tracked by the plugin (today and 7-day breakdown)
- Privacy safeguard: top domains are never cached (displayed from live API response only)

## WP REST API

Four endpoints are available under `/wp-json/mailodds/v1/`, all requiring `manage_options` capability:

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/validate` | Validate a single email |
| POST | `/validate/batch` | Validate up to 100 emails |
| POST | `/suppression/check` | Check if an email is suppressed |
| GET | `/status` | Plugin health and config |

## Webhook Receiver

The plugin can receive `job.completed` webhooks from MailOdds to instantly apply bulk validation results:

1. Set a webhook secret in **Settings > MailOdds > Advanced > Webhook Secret**
2. Configure the webhook URL in your MailOdds dashboard: `https://yoursite.com/wp-json/mailodds/v1/webhook`
3. When a job completes, results are automatically applied to WordPress user meta

The endpoint verifies HMAC-SHA256 signatures and is closed by default (rejects all requests when no secret is configured).

## WP-CLI Commands

```bash
# Single validation
wp mailodds validate user@example.com
wp mailodds validate user@example.com --depth=standard --format=json

# Bulk validate existing users
wp mailodds bulk
wp mailodds bulk --batch=100 --limit=500

# Plugin status
wp mailodds status

# Suppression list management
wp mailodds suppression list
wp mailodds suppression list --type=hard_bounce --per-page=50
wp mailodds suppression add spam@example.com
wp mailodds suppression add spam@example.com --type=complaint
wp mailodds suppression remove spam@example.com
wp mailodds suppression check user@example.com
wp mailodds suppression stats

# Bulk validation jobs
wp mailodds jobs list
wp mailodds jobs create
wp mailodds jobs status <job_id>
wp mailodds jobs results <job_id>
wp mailodds jobs cancel <job_id>

# Validation policies
wp mailodds policies list
wp mailodds policies create "My Policy"
wp mailodds policies create "Strict" --preset=strict
wp mailodds policies test user@example.com 42
wp mailodds policies delete 42
```

## Admin Features

- **Settings page**: API key, depth, threshold, policy dropdown, form toggles, cron, suppression pre-check, webhook secret, telemetry toggle
- **Dashboard widget**: Server-side telemetry with local stats fallback
- **Bulk validation**: Smart routing (sync for small batches, async jobs for large)
- **Suppression list page**: Full CRUD with search and filtering
- **Policies page**: Create, delete, preset, rules, test sandbox
- **User meta**: `_mailodds_status`, `_mailodds_action`, `_mailodds_validated_at`
- **Test mode badge**: Displays when using an `mo_test_` API key
- **Fail-open admin notice**: Warns when suppression checks bypass due to API errors
- **Auto-updates**: Checks GitHub releases every 12 hours

## Cron (Scheduled Validation)

When enabled, the plugin validates unvalidated users on a weekly schedule using a two-phase cron pattern optimized for shared hosting:

1. **Phase A (fire)**: Creates a validation job via the API and stores the job ID
2. **Phase B (check)**: Every 15 minutes, checks job status. When complete, applies results to user meta

This avoids long-running PHP processes that hit `max_execution_time` limits.

## Manual Integration (without plugin)

If you prefer to integrate directly in your theme, add to `wp-config.php`:

```php
define('MAILODDS_API_KEY', 'mo_live_your_api_key');
```

Then in `functions.php`:

```php
add_action('registration_errors', function($errors, $sanitized_user_login, $user_email) {
    if (!defined('MAILODDS_API_KEY')) {
        return $errors;
    }

    $cache_key = 'mailodds_' . substr(hash('sha256', strtolower($user_email)), 0, 16);
    $cached = get_transient($cache_key);

    if (false === $cached) {
        $response = wp_remote_post('https://api.mailodds.com/v1/validate', [
            'headers' => [
                'Authorization' => 'Bearer ' . MAILODDS_API_KEY,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode(['email' => $user_email]),
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return $errors; // Fail-open
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $cached = isset($body['result']) ? $body['result'] : $body;
        set_transient($cache_key, $cached, DAY_IN_SECONDS);
    }

    if (isset($cached['action']) && 'reject' === $cached['action']) {
        $errors->add('invalid_email',
            '<strong>Error:</strong> This email address could not be verified.');
    }

    return $errors;
}, 10, 3);
```

## Test Mode

Use an API key with the `mo_test_` prefix for development. Test mode:

- Does not consume credits
- Shows a test mode badge in the admin
- Uses predictable test domains:

| Domain | Result |
|--------|--------|
| `*@deliverable.mailodds.com` | valid / accept |
| `*@invalid.mailodds.com` | invalid / reject |
| `*@risky.mailodds.com` | catch_all / accept_with_caution |
| `*@disposable.mailodds.com` | do_not_mail / reject |

## Response Actions

The plugin branches on the `action` field from the API response:

| Action | Meaning | Plugin Behavior |
|--------|---------|----------------|
| `accept` | Safe to send | Allow form submission |
| `accept_with_caution` | Valid but risky (catch-all, role) | Allow or block (depends on threshold) |
| `reject` | Invalid or disposable | Block form submission |
| `retry_later` | Temporary failure | Allow (fail-open) |

## Requirements

- WordPress 5.9+
- PHP 7.4+
- MailOdds API key ([get one free](https://mailodds.com/register))

## Uninstall

Deactivating the plugin clears the cron schedule. Deleting the plugin removes all options, transients, and user meta from the database (clean uninstall).

## Links

- [MailOdds Integration Guide](https://mailodds.com/integrations/wordpress)
- [API Documentation](https://mailodds.com/docs)
- [Developer Quickstart](https://mailodds.com/developers)
- [All SDKs](https://mailodds.com/sdks)
- [Email Deliverability Platform](https://mailodds.com/email-deliverability-platform)
- [Email Deliverability Guide](https://mailodds.com/guides/email-deliverability)
- [Pricing](https://mailodds.com/pricing)

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
