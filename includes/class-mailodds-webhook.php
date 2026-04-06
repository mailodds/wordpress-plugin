<?php
/**
 * MailOdds webhook receiver for async job completion.
 *
 * Listens for job.completed events via WP REST API endpoint.
 * Verifies HMAC-SHA256 signature. Closed by default (rejects
 * all requests when webhook secret is not configured).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_Webhook {

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

		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the webhook endpoint.
	 */
	public function register_route() {
		register_rest_route( 'mailodds/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			// phpcs:ignore WordPress.Security.PermissionCallback -- Public endpoint; auth is handled via HMAC-SHA256 signature verification in handle_webhook().
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Handle incoming webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( $request ) {
		$secret = get_option( 'mailodds_webhook_secret', '' );
		if ( empty( $secret ) ) {
			return new WP_Error( 'mailodds_webhook_disabled', __( 'Webhook not configured.', 'mailodds-email-validation' ), array( 'status' => 403 ) );
		}

		// Verify HMAC-SHA256 signature
		$signature = $request->get_header( 'X-MailOdds-Signature' );
		if ( empty( $signature ) ) {
			return new WP_Error( 'mailodds_webhook_no_signature', __( 'Missing signature.', 'mailodds-email-validation' ), array( 'status' => 401 ) );
		}

		$body    = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'mailodds_webhook_invalid_signature', __( 'Invalid signature.', 'mailodds-email-validation' ), array( 'status' => 401 ) );
		}

		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mailodds_webhook_bad_payload', __( 'Invalid payload.', 'mailodds-email-validation' ), array( 'status' => 400 ) );
		}

		$event = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';

		if ( 'job.completed' === $event && isset( $payload['job_id'] ) ) {
			$job_id = sanitize_text_field( $payload['job_id'] );
			if ( ! empty( $job_id ) ) {
				$this->process_job_completion( $job_id );
			}
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Process a completed job by fetching and applying results.
	 *
	 * @param string $job_id Job ID.
	 */
	private function process_job_completion( $job_id ) {
		// Check if this is the active bulk job
		$active = get_transient( 'mailodds_active_bulk_job' );
		if ( ! $active || ! isset( $active['job_id'] ) || $active['job_id'] !== $job_id ) {
			return;
		}

		$page = 1;
		while ( true ) {
			$results = $this->api->get_job_results( $job_id, array( 'page' => $page, 'per_page' => 100 ) );

			if ( is_wp_error( $results ) || empty( $results['results'] ) ) {
				break;
			}

			foreach ( $results['results'] as $item ) {
				if ( ! isset( $item['email'] ) ) {
					continue;
				}

				$user = get_user_by( 'email', $item['email'] );
				if ( $user ) {
					update_user_meta( $user->ID, '_mailodds_status', sanitize_text_field( $item['status'] ) );
					update_user_meta( $user->ID, '_mailodds_action', sanitize_text_field( $item['action'] ) );
					update_user_meta( $user->ID, '_mailodds_validated_at', current_time( 'mysql' ) );
				}
			}

			if ( count( $results['results'] ) < 100 ) {
				break;
			}
			$page++;
		}

		// Clear the active job transient
		delete_transient( 'mailodds_active_bulk_job' );

		// Also clear cron job reference if it matches
		$cron_job_id = get_option( 'mailodds_cron_job_id', '' );
		if ( $cron_job_id === $job_id ) {
			delete_option( 'mailodds_cron_job_id' );
		}
	}
}
