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
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_mailodds' === $hook || 'tools_page_mailodds-bulk' === $hook ) {
			wp_enqueue_style(
				'mailodds-admin',
				MAILODDS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				MAILODDS_VERSION
			);
		}

		if ( 'tools_page_mailodds-bulk' === $hook ) {
			wp_enqueue_script(
				'mailodds-admin',
				MAILODDS_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				MAILODDS_VERSION,
				true
			);
			wp_localize_script( 'mailodds-admin', 'mailodds_ajax', array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mailodds-bulk-nonce' ),
			) );
		}
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'MailOdds Email Validation', 'mailodds' ),
			__( 'MailOdds', 'mailodds' ),
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
			__( 'API Configuration', 'mailodds' ),
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
			__( 'API Key', 'mailodds' ),
			array( $this, 'render_api_key_field' ),
			'mailodds',
			'mailodds_api_section'
		);

		// Validation Settings section
		add_settings_section(
			'mailodds_validation_section',
			__( 'Validation Settings', 'mailodds' ),
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
			__( 'Validation Depth', 'mailodds' ),
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
			__( 'Block Threshold', 'mailodds' ),
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
			__( 'Policy ID', 'mailodds' ),
			array( $this, 'render_policy_field' ),
			'mailodds',
			'mailodds_validation_section'
		);

		// Integration Toggles section
		add_settings_section(
			'mailodds_integrations_section',
			__( 'Form Integrations', 'mailodds' ),
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
			__( 'Active Integrations', 'mailodds' ),
			array( $this, 'render_integrations_field' ),
			'mailodds',
			'mailodds_integrations_section'
		);

		// Cron section
		add_settings_section(
			'mailodds_cron_section',
			__( 'Scheduled Validation', 'mailodds' ),
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
			__( 'Weekly User Validation', 'mailodds' ),
			array( $this, 'render_cron_field' ),
			'mailodds',
			'mailodds_cron_section'
		);
	}

	/**
	 * Render API section description.
	 */
	public function render_api_section() {
		if ( $this->api->is_test_mode() ) {
			echo '<div class="mailodds-test-badge">';
			echo esc_html__( 'TEST MODE -- Using test API key. No credits consumed.', 'mailodds' );
			echo '</div>';
		}
		echo '<p>' . esc_html__( 'Enter your MailOdds API key. Get one at', 'mailodds' );
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
				__( 'Current key: %s', 'mailodds' ),
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
		echo esc_html__( 'Enhanced (full SMTP check)', 'mailodds' ) . '</option>';
		echo '<option value="standard"' . selected( $value, 'standard', false ) . '>';
		echo esc_html__( 'Standard (syntax + MX only)', 'mailodds' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Enhanced is more accurate but slightly slower.', 'mailodds' ) . '</p>';
	}

	/**
	 * Render action threshold field.
	 */
	public function render_threshold_field() {
		$value = get_option( 'mailodds_action_threshold', 'reject' );
		echo '<select name="mailodds_action_threshold" id="mailodds_action_threshold">';
		echo '<option value="reject"' . selected( $value, 'reject', false ) . '>';
		echo esc_html__( 'Block only rejected emails (recommended)', 'mailodds' ) . '</option>';
		echo '<option value="caution"' . selected( $value, 'caution', false ) . '>';
		echo esc_html__( 'Block rejected + risky (catch-all, role accounts)', 'mailodds' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Controls which emails are blocked on registration and forms.', 'mailodds' ) . '</p>';
	}

	/**
	 * Render policy ID field.
	 */
	public function render_policy_field() {
		$value = get_option( 'mailodds_policy_id', 0 );
		echo '<input type="number" name="mailodds_policy_id" id="mailodds_policy_id" ';
		echo 'value="' . esc_attr( $value ) . '" class="small-text" min="0" />';
		echo '<p class="description">' . esc_html__( 'Optional. Apply a MailOdds policy to all validations. Leave 0 for default.', 'mailodds' ) . '</p>';
	}

	/**
	 * Render integrations section description.
	 */
	public function render_integrations_section() {
		echo '<p>' . esc_html__( 'Enable validation for specific form plugins. Only enable integrations you have installed.', 'mailodds' ) . '</p>';
	}

	/**
	 * Render integrations checkboxes.
	 */
	public function render_integrations_field() {
		$integrations = get_option( 'mailodds_integrations', array() );
		$options = array(
			'wp_registration' => __( 'WordPress Registration', 'mailodds' ),
			'woocommerce'     => __( 'WooCommerce (registration + checkout)', 'mailodds' ),
			'wpforms'         => __( 'WPForms', 'mailodds' ),
			'gravity_forms'   => __( 'Gravity Forms', 'mailodds' ),
			'cf7'             => __( 'Contact Form 7', 'mailodds' ),
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
		echo ' /> ' . esc_html__( 'Validate unvalidated users weekly (50 per run)', 'mailodds' ) . '</label>';

		if ( ! empty( $stats['last_run'] ) ) {
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: 1: last run date, 2: count */
				__( 'Last run: %1$s (%2$d users validated)', 'mailodds' ),
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
					__( 'MailOdds: API key not configured. <a href="%s">Configure it now</a> to start validating emails.', 'mailodds' ),
					esc_url( $settings_url )
				),
				array( 'a' => array( 'href' => array() ) )
			);
			echo '</p></div>';
		}

		// Test mode indicator
		if ( $this->api->is_test_mode() ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'MailOdds is running in TEST MODE. Validations use test domains and do not consume credits.', 'mailodds' );
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
			__( 'MailOdds Email Validation', 'mailodds' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render dashboard widget content.
	 */
	public function render_dashboard_widget() {
		if ( ! $this->api->has_key() ) {
			$settings_url = admin_url( 'options-general.php?page=mailodds' );
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: settings page URL */
					__( '<a href="%s">Configure your API key</a> to start validating emails.', 'mailodds' ),
					esc_url( $settings_url )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
			return;
		}

		$stats = get_option( 'mailodds_daily_stats', array() );
		$today = current_time( 'Y-m-d' );

		// Aggregate last 7 days
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

		if ( $this->api->is_test_mode() ) {
			echo '<p><strong>' . esc_html__( 'TEST MODE', 'mailodds' ) . '</strong></p>';
		}
		?>
		<table class="widefat striped" style="border:0;">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Today', 'mailodds' ); ?></th>
					<th><?php esc_html_e( 'Last 7 Days', 'mailodds' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Total', 'mailodds' ); ?></strong></td>
					<td><?php echo esc_html( $today_stats['total'] ); ?></td>
					<td><?php echo esc_html( $totals['total'] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Valid', 'mailodds' ); ?></td>
					<td><?php echo esc_html( $today_stats['valid'] ); ?></td>
					<td><?php echo esc_html( $totals['valid'] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Invalid', 'mailodds' ); ?></td>
					<td><?php echo esc_html( $today_stats['invalid'] ); ?></td>
					<td><?php echo esc_html( $totals['invalid'] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Catch-All', 'mailodds' ); ?></td>
					<td><?php echo esc_html( $today_stats['catch_all'] ); ?></td>
					<td><?php echo esc_html( $totals['catch_all'] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Do Not Mail', 'mailodds' ); ?></td>
					<td><?php echo esc_html( $today_stats['do_not_mail'] ); ?></td>
					<td><?php echo esc_html( $totals['do_not_mail'] ); ?></td>
				</tr>
			</tbody>
		</table>
		<p style="margin-top:12px;">
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=mailodds' ) ); ?>">
				<?php esc_html_e( 'Settings', 'mailodds' ); ?>
			</a>
			&nbsp;|&nbsp;
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=mailodds-bulk' ) ); ?>">
				<?php esc_html_e( 'Bulk Validate', 'mailodds' ); ?>
			</a>
			&nbsp;|&nbsp;
			<a href="https://mailodds.com/dashboard" target="_blank">
				<?php esc_html_e( 'Full Dashboard', 'mailodds' ); ?>
			</a>
		</p>
		<?php
	}
}
