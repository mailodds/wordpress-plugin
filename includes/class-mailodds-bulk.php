<?php
/**
 * MailOdds bulk validation admin tool.
 *
 * Provides an admin page under Tools > MailOdds Bulk Validate for:
 * - Viewing all WordPress users with their validation status
 * - Bulk validating unvalidated users via AJAX
 * - Smart routing: < 100 users uses synchronous batch, >= 100 uses job-based flow
 * - Job history from list_jobs() API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Bulk {

	/**
	 * API client.
	 *
	 * @var MailOdds_API
	 */
	private $api;

	/**
	 * Threshold for switching to job-based flow.
	 *
	 * @var int
	 */
	private $job_threshold = 100;

	/**
	 * Constructor.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public function __construct( MailOdds_API $api ) {
		$this->api = $api;

		add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
		add_action( 'wp_ajax_mailodds_bulk_validate', array( $this, 'ajax_bulk_validate' ) );
		add_action( 'wp_ajax_mailodds_create_bulk_job', array( $this, 'ajax_create_bulk_job' ) );
		add_action( 'wp_ajax_mailodds_poll_job_status', array( $this, 'ajax_poll_job_status' ) );
		add_action( 'wp_ajax_mailodds_apply_job_results', array( $this, 'ajax_apply_job_results' ) );
		add_action( 'wp_ajax_mailodds_cancel_job', array( $this, 'ajax_cancel_job' ) );
	}

	/**
	 * Add page under Tools menu.
	 */
	public function add_tools_page() {
		add_management_page(
			__( 'MailOdds Bulk Validate', 'mailodds-email-validation' ),
			__( 'MailOdds Bulk', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-bulk',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the bulk validation page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get user counts
		$total_users       = count_users();
		$validated_count   = $this->count_validated_users();
		$unvalidated_count = $total_users['total_users'] - $validated_count;

		// Check for active job
		$active_job = get_transient( 'mailodds_active_bulk_job' );

		// Get recent validated users for the table
		$users = get_users( array(
			'meta_key' => '_mailodds_validated_at',
			'orderby'  => 'meta_value',
			'order'    => 'DESC',
			'number'   => 50,
		) );

		// Get unvalidated users
		$unvalidated_users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => '_mailodds_status',
					'compare' => 'NOT EXISTS',
				),
			),
			'number' => 50,
			'fields' => array( 'ID', 'user_email', 'user_registered' ),
		) );

		// Get job history
		$job_history = array();
		if ( $this->api->has_key() ) {
			$jobs = $this->api->list_jobs( array( 'page' => 1, 'per_page' => 10 ) );
			if ( ! is_wp_error( $jobs ) && isset( $jobs['jobs'] ) ) {
				$job_history = $jobs['jobs'];
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MailOdds Bulk Email Validation', 'mailodds-email-validation' ); ?></h1>

			<?php if ( ! $this->api->has_key() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'API key not configured. Go to Settings > MailOdds to set it up.', 'mailodds-email-validation' ); ?></p>
				</div>
			<?php else : ?>

			<!-- Summary cards -->
			<div class="mailodds-bulk-summary" style="display:flex;gap:16px;margin:20px 0;">
				<div class="mailodds-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;">
					<div style="font-size:28px;font-weight:600;"><?php echo esc_html( $total_users['total_users'] ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Total Users', 'mailodds-email-validation' ); ?></div>
				</div>
				<div class="mailodds-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;">
					<div style="font-size:28px;font-weight:600;color:#00a32a;"><?php echo esc_html( $validated_count ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Validated', 'mailodds-email-validation' ); ?></div>
				</div>
				<div class="mailodds-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;">
					<div style="font-size:28px;font-weight:600;color:#d63638;"><?php echo esc_html( $unvalidated_count ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Unvalidated', 'mailodds-email-validation' ); ?></div>
				</div>
			</div>

			<?php if ( $unvalidated_count > 0 ) : ?>
			<!-- Bulk validate button -->
			<div style="margin:20px 0;">
				<button id="mailodds-bulk-start" class="button button-primary button-large"
					data-count="<?php echo esc_attr( $unvalidated_count ); ?>"
					data-threshold="<?php echo esc_attr( $this->job_threshold ); ?>">
					<?php echo esc_html( sprintf(
						/* translators: %d: number of users */
						__( 'Validate %d Unvalidated Users', 'mailodds-email-validation' ),
						$unvalidated_count
					) ); ?>
				</button>
				<?php if ( $active_job && isset( $active_job['job_id'] ) ) : ?>
				<button id="mailodds-bulk-cancel" class="button" data-job="<?php echo esc_attr( $active_job['job_id'] ); ?>">
					<?php esc_html_e( 'Cancel Active Job', 'mailodds-email-validation' ); ?>
				</button>
				<?php endif; ?>
				<span id="mailodds-bulk-status" style="margin-left:12px;"></span>
			</div>
			<div id="mailodds-bulk-progress" style="display:none;margin:20px 0;">
				<div style="background:#e0e0e0;border-radius:4px;height:24px;width:100%;max-width:600px;">
					<div id="mailodds-bulk-bar" style="background:#2271b1;border-radius:4px;height:24px;width:0%;transition:width 0.3s;"></div>
				</div>
				<p id="mailodds-bulk-text" style="color:#646970;"></p>
			</div>
			<?php endif; ?>

			<?php if ( $active_job && isset( $active_job['job_id'] ) ) : ?>
			<div class="notice notice-info" id="mailodds-active-job-notice"
				data-job-id="<?php echo esc_attr( $active_job['job_id'] ); ?>">
				<p>
					<?php echo esc_html( sprintf(
						/* translators: %s: job ID */
						__( 'Active validation job: %s. Checking status...', 'mailodds-email-validation' ),
						$active_job['job_id']
					) ); ?>
				</p>
			</div>
			<?php endif; ?>

			<!-- Unvalidated users table -->
			<?php if ( ! empty( $unvalidated_users ) ) : ?>
			<h2><?php esc_html_e( 'Unvalidated Users', 'mailodds-email-validation' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Registered', 'mailodds-email-validation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $unvalidated_users as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo esc_html( $user->user_registered ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Validated users table -->
			<?php if ( ! empty( $users ) ) : ?>
			<h2 style="margin-top:30px;"><?php esc_html_e( 'Recently Validated Users', 'mailodds-email-validation' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Validated', 'mailodds-email-validation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $users as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user->ID, '_mailodds_status', true ) ); ?></td>
						<td>
							<?php
							$action = get_user_meta( $user->ID, '_mailodds_action', true );
							$color  = 'accept' === $action ? '#00a32a' : ( 'reject' === $action ? '#d63638' : '#dba617' );
							echo '<span style="color:' . esc_attr( $color ) . ';">' . esc_html( $action ) . '</span>';
							?>
						</td>
						<td><?php echo esc_html( get_user_meta( $user->ID, '_mailodds_validated_at', true ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Job history -->
			<?php if ( ! empty( $job_history ) ) : ?>
			<h2 style="margin-top:30px;"><?php esc_html_e( 'Job History', 'mailodds-email-validation' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job ID', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Total', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Processed', 'mailodds-email-validation' ); ?></th>
						<th><?php esc_html_e( 'Created', 'mailodds-email-validation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $job_history as $job ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $job['id'] ) ? substr( $job['id'], 0, 8 ) . '...' : '' ); ?></td>
						<td><?php echo esc_html( isset( $job['status'] ) ? $job['status'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $job['total_count'] ) ? $job['total_count'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $job['processed_count'] ) ? $job['processed_count'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $job['created_at'] ) ? $job['created_at'] : '' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler for synchronous bulk validation (< 100 users).
	 *
	 * Processes users in batches of 20 to avoid timeouts.
	 */
	public function ajax_bulk_validate() {
		check_ajax_referer( 'mailodds-bulk-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		if ( ! $this->api->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'API key not configured.', 'mailodds-email-validation' ) ) );
		}

		$batch_size = 20;
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		// Get unvalidated users
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
			wp_send_json_success( array(
				'done'      => true,
				'processed' => $offset,
			) );
		}

		$emails   = array();
		$user_map = array();
		foreach ( $users as $user ) {
			$emails[] = $user->user_email;
			$user_map[ $user->user_email ] = $user->ID;
		}

		$results = $this->api->validate_batch( $emails );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		$processed = 0;
		foreach ( $results as $item ) {
			$email = isset( $item['email'] ) ? $item['email'] : '';
			if ( isset( $user_map[ $email ] ) ) {
				$user_id = $user_map[ $email ];
				update_user_meta( $user_id, '_mailodds_status', sanitize_text_field( $item['status'] ) );
				update_user_meta( $user_id, '_mailodds_action', sanitize_text_field( $item['action'] ) );
				update_user_meta( $user_id, '_mailodds_validated_at', current_time( 'mysql' ) );
				$processed++;
			}
		}

		wp_send_json_success( array(
			'done'      => false,
			'processed' => $offset + $processed,
			'batch'     => $processed,
		) );
	}

	/**
	 * AJAX handler: create a bulk validation job (>= 100 users).
	 */
	public function ajax_create_bulk_job() {
		check_ajax_referer( 'mailodds-bulk-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		if ( ! $this->api->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'API key not configured.', 'mailodds-email-validation' ) ) );
		}

		// Get all unvalidated user emails
		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => '_mailodds_status',
					'compare' => 'NOT EXISTS',
				),
			),
			'fields' => array( 'ID', 'user_email' ),
		) );

		if ( empty( $users ) ) {
			wp_send_json_error( array( 'message' => __( 'No unvalidated users found.', 'mailodds-email-validation' ) ) );
		}

		$emails = array();
		foreach ( $users as $user ) {
			$emails[] = $user->user_email;
		}

		$depth     = get_option( 'mailodds_depth', 'enhanced' );
		$policy_id = absint( get_option( 'mailodds_policy_id', 0 ) );

		$options = array();
		if ( 'standard' === $depth ) {
			$options['depth'] = 'standard';
		}
		if ( $policy_id > 0 ) {
			$options['policy_id'] = $policy_id;
		}

		$result = $this->api->create_job( $emails, $options );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$job_id = isset( $result['id'] ) ? $result['id'] : '';
		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Job creation failed: no job ID returned.', 'mailodds-email-validation' ) ) );
		}

		// Store active job reference
		set_transient( 'mailodds_active_bulk_job', array(
			'job_id'     => $job_id,
			'created_at' => current_time( 'mysql' ),
			'total'      => count( $emails ),
		), 2 * DAY_IN_SECONDS );

		wp_send_json_success( array(
			'job_id' => $job_id,
			'total'  => count( $emails ),
		) );
	}

	/**
	 * AJAX handler: poll job status.
	 */
	public function ajax_poll_job_status() {
		check_ajax_referer( 'mailodds-bulk-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Job ID required.', 'mailodds-email-validation' ) ) );
		}

		$job = $this->api->get_job( $job_id );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		wp_send_json_success( array(
			'status'          => isset( $job['status'] ) ? $job['status'] : 'unknown',
			'total_count'     => isset( $job['total_count'] ) ? $job['total_count'] : 0,
			'processed_count' => isset( $job['processed_count'] ) ? $job['processed_count'] : 0,
		) );
	}

	/**
	 * AJAX handler: apply job results to user meta.
	 */
	public function ajax_apply_job_results() {
		check_ajax_referer( 'mailodds-bulk-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$page   = isset( $_POST['results_page'] ) ? absint( $_POST['results_page'] ) : 1;

		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Job ID required.', 'mailodds-email-validation' ) ) );
		}

		$results = $this->api->get_job_results( $job_id, array(
			'page'     => $page,
			'per_page' => 100,
		) );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		$items = isset( $results['results'] ) ? $results['results'] : array();
		$applied = 0;

		foreach ( $items as $item ) {
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

		$has_more = count( $items ) >= 100;

		if ( ! $has_more ) {
			// Clear active job transient
			delete_transient( 'mailodds_active_bulk_job' );
		}

		wp_send_json_success( array(
			'applied'   => $applied,
			'has_more'  => $has_more,
			'next_page' => $page + 1,
		) );
	}

	/**
	 * AJAX handler: cancel an active job.
	 */
	public function ajax_cancel_job() {
		check_ajax_referer( 'mailodds-bulk-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( empty( $job_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Job ID required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->cancel_job( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		delete_transient( 'mailodds_active_bulk_job' );

		wp_send_json_success( array( 'message' => __( 'Job cancelled.', 'mailodds-email-validation' ) ) );
	}

	/**
	 * Count users that have been validated.
	 *
	 * @return int
	 */
	private function count_validated_users() {
		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => '_mailodds_status',
					'compare' => 'EXISTS',
				),
			),
			'count_total' => true,
			'fields'      => 'ID',
		) );
		return count( $users );
	}
}
