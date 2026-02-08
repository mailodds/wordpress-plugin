#!/usr/bin/env bash
#
# Integration tests for MailOdds WordPress plugin.
#
# Requires: wp-env running, MAILODDS_TEST_KEY env var set.
# Uses test domains (*@*.mailodds.com) with test-mode API key.
#
set -uo pipefail

PASS=0
FAIL=0

pass() {
	PASS=$((PASS + 1))
	echo "  PASS: $1"
}

fail() {
	FAIL=$((FAIL + 1))
	echo "  FAIL: $1${2:+ (got: $2)}"
}

wp_cli() {
	wp-env run cli -- wp "$@" 2>/dev/null
}

wp_eval() {
	wp-env run cli -- wp eval "$1" 2>/dev/null | tr -d '\r'
}

# =========================================================================
# Preflight
# =========================================================================

if [ -z "${MAILODDS_TEST_KEY:-}" ]; then
	echo "ERROR: MAILODDS_TEST_KEY not set"
	exit 1
fi

# =========================================================================
# Setup
# =========================================================================

echo "=== Setup ==="
wp_cli option update mailodds_api_key "$MAILODDS_TEST_KEY" --quiet
wp_cli option update mailodds_integrations '{"wp_registration":true,"woocommerce":true}' --format=json --quiet
wp_cli option update mailodds_action_threshold reject --quiet
echo "  Options configured"

# =========================================================================
# 1. Plugin activation
# =========================================================================

echo ""
echo "=== Plugin Activation ==="

result=$(wp_eval 'echo class_exists("MailOdds") ? "active" : "inactive";')
if [ "$result" = "active" ]; then
	pass "MailOdds plugin active"
else
	fail "MailOdds plugin active" "$result"
fi

result=$(wp_eval 'echo class_exists("WooCommerce") ? "active" : "inactive";')
if [ "$result" = "active" ]; then
	pass "WooCommerce plugin active"
else
	fail "WooCommerce plugin active" "$result"
fi

# =========================================================================
# 2. WP-CLI commands
# =========================================================================

echo ""
echo "=== WP-CLI Commands ==="

# Valid email
output=$(wp_cli mailodds validate "test@deliverable.mailodds.com" --format=table 2>/dev/null || true)
if echo "$output" | grep -q "valid"; then
	pass "CLI validate: valid email returns valid"
else
	fail "CLI validate: valid email returns valid" "$output"
fi

# Invalid email
output=$(wp_cli mailodds validate "test@invalid.mailodds.com" --format=table 2>/dev/null || true)
if echo "$output" | grep -q "invalid"; then
	pass "CLI validate: invalid email returns invalid"
else
	fail "CLI validate: invalid email returns invalid" "$output"
fi

# Disposable email
output=$(wp_cli mailodds validate "test@disposable.mailodds.com" --format=table 2>/dev/null || true)
if echo "$output" | grep -q "do_not_mail"; then
	pass "CLI validate: disposable email returns do_not_mail"
else
	fail "CLI validate: disposable email returns do_not_mail" "$output"
fi

# Status command
output=$(wp_cli mailodds status 2>/dev/null || true)
if echo "$output" | grep -q "API Key"; then
	pass "CLI status: shows config"
else
	fail "CLI status: shows config" "$output"
fi

# =========================================================================
# 3. WP Registration hooks (threshold=reject)
# =========================================================================

echo ""
echo "=== WP Registration (threshold=reject) ==="

# Reject blocks invalid email
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@invalid.mailodds.com"); echo $e->get_error_code();')
if [ "$result" = "mailodds_invalid_email" ]; then
	pass "Registration blocks invalid email"
else
	fail "Registration blocks invalid email" "$result"
fi

# Reject blocks disposable email
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@disposable.mailodds.com"); echo $e->get_error_code();')
if [ "$result" = "mailodds_invalid_email" ]; then
	pass "Registration blocks disposable email"
else
	fail "Registration blocks disposable email" "$result"
fi

# Accept allows valid email
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@deliverable.mailodds.com"); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Registration allows valid email"
else
	fail "Registration allows valid email" "$result"
fi

# Accept_with_caution passes on reject threshold
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@risky.mailodds.com"); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Registration allows risky email on reject threshold"
else
	fail "Registration allows risky email on reject threshold" "$result"
