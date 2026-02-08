<?php
/**
 * MailOdds bulk validation admin tool.
 *
 * Provides an admin page under Tools > MailOdds Bulk Validate for:
 * - Viewing all WordPress users with their validation status
 * - Bulk validating unvalidated users via AJAX
 * - Exporting validation results
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
	 * Constructor.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public function __construct( MailOdds_API $api ) {
		$this->api = $api;

		add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
		add_action( 'wp_ajax_mailodds_bulk_validate', array( $this, 'ajax_bulk_validate' ) );
	}

	/**
	 * Add page under Tools menu.
	 */
	public function add_tools_page() {
		add_management_page(
			__( 'MailOdds Bulk Validate', 'mailodds' ),
			__( 'MailOdds Bulk', 'mailodds' ),
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MailOdds Bulk Email Validation', 'mailodds' ); ?></h1>

			<?php if ( ! $this->api->has_key() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'API key not configured. Go to Settings > MailOdds to set it up.', 'mailodds' ); ?></p>
				</div>
			<?php else : ?>

			<!-- Summary cards -->
			<div class="mailodds-bulk-summary" style="display:flex;gap:16px;margin:20px 0;">
				<div class="mailodds-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;">
					<div style="font-size:28px;font-weight:600;"><?php echo esc_html( $total_users['total_users'] ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Total Users', 'mailodds' ); ?></div>
				</div>
				<div class="mailodds-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;">
					<div style="font-size:28px;font-weight:600;color:#00a32a;"><?php echo esc_html( $validated_count ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Validated', 'mailodds' ); ?></div>
				</div>
				<div class="mailodds-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;">
					<div style="font-size:28px;font-weight:600;color:#d63638;"><?php echo esc_html( $unvalidated_count ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Unvalidated', 'mailodds' ); ?></div>
				</div>
			</div>

			<?php if ( $unvalidated_count > 0 ) : ?>
			<!-- Bulk validate button -->
			<div style="margin:20px 0;">
				<button id="mailodds-bulk-start" class="button button-primary button-large">
					<?php echo esc_html( sprintf(
						/* translators: %d: number of users */
						__( 'Validate %d Unvalidated Users', 'mailodds' ),
						$unvalidated_count
					) ); ?>
				</button>
				<span id="mailodds-bulk-status" style="margin-left:12px;"></span>
			</div>
			<div id="mailodds-bulk-progress" style="display:none;margin:20px 0;">
				<div style="background:#e0e0e0;border-radius:4px;height:24px;width:100%;max-width:600px;">
					<div id="mailodds-bulk-bar" style="background:#2271b1;border-radius:4px;height:24px;width:0%;transition:width 0.3s;"></div>
				</div>
				<p id="mailodds-bulk-text" style="color:#646970;"></p>
			</div>
			<?php endif; ?>

			<!-- Unvalidated users table -->
			<?php if ( ! empty( $unvalidated_users ) ) : ?>
			<h2><?php esc_html_e( 'Unvalidated Users', 'mailodds' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'mailodds' ); ?></th>
						<th><?php esc_html_e( 'Registered', 'mailodds' ); ?></th>
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
			<h2 style="margin-top:30px;"><?php esc_html_e( 'Recently Validated Users', 'mailodds' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'mailodds' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mailodds' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mailodds' ); ?></th>
						<th><?php esc_html_e( 'Validated', 'mailodds' ); ?></th>
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

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler for bulk validation.
	 *
	 * Processes users in batches of 20 to avoid timeouts.
	 */
	public function ajax_bulk_validate() {
		check_ajax_referer( 'mailodds-bulk-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds' ) ) );
		}

		if ( ! $this->api->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'API key not configured.', 'mailodds' ) ) );
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
