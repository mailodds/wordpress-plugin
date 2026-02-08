<?php
/**
 * Minimal WordPress function stubs for smoke testing.
 *
 * Provides just enough WordPress API surface to load and test
 * the MailOdds_API class without a full WordPress installation.
 */

// WordPress constants the plugin expects
define('ABSPATH', '/app/');
define('MAILODDS_VERSION', '1.0.0');
define('MAILODDS_PLUGIN_FILE', '/app/mailodds.php');
define('MAILODDS_PLUGIN_DIR', '/app/');
define('MAILODDS_PLUGIN_URL', 'http://localhost/wp-content/plugins/mailodds/');
define('MAILODDS_API_BASE', 'https://api.mailodds.com');
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

// WP_Error stub
class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

// Sanitize stubs
function sanitize_email($email) {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
}

function sanitize_text_field($str) {
    return trim(strip_tags($str));
}

// Options stub (in-memory store)
$_wp_options = [];

function get_option($name, $default = false) {
    global $_wp_options;
    return isset($_wp_options[$name]) ? $_wp_options[$name] : $default;
}

function update_option($name, $value, $autoload = null) {
    global $_wp_options;
    $_wp_options[$name] = $value;
    return true;
}

// Transient stubs (no-op for smoke test)
function get_transient($key) {
    return false; // Always miss cache
}

function set_transient($key, $value, $expiration = 0) {
    return true;
}

// User meta stubs
function update_user_meta($user_id, $key, $value) {
    return true;
}

// i18n stub
function __($text, $domain = 'default') {
    return $text;
}

// Time stubs
function current_time($type) {
    if ($type === 'mysql') return gmdate('Y-m-d H:i:s');
    if ($type === 'Y-m-d') return gmdate('Y-m-d');
    return time();
}

// HTTP stubs -- these use curl directly instead of WordPress HTTP API
function wp_remote_post($url, $args = []) {
    $headers = isset($args['headers']) ? $args['headers'] : [];
    $body = isset($args['body']) ? $args['body'] : '';
    $timeout = isset($args['timeout']) ? $args['timeout'] : 10;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $curl_headers = [];
    foreach ($headers as $key => $value) {
        $curl_headers[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return new WP_Error('http_request_failed', $error);
    }

    $response_body = substr($response, $header_size);

    return [
        'response' => ['code' => $http_code],
        'body' => $response_body,
    ];
}

function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}

function wp_json_encode($data) {
    return json_encode($data);
}

function absint($value) {
    return abs((int) $value);
}

function plugin_dir_path($file) {
    return dirname($file) . '/';
}

function plugin_dir_url($file) {
    return 'http://localhost/';
}

// Hooks stubs (no-op)
function add_action($tag, $callback, $priority = 10, $args = 1) {}
function add_filter($tag, $callback, $priority = 10, $args = 1) {}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event($timestamp, $recurrence, $hook) {}
function wp_clear_scheduled_hook($hook) {}
function get_users($args = []) { return []; }
