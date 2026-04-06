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
	 * API base URL (cached from option).
	 *
	 * @var string
	 */
	private $api_base;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key  = $api_key;
		$this->api_base = defined( 'MAILODDS_API_BASE' ) ? MAILODDS_API_BASE : 'https://api.mailodds.com';
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
	// Email Sending
	// =========================================================================

	/**
	 * Send a single transactional email.
	 *
	 * @param array $data {
	 *     Email data.
	 *
	 *     @type string $from    Sender address.
	 *     @type array  $to      Recipients [{email, name}].
	 *     @type string $subject Subject line.
	 *     @type string $html    HTML body.
	 *     @type string $text    Plain text body.
	 *     @type array  $headers Custom headers.
	 *     @type array  $options Delivery options.
	 * }
	 * @return array|WP_Error
	 */
	public function deliver( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/deliver', $data );
	}

	/**
	 * Send a batch of transactional emails (up to 100 recipients).
	 *
	 * @param array $data Batch delivery data.
	 * @return array|WP_Error
	 */
	public function deliver_batch( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/deliver/batch', $data );
	}

	// =========================================================================
	// Sending Domains
	// =========================================================================

	/**
	 * List sending domains.
	 *
	 * @return array|WP_Error
	 */
	public function list_sending_domains() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sending-domains' );
	}

	/**
	 * Register a new sending domain.
	 *
	 * @param string $domain Domain name.
	 * @return array|WP_Error
	 */
	public function create_sending_domain( $domain ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/sending-domains', array( 'domain' => $domain ) );
	}

	/**
	 * Get sending domain details.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function get_sending_domain( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sending-domains/' . rawurlencode( $domain_id ) );
	}

	/**
	 * Verify sending domain DNS records.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function verify_sending_domain( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/sending-domains/' . rawurlencode( $domain_id ) . '/verify' );
	}

	/**
	 * Delete a sending domain.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function delete_sending_domain( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/sending-domains/' . rawurlencode( $domain_id ) );
	}

	/**
	 * Get sending domain identity score.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function get_identity_score( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sending-domains/' . rawurlencode( $domain_id ) . '/identity-score' );
	}

	/**
	 * Get reply forwarding settings for a sending domain.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function get_reply_forwarding( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sending-domains/' . rawurlencode( $domain_id ) . '/reply-forwarding' );
	}

	/**
	 * Update reply forwarding settings for a sending domain.
	 *
	 * @param string $domain_id Domain ID.
	 * @param array  $data      Reply forwarding data.
	 * @return array|WP_Error
	 */
	public function update_reply_forwarding( $domain_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->patch( '/v1/sending-domains/' . rawurlencode( $domain_id ) . '/reply-forwarding', $data );
	}

	// =========================================================================
	// Bounce Stats & Deliverability
	// =========================================================================

	/**
	 * Get bounce statistics.
	 *
	 * @param array $params Query params (days, category, provider).
	 * @return array|WP_Error
	 */
	public function get_bounce_stats( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/bounce-stats', $params );
	}

	/**
	 * Get bounce stats summary.
	 *
	 * @return array|WP_Error
	 */
	public function get_bounce_stats_summary() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/bounce-stats/summary' );
	}

	/**
	 * Get complaint assessment.
	 *
	 * @return array|WP_Error
	 */
	public function get_complaint_assessment() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/complaint-assessment' );
	}

	/**
	 * Get sending statistics.
	 *
	 * @param array $params Query params.
	 * @return array|WP_Error
	 */
	public function get_sending_stats( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sending-stats', $params );
	}

	/**
	 * Get message events (delivery/engagement tracking).
	 *
	 * @param array $params Query params (message_id, event_type, etc.).
	 * @return array|WP_Error
	 */
	public function get_message_events( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/message-events', $params );
	}

	// =========================================================================
	// Bounce Analysis
	// =========================================================================

	/**
	 * Create a bounce analysis.
	 *
	 * @param array $data Bounce log data (text, format).
	 * @return array|WP_Error
	 */
	public function create_bounce_analysis( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/bounce-analyses', $data );
	}

	/**
	 * Get bounce analysis results.
	 *
	 * @param string $analysis_id Analysis ID.
	 * @return array|WP_Error
	 */
	public function get_bounce_analysis( $analysis_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/bounce-analyses/' . rawurlencode( $analysis_id ) );
	}

	/**
	 * Get bounce analysis records.
	 *
	 * @param string $analysis_id Analysis ID.
	 * @param array  $params      Query params (page, per_page).
	 * @return array|WP_Error
	 */
	public function get_bounce_records( $analysis_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/bounce-analyses/' . rawurlencode( $analysis_id ) . '/records', $params );
	}

	/**
	 * Cross-reference bounce analysis with validation.
	 *
	 * @param string $analysis_id Analysis ID.
	 * @return array|WP_Error
	 */
	public function cross_reference_bounces( $analysis_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/bounce-analyses/' . rawurlencode( $analysis_id ) . '/cross-reference' );
	}

	// =========================================================================
	// Sender Health & Reputation
	// =========================================================================

	/**
	 * Get sender reputation.
	 *
	 * @return array|WP_Error
	 */
	public function get_reputation() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/reputation' );
	}

	/**
	 * Get reputation timeline.
	 *
	 * @param array $params Query params (days).
	 * @return array|WP_Error
	 */
	public function get_reputation_timeline( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/reputation/timeline', $params );
	}

	/**
	 * Get sender health.
	 *
	 * @return array|WP_Error
	 */
	public function get_sender_health() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sender-health' );
	}

	/**
	 * Get sender health trend.
	 *
	 * @param array $params Query params (days).
	 * @return array|WP_Error
	 */
	public function get_sender_health_trend( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/sender-health/trend', $params );
	}

	// =========================================================================
	// DMARC Monitoring
	// =========================================================================

	/**
	 * List DMARC monitored domains.
	 *
	 * @return array|WP_Error
	 */
	public function list_dmarc_domains() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/dmarc-domains' );
	}

	/**
	 * Add a domain for DMARC monitoring.
	 *
	 * @param string $domain Domain name.
	 * @return array|WP_Error
	 */
	public function add_dmarc_domain( $domain ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/dmarc-domains', array( 'domain' => $domain ) );
	}

	/**
	 * Get DMARC domain summary.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function get_dmarc_domain( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/dmarc-domains/' . rawurlencode( $domain_id ) );
	}

	/**
	 * Verify DMARC domain DNS publication.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function verify_dmarc_domain( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/dmarc-domains/' . rawurlencode( $domain_id ) . '/verify' );
	}

	/**
	 * Get DMARC sending sources.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function get_dmarc_sources( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/dmarc-domains/' . rawurlencode( $domain_id ) . '/sources' );
	}

	/**
	 * Get DMARC trend data.
	 *
	 * @param string $domain_id Domain ID.
	 * @param array  $params    Query params (days).
	 * @return array|WP_Error
	 */
	public function get_dmarc_trend( $domain_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/dmarc-domains/' . rawurlencode( $domain_id ) . '/trend', $params );
	}

	/**
	 * Get DMARC policy recommendation.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function get_dmarc_recommendation( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/dmarc-domains/' . rawurlencode( $domain_id ) . '/recommendation' );
	}

	// =========================================================================
	// Blacklist Monitoring
	// =========================================================================

	/**
	 * List blacklist monitors.
	 *
	 * @return array|WP_Error
	 */
	public function list_blacklist_monitors() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/blacklist-monitors' );
	}

	/**
	 * Add a blacklist monitor.
	 *
	 * @param array $data Monitor data (host, type).
	 * @return array|WP_Error
	 */
	public function add_blacklist_monitor( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/blacklist-monitors', $data );
	}

	/**
	 * Run an on-demand blacklist check.
	 *
	 * @param string $monitor_id Monitor ID.
	 * @return array|WP_Error
	 */
	public function run_blacklist_check( $monitor_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/blacklist-monitors/' . rawurlencode( $monitor_id ) . '/check' );
	}

	/**
	 * Get blacklist check history.
	 *
	 * @param string $monitor_id Monitor ID.
	 * @return array|WP_Error
	 */
	public function get_blacklist_history( $monitor_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/blacklist-monitors/' . rawurlencode( $monitor_id ) . '/history' );
	}

	// =========================================================================
	// Server Tests
	// =========================================================================

	/**
	 * List server tests.
	 *
	 * @return array|WP_Error
	 */
	public function list_server_tests() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/server-tests' );
	}

	/**
	 * Run a server test (SMTP handshake + DNS audit).
	 *
	 * @param array $data Test data (hostname).
	 * @return array|WP_Error
	 */
	public function run_server_test( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/server-tests', $data );
	}

	/**
	 * Get server test results.
	 *
	 * @param string $test_id Test ID.
	 * @return array|WP_Error
	 */
	public function get_server_test( $test_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/server-tests/' . rawurlencode( $test_id ) );
	}

	// =========================================================================
	// Spam Checks & Content Classification
	// =========================================================================

	/**
	 * Run a spam check.
	 *
	 * @param array $data Spam check data (subject, html, text, from).
	 * @return array|WP_Error
	 */
	public function run_spam_check( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/spam-checks', $data );
	}

	/**
	 * Get spam check results.
	 *
	 * @param string $check_id Check ID.
	 * @return array|WP_Error
	 */
	public function get_spam_check( $check_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/spam-checks/' . rawurlencode( $check_id ) );
	}

	/**
	 * Classify email content.
	 *
	 * @param array $data Content data (subject, html, text).
	 * @return array|WP_Error
	 */
	public function classify_content( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/content-check', $data );
	}

	// =========================================================================
	// Alert Rules
	// =========================================================================

	/**
	 * List alert rules.
	 *
	 * @return array|WP_Error
	 */
	public function list_alert_rules() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/alert-rules' );
	}

	/**
	 * Create an alert rule.
	 *
	 * @param array $data Alert rule data (metric, threshold, channel).
	 * @return array|WP_Error
	 */
	public function create_alert_rule( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/alert-rules', $data );
	}

	/**
	 * Get an alert rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return array|WP_Error
	 */
	public function get_alert_rule( $rule_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/alert-rules/' . rawurlencode( $rule_id ) );
	}

	/**
	 * Update an alert rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @param array  $data    Updated rule data.
	 * @return array|WP_Error
	 */
	public function update_alert_rule( $rule_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/alert-rules/' . rawurlencode( $rule_id ), $data );
	}

	/**
	 * Delete an alert rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return array|WP_Error
	 */
	public function delete_alert_rule( $rule_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/alert-rules/' . rawurlencode( $rule_id ) );
	}

	// =========================================================================
	// Engagement Scoring
	// =========================================================================

	/**
	 * Get engagement summary (active/at-risk/disengaged counts).
	 *
	 * @return array|WP_Error
	 */
	public function get_engagement_summary() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/engagement/summary' );
	}

	/**
	 * Get engagement score for a single email.
	 *
	 * @param string $email Email address.
	 * @return array|WP_Error
	 */
	public function get_engagement_score( $email ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/engagement/score/' . rawurlencode( $email ) );
	}

	/**
	 * Get disengaged contacts.
	 *
	 * @param array $params Query params (page, per_page, days).
	 * @return array|WP_Error
	 */
	public function get_disengaged( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/engagement/disengaged', $params );
	}

	/**
	 * Suppress disengaged contacts.
	 *
	 * @param array $data Suppress data (days, dry_run).
	 * @return array|WP_Error
	 */
	public function suppress_disengaged( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/engagement/suppress-disengaged', $data );
	}

	// =========================================================================
	// Out-of-Office Detection
	// =========================================================================

	/**
	 * List out-of-office contacts.
	 *
	 * @param array $params Query params (page, per_page).
	 * @return array|WP_Error
	 */
	public function list_ooo_contacts( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/out-of-office', $params );
	}

	/**
	 * Check OOO status for a single email.
	 *
	 * @param string $email Email address.
	 * @return array|WP_Error
	 */
	public function get_ooo_status( $email ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/out-of-office/' . rawurlencode( $email ) . '/status' );
	}

	/**
	 * Batch check OOO status (up to 100 emails).
	 *
	 * @param array $emails List of email addresses.
	 * @return array|WP_Error
	 */
	public function batch_check_ooo( $emails ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/out-of-office/batch-check', array( 'emails' => array_values( $emails ) ) );
	}

	/**
	 * Delete an OOO contact record.
	 *
	 * @param string $email Email address.
	 * @return array|WP_Error
	 */
	public function delete_ooo_contact( $email ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/out-of-office/' . rawurlencode( $email ) );
	}

	// =========================================================================
	// Subscriber Lists
	// =========================================================================

	/**
	 * List subscriber lists.
	 *
	 * @return array|WP_Error
	 */
	public function list_subscriber_lists() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/lists' );
	}

	/**
	 * Create a subscriber list.
	 *
	 * @param array $data List data (name, double_opt_in).
	 * @return array|WP_Error
	 */
	public function create_subscriber_list( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/lists', $data );
	}

	/**
	 * Get a subscriber list.
	 *
	 * @param string $list_id List ID.
	 * @return array|WP_Error
	 */
	public function get_subscriber_list( $list_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/lists/' . rawurlencode( $list_id ) );
	}

	/**
	 * Delete a subscriber list.
	 *
	 * @param string $list_id List ID.
	 * @return array|WP_Error
	 */
	public function delete_subscriber_list( $list_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/lists/' . rawurlencode( $list_id ) );
	}

	/**
	 * Get subscribers for a list.
	 *
	 * @param string $list_id List ID.
	 * @param array  $params  Query params (page, per_page, status).
	 * @return array|WP_Error
	 */
	public function get_subscribers( $list_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/lists/' . rawurlencode( $list_id ) . '/subscribers', $params );
	}

	/**
	 * Subscribe an email to a list (triggers double opt-in if enabled).
	 *
	 * @param array $data Subscribe data (email, list_id).
	 * @return array|WP_Error
	 */
	public function subscribe( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/subscribe', $data );
	}

	// =========================================================================
	// Suppression Audit
	// =========================================================================

	/**
	 * Get suppression audit log.
	 *
	 * @param array $params Query params (page, per_page).
	 * @return array|WP_Error
	 */
	public function get_suppression_audit( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/suppression/audit', $params );
	}

	// =========================================================================
	// Blacklist Monitor Deletion
	// =========================================================================

	/**
	 * Delete a blacklist monitor.
	 *
	 * @param string $monitor_id Monitor ID.
	 * @return array|WP_Error
	 */
	public function delete_blacklist_monitor( $monitor_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/blacklist-monitors/' . rawurlencode( $monitor_id ) );
	}

	// =========================================================================
	// Bounce Analysis Deletion
	// =========================================================================

	/**
	 * Delete a bounce analysis.
	 *
	 * @param string $analysis_id Analysis ID.
	 * @return array|WP_Error
	 */
	public function delete_bounce_analysis( $analysis_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/bounce-analyses/' . rawurlencode( $analysis_id ) );
	}

	// =========================================================================
	// Campaigns
	// =========================================================================

	/**
	 * List campaigns.
	 *
	 * @param array $params Query params (page, per_page, status).
	 * @return array|WP_Error
	 */
	public function list_campaigns( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns', $params );
	}

	/**
	 * Create a campaign.
	 *
	 * @param array $data Campaign data.
	 * @return array|WP_Error
	 */
	public function create_campaign( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns', $data );
	}

	/**
	 * Get a campaign by ID.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) );
	}

	/**
	 * Get A/B test results for a campaign.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_ab_results( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/ab-results' );
	}

	/**
	 * Cancel a campaign.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function cancel_campaign( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/cancel' );
	}

	/**
	 * Get campaign conversion attribution.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_attribution( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/conversions/attribution' );
	}

	/**
	 * Get campaign delivery confidence score.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_delivery_confidence( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/delivery-confidence' );
	}

	/**
	 * Get campaign funnel metrics.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_funnel( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/funnel' );
	}

	/**
	 * Get campaign provider intelligence.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_provider_intelligence( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/provider-intelligence' );
	}

	/**
	 * Schedule a campaign for future sending.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param array  $data        Schedule data (send_at).
	 * @return array|WP_Error
	 */
	public function schedule_campaign( $campaign_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/schedule', $data );
	}

	/**
	 * Send a campaign immediately.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function send_campaign( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/send' );
	}

	/**
	 * List template versions for a campaign.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function list_campaign_template_versions( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions' );
	}

	/**
	 * Create a template version for a campaign.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param array  $data        Template version data.
	 * @return array|WP_Error
	 */
	public function create_campaign_template_version( $campaign_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions', $data );
	}

	/**
	 * Rollback to a previous template version.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|WP_Error
	 */
	public function rollback_campaign_template( $campaign_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions/rollback' );
	}

	/**
	 * Get a specific template version.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param string $version_id  Version ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_template_version( $campaign_id, $version_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions/' . rawurlencode( $version_id ) );
	}

	/**
	 * Update a template version.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param string $version_id  Version ID.
	 * @param array  $data        Updated template data.
	 * @return array|WP_Error
	 */
	public function update_campaign_template_version( $campaign_id, $version_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions/' . rawurlencode( $version_id ), $data );
	}

	/**
	 * Start canary deployment for a template version.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param string $version_id  Version ID.
	 * @param array  $data        Canary config (percentage).
	 * @return array|WP_Error
	 */
	public function canary_campaign_template_version( $campaign_id, $version_id, $data = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions/' . rawurlencode( $version_id ) . '/canary', $data );
	}

	/**
	 * Promote a template version to active.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param string $version_id  Version ID.
	 * @return array|WP_Error
	 */
	public function promote_campaign_template_version( $campaign_id, $version_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/template-versions/' . rawurlencode( $version_id ) . '/promote' );
	}

	/**
	 * Create a campaign variant for A/B testing.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param array  $data        Variant data.
	 * @return array|WP_Error
	 */
	public function create_campaign_variant( $campaign_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/campaigns/' . rawurlencode( $campaign_id ) . '/variants', $data );
	}

	// =========================================================================
	// Configuration Sets
	// =========================================================================

	/**
	 * List configuration sets.
	 *
	 * @return array|WP_Error
	 */
	public function list_configuration_sets() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/configuration-sets' );
	}

	/**
	 * Create a configuration set.
	 *
	 * @param array $data Configuration set data.
	 * @return array|WP_Error
	 */
	public function create_configuration_set( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/configuration-sets', $data );
	}

	/**
	 * Get a configuration set by name.
	 *
	 * @param string $name Configuration set name.
	 * @return array|WP_Error
	 */
	public function get_configuration_set( $name ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/configuration-sets/' . rawurlencode( $name ) );
	}

	/**
	 * Update a configuration set.
	 *
	 * @param string $name Configuration set name.
	 * @param array  $data Updated configuration set data.
	 * @return array|WP_Error
	 */
	public function update_configuration_set( $name, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/configuration-sets/' . rawurlencode( $name ), $data );
	}

	/**
	 * Delete a configuration set.
	 *
	 * @param string $name Configuration set name.
	 * @return array|WP_Error
	 */
	public function delete_configuration_set( $name ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/configuration-sets/' . rawurlencode( $name ) );
	}

	/**
	 * Get metrics for a configuration set.
	 *
	 * @param string $name   Configuration set name.
	 * @param array  $params Query params (days, metric).
	 * @return array|WP_Error
	 */
	public function get_configuration_set_metrics( $name, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/configuration-sets/' . rawurlencode( $name ) . '/metrics', $params );
	}

	// =========================================================================
	// Contact Lists
	// =========================================================================

	/**
	 * List contact lists.
	 *
	 * @param array $params Query params (page, per_page).
	 * @return array|WP_Error
	 */
	public function list_contact_lists( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/contact-lists', $params );
	}

	/**
	 * Create a contact list.
	 *
	 * @param array $data Contact list data (name).
	 * @return array|WP_Error
	 */
	public function create_contact_list( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/contact-lists', $data );
	}

	/**
	 * Delete a contact list.
	 *
	 * @param string $list_id Contact list ID.
	 * @return array|WP_Error
	 */
	public function delete_contact_list( $list_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/contact-lists/' . rawurlencode( $list_id ) );
	}

	/**
	 * Append contacts to a contact list.
	 *
	 * @param string $list_id Contact list ID.
	 * @param array  $data    Contact data (emails).
	 * @return array|WP_Error
	 */
	public function append_contact_list( $list_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/append', $data );
	}

	/**
	 * Add a contact to a contact list.
	 *
	 * @param string $list_id Contact list ID.
	 * @param array  $data    Contact data (email, name, metadata).
	 * @return array|WP_Error
	 */
	public function add_contact_list_contact( $list_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/contacts', $data );
	}

	/**
	 * Delete a contact from a contact list.
	 *
	 * @param string $list_id    Contact list ID.
	 * @param string $contact_id Contact ID.
	 * @return array|WP_Error
	 */
	public function delete_contact_list_contact( $list_id, $contact_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/contacts/' . rawurlencode( $contact_id ) );
	}

	/**
	 * Update a contact in a contact list.
	 *
	 * @param string $list_id    Contact list ID.
	 * @param string $contact_id Contact ID.
	 * @param array  $data       Updated contact data.
	 * @return array|WP_Error
	 */
	public function update_contact_list_contact( $list_id, $contact_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->patch( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/contacts/' . rawurlencode( $contact_id ), $data );
	}

	/**
	 * Export a contact list.
	 *
	 * @param string $list_id Contact list ID.
	 * @param array  $params  Query params (format).
	 * @return array|WP_Error
	 */
	public function export_contact_list( $list_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/export', $params );
	}

	/**
	 * Import contacts into a contact list.
	 *
	 * @param string $list_id Contact list ID.
	 * @param array  $data    Import data (emails, file).
	 * @return array|WP_Error
	 */
	public function import_contact_list( $list_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/import', $data );
	}

	/**
	 * Query contacts in a contact list.
	 *
	 * @param string $list_id Contact list ID.
	 * @param array  $data    Query filters.
	 * @return array|WP_Error
	 */
	public function query_contact_list( $list_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/contact-lists/' . rawurlencode( $list_id ) . '/query', $data );
	}

	// =========================================================================
	// Contacts
	// =========================================================================

	/**
	 * Get inactive contacts report.
	 *
	 * @param array $params Query params (days, page, per_page).
	 * @return array|WP_Error
	 */
	public function get_inactive_contacts_report( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/contacts/inactive-report', $params );
	}

	// =========================================================================
	// Deliverability Recommendations
	// =========================================================================

	/**
	 * Get deliverability recommendations.
	 *
	 * @param array $params Query params.
	 * @return array|WP_Error
	 */
	public function get_deliverability_recommendations( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/deliverability/recommendations', $params );
	}

	/**
	 * Dismiss a deliverability recommendation.
	 *
	 * @param string $recommendation_id Recommendation ID.
	 * @return array|WP_Error
	 */
	public function dismiss_deliverability_recommendation( $recommendation_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/deliverability/recommendations/' . rawurlencode( $recommendation_id ) . '/dismiss' );
	}

	// =========================================================================
	// DMARC Domain Deletion
	// =========================================================================

	/**
	 * Delete a DMARC monitored domain.
	 *
	 * @param string $domain_id Domain ID.
	 * @return array|WP_Error
	 */
	public function delete_dmarc_domain( $domain_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/dmarc-domains/' . rawurlencode( $domain_id ) );
	}

	// =========================================================================
	// Event Destinations
	// =========================================================================

	/**
	 * List event destinations.
	 *
	 * @return array|WP_Error
	 */
	public function list_event_destinations() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/event-destinations' );
	}

	/**
	 * Create an event destination.
	 *
	 * @param array $data Event destination data.
	 * @return array|WP_Error
	 */
	public function create_event_destination( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/event-destinations', $data );
	}

	/**
	 * Get event destination schemas.
	 *
	 * @return array|WP_Error
	 */
	public function get_event_destination_schemas() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/event-destinations/schemas' );
	}

	/**
	 * Get event destination templates.
	 *
	 * @return array|WP_Error
	 */
	public function get_event_destination_templates() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/event-destinations/templates' );
	}

	/**
	 * Get an event destination by ID.
	 *
	 * @param string $destination_id Destination ID.
	 * @return array|WP_Error
	 */
	public function get_event_destination( $destination_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/event-destinations/' . rawurlencode( $destination_id ) );
	}

	/**
	 * Update an event destination.
	 *
	 * @param string $destination_id Destination ID.
	 * @param array  $data           Updated destination data.
	 * @return array|WP_Error
	 */
	public function update_event_destination( $destination_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/event-destinations/' . rawurlencode( $destination_id ), $data );
	}

	/**
	 * Delete an event destination.
	 *
	 * @param string $destination_id Destination ID.
	 * @return array|WP_Error
	 */
	public function delete_event_destination( $destination_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/event-destinations/' . rawurlencode( $destination_id ) );
	}

	// =========================================================================
	// Event Tracking
	// =========================================================================

	/**
	 * Track a custom event.
	 *
	 * @param array $data Event data (email, event_type, metadata).
	 * @return array|WP_Error
	 */
	public function track_event( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/events/track', $data );
	}

	// =========================================================================
	// Global Suppressions
	// =========================================================================

	/**
	 * Check if an email is on the global suppression list.
	 *
	 * @param array $params Query params (email).
	 * @return array|WP_Error
	 */
	public function check_global_suppression( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/global-suppressions/check', $params );
	}

	/**
	 * Remove global suppression overrides.
	 *
	 * @param array $data Override data (emails).
	 * @return array|WP_Error
	 */
	public function delete_global_suppression_overrides( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/global-suppressions/overrides', $data );
	}

	/**
	 * Add global suppression overrides.
	 *
	 * @param array $data Override data (emails).
	 * @return array|WP_Error
	 */
	public function add_global_suppression_overrides( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/global-suppressions/overrides', $data );
	}

	// =========================================================================
	// Inbound Messages
	// =========================================================================

	/**
	 * List inbound messages.
	 *
	 * @param array $params Query params (page, per_page, type).
	 * @return array|WP_Error
	 */
	public function list_inbound_messages( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/inbound-messages', $params );
	}

	/**
	 * Get an inbound message by ID.
	 *
	 * @param string $message_id Message ID.
	 * @return array|WP_Error
	 */
	public function get_inbound_message( $message_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/inbound-messages/' . rawurlencode( $message_id ) );
	}

	/**
	 * Submit a correction for an inbound message classification.
	 *
	 * @param string $message_id Message ID.
	 * @param array  $data       Correction data (correct_type).
	 * @return array|WP_Error
	 */
	public function correct_inbound_message( $message_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->patch( '/v1/inbound-messages/' . rawurlencode( $message_id ) . '/correction', $data );
	}

	// =========================================================================
	// ISP FBL Guides
	// =========================================================================

	/**
	 * List ISP feedback loop guides.
	 *
	 * @return array|WP_Error
	 */
	public function list_isp_fbl_guides() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/isp-fbl/guides' );
	}

	/**
	 * Get a specific ISP feedback loop guide.
	 *
	 * @param string $isp_id ISP identifier.
	 * @return array|WP_Error
	 */
	public function get_isp_fbl_guide( $isp_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/isp-fbl/guides/' . rawurlencode( $isp_id ) );
	}

	// =========================================================================
	// Jobs Upload
	// =========================================================================

	/**
	 * Upload a file for bulk validation.
	 *
	 * @param array $data Upload data.
	 * @return array|WP_Error
	 */
	public function upload_job( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/jobs/upload', $data );
	}

	/**
	 * Get a presigned upload URL for bulk validation.
	 *
	 * @param array $data Presigned upload request data.
	 * @return array|WP_Error
	 */
	public function get_presigned_upload( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/jobs/upload/presigned', $data );
	}

	/**
	 * Create a bulk validation job from an S3 file.
	 *
	 * @param array $data S3 upload data (bucket, key).
	 * @return array|WP_Error
	 */
	public function upload_job_s3( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/jobs/upload/s3', $data );
	}

	// =========================================================================
	// Out-of-Office Update
	// =========================================================================

	/**
	 * Update an out-of-office contact record.
	 *
	 * @param string $email Email address.
	 * @param array  $data  Updated OOO data.
	 * @return array|WP_Error
	 */
	public function update_ooo_contact( $email, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->patch( '/v1/out-of-office/' . rawurlencode( $email ), $data );
	}

	// =========================================================================
	// Pixel Settings
	// =========================================================================

	/**
	 * Get pixel tracking settings.
	 *
	 * @return array|WP_Error
	 */
	public function get_pixel_settings() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/pixel-settings' );
	}

	/**
	 * Update pixel tracking settings.
	 *
	 * @param array $data Pixel settings data.
	 * @return array|WP_Error
	 */
	public function update_pixel_settings( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->patch( '/v1/pixel-settings', $data );
	}

	// =========================================================================
	// Reputation Policies
	// =========================================================================

	/**
	 * List reputation policies.
	 *
	 * @return array|WP_Error
	 */
	public function list_reputation_policies() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/reputation-policies' );
	}

	/**
	 * Create a reputation policy.
	 *
	 * @param array $data Reputation policy data.
	 * @return array|WP_Error
	 */
	public function create_reputation_policy( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/reputation-policies', $data );
	}

	/**
	 * Create a reputation policy from a preset.
	 *
	 * @param array $data Preset data (preset, name).
	 * @return array|WP_Error
	 */
	public function create_reputation_policy_from_preset( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/reputation-policies/from-preset', $data );
	}

	/**
	 * Get a reputation policy by ID.
	 *
	 * @param string $policy_id Policy ID.
	 * @return array|WP_Error
	 */
	public function get_reputation_policy( $policy_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/reputation-policies/' . rawurlencode( $policy_id ) );
	}

	/**
	 * Update a reputation policy.
	 *
	 * @param string $policy_id Policy ID.
	 * @param array  $data      Updated policy data.
	 * @return array|WP_Error
	 */
	public function update_reputation_policy( $policy_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/reputation-policies/' . rawurlencode( $policy_id ), $data );
	}

	/**
	 * Delete a reputation policy.
	 *
	 * @param string $policy_id Policy ID.
	 * @return array|WP_Error
	 */
	public function delete_reputation_policy( $policy_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/reputation-policies/' . rawurlencode( $policy_id ) );
	}

	/**
	 * Get reputation policy enforcement status.
	 *
	 * @param string $policy_id Policy ID.
	 * @return array|WP_Error
	 */
	public function get_reputation_policy_status( $policy_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/reputation-policies/' . rawurlencode( $policy_id ) . '/status' );
	}

	/**
	 * Test a reputation policy against sample data.
	 *
	 * @param string $policy_id Policy ID.
	 * @param array  $data      Test data.
	 * @return array|WP_Error
	 */
	public function test_reputation_policy( $policy_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/reputation-policies/' . rawurlencode( $policy_id ) . '/test', $data );
	}

	// =========================================================================
	// Sending Domain Sub-resources
	// =========================================================================

	/**
	 * Update an inbound rule for a sending domain.
	 *
	 * @param string $domain_id Domain ID.
	 * @param string $rule_id   Rule ID.
	 * @param array  $data      Updated rule data.
	 * @return array|WP_Error
	 */
	public function update_sending_domain_inbound_rule( $domain_id, $rule_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/sending-domains/' . rawurlencode( $domain_id ) . '/inbound-rules/' . rawurlencode( $rule_id ), $data );
	}

	/**
	 * Update managed SPF settings for a sending domain.
	 *
	 * @param string $domain_id Domain ID.
	 * @param array  $data      SPF settings data.
	 * @return array|WP_Error
	 */
	public function update_sending_domain_managed_spf( $domain_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/sending-domains/' . rawurlencode( $domain_id ) . '/managed-spf', $data );
	}

	// =========================================================================
	// Simulate
	// =========================================================================

	/**
	 * Simulate an email delivery.
	 *
	 * @param array $data Simulation data.
	 * @return array|WP_Error
	 */
	public function simulate( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/simulate', $data );
	}

	// =========================================================================
	// Spam Check Deletion
	// =========================================================================

	/**
	 * Delete a spam check.
	 *
	 * @param string $check_id Check ID.
	 * @return array|WP_Error
	 */
	public function delete_spam_check( $check_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/spam-checks/' . rawurlencode( $check_id ) );
	}

	// =========================================================================
	// Store Products
	// =========================================================================

	/**
	 * List store products.
	 *
	 * @param array $params Query params (page, per_page, store_id).
	 * @return array|WP_Error
	 */
	public function list_store_products( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/store-products', $params );
	}

	/**
	 * Bulk update store products.
	 *
	 * @param array $data Bulk update data (products).
	 * @return array|WP_Error
	 */
	public function bulk_update_store_products( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->patch( '/v1/store-products/bulk', $data );
	}

	/**
	 * Get a store product by ID.
	 *
	 * @param string $product_id Product ID.
	 * @return array|WP_Error
	 */
	public function get_store_product( $product_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/store-products/' . rawurlencode( $product_id ) );
	}

	// =========================================================================
	// Stores
	// =========================================================================

	/**
	 * List connected stores.
	 *
	 * @return array|WP_Error
	 */
	public function list_stores() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/stores' );
	}

	/**
	 * Connect a new store.
	 *
	 * @param array $data Store data (platform, url, credentials).
	 * @return array|WP_Error
	 */
	public function create_store( $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/stores', $data );
	}

	/**
	 * Get a store by ID.
	 *
	 * @param string $store_id Store ID.
	 * @return array|WP_Error
	 */
	public function get_store( $store_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/stores/' . rawurlencode( $store_id ) );
	}

	/**
	 * Update a store.
	 *
	 * @param string $store_id Store ID.
	 * @param array  $data     Updated store data.
	 * @return array|WP_Error
	 */
	public function update_store( $store_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->put( '/v1/stores/' . rawurlencode( $store_id ), $data );
	}

	/**
	 * Delete a store.
	 *
	 * @param string $store_id Store ID.
	 * @return array|WP_Error
	 */
	public function delete_store( $store_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/stores/' . rawurlencode( $store_id ) );
	}

	/**
	 * Batch import/update products for a store.
	 *
	 * @param string $store_id Store ID.
	 * @param array  $data     Products data.
	 * @return array|WP_Error
	 */
	public function batch_store_products( $store_id, $data ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/stores/' . rawurlencode( $store_id ) . '/products/batch', $data );
	}

	/**
	 * Trigger a store sync.
	 *
	 * @param string $store_id Store ID.
	 * @return array|WP_Error
	 */
	public function sync_store( $store_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/stores/' . rawurlencode( $store_id ) . '/sync' );
	}

	/**
	 * List sync jobs for a store.
	 *
	 * @param string $store_id Store ID.
	 * @param array  $params   Query params (page, per_page).
	 * @return array|WP_Error
	 */
	public function list_store_sync_jobs( $store_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/stores/' . rawurlencode( $store_id ) . '/sync-jobs', $params );
	}

	/**
	 * Get errors for a store sync job.
	 *
	 * @param string $store_id Store ID.
	 * @param string $job_id   Sync job ID.
	 * @param array  $params   Query params (page, per_page).
	 * @return array|WP_Error
	 */
	public function get_store_sync_job_errors( $store_id, $job_id, $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/stores/' . rawurlencode( $store_id ) . '/sync-jobs/' . rawurlencode( $job_id ) . '/errors', $params );
	}

	// =========================================================================
	// Webhook CLI
	// =========================================================================

	/**
	 * List webhook CLI deliveries.
	 *
	 * @param array $params Query params (page, per_page, session_id).
	 * @return array|WP_Error
	 */
	public function list_webhook_deliveries( $params = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->get( '/v1/webhook-cli/deliveries', $params );
	}

	/**
	 * Replay a webhook delivery.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @return array|WP_Error
	 */
	public function replay_webhook_delivery( $delivery_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/webhook-cli/deliveries/' . rawurlencode( $delivery_id ) . '/replay' );
	}

	/**
	 * Create a webhook CLI session.
	 *
	 * @param array $data Session data (events).
	 * @return array|WP_Error
	 */
	public function create_webhook_cli_session( $data = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->post( '/v1/webhook-cli/sessions', $data );
	}

	/**
	 * Delete a webhook CLI session.
	 *
	 * @param string $session_id Session ID.
	 * @return array|WP_Error
	 */
	public function delete_webhook_cli_session( $session_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'mailodds_no_api_key', __( 'MailOdds API key not configured.', 'mailodds-email-validation' ) );
		}
		return $this->delete( '/v1/webhook-cli/sessions/' . rawurlencode( $session_id ) );
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
		$url = $this->api_base . $endpoint;

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
	 * Make a PATCH request.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @return array|WP_Error
	 */
	private function patch( $endpoint, $body = array() ) {
		return $this->request( 'PATCH', $endpoint, array( 'body' => $body ) );
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
