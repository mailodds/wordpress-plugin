=== MailOdds Email Validation ===
Contributors: mailodds
Tags: email validation, email verification, spam prevention, WooCommerce, registration
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Validate emails on WordPress registration, WooCommerce checkout, and popular form plugins. Block fake signups, disposable emails, and invalid addresses.

== Description ==

MailOdds Email Validation integrates the [MailOdds API](https://mailodds.com) into your WordPress site to validate email addresses in real time.

**Features:**

* Validate emails on WordPress registration
* WooCommerce registration and checkout validation
* WPForms, Gravity Forms, and Contact Form 7 integration
* Configurable validation depth (standard or enhanced SMTP checks)
* Block rejected emails only, or also block risky emails (catch-all, role accounts)
* Transient-based caching (24h) to minimize API calls
* Admin dashboard widget with validation statistics
* Bulk validation tool for existing WordPress users
* WP-CLI commands for scripting and automation
* Weekly cron job for periodic user validation
* Policy support for custom validation rules
* Test mode with special test domains (no credits consumed)
* Graceful degradation: if the API is unreachable, forms still work

**Supported Form Plugins:**

* WordPress core registration
* WooCommerce (My Account registration + checkout)
* WPForms
* Gravity Forms
* Contact Form 7

== Installation ==

1. Upload the `mailodds` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings > MailOdds
4. Enter your API key (get one at [mailodds.com/dashboard/settings](https://mailodds.com/dashboard/settings))
5. Enable the form integrations you want

== Configuration ==

**API Key:** Required. Get yours at [mailodds.com](https://mailodds.com). Free accounts get 1,000 validations.

**Validation Depth:**
* Enhanced (default) - Full SMTP verification. Most accurate.
* Standard - Syntax + MX check only. Faster, less accurate.

**Block Threshold:**
* Reject only (default) - Blocks clearly invalid and disposable emails.
* Reject + risky - Also blocks catch-all domains and role accounts.

**Policy ID:** Optional. Apply a MailOdds policy for custom validation rules.

**Weekly Cron:** Enable to automatically validate 50 unvalidated users per week.

== WP-CLI Commands ==

Validate a single email:
    wp mailodds validate user@example.com

Validate with options:
    wp mailodds validate user@example.com --depth=standard --format=json

Bulk validate all unvalidated users:
    wp mailodds bulk

Bulk validate with limits:
    wp mailodds bulk --batch=100 --limit=500

Show plugin status:
    wp mailodds status

== Test Mode ==

Use an API key with the `mo_test_` prefix to enable test mode. Test mode:

* Does not consume credits
* Shows a test mode badge in the admin
* Uses special test domains for predictable results:
  * `*@deliverable.mailodds.com` - Returns valid/accept
  * `*@invalid.mailodds.com` - Returns invalid/reject
  * `*@risky.mailodds.com` - Returns catch_all/accept_with_caution
  * `*@disposable.mailodds.com` - Returns do_not_mail/reject

== Screenshots ==

1. Settings page - Configure API key, validation depth, block threshold, and form integrations.
2. WooCommerce checkout - Invalid email blocked with user-friendly error message.
3. Dashboard widget - 7-day validation stats at a glance.
4. Bulk validation - Validate all existing WordPress users in batches.
5. WP-CLI - Validate emails and manage users from the command line.

== Frequently Asked Questions ==

= Does this work with WooCommerce? =

Yes. Enable the WooCommerce integration in Settings > MailOdds. It validates emails on both My Account registration and guest checkout.

= What happens if the API is down? =

The plugin uses fail-open design. If the MailOdds API is unreachable (timeout, network error), the form submission proceeds normally. No user is blocked due to an API outage.

= Does it cache results? =

Yes. Each email validation result is cached for 24 hours using WordPress transients. This prevents re-validating the same email on retries or double-submits.

= Do I need WooCommerce? =

No. The plugin works with plain WordPress registration out of the box. WooCommerce, WPForms, Gravity Forms, and Contact Form 7 integrations are optional and enabled individually.

= How many credits does it use? =

One credit per unique email validation. Cached results and test mode do not consume credits.

== Changelog ==

= 1.0.1 =
* Added screenshot descriptions for wordpress.org directory
* Added FAQ entry for WooCommerce-free usage
* Aligned API response handling with flat response format
* Added 67 unit tests, 20 integration tests, and GitHub Actions CI

= 1.0.0 =
* Initial release
* WordPress registration validation
* WooCommerce registration and checkout validation
* WPForms, Gravity Forms, Contact Form 7 integration
* Admin settings page with configurable depth and threshold
* Dashboard widget with 7-day stats
* Bulk validation tool for existing users
* WP-CLI commands (validate, bulk, status)
* Weekly cron job for periodic validation
* Test mode support
* Transient-based caching

== Upgrade Notice ==

= 1.0.1 =
Test suite, CI pipeline, and flat API response alignment.

= 1.0.0 =
Initial release.
