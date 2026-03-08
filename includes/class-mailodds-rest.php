<?php
/**
 * MailOdds WP REST API endpoints.
 *
 * Provides:
 *   POST /wp-json/mailodds/v1/validate         Single email validation
 *   POST /wp-json/mailodds/v1/validate/batch    Batch validation (up to 100)
 *   POST /wp-json/mailodds/v1/suppression/check Suppression check
 *   GET  /wp-json/mailodds/v1/status            Plugin health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_REST {

	/**
	 * API client.
	 *
	 * @var MailOdds_API
	 */
	private $api;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'mailodds/v1';

	/**
	 * Constructor.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public function __construct( MailOdds_API $api ) {
		$this->api = $api;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate_single' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'depth' => array(
					'type'    => 'string',
					'default' => 'enhanced',
					'enum'    => array( 'standard', 'enhanced' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/validate/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate_batch' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'emails' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
				),
				'depth' => array(
					'type'    => 'string',
					'default' => 'enhanced',
					'enum'    => array( 'standard', 'enhanced' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/suppression/check', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'suppression_check' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
			),
		) );

		register_rest_route( $this->namespace, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	/**
	 * Permission check: manage_options capability.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'mailodds-email-validation' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Validate a single email.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_single( $request ) {
		if ( ! $this->api->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'API key not configured.', 'mailodds-email-validation' ), array( 'status' => 500 ) );
		}

		$result = $this->api->validate( $request->get_param( 'email' ), array(
			'depth' => $request->get_param( 'depth' ),
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Validate a batch of emails.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_batch( $request ) {
		if ( ! $this->api->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'API key not configured.', 'mailodds-email-validation' ), array( 'status' => 500 ) );
		}

		$emails = $request->get_param( 'emails' );
		if ( count( $emails ) > 100 ) {
			return new WP_Error( 'mailodds_too_many_emails', __( 'Maximum 100 emails per batch.', 'mailodds-email-validation' ), array( 'status' => 400 ) );
		}

		$result = $this->api->validate_batch( $emails, array(
			'depth' => $request->get_param( 'depth' ),
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'results' => $result ) );
	}

	/**
	 * Check suppression status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suppression_check( $request ) {
		if ( ! $this->api->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'API key not configured.', 'mailodds-email-validation' ), array( 'status' => 500 ) );
		}

		$result = $this->api->check_suppression( $request->get_param( 'email' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get plugin status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$stats = get_option( 'mailodds_daily_stats', array() );
		$today = current_time( 'Y-m-d' );

		return rest_ensure_response( array(
			'version'   => MAILODDS_VERSION,
			'has_key'   => $this->api->has_key(),
			'test_mode' => $this->api->is_test_mode(),
			'depth'     => get_option( 'mailodds_depth', 'enhanced' ),
			'today'     => isset( $stats[ $today ] ) ? $stats[ $today ] : null,
		) );
	}
}
