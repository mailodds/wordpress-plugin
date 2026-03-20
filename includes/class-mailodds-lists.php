<?php
/**
 * MailOdds subscriber list management.
 *
 * Admin page for managing subscriber lists with double opt-in support.
 * Also provides a shortcode [mailodds_subscribe] for frontend forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Lists {

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
		add_action( 'wp_ajax_mailodds_list_subscriber_lists', array( $this, 'ajax_list_subscriber_lists' ) );
		add_action( 'wp_ajax_mailodds_create_subscriber_list', array( $this, 'ajax_create_subscriber_list' ) );
		add_action( 'wp_ajax_mailodds_delete_subscriber_list', array( $this, 'ajax_delete_subscriber_list' ) );
		add_action( 'wp_ajax_mailodds_get_subscribers', array( $this, 'ajax_get_subscribers' ) );
		add_action( 'wp_ajax_mailodds_subscribe_email', array( $this, 'ajax_subscribe_email' ) );

		// Public AJAX for frontend subscribe form
		add_action( 'wp_ajax_nopriv_mailodds_public_subscribe', array( $this, 'ajax_public_subscribe' ) );
		add_action( 'wp_ajax_mailodds_public_subscribe', array( $this, 'ajax_public_subscribe' ) );

		// Shortcode
		add_shortcode( 'mailodds_subscribe', array( $this, 'subscribe_shortcode' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'MailOdds Lists', 'mailodds-email-validation' ),
			__( 'MailOdds Lists', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-lists',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the subscriber lists management page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscriber Lists', 'mailodds-email-validation' ); ?></h1>
			<p><?php esc_html_e( 'Manage subscriber lists with double opt-in support. Use [mailodds_subscribe list_id="ID"] shortcode on any page.', 'mailodds-email-validation' ); ?></p>

			<h2><?php esc_html_e( 'Create List', 'mailodds-email-validation' ); ?></h2>
			<form id="mailodds-create-list-form">
				<?php wp_nonce_field( 'mailodds-lists-nonce', 'mailodds_lists_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="mailodds-list-name"><?php esc_html_e( 'Name', 'mailodds-email-validation' ); ?></label></th>
						<td><input type="text" id="mailodds-list-name" class="regular-text" placeholder="Newsletter subscribers" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Double Opt-In', 'mailodds-email-validation' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="mailodds-list-doi" checked />
								<?php esc_html_e( 'Require email confirmation', 'mailodds-email-validation' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create List', 'mailodds-email-validation' ); ?></button>
			</form>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Your Lists', 'mailodds-email-validation' ); ?></h2>
			<div id="mailodds-subscriber-lists"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Add Subscriber', 'mailodds-email-validation' ); ?></h2>
			<form id="mailodds-subscribe-form">
				<input type="email" id="mailodds-subscribe-email" class="regular-text" placeholder="user@example.com" />
				<select id="mailodds-subscribe-list-id">
					<option value=""><?php esc_html_e( 'Select list...', 'mailodds-email-validation' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Subscribe', 'mailodds-email-validation' ); ?></button>
			</form>
			<div id="mailodds-subscribe-result" style="display:none; margin-top:15px;"></div>
		</div>
		<?php
	}

	/**
	 * Subscribe form shortcode.
	 *
	 * Usage: [mailodds_subscribe list_id="abc123" button_text="Subscribe"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function subscribe_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'list_id'     => '',
			'button_text' => __( 'Subscribe', 'mailodds-email-validation' ),
			'placeholder' => __( 'Your email address', 'mailodds-email-validation' ),
		), $atts, 'mailodds_subscribe' );

		if ( empty( $atts['list_id'] ) ) {
			return '<p>' . esc_html__( 'MailOdds subscribe: list_id attribute is required.', 'mailodds-email-validation' ) . '</p>';
		}

		$nonce = wp_create_nonce( 'mailodds-public-subscribe' );
		$id    = 'mailodds-subscribe-' . esc_attr( $atts['list_id'] );

		ob_start();
		?>
		<form class="mailodds-subscribe-form" id="<?php echo esc_attr( $id ); ?>" data-list-id="<?php echo esc_attr( $atts['list_id'] ); ?>">
			<input type="email" name="email" required placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<input type="hidden" name="action" value="mailodds_public_subscribe" />
			<button type="submit"><?php echo esc_html( $atts['button_text'] ); ?></button>
			<div class="mailodds-subscribe-message" style="display:none;"></div>
		</form>
		<script>
		(function(){
			var form = document.getElementById('<?php echo esc_js( $id ); ?>');
			if (!form) return;
			form.addEventListener('submit', function(e) {
				e.preventDefault();
				var email = form.querySelector('input[name="email"]').value;
				var msg = form.querySelector('.mailodds-subscribe-message');
				var fd = new FormData();
				fd.append('action', 'mailodds_public_subscribe');
				fd.append('nonce', form.querySelector('input[name="nonce"]').value);
				fd.append('email', email);
				fd.append('list_id', form.dataset.listId);
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:fd})
					.then(function(r){return r.json();})
					.then(function(data){
						msg.style.display='block';
						msg.textContent = data.success ? (data.data.message || 'Subscribed!') : (data.data.message || 'Error');
					});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	// =========================================================================
	// Admin AJAX Handlers
	// =========================================================================

	public function ajax_list_subscriber_lists() {
		check_ajax_referer( 'mailodds-lists-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->list_subscriber_lists();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_create_subscriber_list() {
		check_ajax_referer( 'mailodds-lists-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$doi  = isset( $_POST['double_opt_in'] ) && 'true' === $_POST['double_opt_in'];
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'List name is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->create_subscriber_list( array( 'name' => $name, 'double_opt_in' => $doi ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_delete_subscriber_list() {
		check_ajax_referer( 'mailodds-lists-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';
		if ( empty( $list_id ) ) {
			wp_send_json_error( array( 'message' => __( 'List ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->delete_subscriber_list( $list_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'List deleted.', 'mailodds-email-validation' ) ) );
	}

	public function ajax_get_subscribers() {
		check_ajax_referer( 'mailodds-lists-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';
		if ( empty( $list_id ) ) {
			wp_send_json_error( array( 'message' => __( 'List ID is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_subscribers( $list_id, array(
			'page'     => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
			'per_page' => 25,
		) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_subscribe_email() {
		check_ajax_referer( 'mailodds-lists-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';
		if ( empty( $email ) || empty( $list_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Email and list ID are required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->subscribe( array( 'email' => $email, 'list_id' => $list_id ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// Public AJAX Handler (no auth required)
	// =========================================================================

	/**
	 * Public subscribe handler for frontend shortcode forms.
	 */
	public function ajax_public_subscribe() {
		check_ajax_referer( 'mailodds-public-subscribe', 'nonce' );

		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';

		if ( empty( $email ) || empty( $list_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'mailodds-email-validation' ) ) );
		}

		if ( ! $this->api->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'Subscribe service is not configured.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->subscribe( array( 'email' => $email, 'list_id' => $list_id ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Please check your email to confirm your subscription.', 'mailodds-email-validation' ) ) );
	}
}
