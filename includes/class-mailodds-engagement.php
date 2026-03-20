<?php
/**
 * MailOdds engagement scoring and out-of-office detection.
 *
 * Admin page for viewing engagement summaries, individual scores,
 * disengaged contacts, and out-of-office status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Engagement {

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

		// Engagement AJAX
		add_action( 'wp_ajax_mailodds_engagement_summary', array( $this, 'ajax_engagement_summary' ) );
		add_action( 'wp_ajax_mailodds_engagement_score', array( $this, 'ajax_engagement_score' ) );
		add_action( 'wp_ajax_mailodds_disengaged', array( $this, 'ajax_disengaged' ) );
		add_action( 'wp_ajax_mailodds_suppress_disengaged', array( $this, 'ajax_suppress_disengaged' ) );

		// OOO AJAX
		add_action( 'wp_ajax_mailodds_list_ooo', array( $this, 'ajax_list_ooo' ) );
		add_action( 'wp_ajax_mailodds_check_ooo', array( $this, 'ajax_check_ooo' ) );
		add_action( 'wp_ajax_mailodds_batch_ooo', array( $this, 'ajax_batch_ooo' ) );
		add_action( 'wp_ajax_mailodds_delete_ooo', array( $this, 'ajax_delete_ooo' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'MailOdds Engagement', 'mailodds-email-validation' ),
			__( 'MailOdds Engagement', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-engagement',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the engagement page with tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'engagement';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Engagement & Out-of-Office', 'mailodds-email-validation' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'engagement', admin_url( 'tools.php?page=mailodds-engagement' ) ) ); ?>"
				   class="nav-tab <?php echo 'engagement' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Engagement', 'mailodds-email-validation' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'ooo', admin_url( 'tools.php?page=mailodds-engagement' ) ) ); ?>"
				   class="nav-tab <?php echo 'ooo' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Out-of-Office', 'mailodds-email-validation' ); ?>
				</a>
			</nav>

			<?php wp_nonce_field( 'mailodds-engagement-nonce', 'mailodds_engagement_nonce' ); ?>

			<div class="tab-content" style="margin-top:20px;">
				<?php
				if ( 'ooo' === $active_tab ) {
					$this->render_ooo_tab();
				} else {
					$this->render_engagement_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render engagement tab.
	 */
	private function render_engagement_tab() {
		?>
		<div class="mailodds-dashboard-grid" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
			<div class="card">
				<h2><?php esc_html_e( 'Summary', 'mailodds-email-validation' ); ?></h2>
				<div id="mailodds-engagement-summary"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
			</div>
		</div>

		<h2 style="margin-top:20px;"><?php esc_html_e( 'Check Individual Score', 'mailodds-email-validation' ); ?></h2>
		<form id="mailodds-engagement-score-form">
			<input type="email" id="mailodds-engagement-email" class="regular-text" placeholder="user@example.com" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Check Score', 'mailodds-email-validation' ); ?></button>
		</form>
		<div id="mailodds-engagement-score-result" style="display:none; margin-top:15px;"></div>

		<h2 style="margin-top:20px;"><?php esc_html_e( 'Disengaged Contacts', 'mailodds-email-validation' ); ?></h2>
		<p>
			<button id="mailodds-load-disengaged" class="button"><?php esc_html_e( 'Load Disengaged', 'mailodds-email-validation' ); ?></button>
			<button id="mailodds-suppress-disengaged-btn" class="button" style="margin-left:10px;"><?php esc_html_e( 'Suppress Disengaged (Dry Run)', 'mailodds-email-validation' ); ?></button>
		</p>
		<div id="mailodds-disengaged-list" style="display:none; margin-top:15px;"></div>
		<?php
	}

	/**
	 * Render OOO tab.
	 */
	private function render_ooo_tab() {
		?>
		<h2><?php esc_html_e( 'Out-of-Office Detection', 'mailodds-email-validation' ); ?></h2>
		<p><?php esc_html_e( 'Check which contacts are currently out of office.', 'mailodds-email-validation' ); ?></p>

		<h3><?php esc_html_e( 'Check Single Email', 'mailodds-email-validation' ); ?></h3>
		<form id="mailodds-ooo-check-form">
			<input type="email" id="mailodds-ooo-email" class="regular-text" placeholder="user@example.com" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Check OOO', 'mailodds-email-validation' ); ?></button>
		</form>
		<div id="mailodds-ooo-check-result" style="display:none; margin-top:15px;"></div>

		<h3 style="margin-top:20px;"><?php esc_html_e( 'Batch Check WordPress Users', 'mailodds-email-validation' ); ?></h3>
		<p>
			<button id="mailodds-batch-ooo-btn" class="button button-primary"><?php esc_html_e( 'Check All Users', 'mailodds-email-validation' ); ?></button>
		</p>
		<div id="mailodds-batch-ooo-result" style="display:none; margin-top:15px;"></div>

		<h3 style="margin-top:20px;"><?php esc_html_e( 'Known OOO Contacts', 'mailodds-email-validation' ); ?></h3>
		<div id="mailodds-ooo-list"><?php esc_html_e( 'Loading...', 'mailodds-email-validation' ); ?></div>
		<?php
	}

	// =========================================================================
	// Engagement AJAX Handlers
	// =========================================================================

	public function ajax_engagement_summary() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_engagement_summary();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_engagement_score() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_engagement_score( $email );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_disengaged() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$params = array(
			'page'     => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
			'per_page' => 25,
		);
		$result = $this->api->get_disengaged( $params );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_suppress_disengaged() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->suppress_disengaged( array( 'dry_run' => true ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// OOO AJAX Handlers
	// =========================================================================

	public function ajax_list_ooo() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->list_ooo_contacts( array( 'per_page' => 50 ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_check_ooo() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->get_ooo_status( $email );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_batch_ooo() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$users = get_users( array(
			'number' => 100,
			'fields' => array( 'user_email' ),
		) );
		$emails = array();
		foreach ( $users as $user ) {
			$emails[] = $user->user_email;
		}
		if ( empty( $emails ) ) {
			wp_send_json_error( array( 'message' => __( 'No users found.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->batch_check_ooo( $emails );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_delete_ooo() {
		check_ajax_referer( 'mailodds-engagement-nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email is required.', 'mailodds-email-validation' ) ) );
		}
		$result = $this->api->delete_ooo_contact( $email );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'OOO record deleted.', 'mailodds-email-validation' ) ) );
	}
}
