<?php
/**
 * WordPress plugin smoke test.
 *
 * Exercises the MailOdds_API class directly (bypassing full WordPress bootstrap)
 * against all 7 test domains and 2 error cases.
 *
 * Usage: MAILODDS_TEST_KEY=mo_test_... php ci/smoke_test.php
 */

$apiKey = getenv('MAILODDS_TEST_KEY');
if (!$apiKey) {
    echo "ERROR: MAILODDS_TEST_KEY not set\n";
    exit(1);
}

// Load WordPress stubs and plugin API class
require_once __DIR__ . '/wp_stubs.php';
require_once __DIR__ . '/../includes/class-mailodds-api.php';

$api = new MailOdds_API($apiKey);

$passed = 0;
$failed = 0;

function check($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        echo "  FAIL: $label expected=" . var_export($expected, true) . " got=" . var_export($actual, true) . "\n";
    }
}

function checkBool($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        $expStr = $expected ? 'true' : 'false';
        $actStr = is_null($actual) ? 'null' : ($actual ? 'true' : 'false');
        echo "  FAIL: $label expected=$expStr got=$actStr\n";
    }
}

// Test domain cases:
// [email, status, action, sub_status, free_provider, disposable, role_account, mx_found, depth]
$cases = [
    ['test@deliverable.mailodds.com', 'valid', 'accept', null, false, false, false, true, 'enhanced'],
    ['test@invalid.mailodds.com', 'invalid', 'reject', 'smtp_rejected', false, false, false, true, 'enhanced'],
    ['test@risky.mailodds.com', 'catch_all', 'accept_with_caution', 'catch_all_detected', false, false, false, true, 'enhanced'],
    ['test@disposable.mailodds.com', 'do_not_mail', 'reject', 'disposable', false, true, false, true, 'enhanced'],
    ['test@role.mailodds.com', 'do_not_mail', 'reject', 'role_account', false, false, true, true, 'enhanced'],
    ['test@timeout.mailodds.com', 'unknown', 'retry_later', 'smtp_unreachable', false, false, false, true, 'enhanced'],
    ['test@freeprovider.mailodds.com', 'valid', 'accept', null, true, false, false, true, 'enhanced'],
];

echo "WordPress Plugin Smoke Test\n";
echo "===========================\n\n";

foreach ($cases as [$email, $expStatus, $expAction, $expSub, $expFree, $expDisp, $expRole, $expMx, $expDepth]) {
    $domain = explode('.', explode('@', $email)[1])[0];
    echo "Testing: $domain\n";

    $result = $api->validate($email, ['skip_cache' => true]);

    if (is_wp_error($result)) {
        $failed++;
        echo "  FAIL: $domain WP_Error: " . $result->get_error_message() . "\n";
        continue;
    }

    check("$domain.status", $expStatus, isset($result['status']) ? $result['status'] : null);
    check("$domain.action", $expAction, isset($result['action']) ? $result['action'] : null);

    // sub_status: may be absent (null expected) or present
    $actualSub = isset($result['sub_status']) ? $result['sub_status'] : null;
    check("$domain.sub_status", $expSub, $actualSub);

    checkBool("$domain.free_provider", $expFree, isset($result['free_provider']) ? $result['free_provider'] : null);
    checkBool("$domain.disposable", $expDisp, isset($result['disposable']) ? $result['disposable'] : null);
    checkBool("$domain.role_account", $expRole, isset($result['role_account']) ? $result['role_account'] : null);
    checkBool("$domain.mx_found", $expMx, isset($result['mx_found']) ? $result['mx_found'] : null);
    check("$domain.depth", $expDepth, isset($result['depth']) ? $result['depth'] : null);

    if (isset($result['processed_at']) && !empty($result['processed_at'])) {
        $passed++;
    } else {
        $failed++;
        echo "  FAIL: $domain.processed_at is empty\n";
    }
}

echo "\n";

// Error handling: 401 with bad key
echo "Testing: error.401\n";
$badApi = new MailOdds_API('invalid_key');
$result = $badApi->validate('test@deliverable.mailodds.com', ['skip_cache' => true]);
if (is_wp_error($result)) {
    check('error.401', 'mailodds_api_error', $result->get_error_code());
} else {
    $failed++;
    echo "  FAIL: error.401 expected WP_Error, got success\n";
}

// Error handling: 400/422 with empty email
echo "Testing: error.empty_email\n";
$result = $api->validate('', ['skip_cache' => true]);
if (is_wp_error($result)) {
    check('error.empty_email', 'mailodds_invalid_email', $result->get_error_code());
} else {
    $failed++;
    echo "  FAIL: error.empty_email expected WP_Error, got success\n";
}

echo "\n";

$total = $passed + $failed;
$label = $failed === 0 ? 'PASS' : 'FAIL';
echo "$label: WordPress Plugin ($passed/$total)\n";
exit($failed === 0 ? 0 : 1);
