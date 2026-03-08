<?php
/**
 * MailOdds validation policies admin page.
 *
 * Provides an admin page under Settings > MailOdds Policies for:
 * - Listing policies with enable/disable/delete
 * - Creating policies and from presets
 * - Managing policy rules
 * - Testing policy evaluation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Policies {

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
		add_action( 'wp_ajax_mailodds_create_policy', array( $this, 'ajax_create_policy' ) );
		add_action( 'wp_ajax_mailodds_create_preset', array( $this, 'ajax_create_preset' ) );
		add_action( 'wp_ajax_mailodds_delete_policy', array( $this, 'ajax_delete_policy' ) );
		add_action( 'wp_ajax_mailodds_test_policy', array( $this, 'ajax_test_policy' ) );
		add_action( 'wp_ajax_mailodds_add_rule', array( $this, 'ajax_add_rule' ) );
		add_action( 'wp_ajax_mailodds_delete_rule', array( $this, 'ajax_delete_rule' ) );
	}

	/**
	 * Add policies page under Settings menu.
	 */
	public function add_menu_page() {
		add_options_page(
			__( 'MailOdds Policies', 'mailodds-email-validation' ),
			__( 'MailOdds Policies', 'mailodds-email-validation' ),
			'manage_options',
			'mailodds-policies',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the policies management page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->api->has_key() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'MailOdds Policies', 'mailodds-email-validation' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'API key not configured. Go to Settings > MailOdds to set it up.', 'mailodds-email-validation' ) . '</p></div></div>';
			return;
		}

		$policies = $this->api->list_policies();
		$selected = isset( $_GET['policy_id'] ) ? absint( $_GET['policy_id'] ) : 0;
		$detail   = null;

		if ( $selected > 0 ) {
			$detail = $this->api->get_policy( $selected );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MailOdds Validation Policies', 'mailodds-email-validation' ); ?></h1>

			<?php if ( is_wp_error( $policies ) ) : ?>
				<?php
				$error_data = $policies->get_error_data();
				$status     = isset( $error_data['status'] ) ? $error_data['status'] : 0;
				if ( 403 === $status ) :
				?>
					<div class="notice notice-warning"><p>
						<?php esc_html_e( 'Validation policies require a Growth plan or higher.', 'mailodds-email-validation' ); ?>
						<a href="https://mailodds.com/pricing" target="_blank"><?php esc_html_e( 'Upgrade', 'mailodds-email-validation' ); ?></a>
					</p></div>
				<?php else : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $policies->get_error_message() ); ?></p></div>
				<?php endif; ?>
			<?php else : ?>

			<div style="display:flex;gap:20px;margin:20px 0;align-items:flex-start;">
				<!-- Policy list -->
				<div style="flex:1;">
					<h2><?php esc_html_e( 'Your Policies', 'mailodds-email-validation' ); ?></h2>

					<?php
					$policy_list = is_array( $policies ) && isset( $policies['policies'] ) ? $policies['policies'] : ( is_array( $policies ) ? $policies : array() );
					if ( empty( $policy_list ) ) :
					?>
						<p><?php esc_html_e( 'No policies created yet. Create one or use a preset.', 'mailodds-email-validation' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'ID', 'mailodds-email-validation' ); ?></th>
									<th><?php esc_html_e( 'Name', 'mailodds-email-validation' ); ?></th>
									<th><?php esc_html_e( 'Rules', 'mailodds-email-validation' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $policy_list as $policy ) : ?>
								<tr>
									<td><?php echo esc_html( isset( $policy['id'] ) ? $policy['id'] : '' ); ?></td>
									<td>
										<a href="<?php echo esc_url( add_query_arg( 'policy_id', $policy['id'] ) ); ?>">
											<?php echo esc_html( isset( $policy['name'] ) ? $policy['name'] : 'Unnamed' ); ?>
										</a>
									</td>
									<td><?php echo esc_html( isset( $policy['rule_count'] ) ? $policy['rule_count'] : '0' ); ?></td>
									<td>
										<button class="button mailodds-policy-delete" data-id="<?php echo esc_attr( $policy['id'] ); ?>">
											<?php esc_html_e( 'Delete', 'mailodds-email-validation' ); ?>
										</button>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<!-- Quick create from preset -->
					<h3 style="margin-top:30px;"><?php esc_html_e( 'Quick Create from Preset', 'mailodds-email-validation' ); ?></h3>
					<div style="display:flex;gap:8px;">
						<button class="button mailodds-preset-create" data-preset="strict"><?php esc_html_e( 'Strict', 'mailodds-email-validation' ); ?></button>
						<button class="button mailodds-preset-create" data-preset="permissive"><?php esc_html_e( 'Permissive', 'mailodds-email-validation' ); ?></button>
						<button class="button mailodds-preset-create" data-preset="smtp_required"><?php esc_html_e( 'SMTP Required', 'mailodds-email-validation' ); ?></button>
					</div>
					<span id="mailodds-preset-status" style="margin-left:8px;"></span>

					<!-- Create custom policy -->
					<h3 style="margin-top:30px;"><?php esc_html_e( 'Create Custom Policy', 'mailodds-email-validation' ); ?></h3>
					<p>
						<input type="text" id="mailodds-policy-name" placeholder="<?php esc_attr_e( 'Policy name', 'mailodds-email-validation' ); ?>" class="regular-text" />
						<button id="mailodds-policy-create" class="button button-primary"><?php esc_html_e( 'Create', 'mailodds-email-validation' ); ?></button>
						<span id="mailodds-policy-create-status" style="margin-left:8px;"></span>
					</p>

					<!-- Test Policy -->
					<h3 style="margin-top:30px;"><?php esc_html_e( 'Test Policy', 'mailodds-email-validation' ); ?></h3>
					<p>
						<input type="email" id="mailodds-policy-test-email" placeholder="<?php esc_attr_e( 'Email to test', 'mailodds-email-validation' ); ?>" class="regular-text" />
						<input type="number" id="mailodds-policy-test-id" placeholder="<?php esc_attr_e( 'Policy ID', 'mailodds-email-validation' ); ?>" class="small-text" min="1" />
						<button id="mailodds-policy-test" class="button"><?php esc_html_e( 'Test', 'mailodds-email-validation' ); ?></button>
					</p>
					<div id="mailodds-policy-test-result" style="margin-top:8px;"></div>
				</div>

				<!-- Policy detail -->
				<?php if ( $detail && ! is_wp_error( $detail ) ) : ?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:350px;">
					<h3 style="margin-top:0;"><?php echo esc_html( isset( $detail['name'] ) ? $detail['name'] : 'Policy #' . $selected ); ?></h3>

					<?php if ( isset( $detail['rules'] ) && is_array( $detail['rules'] ) ) : ?>
					<h4><?php esc_html_e( 'Rules', 'mailodds-email-validation' ); ?></h4>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Field', 'mailodds-email-validation' ); ?></th>
								<th><?php esc_html_e( 'Operator', 'mailodds-email-validation' ); ?></th>
								<th><?php esc_html_e( 'Value', 'mailodds-email-validation' ); ?></th>
								<th><?php esc_html_e( 'Action', 'mailodds-email-validation' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $detail['rules'] as $rule ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $rule['field'] ) ? $rule['field'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $rule['operator'] ) ? $rule['operator'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $rule['value'] ) ? $rule['value'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $rule['action'] ) ? $rule['action'] : '' ); ?></td>
								<td>
									<button class="button mailodds-rule-delete"
										data-policy="<?php echo esc_attr( $selected ); ?>"
										data-rule="<?php echo esc_attr( isset( $rule['id'] ) ? $rule['id'] : '' ); ?>">
										<?php esc_html_e( 'Remove', 'mailodds-email-validation' ); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>

					<!-- Add rule form -->
					<h4 style="margin-top:20px;"><?php esc_html_e( 'Add Rule', 'mailodds-email-validation' ); ?></h4>
					<p>
						<label><?php esc_html_e( 'Field:', 'mailodds-email-validation' ); ?></label>
						<input type="text" id="mailodds-rule-field" class="regular-text" placeholder="status" />
					</p>
					<p>
						<label><?php esc_html_e( 'Operator:', 'mailodds-email-validation' ); ?></label>
						<select id="mailodds-rule-operator">
							<option value="equals">equals</option>
							<option value="not_equals">not_equals</option>
							<option value="in">in</option>
							<option value="not_in">not_in</option>
						</select>
					</p>
					<p>
						<label><?php esc_html_e( 'Value:', 'mailodds-email-validation' ); ?></label>
						<input type="text" id="mailodds-rule-value" class="regular-text" placeholder="invalid" />
					</p>
					<p>
						<label><?php esc_html_e( 'Action:', 'mailodds-email-validation' ); ?></label>
						<select id="mailodds-rule-action">
							<option value="reject">reject</option>
							<option value="accept">accept</option>
							<option value="accept_with_caution">accept_with_caution</option>
						</select>
					</p>
					<button id="mailodds-rule-add" class="button button-primary" data-policy="<?php echo esc_attr( $selected ); ?>">
						<?php esc_html_e( 'Add Rule', 'mailodds-email-validation' ); ?>
					</button>
					<span id="mailodds-rule-add-status" style="margin-left:8px;"></span>
				</div>
				<?php endif; ?>
			</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX: create custom policy.
	 */
	public function ajax_create_policy() {
		check_ajax_referer( 'mailodds-policy-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Policy name required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->create_policy( array( 'name' => $name ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: create policy from preset.
	 */
	public function ajax_create_preset() {
		check_ajax_referer( 'mailodds-policy-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$preset = isset( $_POST['preset'] ) ? sanitize_text_field( wp_unslash( $_POST['preset'] ) ) : '';
		if ( empty( $preset ) ) {
			wp_send_json_error( array( 'message' => __( 'Preset name required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->create_policy_from_preset( $preset );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: delete policy.
	 */
	public function ajax_delete_policy() {
		check_ajax_referer( 'mailodds-policy-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$id = isset( $_POST['policy_id'] ) ? absint( $_POST['policy_id'] ) : 0;
		if ( $id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid policy ID.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->delete_policy( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Policy deleted.', 'mailodds-email-validation' ) ) );
	}

	/**
	 * AJAX: test policy.
	 */
	public function ajax_test_policy() {
		check_ajax_referer( 'mailodds-policy-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$policy_id = isset( $_POST['policy_id'] ) ? absint( $_POST['policy_id'] ) : 0;

		if ( empty( $email ) || $policy_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Email and policy ID required.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->test_policy( array( 'email' => $email, 'policy_id' => $policy_id ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: add rule to policy.
	 */
	public function ajax_add_rule() {
		check_ajax_referer( 'mailodds-policy-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$policy_id = isset( $_POST['policy_id'] ) ? absint( $_POST['policy_id'] ) : 0;
		$field     = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
		$operator  = isset( $_POST['operator'] ) ? sanitize_text_field( wp_unslash( $_POST['operator'] ) ) : '';
		$value     = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$action    = isset( $_POST['rule_action'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_action'] ) ) : '';

		if ( $policy_id < 1 || empty( $field ) || empty( $operator ) || empty( $value ) || empty( $action ) ) {
			wp_send_json_error( array( 'message' => __( 'All rule fields are required.', 'mailodds-email-validation' ) ) );
		}

		$rule = array(
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
			'action'   => $action,
		);

		$result = $this->api->add_policy_rule( $policy_id, $rule );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: delete rule from policy.
	 */
	public function ajax_delete_rule() {
		check_ajax_referer( 'mailodds-policy-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mailodds-email-validation' ) ) );
		}

		$policy_id = isset( $_POST['policy_id'] ) ? absint( $_POST['policy_id'] ) : 0;
		$rule_id   = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

		if ( $policy_id < 1 || $rule_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid policy or rule ID.', 'mailodds-email-validation' ) ) );
		}

		$result = $this->api->delete_policy_rule( $policy_id, $rule_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Rule deleted.', 'mailodds-email-validation' ) ) );
	}
}
