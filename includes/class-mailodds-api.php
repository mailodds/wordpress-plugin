<?php
/**
 * MailOdds API client.
 *
 * Lightweight wrapper around the MailOdds REST API using WordPress HTTP functions.
 * Includes transient-based caching to minimize API calls and credit usage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_API {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 10;

	/**
	 * Cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	private $cache_ttl = DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Check if an API key is configured.
	 *
	 * @return bool
	 */
	public function has_key() {
		return ! empty( $this->api_key );
	}

	/**
	 * Check if running in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 0 === strpos( $this->api_key, 'mo_test_' );
	}

	/**
	 * Validate a single email address.
	 *
	 * Uses transient cache to avoid re-validating the same email within 24 hours.
	 *
	 * @param string $email   Email address.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type string $depth      Validation depth: 'standard' or 'enhanced'. Default 'enhanced'.
	 *     @type int    $policy_id  Policy ID to apply. Default none.
	 *     @type bool   $skip_cache Skip transient cache. Default false.
	 * }
	 * @return array|WP_Error Validation result or error.
	 */
	public function validate( $email, $options = array() ) {
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return new WP_Error( 'mailodds_invalid_email', __( 'Invalid email address.', 'mailodds-email-validation' ) );
		}

		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}

		$depth      = isset( $options['depth'] ) ? $options['depth'] : get_option( 'mailodds_depth', 'enhanced' );
		$policy_id  = isset( $options['policy_id'] ) ? absint( $options['policy_id'] ) : absint( get_option( 'mailodds_policy_id', 0 ) );
		$skip_cache = ! empty( $options['skip_cache'] );

		// Check transient cache
		if ( ! $skip_cache ) {
			$cache_key = $this->cache_key( $email, $depth );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['_cached'] = true;
				return $cached;
			}
		}

		// Build request body
		$body = array( 'email' => $email );
		if ( 'standard' === $depth ) {
			$body['depth'] = 'standard';
		}
		if ( $policy_id > 0 ) {
			$body['policy_id'] = $policy_id;
		}

		$result = $this->post( '/v1/validate', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result;

		// Cache successful results
		if ( ! $skip_cache && isset( $data['status'] ) ) {
			$cache_key = $this->cache_key( $email, $depth );
			set_transient( $cache_key, $data, $this->cache_ttl );
		}

		// Track local stats
		$this->track_validation( $data );

		return $data;
	}

	/**
	 * Validate multiple email addresses.
	 *
	 * @param array $emails  List of email addresses.
	 * @param array $options {
	 *     Optional settings.
	 *
	 *     @type string $depth     Validation depth. Default 'enhanced'.
	 *     @type int    $policy_id Policy ID. Default none.
	 * }
	 * @return array|WP_Error Array of results or error.
	 */
	public function validate_batch( $emails, $options = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}

		$emails = array_map( 'sanitize_email', $emails );
		$emails = array_filter( $emails );

		if ( empty( $emails ) ) {
			return new WP_Error( 'mailodds_no_emails', __( 'No valid emails provided.', 'mailodds-email-validation' ) );
		}

		$depth     = isset( $options['depth'] ) ? $options['depth'] : get_option( 'mailodds_depth', 'enhanced' );
		$policy_id = isset( $options['policy_id'] ) ? absint( $options['policy_id'] ) : absint( get_option( 'mailodds_policy_id', 0 ) );

		$body = array( 'emails' => array_values( $emails ) );
		if ( 'standard' === $depth ) {
			$body['depth'] = 'standard';
		}
		if ( $policy_id > 0 ) {
			$body['policy_id'] = $policy_id;
		}

		$result = $this->post( '/v1/validate/batch', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$results = isset( $result['results'] ) ? $result['results'] : array();

		// Cache each result individually
		foreach ( $results as $item ) {
			if ( isset( $item['email'], $item['status'] ) ) {
				$cache_key = $this->cache_key( $item['email'], $depth );
				set_transient( $cache_key, $item, $this->cache_ttl );
				$this->track_validation( $item );
			}
		}

		return $results;
	}

	// =========================================================================
	// Suppression List
	// =========================================================================

	/**
	 * Retrieve the suppression list.
	 *
	 * @param array $params Query parameters (page, per_page, etc.).
	 * @return array|WP_Error
	 */
	public function get_suppression_list( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/suppression', $params );
	}

	/**
	 * Add entries to the suppression list.
	 *
	 * @param array $entries Array of suppression entries.
	 * @return array|WP_Error
	 */
	public function add_suppression( $entries ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/suppression', array( 'entries' => $entries ) );
	}

	/**
	 * Remove entries from the suppression list.
	 *
	 * @param array $entries Array of suppression entries to remove.
	 * @return array|WP_Error
	 */
	public function remove_suppression( $entries ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/suppression', array( 'entries' => $entries ) );
	}

	/**
	 * Check if an email is on the suppression list.
	 *
	 * @param string $email Email address to check.
	 * @return array|WP_Error
	 */
	public function check_suppression( $email ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/suppression/check', array( 'email' => sanitize_email( $email ) ) );
	}

	/**
	 * Get suppression list statistics.
	 *
	 * @return array|WP_Error
	 */
	public function get_suppression_stats() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/suppression/stats' );
	}

	// =========================================================================
	// Bulk Validation Jobs
	// =========================================================================

	/**
	 * Create a bulk validation job.
	 *
	 * @param array $emails  List of email addresses.
	 * @param array $options Optional settings (depth, policy_id).
	 * @return array|WP_Error
	 */
	public function create_job( $emails, $options = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		$body = array( 'emails' => array_values( $emails ) );
		if ( isset( $options['depth'] ) ) {
			$body['depth'] = $options['depth'];
		}
		if ( isset( $options['policy_id'] ) && absint( $options['policy_id'] ) > 0 ) {
			$body['policy_id'] = absint( $options['policy_id'] );
		}
		return $this->post( '/v1/jobs', $body );
	}

	/**
	 * List bulk validation jobs.
	 *
	 * @param array $params Query parameters (page, per_page, etc.).
	 * @return array|WP_Error
	 */
	public function list_jobs( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/jobs', $params );
	}

	/**
	 * Get a bulk validation job by ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function get_job( $job_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/jobs/' . rawurlencode( $job_id ) );
	}

	/**
	 * Get results for a bulk validation job.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $params Query parameters (page, per_page, etc.).
	 * @return array|WP_Error
	 */
	public function get_job_results( $job_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/jobs/' . rawurlencode( $job_id ) . '/results', $params );
	}

	/**
	 * Cancel a bulk validation job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function cancel_job( $job_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/jobs/' . rawurlencode( $job_id ) . '/cancel' );
	}

	/**
	 * Delete a bulk validation job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function delete_job( $job_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/jobs/' . rawurlencode( $job_id ) );
	}

	// =========================================================================
	// Validation Policies
	// =========================================================================

	/**
	 * List validation policies.
	 *
	 * @return array|WP_Error
	 */
	public function list_policies() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/policies' );
	}

	/**
	 * Create a validation policy.
	 *
	 * @param array $data Policy data.
	 * @return array|WP_Error
	 */
	public function create_policy( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/policies', $data );
	}

	/**
	 * Get available policy presets.
	 *
	 * @return array|WP_Error
	 */
	public function get_policy_presets() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/policies/presets' );
	}

	/**
	 * Create a policy from a preset.
	 *
	 * @param string $preset Preset identifier.
	 * @param string $name   Optional custom name.
	 * @return array|WP_Error
	 */
	public function create_policy_from_preset( $preset, $name = '' ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		$body = array( 'preset' => $preset );
		if ( ! empty( $name ) ) {
			$body['name'] = $name;
		}
		return $this->post( '/v1/policies/from-preset', $body );
	}

	/**
	 * Get a validation policy by ID.
	 *
	 * @param int $policy_id Policy ID.
	 * @return array|WP_Error
	 */
	public function get_policy( $policy_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/policies/' . absint( $policy_id ) );
	}

	/**
	 * Update a validation policy.
	 *
	 * @param int   $policy_id Policy ID.
	 * @param array $data      Updated policy data.
	 * @return array|WP_Error
	 */
	public function update_policy( $policy_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/policies/' . absint( $policy_id ), $data );
	}

	/**
	 * Delete a validation policy.
	 *
	 * @param int $policy_id Policy ID.
	 * @return array|WP_Error
	 */
	public function delete_policy( $policy_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/policies/' . absint( $policy_id ) );
	}

	/**
	 * Add a rule to a validation policy.
	 *
	 * @param int   $policy_id Policy ID.
	 * @param array $rule      Rule data.
	 * @return array|WP_Error
	 */
	public function add_policy_rule( $policy_id, $rule ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/policies/' . absint( $policy_id ) . '/rules', $rule );
	}

	/**
	 * Delete a rule from a validation policy.
	 *
	 * @param int $policy_id Policy ID.
	 * @param int $rule_id   Rule ID.
	 * @return array|WP_Error
	 */
	public function delete_policy_rule( $policy_id, $rule_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/policies/' . absint( $policy_id ) . '/rules/' . absint( $rule_id ) );
	}

	/**
	 * Test a validation policy against sample data.
	 *
	 * @param array $data Test data (email, policy rules, etc.).
	 * @return array|WP_Error
	 */
	public function test_policy( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/policies/test', $data );
	}

	// =========================================================================
	// Telemetry
	// =========================================================================

	/**
	 * Get telemetry summary.
	 *
	 * @param string $window Time window (e.g. '24h', '7d').
	 * @return array|WP_Error
	 */
	public function get_telemetry( $window = '24h' ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/telemetry/summary', array( 'window' => $window ) );
	}

	// =========================================================================
	// HTTP Methods (private)
	// =========================================================================

	/**
	 * Make an HTTP request to the MailOdds API.
	 *
	 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
	 * @param string $endpoint API endpoint path.
	 * @param array  $args     Optional. Request arguments (body, query).
	 * @return array|WP_Error Decoded response or error.
	 */
	private function request( $method, $endpoint, $args = array() ) {
		$url = MAILODDS_API_BASE . $endpoint;

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'User-Agent'    => 'MailOdds-WordPress/' . MAILODDS_VERSION,
		);

		$request_args = array(
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => $this->timeout,
		);

		if ( isset( $args['body'] ) ) {
			$request_args['headers']['Content-Type'] = 'application/json';
			$request_args['body'] = wp_json_encode( $args['body'] );
		}

		if ( isset( $args['query'] ) && ! empty( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// 429 rate-limit: single retry with Retry-After (capped at 5s)
		if ( 429 === $code ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = max( 1, min( $retry_after, 5 ) );
			sleep( $retry_after );

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 429 === $code ) {
				return new WP_Error( 'mailodds_rate_limited', __( 'API rate limit exceeded. Please try again later.', 'mailodds-email-validation' ) );
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error'] ) ? $body['error'] : __( 'API request failed.', 'mailodds-email-validation' );
			return new WP_Error( 'mailodds_api_error', $message, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * Make a POST request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @return array|WP_Error
	 */
	private function post( $endpoint, $body = array() ) {
		return $this->request( 'POST', $endpoint, array( 'body' => $body ) );
	}

	/**
	 * Make a GET request.
	 *
	 * @param string $endpoint     API endpoint path.
	 * @param array  $query_params Query parameters.
	 * @return array|WP_Error
	 */
	private function get( $endpoint, $query_params = array() ) {
		$args = array();
		if ( ! empty( $query_params ) ) {
			$args['query'] = $query_params;
		}
		return $this->request( 'GET', $endpoint, $args );
	}

	/**
	 * Make a PUT request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @return array|WP_Error
	 */
	private function put( $endpoint, $body = array() ) {
		return $this->request( 'PUT', $endpoint, array( 'body' => $body ) );
	}

	/**
	 * Make a DELETE request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Optional request body.
	 * @return array|WP_Error
	 */
	private function delete( $endpoint, $body = array() ) {
		$args = array();
		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		}
		return $this->request( 'DELETE', $endpoint, $args );
	}

	/**
	 * Generate a cache key for an email/depth pair.
	 *
	 * @param string $email Email address.
	 * @param string $depth Validation depth.
	 * @return string Transient key.
	 */
	private function cache_key( $email, $depth ) {
		return 'mailodds_' . substr( hash( 'sha256', strtolower( $email ) . ':' . $depth ), 0, 16 );
	}

	/**
	 * Track validation result in local stats.
	 *
	 * Stores daily counters in a WordPress option for the dashboard widget.
	 *
	 * @param array $result Validation result.
	 */
	private function track_validation( $result ) {
		$today = current_time( 'Y-m-d' );
		$stats = get_option( 'mailodds_daily_stats', array() );

		if ( ! isset( $stats[ $today ] ) ) {
			$stats[ $today ] = array(
				'total'       => 0,
				'valid'       => 0,
				'invalid'     => 0,
				'catch_all'   => 0,
				'unknown'     => 0,
				'do_not_mail' => 0,
			);
		}

		$stats[ $today ]['total']++;
		$status = isset( $result['status'] ) ? $result['status'] : 'unknown';
		if ( isset( $stats[ $today ][ $status ] ) ) {
			$stats[ $today ][ $status ]++;
		}

		// Keep only last 30 days
		$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		foreach ( array_keys( $stats ) as $date ) {
			if ( $date < $cutoff ) {
				unset( $stats[ $date ] );
			}
		}

		update_option( 'mailodds_daily_stats', $stats, false );
	}
}
