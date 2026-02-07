# MailOdds Email Validation for WordPress

Official WordPress plugin for the [MailOdds Email Validation API](https://mailodds.com). Validate emails on registration, WooCommerce checkout, and popular form plugins. Block fake signups, disposable emails, and invalid addresses in real time.

[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-blue)](https://wordpress.org) [![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://www.php.net) [![License: GPL v2](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)

## Installation

1. Download the [latest release ZIP](https://github.com/mailodds/wordpress-plugin/releases/latest)
2. Upload the `mailodds` folder to `/wp-content/plugins/`
3. Activate via **Plugins** menu
4. Go to **Settings > MailOdds** and enter your API key

Get a free API key at [mailodds.com/register](https://mailodds.com/register) (includes 1,000 free validations).

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

1. Checks the transient cache (24h TTL) for a previous result
2. If uncached, calls the MailOdds API with a 5-second timeout
3. Caches the result for 24 hours to save credits on retries
4. Blocks the form if the action is `reject` (configurable threshold)
5. If the API is unreachable, allows the form through (fail-open design)

## Configuration

| Setting | Options | Default |
|---------|---------|---------|
| **API Key** | `mo_live_*` or `mo_test_*` | Required |
| **Validation Depth** | Enhanced (full SMTP) / Standard (syntax + MX) | Enhanced |
| **Block Threshold** | Reject only / Reject + risky | Reject only |
| **Policy ID** | Custom MailOdds policy | None |
| **Weekly Cron** | Validate 50 unvalidated users/week | Disabled |

**Reject only** blocks invalid and disposable emails. **Reject + risky** also blocks catch-all domains and role accounts (info@, admin@, etc).

## WP-CLI Commands

```bash
# Validate a single email
wp mailodds validate user@example.com

# Validate with JSON output
wp mailodds validate user@example.com --format=json

# Bulk validate all unvalidated WordPress users
wp mailodds bulk

# Bulk validate with batch size and limit
wp mailodds bulk --batch=100 --limit=500

# Show plugin status and stats
wp mailodds status
```

## Admin Features

- **Settings page**: API key, validation depth, block threshold, form toggles
- **Dashboard widget**: 7-day validation stats (accept/reject/error counts)
- **Bulk validation tool**: Tools > MailOdds Bulk -- validate existing users
- **User meta**: Each validated user stores `_mailodds_status`, `_mailodds_action`, `_mailodds_validated_at`
- **Test mode badge**: Displays when using an `mo_test_` API key

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

Deactivating the plugin clears the cron schedule. Deleting the plugin removes all options and user meta from the database (clean uninstall).

## Links

- [MailOdds Integration Guide](https://mailodds.com/integrations/wordpress)
- [API Documentation](https://mailodds.com/docs)
- [Developer Quickstart](https://mailodds.com/developers)
- [All SDKs](https://mailodds.com/sdks)

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
