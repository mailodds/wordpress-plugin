<?php
/**
 * MailOdds spam check and content classification.
 *
 * Admin page for running spam scoring and AI content classification
 * on email content before sending.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Spam_Check {

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
		add_action( 'wp_ajax_mailodds_run_spam_check', array( $this, 'ajax_run_spam_check' ) );
		add_action( 'wp_ajax_mailodds_classify_content', array( $this, 'ajax_classify_content' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'MailOdds Spam Check', 'mailodds-email-validation' ),
			__( 'MailOdds Spam Check', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-spam-check',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the spam check page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spam Check & Content Analysis', 'mailodds-email-validation' ); ?></h1>

			<h2><?php esc_html_e( 'Spam Score Check', 'mailodds-email-validation' ); ?></h2>
			<p><?php esc_html_e( 'Check your email content against spam filters before sending.', 'mailodds-email-validation' ); ?></p>

			<form id="mailodds-spam-check-form">
				<?php wp_nonce_field( 'mailodds-spam-check-nonce', 'mailodds_spam_check_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="mailodds-spam-from"><?php esc_html_e( 'From', 'mailodds-email-validation' ); ?></label></th>
						<td><input type="email" id="mailodds-spam-from" class="regular-text" placeholder="sender@example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mailodds-spam-subject"><?php esc_html_e( 'Subject', 'mailodds-email-validation' ); ?></label></th>
						<td><input type="text" id="mailodds-spam-subject" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mailodds-spam-html"><?php esc_html_e( 'HTML Body', 'mailodds-email-validation' ); ?></label></th>
						<td><textarea id="mailodds-spam-html" rows="8" class="large-text"></textarea></td>
					</tr>
				</table>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Spam Check', 'mailodds-email-validation' ); ?></button>
				<button type="button" id="mailodds-classify-btn" class="button"><?php esc_html_e( 'Classify Content', 'mailodds-email-validation' ); ?></button>
			</form>

			<div id="mailodds-spam-result" style="display:none; margin-top:20px;"></div>
			<div id="mailodds-classify-result" style="display:none; margin-top:20px;"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: Run spam check.
	 */
	public function ajax_run_spam_check() {
		check_ajax_referer( 'mailodds-spam-check-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$data = array();
		if ( isset( $_POST['from'] ) ) {
			$data['from'] = sanitize_email( wp_unslash( $_POST['from'] ) );
		}
		if ( isset( $_POST['subject'] ) ) {
			$data['subject'] = sanitize_text_field( wp_unslash( $_POST['subject'] ) );
		}
		if ( isset( $_POST['html'] ) ) {
			$data['html'] = wp_kses_post( wp_unslash( $_POST['html'] ) );
		}

		$result = $this->api->run_spam_check( $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Classify content.
	 */
	public function ajax_classify_content() {
		check_ajax_referer( 'mailodds-spam-check-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$data = array();
		if ( isset( $_POST['subject'] ) ) {
			$data['subject'] = sanitize_text_field( wp_unslash( $_POST['subject'] ) );
		}
		if ( isset( $_POST['html'] ) ) {
			$data['html'] = wp_kses_post( wp_unslash( $_POST['html'] ) );
		}

		$result = $this->api->classify_content( $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}
}
