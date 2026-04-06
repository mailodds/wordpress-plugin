<?php
/**
 * MailOdds admin settings and dashboard widget.
 *
 * Provides:
 * - Settings page under Settings > MailOdds
 * - Dashboard widget with validation stats
 * - Admin notices for missing API key
 * - Test mode indicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Admin {

	/**
	 * API client.
	 *
	 * @var MailOdds_API
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public function __construct( MailOdds_API $api ) {
		$this->api = $api;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'update_option_mailodds_api_key', array( $this, 'flush_transient_cache' ) );

		// AJAX handlers for store connection
		add_action( 'wp_ajax_mailodds_connect_store', array( $this, 'ajax_connect_store' ) );
		add_action( 'wp_ajax_mailodds_disconnect_store', array( $this, 'ajax_disconnect_store' ) );
	}

	/**
	 * Flush all MailOdds transient caches.
	 *
	 * Called when the API key changes to prevent stale cached results
	 * from being served under the new key.
	 */
	public function flush_transient_cache() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_mailodds_%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_mailodds_%' ) );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$plugin_pages = array(
			'settings_page_mailodds',
			'settings_page_mailodds-policies',
			'tools_page_mailodds-bulk',
			'tools_page_mailodds-suppressions',
		);

		if ( in_array( $hook, $plugin_pages, true ) ) {
			wp_enqueue_style(
				'mailodds-admin',
				MAILODDS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				MAILODDS_VERSION
			);

			wp_enqueue_script(
				'mailodds-admin',
				MAILODDS_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				MAILODDS_VERSION,
				true
			);

			// Determine the right nonce based on page
			$nonce = 'mailodds-bulk-nonce';
			if ( 'tools_page_mailodds-suppressions' === $hook ) {
				$nonce = 'mailodds-suppression-nonce';
			} elseif ( 'settings_page_mailodds-policies' === $hook ) {
				$nonce = 'mailodds-policy-nonce';
			}

			wp_localize_script( 'mailodds-admin', 'mailodds_ajax', array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( $nonce ),
			) );

			// Store connect/disconnect script (settings page only)
			if ( 'settings_page_mailodds' === $hook ) {
				wp_enqueue_script(
					'mailodds-store',
					MAILODDS_PLUGIN_URL . 'assets/js/store.js',
					array( 'jquery' ),
					MAILODDS_VERSION,
					true
				);

				wp_localize_script( 'mailodds-store', 'mailodds_store', array(
					'ajaxurl'            => admin_url( 'admin-ajax.php' ),
					'nonce'              => wp_create_nonce( 'mailodds-store-nonce' ),
					'connect_nonce'      => wp_create_nonce( 'mailodds_connect_nonce' ),
					'confirm_disconnect' => __( 'Disconnect your store? Product sync will stop.', 'mailodds-email-validation' ),
				) );
			}
		}
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'MailOdds Email Validation', 'mailodds-email-validation' ),
			__( 'MailOdds', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		// API Settings section
		add_settings_section(
			'mailodds_api_section',
			__( 'API Configuration', 'mailodds-email-validation' ),
			array( $this, 'render_api_section' ),
			'mailodds'
		);

		// API Key
		register_setting( 'mailodds_options', 'mailodds_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field(
			'mailodds_api_key',
			__( 'API Key', 'mailodds-email-validation' ),
			array( $this, 'render_api_key_field' ),
			'mailodds',
			'mailodds_api_section'
		);

		// Validation Settings section
		add_settings_section(
			'mailodds_validation_section',
			__( 'Validation Settings', 'mailodds-email-validation' ),
			null,
			'mailodds'
		);

		// Depth
		register_setting( 'mailodds_options', 'mailodds_depth', array(
			'type'              => 'string',
			'default'           => 'enhanced',
			'sanitize_callback' => array( $this, 'sanitize_depth' ),
		) );
		add_settings_field(
			'mailodds_depth',
			__( 'Validation Depth', 'mailodds-email-validation' ),
			array( $this, 'render_depth_field' ),
			'mailodds',
			'mailodds_validation_section'
		);

		// Action Threshold
		register_setting( 'mailodds_options', 'mailodds_action_threshold', array(
			'type'              => 'string',
			'default'           => 'reject',
			'sanitize_callback' => array( $this, 'sanitize_threshold' ),
		) );
		add_settings_field(
			'mailodds_action_threshold',
			__( 'Block Threshold', 'mailodds-email-validation' ),
			array( $this, 'render_threshold_field' ),
			'mailodds',
			'mailodds_validation_section'
		);

		// Policy ID
		register_setting( 'mailodds_options', 'mailodds_policy_id', array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		) );
		add_settings_field(
			'mailodds_policy_id',
			__( 'Policy ID', 'mailodds-email-validation' ),
			array( $this, 'render_policy_field' ),
			'mailodds',
			'mailodds_validation_section'
		);

		// Integration Toggles section
		add_settings_section(
			'mailodds_integrations_section',
			__( 'Form Integrations', 'mailodds-email-validation' ),
			array( $this, 'render_integrations_section' ),
			'mailodds'
		);

		register_setting( 'mailodds_options', 'mailodds_integrations', array(
			'type'              => 'array',
			'default'           => array(
				'wp_registration' => true,
				'woocommerce'     => false,
				'wpforms'         => false,
				'gravity_forms'   => false,
				'cf7'             => false,
			),
			'sanitize_callback' => array( $this, 'sanitize_integrations' ),
		) );
		add_settings_field(
			'mailodds_integrations',
			__( 'Active Integrations', 'mailodds-email-validation' ),
			array( $this, 'render_integrations_field' ),
			'mailodds',
			'mailodds_integrations_section'
		);

		// Cron section
		add_settings_section(
			'mailodds_cron_section',
			__( 'Scheduled Validation', 'mailodds-email-validation' ),
			null,
			'mailodds'
		);

		register_setting( 'mailodds_options', 'mailodds_cron_enabled', array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		) );
		add_settings_field(
			'mailodds_cron_enabled',
			__( 'Weekly User Validation', 'mailodds-email-validation' ),
			array( $this, 'render_cron_field' ),
			'mailodds',
			'mailodds_cron_section'
		);

		// Suppression pre-check
		register_setting( 'mailodds_options', 'mailodds_check_suppression', array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		) );
		add_settings_field(
			'mailodds_check_suppression',
			__( 'Suppression Pre-check', 'mailodds-email-validation' ),
			array( $this, 'render_suppression_check_field' ),
			'mailodds',
			'mailodds_validation_section'
		);

		// Advanced section
		add_settings_section(
			'mailodds_advanced_section',
			__( 'Advanced', 'mailodds-email-validation' ),
			null,
			'mailodds'
		);

		// Webhook Secret
		register_setting( 'mailodds_options', 'mailodds_webhook_secret', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field(
			'mailodds_webhook_secret',
			__( 'Webhook Secret', 'mailodds-email-validation' ),
			array( $this, 'render_webhook_secret_field' ),
			'mailodds',
			'mailodds_advanced_section'
		);

		// Telemetry Dashboard
		register_setting( 'mailodds_options', 'mailodds_telemetry_dashboard', array(
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		) );
		add_settings_field(
			'mailodds_telemetry_dashboard',
			__( 'Telemetry Widget', 'mailodds-email-validation' ),
			array( $this, 'render_telemetry_dashboard_field' ),
			'mailodds',
			'mailodds_advanced_section'
		);

		// Store Connection section
		add_settings_section(
			'mailodds_store_section',
			__( 'Store Connection', 'mailodds-email-validation' ),
			array( $this, 'render_store_section' ),
			'mailodds'
		);

		add_settings_field(
			'mailodds_store_status',
			__( 'Connection Status', 'mailodds-email-validation' ),
			array( $this, 'render_store_status_field' ),
			'mailodds',
			'mailodds_store_section'
		);
	}

	/**
	 * Render API section description.
	 */
	public function render_api_section() {
		if ( $this->api->is_test_mode() ) {
			echo '<div class="mailodds-test-badge">';
			echo esc_html__( 'TEST MODE -- Using test API key. No credits consumed.', 'mailodds-email-validation' );
			echo '</div>';
		}
		echo '<p>' . esc_html__( 'Enter your MailOdds API key. Get one at', 'mailodds-email-validation' );
		echo ' <a href="https://mailodds.com/dashboard/settings" target="_blank">mailodds.com/dashboard/settings</a>.</p>';
	}

	/**
	 * Render API key field.
	 */
	public function render_api_key_field() {
		$value = get_option( 'mailodds_api_key', '' );
		$masked = '';
		if ( ! empty( $value ) ) {
			$masked = substr( $value, 0, 8 ) . str_repeat( '*', max( 0, strlen( $value ) - 12 ) ) . substr( $value, -4 );
		}
		echo '<input type="password" id="mailodds_api_key" name="mailodds_api_key" ';
		echo 'value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		if ( ! empty( $masked ) ) {
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: %s: masked API key */
				__( 'Current key: %s', 'mailodds-email-validation' ),
				$masked
			) ) . '</p>';
		}
	}

	/**
	 * Render depth field.
	 */
	public function render_depth_field() {
		$value = get_option( 'mailodds_depth', 'enhanced' );
		echo '<select name="mailodds_depth" id="mailodds_depth">';
		echo '<option value="enhanced"' . selected( $value, 'enhanced', false ) . '>';
		echo esc_html__( 'Enhanced (full SMTP check)', 'mailodds-email-validation' ) . '</option>';
		echo '<option value="standard"' . selected( $value, 'standard', false ) . '>';
		echo esc_html__( 'Standard (syntax + MX only)', 'mailodds-email-validation' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Enhanced is more accurate but slightly slower.', 'mailodds-email-validation' ) . '</p>';
	}

	/**
	 * Render action threshold field.
	 */
	public function render_threshold_field() {
		$value = get_option( 'mailodds_action_threshold', 'reject' );
		echo '<select name="mailodds_action_threshold" id="mailodds_action_threshold">';
		echo '<option value="reject"' . selected( $value, 'reject', false ) . '>';
		echo esc_html__( 'Block only rejected emails (recommended)', 'mailodds-email-validation' ) . '</option>';
		echo '<option value="caution"' . selected( $value, 'caution', false ) . '>';
		echo esc_html__( 'Block rejected + risky (catch-all, role accounts)', 'mailodds-email-validation' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Controls which emails are blocked on registration and forms.', 'mailodds-email-validation' ) . '</p>';
	}

	/**
	 * Render policy ID field with dropdown populated from API.
	 */
	public function render_policy_field() {
		$value = get_option( 'mailodds_policy_id', 0 );
		$policies = false;

		if ( $this->api->has_key() ) {
			$policies = get_transient( 'mailodds_policies_list' );
			if ( false === $policies ) {
				$result = $this->api->list_policies();
				if ( ! is_wp_error( $result ) ) {
					$policies = isset( $result['policies'] ) ? $result['policies'] : ( is_array( $result ) ? $result : array() );
					set_transient( 'mailodds_policies_list', $policies, 300 );
				}
			}
		}

		if ( ! empty( $policies ) && is_array( $policies ) ) {
			echo '<select name="mailodds_policy_id" id="mailodds_policy_id">';
			echo '<option value="0"' . selected( $value, 0, false ) . '>';
			echo esc_html__( 'Default (no policy)', 'mailodds-email-validation' ) . '</option>';
			foreach ( $policies as $policy ) {
				$pid  = isset( $policy['id'] ) ? absint( $policy['id'] ) : 0;
				$name = isset( $policy['name'] ) ? $policy['name'] : sprintf( 'Policy #%d', $pid );
				echo '<option value="' . esc_attr( $pid ) . '"' . selected( $value, $pid, false ) . '>';
				echo esc_html( $name ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="number" name="mailodds_policy_id" id="mailodds_policy_id" ';
			echo 'value="' . esc_attr( $value ) . '" class="small-text" min="0" />';
		}
		echo '<p class="description">' . esc_html__( 'Optional. Apply a MailOdds policy to all validations. Leave at default for none.', 'mailodds-email-validation' ) . '</p>';
	}

	/**
	 * Render integrations section description.
	 */
	public function render_integrations_section() {
		echo '<p>' . esc_html__( 'Enable validation for specific form plugins. Only enable integrations you have installed.', 'mailodds-email-validation' ) . '</p>';
	}

	/**
	 * Render integrations checkboxes.
	 */
	public function render_integrations_field() {
		$integrations = get_option( 'mailodds_integrations', array() );
		$options = array(
			'wp_registration' => __( 'WordPress Registration', 'mailodds-email-validation' ),
			'woocommerce'     => __( 'WooCommerce (registration + checkout)', 'mailodds-email-validation' ),
			'wpforms'         => __( 'WPForms', 'mailodds-email-validation' ),
			'gravity_forms'   => __( 'Gravity Forms', 'mailodds-email-validation' ),
			'cf7'             => __( 'Contact Form 7', 'mailodds-email-validation' ),
		);

		foreach ( $options as $key => $label ) {
			$checked = ! empty( $integrations[ $key ] );
			echo '<label style="display:block;margin-bottom:8px;">';
			echo '<input type="checkbox" name="mailodds_integrations[' . esc_attr( $key ) . ']" value="1"';
			checked( $checked );
			echo ' /> ' . esc_html( $label );
			echo '</label>';
		}
	}

	/**
	 * Render cron toggle field.
	 */
	public function render_cron_field() {
		$enabled = get_option( 'mailodds_cron_enabled', false );
		$stats   = get_option( 'mailodds_cron_stats', array() );

		echo '<label>';
		echo '<input type="checkbox" name="mailodds_cron_enabled" value="1"';
		checked( $enabled );
		echo ' /> ' . esc_html__( 'Validate unvalidated users weekly (50 per run)', 'mailodds-email-validation' ) . '</label>';

		if ( ! empty( $stats['last_run'] ) ) {
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: 1: last run date, 2: count */
				__( 'Last run: %1$s (%2$d users validated)', 'mailodds-email-validation' ),
				$stats['last_run'],
				$stats['last_count']
			) ) . '</p>';
		}

		// Manage cron schedule on save
		if ( $enabled && ! wp_next_scheduled( 'mailodds_cron_validate_users' ) ) {
			wp_schedule_event( time(), 'mailodds_weekly', 'mailodds_cron_validate_users' );
		} elseif ( ! $enabled ) {
			wp_clear_scheduled_hook( 'mailodds_cron_validate_users' );
		}
	}

	/**
	 * Render suppression pre-check toggle field.
	 */
	public function render_suppression_check_field() {
		$enabled = get_option( 'mailodds_check_suppression', false );
		echo '<label>';
		echo '<input type="checkbox" name="mailodds_check_suppression" value="1"';
		checked( $enabled );
		echo ' /> ' . esc_html__( 'Check suppression list before validating (saves API credits)', 'mailodds-email-validation' ) . '</label>';
	}

	/**
	 * Render webhook secret field.
	 */
	public function render_webhook_secret_field() {
		$value = get_option( 'mailodds_webhook_secret', '' );
		echo '<input type="text" name="mailodds_webhook_secret" id="mailodds_webhook_secret" ';
		echo 'value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'HMAC secret for verifying webhook payloads from MailOdds. Leave empty to disable webhooks.', 'mailodds-email-validation' ) . '</p>';
	}

	/**
	 * Render telemetry dashboard toggle field.
	 */
	public function render_telemetry_dashboard_field() {
		$enabled = get_option( 'mailodds_telemetry_dashboard', true );
		echo '<label>';
		echo '<input type="checkbox" name="mailodds_telemetry_dashboard" value="1"';
		checked( $enabled );
		echo ' /> ' . esc_html__( 'Show server-side telemetry in the dashboard widget', 'mailodds-email-validation' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disable if you prefer not to fetch telemetry data from the MailOdds API (GDPR/CCPA).', 'mailodds-email-validation' ) . '</p>';
	}

	/**
	 * Sanitize depth value.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	public function sanitize_depth( $value ) {
		return in_array( $value, array( 'standard', 'enhanced' ), true ) ? $value : 'enhanced';
	}

	/**
	 * Sanitize threshold value.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	public function sanitize_threshold( $value ) {
		return in_array( $value, array( 'reject', 'caution' ), true ) ? $value : 'reject';
	}

	/**
	 * Sanitize integrations array.
	 *
	 * @param array $value Input value.
	 * @return array
	 */
	public function sanitize_integrations( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array( 'wp_registration', 'woocommerce', 'wpforms', 'gravity_forms', 'cf7' );
		$clean = array();
		foreach ( $allowed as $key ) {
			$clean[ $key ] = ! empty( $value[ $key ] );
		}
		return $clean;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'mailodds_options' );
				do_settings_sections( 'mailodds' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show admin notices.
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// No API key configured
		if ( ! $this->api->has_key() ) {
			$settings_url = admin_url( 'options-general.php?page=mailodds' );
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses(
				sprintf(
					/* translators: %s: settings page URL */
					__( 'MailOdds: API key not configured. <a href="%s">Configure it now</a> to start validating emails.', 'mailodds-email-validation' ),
					esc_url( $settings_url )
				),
				array( 'a' => array( 'href' => array() ) )
			);
			echo '</p></div>';
		}

		// Test mode indicator
		if ( $this->api->is_test_mode() ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'MailOdds is running in TEST MODE. Validations use test domains and do not consume credits.', 'mailodds-email-validation' );
			echo '</p></div>';
		}

		// Suppression fail-open warning
		$failopen_count = absint( get_transient( 'mailodds_suppression_failopen_count' ) );
		if ( $failopen_count > 0 ) {
			$since = get_transient( 'mailodds_suppression_failopen_since' );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html( sprintf(
				/* translators: 1: count, 2: date */
				__( 'MailOdds: %1$d emails bypassed suppression check due to API errors since %2$s.', 'mailodds-email-validation' ),
				$failopen_count,
				$since ? $since : __( 'unknown', 'mailodds-email-validation' )
			) );
			echo '</p></div>';
		}
	}

	/**
	 * Register dashboard widget.
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'mailodds_dashboard_widget',
			__( 'MailOdds Email Validation', 'mailodds-email-validation' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render dashboard widget content.
	 *
	 * Shows local stats (always available) plus server-side telemetry
	 * when enabled and the API is reachable.
	 */
	public function render_dashboard_widget() {
		if ( ! $this->api->has_key() ) {
			$settings_url = admin_url( 'options-general.php?page=mailodds' );
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: settings page URL */
					__( '<a href="%s">Configure your API key</a> to start validating emails.', 'mailodds-email-validation' ),
					esc_url( $settings_url )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
			return;
		}

		if ( $this->api->is_test_mode() ) {
			echo '<p><strong>' . esc_html__( 'TEST MODE', 'mailodds-email-validation' ) . '</strong></p>';
		}

		// Server-side telemetry (when enabled)
		$telemetry_enabled = get_option( 'mailodds_telemetry_dashboard', true );
		$telemetry_24h = null;
		$telemetry_30d = null;

		if ( $telemetry_enabled ) {
			$telemetry_24h = get_transient( 'mailodds_telemetry_24h' );
			if ( false === $telemetry_24h ) {
				$result = $this->api->get_telemetry( '24h' );
				if ( ! is_wp_error( $result ) ) {
					// Privacy safeguard: strip topDomains before caching
					unset( $result['topDomains'] );
					$telemetry_24h = $result;
					set_transient( 'mailodds_telemetry_24h', $telemetry_24h, 300 );
				}
			}

			$telemetry_30d = get_transient( 'mailodds_telemetry_30d' );
			if ( false === $telemetry_30d ) {
				$result = $this->api->get_telemetry( '30d' );
				if ( ! is_wp_error( $result ) ) {
					unset( $result['topDomains'] );
					$telemetry_30d = $result;
					set_transient( 'mailodds_telemetry_30d', $telemetry_30d, 300 );
				}
			}
		}

		if ( $telemetry_24h || $telemetry_30d ) {
			// Server-side telemetry display
			?>
			<table class="widefat striped" style="border:0;">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Last 24h', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Last 30 Days', 'mailodds-email-validation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total', 'mailodds-email-validation' ); ?></strong></td>
						<td><?php echo esc_html( $telemetry_24h ? ( isset( $telemetry_24h['total'] ) ? $telemetry_24h['total'] : 0 ) : '-' ); ?></td>
						<td><?php echo esc_html( $telemetry_30d ? ( isset( $telemetry_30d['total'] ) ? $telemetry_30d['total'] : 0 ) : '-' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Deliverable', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $telemetry_24h && isset( $telemetry_24h['deliverable'] ) ? $telemetry_24h['deliverable'] : '-' ); ?></td>
						<td><?php echo esc_html( $telemetry_30d && isset( $telemetry_30d['deliverable'] ) ? $telemetry_30d['deliverable'] : '-' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Rejected', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $telemetry_24h && isset( $telemetry_24h['rejected'] ) ? $telemetry_24h['rejected'] : '-' ); ?></td>
						<td><?php echo esc_html( $telemetry_30d && isset( $telemetry_30d['rejected'] ) ? $telemetry_30d['rejected'] : '-' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Unknown', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $telemetry_24h && isset( $telemetry_24h['unknown'] ) ? $telemetry_24h['unknown'] : '-' ); ?></td>
						<td><?php echo esc_html( $telemetry_30d && isset( $telemetry_30d['unknown'] ) ? $telemetry_30d['unknown'] : '-' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Credits Used', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $telemetry_24h && isset( $telemetry_24h['creditsUsed'] ) ? $telemetry_24h['creditsUsed'] : '-' ); ?></td>
						<td><?php echo esc_html( $telemetry_30d && isset( $telemetry_30d['creditsUsed'] ) ? $telemetry_30d['creditsUsed'] : '-' ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php
			// Top rejection reasons (from 30d data)
			if ( $telemetry_30d && ! empty( $telemetry_30d['topReasons'] ) && is_array( $telemetry_30d['topReasons'] ) ) {
				echo '<p style="margin-top:8px;"><strong>' . esc_html__( 'Top Rejection Reasons (30d)', 'mailodds-email-validation' ) . '</strong></p>';
				echo '<ul style="margin:4px 0 0 16px;list-style:disc;">';
				foreach ( array_slice( $telemetry_30d['topReasons'], 0, 5 ) as $reason ) {
					$label = isset( $reason['reason'] ) ? $reason['reason'] : '';
					$count = isset( $reason['count'] ) ? $reason['count'] : 0;
					echo '<li>' . esc_html( $label ) . ': ' . esc_html( $count ) . '</li>';
				}
				echo '</ul>';
			}
		} else {
			// Fallback to local stats
			$stats = get_option( 'mailodds_daily_stats', array() );
			$today = current_time( 'Y-m-d' );

			$totals = array(
				'total'       => 0,
				'valid'       => 0,
				'invalid'     => 0,
				'catch_all'   => 0,
				'unknown'     => 0,
				'do_not_mail' => 0,
			);
			$today_stats = isset( $stats[ $today ] ) ? $stats[ $today ] : $totals;

			for ( $i = 0; $i < 7; $i++ ) {
				$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
				if ( isset( $stats[ $date ] ) ) {
					foreach ( $totals as $key => $val ) {
						$totals[ $key ] += isset( $stats[ $date ][ $key ] ) ? $stats[ $date ][ $key ] : 0;
					}
				}
			}

			?>
			<table class="widefat striped" style="border:0;">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Today', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Last 7 Days', 'mailodds-email-validation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total', 'mailodds-email-validation' ); ?></strong></td>
						<td><?php echo esc_html( $today_stats['total'] ); ?></td>
						<td><?php echo esc_html( $totals['total'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Valid', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $today_stats['valid'] ); ?></td>
						<td><?php echo esc_html( $totals['valid'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Invalid', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $today_stats['invalid'] ); ?></td>
						<td><?php echo esc_html( $totals['invalid'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Catch-All', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $today_stats['catch_all'] ); ?></td>
						<td><?php echo esc_html( $totals['catch_all'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Do Not Mail', 'mailodds-email-validation' ); ?></td>
						<td><?php echo esc_html( $today_stats['do_not_mail'] ); ?></td>
						<td><?php echo esc_html( $totals['do_not_mail'] ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php
		}
		?>
		<p style="margin-top:12px;">
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=mailodds' ) ); ?>">
				<?php esc_html_e( 'Settings', 'mailodds-email-validation' ); ?>
			</a>
			&nbsp;|&nbsp;
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=mailodds-bulk' ) ); ?>">
				<?php esc_html_e( 'Bulk Validate', 'mailodds-email-validation' ); ?>
			</a>
			&nbsp;|&nbsp;
			<a href="https://mailodds.com/dashboard" target="_blank">
				<?php esc_html_e( 'Full Dashboard', 'mailodds-email-validation' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render store connection section description.
	 */
	public function render_store_section() {
		echo '<p>' . esc_html__(
			'Connect your WooCommerce store to sync products for personalized email campaigns.',
			'mailodds-email-validation'
		) . '</p>';
	}

	/**
	 * Render store connection status and action button.
	 */
	public function render_store_status_field() {
		$is_connected  = get_option( 'mailodds_store_connected', false );
		$store_id      = get_option( 'mailodds_store_id', '' );
		$connected_via = get_option( 'mailodds_connected_via', '' );
		$has_wc        = class_exists( 'WooCommerce' );

		// Connection health check
		$connect = new MailOdds_Connect();
		$health  = $connect->check_connection_health();

		// Show reconnect banner for disconnected state (401 from API)
		if ( 'disconnected' === $health && $is_connected ) {
			echo '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;margin-bottom:12px;">';
			echo '<strong style="color:#991b1b;">' . esc_html__( 'Connection Lost', 'mailodds-email-validation' ) . '</strong>';
			echo '<p style="color:#991b1b;margin:4px 0 8px;">' . esc_html__( 'Your API key was revoked or expired. Click Reconnect to restore the connection.', 'mailodds-email-validation' ) . '</p>';
			echo '<button type="button" class="button button-primary" id="mailodds-oneclick-connect">';
			echo esc_html__( 'Reconnect to MailOdds', 'mailodds-email-validation' );
			echo '</button>';
			echo '<span id="mailodds-store-spinner" class="spinner" style="float:none;margin-top:0;"></span>';
			echo '<span id="mailodds-store-message" style="margin-left:8px;"></span>';
			echo '</div>';
			return;
		}

		if ( $is_connected && ! empty( $store_id ) ) {
			// Health indicator color
			$health_colors = array(
				'connected' => '#10b981',
				'degraded'  => '#f59e0b',
				'disconnected' => '#ef4444',
				'not_configured' => '#a1a1aa',
			);
			$color = isset( $health_colors[ $health ] ) ? $health_colors[ $health ] : '#a1a1aa';

			echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
			echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr( $color ) . ';"></span>';
			echo '<strong>' . esc_html__( 'Connected', 'mailodds-email-validation' ) . '</strong>';
			if ( 'one_click_connect' === $connected_via ) {
				echo ' <span style="font-size:11px;color:#6b7280;">' . esc_html__( '(via one-click)', 'mailodds-email-validation' ) . '</span>';
			}
			echo '</div>';
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: %s: store ID */
				__( 'Store ID: %s', 'mailodds-email-validation' ),
				$store_id
			) ) . '</p>';
			echo '<p style="margin-top:8px;">';
			echo '<button type="button" class="button button-secondary" id="mailodds-disconnect-store">';
			echo esc_html__( 'Disconnect Store', 'mailodds-email-validation' );
			echo '</button>';
			echo '<span id="mailodds-store-spinner" class="spinner" style="float:none;margin-top:0;"></span>';
			echo '<span id="mailodds-store-message" style="margin-left:8px;"></span>';
			echo '</p>';
		} else {
			echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
			echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#a1a1aa;"></span>';
			echo '<strong>' . esc_html__( 'Not Connected', 'mailodds-email-validation' ) . '</strong>';
			echo '</div>';

			// One-click connect as primary CTA
			echo '<p style="margin-top:8px;">';
			echo '<button type="button" class="button button-primary" id="mailodds-oneclick-connect" style="margin-right:8px;">';
			echo esc_html__( 'Connect to MailOdds', 'mailodds-email-validation' );
			echo '</button>';
			echo '<span id="mailodds-store-spinner" class="spinner" style="float:none;margin-top:0;"></span>';
			echo '<span id="mailodds-store-message" style="margin-left:8px;"></span>';
			echo '</p>';
			echo '<p class="description" style="margin-top:4px;">';
			echo esc_html__( 'Click to authorize your store with MailOdds. No API key copy-paste needed.', 'mailodds-email-validation' );
			echo '</p>';

			// Manual setup as secondary option
			if ( $has_wc && $this->api->has_key() ) {
				echo '<details style="margin-top:12px;">';
				echo '<summary style="cursor:pointer;font-size:12px;color:#6b7280;">' . esc_html__( 'Advanced: Manual store connection', 'mailodds-email-validation' ) . '</summary>';
				echo '<p style="margin-top:8px;">';
				echo '<button type="button" class="button button-secondary" id="mailodds-connect-store">';
				echo esc_html__( 'Connect Store (Manual)', 'mailodds-email-validation' );
				echo '</button>';
				echo '</p>';
				echo '<p class="description" style="margin-top:4px;">';
				echo esc_html__( 'Creates read-only WooCommerce API keys and registers your store with MailOdds.', 'mailodds-email-validation' );
				echo '</p>';
				echo '</details>';
			}
		}

		// Show success notice after one-click connect redirect
		if ( isset( $_GET['connected'] ) && '1' === $_GET['connected'] ) {
			$verified = isset( $_GET['verified'] ) && '1' === $_GET['verified'];
			echo '<div class="notice notice-success" style="margin-top:12px;padding:8px 12px;">';
			if ( $verified ) {
				echo '<p>' . esc_html__( 'Store connected and verified successfully.', 'mailodds-email-validation' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Store connected. API verification test was not conclusive; validation should still work.', 'mailodds-email-validation' ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * AJAX handler: connect store.
	 */
	public function ajax_connect_store() {
		check_ajax_referer( 'mailodds-store-nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$handshake = new MailOdds_Handshake();
		$result    = $handshake->connect();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'  => __( 'Store connected successfully.', 'mailodds-email-validation' ),
			'store_id' => isset( $result['store_id'] ) ? $result['store_id'] : '',
		) );
	}

	/**
	 * AJAX handler: disconnect store.
	 */
	public function ajax_disconnect_store() {
		check_ajax_referer( 'mailodds-store-nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$handshake = new MailOdds_Handshake();
		$handshake->disconnect();

		wp_send_json_success( array(
			'message' => __( 'Store disconnected.', 'mailodds-email-validation' ),
		) );
	}
}
