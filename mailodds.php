<?php
/**
 * Plugin Name: MailOdds Email Validation
 * Plugin URI:  https://mailodds.com/integrations/wordpress
 * Description: Validate emails on registration, checkout, and contact forms using the MailOdds API. Blocks fake signups, disposable emails, and invalid addresses.
 * Version:     1.0.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author:      MailOdds
 * Author URI:  https://mailodds.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mailodds
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'MAILODDS_VERSION', '1.0.0' );
define( 'MAILODDS_PLUGIN_FILE', __FILE__ );
define( 'MAILODDS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILODDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAILODDS_API_BASE', 'https://api.mailodds.com' );

// Load includes
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-api.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-admin.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-validator.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-bulk.php';

// WP-CLI commands (only in CLI context)
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-cli.php';
}

/**
 * Main plugin class.
 */
final class MailOdds {

	/**
	 * Singleton instance.
	 *
	 * @var MailOdds|null
	 */
	private static $instance = null;

	/**
	 * API client instance.
	 *
	 * @var MailOdds_API
	 */
	public $api;

	/**
	 * Get singleton instance.
	 *
	 * @return MailOdds
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$api_key    = get_option( 'mailodds_api_key', '' );
		$this->api  = new MailOdds_API( $api_key );

		// Initialize components
		new MailOdds_Admin( $this->api );
		new MailOdds_Validator( $this->api );
		new MailOdds_Bulk( $this->api );

		// WP-CLI
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			MailOdds_CLI::register( $this->api );
		}

		// Cron for periodic validation
		add_action( 'mailodds_cron_validate_users', array( $this, 'cron_validate_users' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
	}

	/**
	 * Add weekly cron schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedule( $schedules ) {
		$schedules['mailodds_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (MailOdds)', 'mailodds' ),
		);
		return $schedules;
	}

	/**
	 * Cron job: validate unvalidated users in batches.
	 */
	public function cron_validate_users() {
		if ( ! $this->api->has_key() ) {
			return;
		}

		$batch_size = 50;
		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => '_mailodds_status',
					'compare' => 'NOT EXISTS',
				),
			),
			'number' => $batch_size,
			'fields' => array( 'ID', 'user_email' ),
		) );

		if ( empty( $users ) ) {
			return;
		}

		$emails = array();
		$user_map = array();
		foreach ( $users as $user ) {
			$emails[] = $user->user_email;
			$user_map[ $user->user_email ] = $user->ID;
		}

		$results = $this->api->validate_batch( $emails );

		if ( is_wp_error( $results ) ) {
			return;
		}

		foreach ( $results as $result ) {
			$email = $result['email'];
			if ( isset( $user_map[ $email ] ) ) {
				$user_id = $user_map[ $email ];
				update_user_meta( $user_id, '_mailodds_status', sanitize_text_field( $result['status'] ) );
				update_user_meta( $user_id, '_mailodds_action', sanitize_text_field( $result['action'] ) );
				update_user_meta( $user_id, '_mailodds_validated_at', current_time( 'mysql' ) );
			}
		}

		// Track stats
		$stats = get_option( 'mailodds_cron_stats', array() );
		$stats['last_run']   = current_time( 'mysql' );
		$stats['last_count'] = count( $users );
		update_option( 'mailodds_cron_stats', $stats );
	}
}

/**
 * Activation hook.
 */
function mailodds_activate() {
	// Schedule cron if enabled
	if ( get_option( 'mailodds_cron_enabled', false ) ) {
		if ( ! wp_next_scheduled( 'mailodds_cron_validate_users' ) ) {
			wp_schedule_event( time(), 'mailodds_weekly', 'mailodds_cron_validate_users' );
		}
	}
}
register_activation_hook( __FILE__, 'mailodds_activate' );

/**
 * Deactivation hook.
 */
function mailodds_deactivate() {
	wp_clear_scheduled_hook( 'mailodds_cron_validate_users' );
}
register_deactivation_hook( __FILE__, 'mailodds_deactivate' );

// Boot
add_action( 'plugins_loaded', array( 'MailOdds', 'get_instance' ) );
