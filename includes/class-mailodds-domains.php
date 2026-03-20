<?php
/**
 * MailOdds sending domain management.
 *
 * Admin page for registering, verifying, and managing sending domains.
 * Required for transactional email sending via the MailOdds API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Domains {

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
		add_action( 'wp_ajax_mailodds_add_domain', array( $this, 'ajax_add_domain' ) );
		add_action( 'wp_ajax_mailodds_verify_domain', array( $this, 'ajax_verify_domain' ) );
		add_action( 'wp_ajax_mailodds_delete_domain', array( $this, 'ajax_delete_domain' ) );
		add_action( 'wp_ajax_mailodds_domain_score', array( $this, 'ajax_domain_score' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'options-general.php',
			__( 'MailOdds Domains', 'mailodds-email-validation' ),
			__( 'MailOdds Domains', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-domains',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the domains management page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$domains = array();
		if ( $this->api->has_key() ) {
			$result = $this->api->list_sending_domains();
			if ( ! is_wp_error( $result ) && isset( $result['domains'] ) ) {
				$domains = $result['domains'];
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sending Domains', 'mailodds-email-validation' ); ?></h1>
			<p><?php esc_html_e( 'Register and verify domains for transactional email sending.', 'mailodds-email-validation' ); ?></p>

			<h2><?php esc_html_e( 'Add Domain', 'mailodds-email-validation' ); ?></h2>
			<form id="mailodds-add-domain-form">
				<?php wp_nonce_field( 'mailodds-domain-nonce', 'mailodds_domain_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="mailodds-domain"><?php esc_html_e( 'Domain', 'mailodds-email-validation' ); ?></label></th>
						<td>
							<input type="text" id="mailodds-domain" class="regular-text" placeholder="example.com" />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Domain', 'mailodds-email-validation' ); ?></button>
						</td>
					</tr>
				</table>
			</form>
			<div id="mailodds-domain-message" style="display:none;"></div>

			<h2><?php esc_html_e( 'Registered Domains', 'mailodds-email-validation' ); ?></h2>
			<?php if ( empty( $domains ) ) : ?>
				<p><?php esc_html_e( 'No sending domains registered yet.', 'mailodds-email-validation' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Domain', 'mailodds-email-validation' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mailodds-email-validation' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mailodds-email-validation' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mailodds-email-validation' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $domains as $domain ) : ?>
						<tr data-domain-id="<?php echo esc_attr( $domain['id'] ); ?>">
							<td><?php echo esc_html( $domain['domain'] ); ?></td>
							<td>
								<span class="mailodds-domain-status"><?php echo esc_html( isset( $domain['status'] ) ? $domain['status'] : 'pending' ); ?></span>
							</td>
							<td><?php echo esc_html( isset( $domain['created_at'] ) ? $domain['created_at'] : '-' ); ?></td>
							<td>
								<button class="button mailodds-verify-domain" data-id="<?php echo esc_attr( $domain['id'] ); ?>"><?php esc_html_e( 'Verify DNS', 'mailodds-email-validation' ); ?></button>
								<button class="button mailodds-domain-score-btn" data-id="<?php echo esc_attr( $domain['id'] ); ?>"><?php esc_html_e( 'Score', 'mailodds-email-validation' ); ?></button>
								<button class="button mailodds-delete-domain" data-id="<?php echo esc_attr( $domain['id'] ); ?>"><?php esc_html_e( 'Delete', 'mailodds-email-validation' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<div id="mailodds-domain-detail" style="display:none; margin-top:20px;"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: Add a sending domain.
	 */
	public function ajax_add_domain() {
		check_ajax_referer( 'mailodds-domain-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if ( empty( $domain ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain is required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->create_sending_domain( $domain );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Verify sending domain DNS.
	 */
	public function ajax_verify_domain() {
		check_ajax_referer( 'mailodds-domain-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$domain_id = isset( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		if ( empty( $domain_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain ID is required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->verify_sending_domain( $domain_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Delete a sending domain.
	 */
	public function ajax_delete_domain() {
		check_ajax_referer( 'mailodds-domain-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$domain_id = isset( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		if ( empty( $domain_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain ID is required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->delete_sending_domain( $domain_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Domain deleted.', 'mailodds-email-validation' ) ) );
	}

	/**
	 * AJAX: Get domain identity score.
	 */
	public function ajax_domain_score() {
		check_ajax_referer( 'mailodds-domain-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$domain_id = isset( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		if ( empty( $domain_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Domain ID is required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->get_identity_score( $domain_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
}
