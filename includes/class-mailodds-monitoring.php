<?php
/**
 * MailOdds monitoring dashboard.
 *
 * Unified admin page for DMARC monitoring, blacklist monitoring,
 * server tests, and alert rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Monitoring {

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

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );

		// DMARC AJAX
		add_action( 'wp_ajax_mailodds_list_dmarc', array( $this, 'ajax_list_dmarc' ) );
		add_action( 'wp_ajax_mailodds_add_dmarc', array( $this, 'ajax_add_dmarc' ) );
		add_action( 'wp_ajax_mailodds_verify_dmarc', array( $this, 'ajax_verify_dmarc' ) );
		add_action( 'wp_ajax_mailodds_dmarc_sources', array( $this, 'ajax_dmarc_sources' ) );
		add_action( 'wp_ajax_mailodds_dmarc_recommendation', array( $this, 'ajax_dmarc_recommendation' ) );

		// Blacklist AJAX
		add_action( 'wp_ajax_mailodds_list_blacklist', array( $this, 'ajax_list_blacklist' ) );
		add_action( 'wp_ajax_mailodds_add_blacklist', array( $this, 'ajax_add_blacklist' ) );
		add_action( 'wp_ajax_mailodds_check_blacklist', array( $this, 'ajax_check_blacklist' ) );
		add_action( 'wp_ajax_mailodds_blacklist_history', array( $this, 'ajax_blacklist_history' ) );

		// Server test AJAX
		add_action( 'wp_ajax_mailodds_run_server_test', array( $this, 'ajax_run_server_test' ) );
		add_action( 'wp_ajax_mailodds_get_server_test', array( $this, 'ajax_get_server_test' ) );

		// Alert rules AJAX
		add_action( 'wp_ajax_mailodds_list_alerts', array( $this, 'ajax_list_alerts' ) );
		add_action( 'wp_ajax_mailodds_create_alert', array( $this, 'ajax_create_alert' ) );
		add_action( 'wp_ajax_mailodds_delete_alert', array( $this, 'ajax_delete_alert' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'MailOdds Monitoring', 'mailodds-email-validation' ),
			__( 'MailOdds Monitoring', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-monitoring',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the monitoring page with tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dmarc';
		$tabs = array(
			'dmarc'      => __( 'DMARC', 'mailodds-email-validation' ),
			'blacklist'  => __( 'Blacklist', 'mailodds-email-validation' ),
			'server'     => __( 'Server Tests', 'mailodds-email-validation' ),
			'alerts'     => __( 'Alert Rules', 'mailodds-email-validation' ),
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Monitoring', 'mailodds-email-validation' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_id => $tab_name ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_id, admin_url( 'tools.php?page=mailodds-monitoring' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_name ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php wp_nonce_field( 'mailodds-monitoring-nonce', 'mailodds_monitoring_nonce' ); ?>

			<div class="tab-content" style="margin-top:20px;">
				<?php
				switch ( $active_tab ) {
					case 'dmarc':
						$this->render_dmarc_tab();
						break;
					case 'blacklist':
						$this->render_blacklist_tab();
						break;
					case 'server':
						$this->render_server_tab();
						break;
					case 'alerts':
						$this->render_alerts_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render DMARC monitoring tab.
	 */
	private function render_dmarc_tab() {
		?>
		<h2><?php esc_html_e( 'DMARC Monitoring', 'mailodds-email-validation' ); ?></h2>
		<p><?php esc_html_e( 'Monitor email authentication (SPF, DKIM, DMARC) for your domains.', 'mailodds-email-validation' ); ?></p>

		<form id="mailodds-add-dmarc-form" style="margin-bottom:20px;">
			<input type="text" id="mailodds-dmarc-domain" class="regular-text" placeholder="example.com" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Domain', 'mailodds-email-validation' ); ?></button>
		</form>

		<div id="mailodds-dmarc-list"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
		<div id="mailodds-dmarc-detail" style="display:none; margin-top:20px;"></div>
		<?php
	}

	/**
	 * Render blacklist monitoring tab.
	 */
	private function render_blacklist_tab() {
		?>
		<h2><?php esc_html_e( 'Blacklist Monitoring', 'mailodds-email-validation' ); ?></h2>
		<p><?php esc_html_e( 'Monitor your IPs and domains against email blacklists.', 'mailodds-email-validation' ); ?></p>

		<form id="mailodds-add-blacklist-form" style="margin-bottom:20px;">
			<input type="text" id="mailodds-blacklist-host" class="regular-text" placeholder="IP address or domain" />
			<select id="mailodds-blacklist-type">
				<option value="ip"><?php esc_html_e( 'IP Address', 'mailodds-email-validation' ); ?></option>
				<option value="domain"><?php esc_html_e( 'Domain', 'mailodds-email-validation' ); ?></option>
			</select>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Monitor', 'mailodds-email-validation' ); ?></button>
		</form>

		<div id="mailodds-blacklist-list"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
		<?php
	}

	/**
	 * Render server tests tab.
	 */
	private function render_server_tab() {
		?>
		<h2><?php esc_html_e( 'Server Tests', 'mailodds-email-validation' ); ?></h2>
		<p><?php esc_html_e( 'Run SMTP handshake and DNS audit tests against mail servers.', 'mailodds-email-validation' ); ?></p>

		<form id="mailodds-server-test-form" style="margin-bottom:20px;">
			<input type="text" id="mailodds-server-hostname" class="regular-text" placeholder="mail.example.com" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Test', 'mailodds-email-validation' ); ?></button>
		</form>

		<div id="mailodds-server-test-result" style="display:none;"></div>

		<h3><?php esc_html_e( 'Past Tests', 'mailodds-email-validation' ); ?></h3>
		<div id="mailodds-server-test-list"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
		<?php
	}

	/**
	 * Render alert rules tab.
	 */
	private function render_alerts_tab() {
		?>
		<h2><?php esc_html_e( 'Alert Rules', 'mailodds-email-validation' ); ?></h2>
		<p><?php esc_html_e( 'Configure automated alerts for deliverability metrics.', 'mailodds-email-validation' ); ?></p>

		<form id="mailodds-create-alert-form" style="margin-bottom:20px;">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="mailodds-alert-metric"><?php esc_html_e( 'Metric', 'mailodds-email-validation' ); ?></label></th>
					<td>
						<select id="mailodds-alert-metric">
							<option value="bounce_rate"><?php esc_html_e( 'Bounce Rate', 'mailodds-email-validation' ); ?></option>
							<option value="complaint_rate"><?php esc_html_e( 'Complaint Rate', 'mailodds-email-validation' ); ?></option>
							<option value="delivery_rate"><?php esc_html_e( 'Delivery Rate', 'mailodds-email-validation' ); ?></option>
							<option value="blacklist_listed"><?php esc_html_e( 'Blacklist Listed', 'mailodds-email-validation' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mailodds-alert-threshold"><?php esc_html_e( 'Threshold', 'mailodds-email-validation' ); ?></label></th>
					<td>
						<input type="number" id="mailodds-alert-threshold" step="0.1" min="0" max="100" value="5" class="small-text" />
						<span>%</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mailodds-alert-channel"><?php esc_html_e( 'Notify via', 'mailodds-email-validation' ); ?></label></th>
					<td>
						<select id="mailodds-alert-channel">
							<option value="email"><?php esc_html_e( 'Email', 'mailodds-email-validation' ); ?></option>
							<option value="webhook"><?php esc_html_e( 'Webhook', 'mailodds-email-validation' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Alert', 'mailodds-email-validation' ); ?></button>
		</form>

		<div id="mailodds-alerts-list"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
		<?php
	}

	// =========================================================================
	// DMARC AJAX Handlers
	// =========================================================================

	public function ajax_list_dmarc() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->list_dmarc_domains();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_add_dmarc() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if ( empty( $domain ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->add_dmarc_domain( $domain );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_verify_dmarc() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$domain_id = isset( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		if ( empty( $domain_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->verify_dmarc_domain( $domain_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_dmarc_sources() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$domain_id = isset( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		if ( empty( $domain_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_dmarc_sources( $domain_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_dmarc_recommendation() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$domain_id = isset( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		if ( empty( $domain_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_dmarc_recommendation( $domain_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// Blacklist AJAX Handlers
	// =========================================================================

	public function ajax_list_blacklist() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->list_blacklist_monitors();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_add_blacklist() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'ip';
		if ( empty( $host ) ) {
			wp_send_json_error( array( 'message' => __( 'Host is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->add_blacklist_monitor( array( 'host' => $host, 'type' => $type ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_check_blacklist() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$monitor_id = isset( $_POST['monitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['monitor_id'] ) ) : '';
		if ( empty( $monitor_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Monitor ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->run_blacklist_check( $monitor_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_blacklist_history() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$monitor_id = isset( $_POST['monitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['monitor_id'] ) ) : '';
		if ( empty( $monitor_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Monitor ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_blacklist_history( $monitor_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// Server Test AJAX Handlers
	// =========================================================================

	public function ajax_run_server_test() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$hostname = isset( $_POST['hostname'] ) ? sanitize_text_field( wp_unslash( $_POST['hostname'] ) ) : '';
		if ( empty( $hostname ) ) {
			wp_send_json_error( array( 'message' => __( 'Hostname is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->run_server_test( array( 'hostname' => $hostname ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_get_server_test() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$test_id = isset( $_POST['test_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_id'] ) ) : '';
		if ( empty( $test_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Test ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_server_test( $test_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// Alert Rules AJAX Handlers
	// =========================================================================

	public function ajax_list_alerts() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->list_alert_rules();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_create_alert() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$metric    = isset( $_POST['metric'] ) ? sanitize_text_field( wp_unslash( $_POST['metric'] ) ) : '';
		$threshold = isset( $_POST['threshold'] ) ? floatval( $_POST['threshold'] ) : 0;
		$channel   = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : 'email';

		if ( empty( $metric ) ) {
			wp_send_json_error( array( 'message' => __( 'Metric is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->create_alert_rule( array(
			'metric'    => $metric,
			'threshold' => $threshold,
			'channel'   => $channel,
		) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_delete_alert() {
		check_ajax_referer( 'mailodds-monitoring-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		if ( empty( $rule_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Rule ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->delete_alert_rule( $rule_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Alert rule deleted.', 'mailodds-email-validation' ) ) );
	}
}
