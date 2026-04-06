=== MailOdds Email Validation ===
Contributors: mailodds
Tags: email validation, email verification, spam prevention, WooCommerce, registration
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Validate emails on registration, WooCommerce checkout, and forms. Block fake signups and disposable emails.

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
* WooCommerce store connection with product catalog sync
* Test mode with special test domains (no credits consumed)
* Graceful degradation: if the API is unreachable, forms still work

**Third-Party Services:**

This plugin connects to the following external services:

* **MailOdds API** ([mailodds.com](https://mailodds.com)) - Email validation, suppression lists, bulk jobs, and telemetry. Email addresses entered in forms, registration, and checkout are sent to the MailOdds API for validation. An API key is required.
  [Terms of Service](https://mailodds.com/terms) | [Privacy Policy](https://mailodds.com/privacy)

* **GitHub API** ([api.github.com](https://api.github.com)) - Used solely for checking plugin updates when installed from GitHub. No user data is transmitted.
  [GitHub Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

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

Screenshots will be available after the first WordPress.org release.

== Privacy ==

This plugin sends email addresses to the MailOdds API (api.mailodds.com) for validation when:

* A user registers on your WordPress site
* A customer checks out on WooCommerce
* A visitor submits a form (WPForms, Gravity Forms, Contact Form 7)
* You run bulk validation on existing users
* The weekly cron job validates unvalidated users

Validation results are cached locally as WordPress transients for 24 hours. Per-user validation status is stored as user meta. No personally identifiable information beyond the email address is sent to the API.

When installed from GitHub, the plugin checks api.github.com for new releases every 12 hours. No user data is included in these requests.

See the [MailOdds Privacy Policy](https://mailodds.com/privacy) for full details on how email data is processed.

== Frequently Asked Questions ==

= Does this work with WooCommerce? =

Yes. Enable the WooCommerce integration in Settings > MailOdds. It validates emails on both My Account registration and guest checkout.

= Do I need WooCommerce? =

No. The plugin works with plain WordPress registration out of the box. WooCommerce, WPForms, Gravity Forms, and Contact Form 7 integrations are optional and enabled individually.

= What happens if the API is down? =

The plugin uses fail-open design. If the MailOdds API is unreachable (timeout, network error), the form submission proceeds normally. No user is blocked due to an API outage.

= How do I connect my WooCommerce store? =

Go to Settings > MailOdds > Store Connection and click Connect. The plugin creates a WooCommerce REST API key, completes a secure handshake with MailOdds, and begins syncing your product catalog automatically. Products are kept in sync on every create, update, and delete.

= How do I disconnect my store? =

Click Disconnect in Settings > MailOdds > Store Connection. The plugin revokes the WooCommerce API key and notifies MailOdds. If you uninstall the plugin entirely, the API key is also revoked automatically.

= How do I validate existing users in bulk? =

Go to Tools > MailOdds Bulk Validate. Select a batch size and click Start. For large lists, the plugin creates an async job and polls for results. You can also run `wp mailodds bulk --batch=100 --limit=500` from the command line.

= Can I automate validation on a schedule? =

Yes. Enable the weekly cron in Settings > MailOdds. It validates up to 50 unvalidated users per run. For larger sites, use `wp cron event run mailodds_cron_validate_users` to trigger it manually or adjust the schedule with a cron management plugin.

= What are WP-CLI commands available? =

Three commands: `wp mailodds validate user@example.com` (single email), `wp mailodds bulk` (batch validate users), and `wp mailodds status` (show API key status, cached results, and cron schedule). Add `--format=json` for machine-readable output.

= How do I receive real-time validation events? =

The plugin registers a webhook endpoint at `/wp-json/mailodds/v1/webhook`. Configure your webhook URL and secret in the MailOdds dashboard. The plugin verifies the `X-MailOdds-Signature` header using HMAC-SHA256 before processing any event.

= What is a suppression list? =

Emails that hard-bounce or generate complaints are automatically added to your suppression list. The plugin checks this list before sending and skips suppressed addresses. You can view and manage suppressions in the MailOdds dashboard.

= What are policies? =

Policies are custom validation rules you create in the MailOdds dashboard. Enter a Policy ID in Settings > MailOdds to apply it. For example, a policy can reject all free-provider emails or require MX records from specific domains.

= How do I set up test mode? =

Use an API key with the `mo_test_` prefix. Test mode does not consume credits and shows a badge in the admin. Use test domains for predictable results: `*@deliverable.mailodds.com` (valid), `*@invalid.mailodds.com` (invalid), `*@disposable.mailodds.com` (disposable).

= How does the plugin update? =

If installed from WordPress.org, updates are delivered through the standard WordPress update system. If installed from GitHub, the plugin checks GitHub releases for new versions. Either way, updates appear in Dashboard > Updates like any other plugin.

= How many credits does it use? =

One credit per unique email validation. Cached results (24 hours) and test mode do not consume credits. Bulk validation uses one credit per email in the batch.

== Changelog ==

= 2.1.0 =
* WooCommerce store connection: handshake flow, API key generation, connect/disconnect UI
* Product catalog sync on WooCommerce product create, update, and delete hooks
* AJAX-based store management with nonce verification
* Externalized store JavaScript using wp_localize_script
* WC API key revocation on disconnect and plugin uninstall
* Security: SSRF validation, HTTPS enforcement, constant-time secret comparison

= 2.0.0 =
* Bulk validation tool for existing WordPress users
* Suppression list management
* Policy-based validation rules
* Webhook receiver for real-time validation events
* REST API endpoints for headless integrations
* WP-CLI enhancements

= 1.0.2 =
* Fixed text domain to match wordpress.org slug (mailodds-email-validation)
* Shortened readme short description to meet 150-character limit

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

= 2.1.0 =
WooCommerce store connection with product catalog sync. Connect your store to MailOdds for e-commerce event tracking.

= 2.0.0 =
Major update: bulk validation, suppression lists, policies, webhooks, and REST API.

= 1.0.2 =
Text domain fix for wordpress.org directory compliance.

= 1.0.1 =
Test suite, CI pipeline, and flat API response alignment.

= 1.0.0 =
Initial release.
