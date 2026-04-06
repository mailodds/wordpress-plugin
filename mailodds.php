<?php
/**
 * Plugin Name: MailOdds Email Validation
 * Plugin URI:  https://mailodds.com/integrations/wordpress
 * Description: Validate emails on registration, checkout, and contact forms using the MailOdds API. Blocks fake signups, disposable emails, and invalid addresses.
 * Version:     2.1.1
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author:      MailOdds
 * Author URI:  https://mailodds.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mailodds-email-validation
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'MAILODDS_VERSION', '2.1.1' );
define( 'MAILODDS_PLUGIN_FILE', __FILE__ );
define( 'MAILODDS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILODDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAILODDS_API_BASE', 'https://api.mailodds.com' );

// Load includes
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-api.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-admin.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-validator.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-bulk.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-suppression.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-policies.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-updater.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-rest.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-webhook.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-handshake.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-connect.php';
require_once MAILODDS_PLUGIN_DIR . 'includes/class-mailodds-catalog.php';

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
	 * Handshake handler for store connection.
	 *
	 * @var MailOdds_Handshake
	 */
	public $handshake;

	/**
	 * Catalog sync hook handler.
	 *
	 * @var MailOdds_Catalog
	 */
	public $catalog;

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
		new MailOdds_Suppression( $this->api );
		new MailOdds_Policies( $this->api );
		new MailOdds_Updater();
		new MailOdds_REST( $this->api );
		new MailOdds_Webhook( $this->api );
		$this->handshake = new MailOdds_Handshake();
		$connect = new MailOdds_Connect();
		$connect->register_hooks();
		$this->catalog = new MailOdds_Catalog();
		$this->catalog->register_hooks();

		// WP-CLI
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			MailOdds_CLI::register( $this->api );
		}

		// Prevent WooCommerce from silently disabling webhooks after 5 failures.
		// WooCommerce default is 5 consecutive failures before auto-disable.
		// MailOdds needs reliable webhook delivery for bi-directional sync.
		add_filter( 'woocommerce_max_webhook_delivery_failures', array( $this, 'increase_webhook_failure_threshold' ) );

		// Dependency checks
		add_action( 'admin_notices', array( $this, 'dependency_notices' ) );

		// Cron for periodic validation (two-phase: fire + check)
		add_action( 'mailodds_cron_validate_users', array( $this, 'cron_validate_users' ) );
		add_action( 'mailodds_cron_check_job', array( $this, 'cron_check_job' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
	}

	/**
	 * Show admin notices for missing dependencies.
	 *
	 * Checks PHP version, curl extension, and WooCommerce availability
	 * when the WooCommerce integration is enabled.
	 */
	public function dependency_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// PHP version check (runtime, complements the plugin header)
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html( sprintf(
				/* translators: %s: current PHP version */
				__( 'MailOdds requires PHP 7.4 or later. Your server is running PHP %s. Please upgrade PHP to use this plugin.', 'mailodds-email-validation' ),
				PHP_VERSION
			) );
			echo '</p></div>';
		}

		// curl extension check
		if ( ! extension_loaded( 'curl' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'MailOdds requires the PHP curl extension. Please enable it in your server configuration (php.ini) for the plugin to communicate with the MailOdds API.', 'mailodds-email-validation' );
			echo '</p></div>';
		}

		// WooCommerce active check (only if WC integration is enabled)
		$integrations = get_option( 'mailodds_integrations', array() );
		if ( ! empty( $integrations['woocommerce'] ) && ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'MailOdds: WooCommerce integration is enabled but WooCommerce is not active. Install and activate WooCommerce, or disable the WooCommerce integration in Settings > MailOdds.', 'mailodds-email-validation' );
			echo '</p></div>';
		}
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
			'display'  => __( 'Once Weekly (MailOdds)', 'mailodds-email-validation' ),
		);
		$schedules['mailodds_15min'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 Minutes (MailOdds)', 'mailodds-email-validation' ),
		);
		return $schedules;
	}

	/**
	 * Increase WooCommerce webhook failure threshold.
	 *
	 * WooCommerce auto-disables webhooks after N consecutive delivery
	 * failures. The default (5) is too aggressive for stores with
	 * intermittent connectivity. Raising to 100 keeps MailOdds sync
	 * webhooks active through temporary outages.
	 *
	 * @param int $threshold Current failure threshold.
	 * @return int
	 */
	public function increase_webhook_failure_threshold( $threshold ) {
		return 100;
	}

	/**
	 * Cron Phase A: create a validation job (fire and forget).
	 *
	 * For small batches (< 100), uses synchronous validate_batch.
	 * For larger batches, creates an async job and stores the job_id
	 * for Phase B to check on the next cron tick.
	 */
	public function cron_validate_users() {
		if ( ! $this->api->has_key() ) {
			return;
		}

		// Skip if a job is already in progress
		$existing_job = get_option( 'mailodds_cron_job_id', '' );
		if ( ! empty( $existing_job ) ) {
			return;
		}

		// Acquire a lock to prevent concurrent cron executions
		if ( false === get_transient( 'mailodds_cron_lock' ) ) {
			set_transient( 'mailodds_cron_lock', 1, 600 );
		} else {
			return;
		}

		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => '_mailodds_status',
					'compare' => 'NOT EXISTS',
				),
			),
			'number' => 500,
			'fields' => array( 'ID', 'user_email' ),
		) );

		if ( empty( $users ) ) {
			return;
		}

		$emails   = array();
		$user_map = array();
		foreach ( $users as $user ) {
			$emails[]                       = $user->user_email;
			$user_map[ $user->user_email ]  = $user->ID;
		}

		// Small batch: synchronous
		if ( count( $emails ) < 100 ) {
			$results = $this->api->validate_batch( array_slice( $emails, 0, 50 ) );

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

			$stats = get_option( 'mailodds_cron_stats', array() );
			$stats['last_run']   = current_time( 'mysql' );
			$stats['last_count'] = count( $results );
			update_option( 'mailodds_cron_stats', $stats );
			delete_transient( 'mailodds_cron_lock' );
			return;
		}

		// Large batch: create async job
		$job = $this->api->create_job( $emails );

		if ( is_wp_error( $job ) ) {
			return;
		}

		$job_id = isset( $job['id'] ) ? $job['id'] : '';
		if ( ! empty( $job_id ) ) {
			update_option( 'mailodds_cron_job_id', $job_id );

			// Schedule the check if not already scheduled
			if ( ! wp_next_scheduled( 'mailodds_cron_check_job' ) ) {
				wp_schedule_event( time() + 900, 'mailodds_15min', 'mailodds_cron_check_job' );
			}
		}

		delete_transient( 'mailodds_cron_lock' );
	}

	/**
	 * Cron Phase B: check job status and apply results.
	 */
	public function cron_check_job() {
		$job_id = get_option( 'mailodds_cron_job_id', '' );
		if ( empty( $job_id ) ) {
			wp_clear_scheduled_hook( 'mailodds_cron_check_job' );
			return;
		}

		if ( ! $this->api->has_key() ) {
			return;
		}

		$job = $this->api->get_job( $job_id );

		if ( is_wp_error( $job ) ) {
			return;
		}

		$status = isset( $job['status'] ) ? $job['status'] : '';

		if ( 'completed' !== $status ) {
			if ( 'failed' === $status || 'cancelled' === $status ) {
				delete_option( 'mailodds_cron_job_id' );
				wp_clear_scheduled_hook( 'mailodds_cron_check_job' );
			}
			return;
		}

		// Apply results
		$page      = 1;
		$applied   = 0;
		while ( true ) {
			$results = $this->api->get_job_results( $job_id, array( 'page' => $page, 'per_page' => 100 ) );

			if ( is_wp_error( $results ) || empty( $results['results'] ) ) {
				break;
			}

			foreach ( $results['results'] as $item ) {
				if ( ! isset( $item['email'] ) ) {
					continue;
				}
				$user = get_user_by( 'email', $item['email'] );
				if ( $user ) {
					update_user_meta( $user->ID, '_mailodds_status', sanitize_text_field( $item['status'] ) );
					update_user_meta( $user->ID, '_mailodds_action', sanitize_text_field( $item['action'] ) );
					update_user_meta( $user->ID, '_mailodds_validated_at', current_time( 'mysql' ) );
					$applied++;
				}
			}

			if ( count( $results['results'] ) < 100 ) {
				break;
			}
			$page++;
		}

		// Clean up
		delete_option( 'mailodds_cron_job_id' );
		wp_clear_scheduled_hook( 'mailodds_cron_check_job' );

		$stats = get_option( 'mailodds_cron_stats', array() );
		$stats['last_run']   = current_time( 'mysql' );
		$stats['last_count'] = $applied;
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
	wp_clear_scheduled_hook( 'mailodds_cron_check_job' );
}
register_deactivation_hook( __FILE__, 'mailodds_deactivate' );

// Boot
add_action( 'plugins_loaded', array( 'MailOdds', 'get_instance' ) );