fi

# Retry_later passes on reject threshold
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@timeout.mailodds.com"); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Registration allows unknown email on reject threshold"
else
	fail "Registration allows unknown email on reject threshold" "$result"
fi

# =========================================================================
# 4. WooCommerce checkout hooks (threshold=reject)
# =========================================================================

echo ""
echo "=== WooCommerce Checkout (threshold=reject) ==="

# Reject blocks invalid email at checkout
result=$(wp_eval '$e = new WP_Error(); do_action("woocommerce_after_checkout_validation", array("billing_email" => "test@invalid.mailodds.com"), $e); echo $e->get_error_code();')
if [ "$result" = "validation" ]; then
	pass "Checkout blocks invalid email"
else
	fail "Checkout blocks invalid email" "$result"
fi

# Accept allows valid email at checkout
result=$(wp_eval '$e = new WP_Error(); do_action("woocommerce_after_checkout_validation", array("billing_email" => "test@deliverable.mailodds.com"), $e); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Checkout allows valid email"
else
	fail "Checkout allows valid email" "$result"
fi

# Empty billing_email skips validation
result=$(wp_eval '$e = new WP_Error(); do_action("woocommerce_after_checkout_validation", array(), $e); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Checkout skips empty billing email"
else
	fail "Checkout skips empty billing email" "$result"
fi

# =========================================================================
# 5. Caution threshold
# =========================================================================

echo ""
echo "=== Caution Threshold ==="

wp_cli option update mailodds_action_threshold caution --quiet

# Risky email blocked on caution threshold
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@risky.mailodds.com"); echo $e->get_error_code();')
if [ "$result" = "mailodds_invalid_email" ]; then
	pass "Caution threshold blocks risky email"
else
	fail "Caution threshold blocks risky email" "$result"
fi

# Unknown email blocked on caution threshold
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@timeout.mailodds.com"); echo $e->get_error_code();')
if [ "$result" = "mailodds_invalid_email" ]; then
	pass "Caution threshold blocks unknown email"
else
	fail "Caution threshold blocks unknown email" "$result"
fi

# Valid email still passes on caution threshold
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@deliverable.mailodds.com"); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Caution threshold allows valid email"
else
	fail "Caution threshold allows valid email" "$result"
fi

# Restore reject threshold
wp_cli option update mailodds_action_threshold reject --quiet

# =========================================================================
# 6. Fail-open (bad API key)
# =========================================================================

echo ""
echo "=== Fail-Open ==="

# Flush cached validation results so the bad key actually hits the API
wp_eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_transient_mailodds_%\"");' > /dev/null

# Set a bad API key so real API returns 401
wp_cli option update mailodds_api_key "mo_live_BADKEY_integration_test" --quiet

# Use unique email prefixes to avoid any remaining cache
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "failopen@invalid.mailodds.com"); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Fail-open: registration allows on API error"
else
	fail "Fail-open: registration allows on API error" "$result"
fi

result=$(wp_eval '$e = new WP_Error(); do_action("woocommerce_after_checkout_validation", array("billing_email" => "failopen@invalid.mailodds.com"), $e); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "Fail-open: checkout allows on API error"
else
	fail "Fail-open: checkout allows on API error" "$result"
fi

# Restore good key
wp_cli option update mailodds_api_key "$MAILODDS_TEST_KEY" --quiet

# =========================================================================
# 7. No API key = no hooks
# =========================================================================

echo ""
echo "=== No API Key ==="

wp_cli option update mailodds_api_key "" --quiet

# With no API key, validator should not register hooks, so no filtering
result=$(wp_eval '$e = apply_filters("registration_errors", new WP_Error(), "u", "test@invalid.mailodds.com"); echo $e->get_error_code();')
if [ -z "$result" ]; then
	pass "No API key: registration skips validation"
else
	fail "No API key: registration skips validation" "$result"
fi

# Restore key
wp_cli option update mailodds_api_key "$MAILODDS_TEST_KEY" --quiet

# =========================================================================
# Summary
# =========================================================================

echo ""
TOTAL=$((PASS + FAIL))
echo "=== Results: $PASS/$TOTAL passed ==="

if [ "$FAIL" -gt 0 ]; then
	echo "FAILED: $FAIL test(s)"
	exit 1
fi

echo "All integration tests passed."
exit 0
