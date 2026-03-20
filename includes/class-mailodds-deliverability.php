<?php
/**
 * MailOdds deliverability dashboard.
 *
 * Admin page showing bounce statistics, complaint assessment, sender health,
 * sending stats, and bounce analysis tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Deliverability {

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

		// AJAX handlers
		add_action( 'wp_ajax_mailodds_bounce_stats', array( $this, 'ajax_bounce_stats' ) );
		add_action( 'wp_ajax_mailodds_complaint_assessment', array( $this, 'ajax_complaint_assessment' ) );
		add_action( 'wp_ajax_mailodds_sender_health', array( $this, 'ajax_sender_health' ) );
		add_action( 'wp_ajax_mailodds_sending_stats', array( $this, 'ajax_sending_stats' ) );
		add_action( 'wp_ajax_mailodds_create_bounce_analysis', array( $this, 'ajax_create_bounce_analysis' ) );
		add_action( 'wp_ajax_mailodds_get_reputation', array( $this, 'ajax_get_reputation' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'MailOdds Deliverability', 'mailodds-email-validation' ),
			__( 'MailOdds Deliverability', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-deliverability',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the deliverability dashboard page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Deliverability Dashboard', 'mailodds-email-validation' ); ?></h1>

			<div class="mailodds-dashboard-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">

				<div class="card">
					<h2><?php esc_html_e( 'Bounce Summary', 'mailodds-email-validation' ); ?></h2>
					<div id="mailodds-bounce-summary"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Complaint Assessment', 'mailodds-email-validation' ); ?></h2>
					<div id="mailodds-complaint-assessment"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Sender Health', 'mailodds-email-validation' ); ?></h2>
					<div id="mailodds-sender-health"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Sender Reputation', 'mailodds-email-validation' ); ?></h2>
					<div id="mailodds-reputation"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
				</div>

			</div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Sending Statistics', 'mailodds-email-validation' ); ?></h2>
			<div id="mailodds-sending-stats"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Bounce Analysis', 'mailodds-email-validation' ); ?></h2>
			<p><?php esc_html_e( 'Paste bounce log text to analyze and classify bounces.', 'mailodds-email-validation' ); ?></p>
			<form id="mailodds-bounce-analysis-form">
				<?php wp_nonce_field( 'mailodds-deliverability-nonce', 'mailodds_deliverability_nonce' ); ?>
				<textarea id="mailodds-bounce-log" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'Paste bounce log text here...', 'mailodds-email-validation' ); ?>"></textarea>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Analyze Bounces', 'mailodds-email-validation' ); ?></button>
				</p>
			</form>
			<div id="mailodds-bounce-analysis-result" style="display:none; margin-top:15px;"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: Get bounce stats summary.
	 */
	public function ajax_bounce_stats() {
		check_ajax_referer( 'mailodds-deliverability-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->get_bounce_stats_summary();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get complaint assessment.
	 */
	public function ajax_complaint_assessment() {
		check_ajax_referer( 'mailodds-deliverability-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->get_complaint_assessment();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get sender health.
	 */
	public function ajax_sender_health() {
		check_ajax_referer( 'mailodds-deliverability-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->get_sender_health();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get sending stats.
	 */
	public function ajax_sending_stats() {
		check_ajax_referer( 'mailodds-deliverability-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->get_sending_stats();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Create bounce analysis.
	 */
	public function ajax_create_bounce_analysis() {
		check_ajax_referer( 'mailodds-deliverability-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( empty( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Bounce log text is required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->create_bounce_analysis( array( 'text' => $text ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get sender reputation.
	 */
	public function ajax_get_reputation() {
		check_ajax_referer( 'mailodds-deliverability-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->get_reputation();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
}
