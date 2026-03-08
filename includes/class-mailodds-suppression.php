<?php
/**
 * MailOdds suppression list admin page.
 *
 * Provides an admin page under Tools > MailOdds Suppressions for:
 * - Viewing suppression entries with search, type filter, and pagination
 * - Adding entries via form
 * - Removing entries via AJAX
 * - Viewing suppression stats summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Suppression {

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
		add_action( 'wp_ajax_mailodds_add_suppression', array( $this, 'ajax_add_suppression' ) );
		add_action( 'wp_ajax_mailodds_remove_suppression', array( $this, 'ajax_remove_suppression' ) );
	}

	/**
	 * Add suppression page under Tools menu.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'MailOdds Suppressions', 'mailodds-email-validation' ),
			__( 'MailOdds Suppressions', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-suppressions',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the suppression management page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->api->has_key() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'MailOdds Suppressions', 'mailodds-email-validation' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'API key not configured. Go to Settings > MailOdds to set it up.', 'mailodds-email-validation' ) . '</p></div></div>';
			return;
		}

		$page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$type     = isset( $_GET['suppression_type'] ) ? sanitize_text_field( wp_unslash( $_GET['suppression_type'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$per_page = 25;

		$params = array(
			'page'     => $page,
			'per_page' => $per_page,
		);
		if ( ! empty( $type ) ) {
			$params['type'] = $type;
		}
		if ( ! empty( $search ) ) {
			$params['search'] = $search;
		}

		$list  = $this->api->get_suppression_list( $params );
		$stats = $this->api->get_suppression_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MailOdds Suppressions', 'mailodds-email-validation' ); ?></h1>

			<?php if ( ! is_wp_error( $stats ) && is_array( $stats ) ) : ?>
			<div class="mailodds-suppression-stats" style="display:flex;gap:16px;margin:20px 0;">
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;text-align:center;">
					<div style="font-size:28px;font-weight:600;"><?php echo esc_html( isset( $stats['total'] ) ? $stats['total'] : 0 ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Total Suppressed', 'mailodds-email-validation' ); ?></div>
				</div>
				<?php if ( isset( $stats['by_type'] ) && is_array( $stats['by_type'] ) ) : ?>
					<?php foreach ( $stats['by_type'] as $stat_type => $count ) : ?>
					<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;flex:1;text-align:center;">
						<div style="font-size:28px;font-weight:600;"><?php echo esc_html( $count ); ?></div>
						<div style="color:#646970;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $stat_type ) ) ); ?></div>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div style="display:flex;gap:20px;margin:20px 0;align-items:flex-start;">
				<!-- Add suppression form -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:300px;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Add Suppression', 'mailodds-email-validation' ); ?></h3>
					<p>
						<label><?php esc_html_e( 'Email:', 'mailodds-email-validation' ); ?></label><br>
						<input type="email" id="mailodds-supp-email" class="regular-text" />
					</p>
					<p>
						<label><?php esc_html_e( 'Type:', 'mailodds-email-validation' ); ?></label><br>
						<select id="mailodds-supp-type">
							<option value="hard_bounce"><?php esc_html_e( 'Hard Bounce', 'mailodds-email-validation' ); ?></option>
							<option value="complaint"><?php esc_html_e( 'Complaint', 'mailodds-email-validation' ); ?></option>
							<option value="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'mailodds-email-validation' ); ?></option>
							<option value="manual"><?php esc_html_e( 'Manual', 'mailodds-email-validation' ); ?></option>
						</select>
					</p>
					<p>
						<label><?php esc_html_e( 'Reason (optional):', 'mailodds-email-validation' ); ?></label><br>
						<input type="text" id="mailodds-supp-reason" class="regular-text" />
					</p>
					<button id="mailodds-supp-add" class="button button-primary"><?php esc_html_e( 'Add', 'mailodds-email-validation' ); ?></button>
					<span id="mailodds-supp-add-status" style="margin-left:8px;"></span>
				</div>

				<!-- Search / Filter -->
				<div style="flex:1;">
					<form method="get" style="margin-bottom:16px;">
						<input type="hidden" name="page" value="mailodds-suppressions" />
						<select name="suppression_type">
							<option value=""><?php esc_html_e( 'All Types', 'mailodds-email-validation' ); ?></option>
							<option value="hard_bounce" <?php selected( $type, 'hard_bounce' ); ?>><?php esc_html_e( 'Hard Bounce', 'mailodds-email-validation' ); ?></option>
							<option value="complaint" <?php selected( $type, 'complaint' ); ?>><?php esc_html_e( 'Complaint', 'mailodds-email-validation' ); ?></option>
							<option value="unsubscribe" <?php selected( $type, 'unsubscribe' ); ?>><?php esc_html_e( 'Unsubscribe', 'mailodds-email-validation' ); ?></option>
							<option value="manual" <?php selected( $type, 'manual' ); ?>><?php esc_html_e( 'Manual', 'mailodds-email-validation' ); ?></option>
						</select>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email...', 'mailodds-email-validation' ); ?>" />
						<?php submit_button( __( 'Filter', 'mailodds-email-validation' ), 'secondary', 'filter', false ); ?>
					</form>

					<?php if ( is_wp_error( $list ) ) : ?>
						<div class="notice notice-error"><p><?php echo esc_html( $list->get_error_message() ); ?></p></div>
					<?php elseif ( isset( $list['entries'] ) && is_array( $list['entries'] ) ) : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Email', 'mailodds-email-validation' ); ?></th>
									<th><?php esc_html_e( 'Type', 'mailodds-email-validation' ); ?></th>
									<th><?php esc_html_e( 'Source', 'mailodds-email-validation' ); ?></th>
									<th><?php esc_html_e( 'Created', 'mailodds-email-validation' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $list['entries'] ) ) : ?>
									<tr><td colspan="5"><?php esc_html_e( 'No suppression entries found.', 'mailodds-email-validation' ); ?></td></tr>
								<?php else : ?>
									<?php foreach ( $list['entries'] as $entry ) : ?>
									<tr>
										<td><?php echo esc_html( isset( $entry['email'] ) ? $entry['email'] : '' ); ?></td>
										<td><?php echo esc_html( isset( $entry['type'] ) ? $entry['type'] : '' ); ?></td>
										<td><?php echo esc_html( isset( $entry['source'] ) ? $entry['source'] : '' ); ?></td>
										<td><?php echo esc_html( isset( $entry['created_at'] ) ? $entry['created_at'] : '' ); ?></td>
										<td>
											<button class="button mailodds-supp-remove" data-email="<?php echo esc_attr( isset( $entry['email'] ) ? $entry['email'] : '' ); ?>">
												<?php esc_html_e( 'Remove', 'mailodds-email-validation' ); ?>
											</button>
										</td>
									</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>

						<?php
						$total = isset( $list['total'] ) ? absint( $list['total'] ) : 0;
						$pages = ceil( $total / $per_page );
						if ( $pages > 1 ) :
						?>
						<div class="tablenav bottom">
							<div class="tablenav-pages">
								<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
									<?php if ( $i === $page ) : ?>
										<span class="tablenav-pages-navspan button disabled"><?php echo esc_html( $i ); ?></span>
									<?php else : ?>
										<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo esc_html( $i ); ?></a>
									<?php endif; ?>
								<?php endfor; ?>
							</div>
						</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: add suppression entry.
	 */
	public function ajax_add_suppression() {
		check_ajax_referer( 'mailodds-suppression-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'manual';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Valid email required.', 'mailodds-email-validation' ) ) );
		}

		$entry = array(
			'email' => $email,
			'type'  => $type,
		);
		if ( ! empty( $reason ) ) {
			$entry['reason'] = $reason;
		}

		$result = $this->api->add_suppression( array( $entry ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Entry added.', 'mailodds-email-validation' ) ) );
	}

	/**
	 * AJAX handler: remove suppression entry.
	 */
	public function ajax_remove_suppression() {
		check_ajax_referer( 'mailodds-suppression-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Valid email required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->remove_suppression( array( array( 'email' => $email ) ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Entry removed.', 'mailodds-email-validation' ) ) );
	}
}
