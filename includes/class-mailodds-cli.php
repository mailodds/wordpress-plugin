<?php
/**
 * MailOdds WP-CLI commands.
 *
 * Commands:
 *   wp mailodds validate <email>                Validate a single email
 *   wp mailodds bulk [--batch=50]               Bulk validate unvalidated users
 *   wp mailodds status                          Show plugin status and stats
 *   wp mailodds suppression list                List suppression entries
 *   wp mailodds suppression add <email>         Add suppression entry
 *   wp mailodds suppression remove <email>      Remove suppression entry
 *   wp mailodds suppression check <email>       Check if email is suppressed
 *   wp mailodds suppression stats               Show suppression stats
 *   wp mailodds jobs list                       List validation jobs
 *   wp mailodds jobs create                     Create a bulk validation job
 *   wp mailodds jobs status <job_id>            Check job status
 *   wp mailodds jobs results <job_id>           Get job results
 *   wp mailodds jobs cancel <job_id>            Cancel a job
 *   wp mailodds policies list                   List validation policies
 *   wp mailodds policies create <name>          Create a policy
 *   wp mailodds policies test <email> <id>      Test a policy
 *   wp mailodds policies delete <id>            Delete a policy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailOdds_CLI {

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
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @param MailOdds_API $api API client.
	 */
	public static function register( MailOdds_API $api ) {
		$instance = new self( $api );
		WP_CLI::add_command( 'mailodds validate', array( $instance, 'validate' ) );
		WP_CLI::add_command( 'mailodds bulk', array( $instance, 'bulk' ) );
		WP_CLI::add_command( 'mailodds status', array( $instance, 'status' ) );

		// Suppression commands
		WP_CLI::add_command( 'mailodds suppression list', array( $instance, 'suppression_list' ) );
		WP_CLI::add_command( 'mailodds suppression add', array( $instance, 'suppression_add' ) );
		WP_CLI::add_command( 'mailodds suppression remove', array( $instance, 'suppression_remove' ) );
		WP_CLI::add_command( 'mailodds suppression check', array( $instance, 'suppression_check' ) );
		WP_CLI::add_command( 'mailodds suppression stats', array( $instance, 'suppression_stats' ) );

		// Jobs commands
		WP_CLI::add_command( 'mailodds jobs list', array( $instance, 'jobs_list' ) );
		WP_CLI::add_command( 'mailodds jobs create', array( $instance, 'jobs_create' ) );
		WP_CLI::add_command( 'mailodds jobs status', array( $instance, 'jobs_status' ) );
		WP_CLI::add_command( 'mailodds jobs results', array( $instance, 'jobs_results' ) );
		WP_CLI::add_command( 'mailodds jobs cancel', array( $instance, 'jobs_cancel' ) );

		// Policy commands
		WP_CLI::add_command( 'mailodds policies list', array( $instance, 'policies_list' ) );
		WP_CLI::add_command( 'mailodds policies create', array( $instance, 'policies_create' ) );
		WP_CLI::add_command( 'mailodds policies test', array( $instance, 'policies_test' ) );
		WP_CLI::add_command( 'mailodds policies delete', array( $instance, 'policies_delete' ) );
	}

	/**
	 * Validate a single email address.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Email address to validate.
	 *
	 * [--depth=<depth>]
	 * : Validation depth (standard or enhanced).
	 * ---
	 * default: enhanced
	 * options:
	 *   - standard
	 *   - enhanced
	 * ---
	 *
	 * [--skip-cache]
	 * : Skip the transient cache.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailodds validate user@example.com
	 *     wp mailodds validate user@example.com --depth=standard --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function validate( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured. Set it in Settings > MailOdds.' );
		}

		$email   = $args[0];
		$options = array(
			'depth'      => isset( $assoc_args['depth'] ) ? $assoc_args['depth'] : 'enhanced',
			'skip_cache' => isset( $assoc_args['skip-cache'] ),
		);

		$result = $this->api->validate( $email, $options );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			WP_CLI\Utils\format_items( 'yaml', array( $result ), array_keys( $result ) );
			return;
		}

		// Table format - show key fields
		$display = array(
			array(
				'Field' => 'email',
				'Value' => isset( $result['email'] ) ? $result['email'] : $email,
			),
			array(
				'Field' => 'status',
				'Value' => isset( $result['status'] ) ? $result['status'] : 'unknown',
			),
			array(
				'Field' => 'action',
				'Value' => isset( $result['action'] ) ? $result['action'] : 'unknown',
			),
			array(
				'Field' => 'sub_status',
				'Value' => isset( $result['sub_status'] ) ? $result['sub_status'] : '-',
			),
		);

		$bool_fields = array( 'free_provider', 'disposable', 'role_account', 'mx_found' );
		foreach ( $bool_fields as $field ) {
			$display[] = array(
				'Field' => $field,
				'Value' => isset( $result[ $field ] ) ? ( $result[ $field ] ? 'true' : 'false' ) : '-',
			);
		}

		if ( isset( $result['depth'] ) ) {
			$display[] = array(
				'Field' => 'depth',
				'Value' => $result['depth'],
			);
		}

		if ( ! empty( $result['_cached'] ) ) {
			$display[] = array(
				'Field' => 'cached',
				'Value' => 'true',
			);
		}

		WP_CLI\Utils\format_items( 'table', $display, array( 'Field', 'Value' ) );

		$action = isset( $result['action'] ) ? $result['action'] : '';
		if ( 'accept' === $action ) {
			WP_CLI::success( 'Email is valid.' );
		} elseif ( 'reject' === $action ) {
			WP_CLI::warning( 'Email should be rejected.' );
		} elseif ( 'accept_with_caution' === $action ) {
			WP_CLI::warning( 'Email is risky (accept with caution).' );
		} else {
			WP_CLI::warning( 'Email status is unknown.' );
		}
	}

	/**
	 * Bulk validate unvalidated WordPress users.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Number of users per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum total users to validate (0 = all).
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailodds bulk
	 *     wp mailodds bulk --batch=100 --limit=500
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function bulk( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured. Set it in Settings > MailOdds.' );
		}

		$batch_size = isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 50;
		$limit      = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

		if ( $batch_size < 1 ) {
			$batch_size = 50;
		}

		$total_processed = 0;
		$total_errors    = 0;
		$summary         = array(
			'valid'       => 0,
			'invalid'     => 0,
			'catch_all'   => 0,
			'unknown'     => 0,
			'do_not_mail' => 0,
		);

		WP_CLI::line( 'Starting bulk validation...' );

		while ( true ) {
			if ( $limit > 0 && $total_processed >= $limit ) {
				break;
			}

			$remaining  = $limit > 0 ? min( $batch_size, $limit - $total_processed ) : $batch_size;

			$users = get_users( array(
				'meta_query' => array(
					array(
						'key'     => '_mailodds_status',
						'compare' => 'NOT EXISTS',
					),
				),
				'number' => $remaining,
				'fields' => array( 'ID', 'user_email' ),
			) );

			if ( empty( $users ) ) {
				break;
			}

			$emails   = array();
			$user_map = array();
			foreach ( $users as $user ) {
				$emails[] = $user->user_email;
				$user_map[ $user->user_email ] = $user->ID;
			}

			$results = $this->api->validate_batch( $emails );

			if ( is_wp_error( $results ) ) {
				WP_CLI::warning( 'Batch failed: ' . $results->get_error_message() );
				$total_errors += count( $emails );
				// Mark as failed to avoid infinite loop
				foreach ( $users as $user ) {
					update_user_meta( $user->ID, '_mailodds_status', 'error' );
					update_user_meta( $user->ID, '_mailodds_action', 'retry_later' );
					update_user_meta( $user->ID, '_mailodds_validated_at', current_time( 'mysql' ) );
				}
				continue;
			}

			foreach ( $results as $item ) {
				$email = isset( $item['email'] ) ? $item['email'] : '';
				if ( isset( $user_map[ $email ] ) ) {
					$user_id = $user_map[ $email ];
					$status  = isset( $item['status'] ) ? $item['status'] : 'unknown';
					update_user_meta( $user_id, '_mailodds_status', sanitize_text_field( $status ) );
					update_user_meta( $user_id, '_mailodds_action', sanitize_text_field( $item['action'] ) );
					update_user_meta( $user_id, '_mailodds_validated_at', current_time( 'mysql' ) );
					if ( isset( $summary[ $status ] ) ) {
						$summary[ $status ]++;
					}
				}
			}

			$total_processed += count( $users );
			WP_CLI::line( sprintf( '  Processed %d users...', $total_processed ) );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Results:' );
		WP_CLI::line( sprintf( '  Total processed: %d', $total_processed ) );
		WP_CLI::line( sprintf( '  Valid:           %d', $summary['valid'] ) );
		WP_CLI::line( sprintf( '  Invalid:         %d', $summary['invalid'] ) );
		WP_CLI::line( sprintf( '  Catch-all:       %d', $summary['catch_all'] ) );
		WP_CLI::line( sprintf( '  Do Not Mail:     %d', $summary['do_not_mail'] ) );
		WP_CLI::line( sprintf( '  Unknown:         %d', $summary['unknown'] ) );

		if ( $total_errors > 0 ) {
			WP_CLI::line( sprintf( '  Errors:          %d', $total_errors ) );
		}

		WP_CLI::success( 'Bulk validation complete.' );
	}

	/**
	 * Show MailOdds plugin status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailodds status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$has_key   = $this->api->has_key();
		$test_mode = $this->api->is_test_mode();
		$depth     = get_option( 'mailodds_depth', 'enhanced' );
		$threshold = get_option( 'mailodds_action_threshold', 'reject' );
		$policy_id = get_option( 'mailodds_policy_id', 0 );
		$cron      = get_option( 'mailodds_cron_enabled', false );
		$stats     = get_option( 'mailodds_daily_stats', array() );
		$integrations = get_option( 'mailodds_integrations', array() );

		$display = array(
			array( 'Setting' => 'Version',        'Value' => MAILODDS_VERSION ),
			array( 'Setting' => 'API Key',         'Value' => $has_key ? 'Configured' : 'NOT SET' ),
			array( 'Setting' => 'Test Mode',       'Value' => $test_mode ? 'Yes' : 'No' ),
			array( 'Setting' => 'Depth',           'Value' => $depth ),
			array( 'Setting' => 'Block Threshold', 'Value' => $threshold ),
			array( 'Setting' => 'Policy ID',       'Value' => $policy_id > 0 ? $policy_id : 'Default' ),
			array( 'Setting' => 'Weekly Cron',     'Value' => $cron ? 'Enabled' : 'Disabled' ),
		);

		// Active integrations
		$active = array();
		foreach ( $integrations as $key => $enabled ) {
			if ( $enabled ) {
				$active[] = $key;
			}
		}
		$display[] = array(
			'Setting' => 'Integrations',
			'Value'   => ! empty( $active ) ? implode( ', ', $active ) : 'None',
		);

		// Today's stats
		$today       = current_time( 'Y-m-d' );
		$today_stats = isset( $stats[ $today ] ) ? $stats[ $today ] : null;
		if ( $today_stats ) {
			$display[] = array(
				'Setting' => 'Today Validated',
				'Value'   => $today_stats['total'],
			);
		}

		// Validated users count
		$validated = get_users( array(
			'meta_query' => array(
				array(
					'key'     => '_mailodds_status',
					'compare' => 'EXISTS',
				),
			),
			'count_total' => true,
			'fields'      => 'ID',
		) );
		$display[] = array( 'Setting' => 'Users Validated', 'Value' => count( $validated ) );

		WP_CLI\Utils\format_items( 'table', $display, array( 'Setting', 'Value' ) );
	}

	// =========================================================================
	// Suppression commands
	// =========================================================================

	/**
	 * List suppression entries.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function suppression_list( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$params = array(
			'page'     => isset( $assoc_args['page'] ) ? absint( $assoc_args['page'] ) : 1,
			'per_page' => isset( $assoc_args['per-page'] ) ? absint( $assoc_args['per-page'] ) : 25,
		);
		if ( isset( $assoc_args['type'] ) ) {
			$params['type'] = $assoc_args['type'];
		}

		$result = $this->api->get_suppression_list( $params );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$entries = isset( $result['entries'] ) ? $result['entries'] : array();
		if ( empty( $entries ) ) {
			WP_CLI::line( 'No suppression entries found.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $entries, array( 'email', 'type', 'source', 'created_at' ) );
		WP_CLI::line( sprintf( 'Total: %d', isset( $result['total'] ) ? $result['total'] : count( $entries ) ) );
	}

	/**
	 * Add email to suppression list.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function suppression_add( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$email = $args[0];
		$type  = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'manual';

		$result = $this->api->add_suppression( array( array( 'email' => $email, 'type' => $type ) ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Added $email to suppression list." );
	}

	/**
	 * Remove email from suppression list.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function suppression_remove( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$email  = $args[0];
		$result = $this->api->remove_suppression( array( array( 'email' => $email ) ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Removed $email from suppression list." );
	}

	/**
	 * Check if email is suppressed.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function suppression_check( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$email  = $args[0];
		$result = $this->api->check_suppression( $email );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$suppressed = isset( $result['suppressed'] ) && $result['suppressed'];
		if ( $suppressed ) {
			$type = isset( $result['type'] ) ? $result['type'] : 'unknown';
			WP_CLI::warning( "$email is suppressed (type: $type)." );
		} else {
			WP_CLI::success( "$email is not suppressed." );
		}
	}

	/**
	 * Show suppression stats.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function suppression_stats( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$result = $this->api->get_suppression_stats();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line( sprintf( 'Total suppressed: %d', isset( $result['total'] ) ? $result['total'] : 0 ) );
		if ( isset( $result['by_type'] ) && is_array( $result['by_type'] ) ) {
			foreach ( $result['by_type'] as $type => $count ) {
				WP_CLI::line( sprintf( '  %s: %d', $type, $count ) );
			}
		}
	}

	// =========================================================================
	// Jobs commands
	// =========================================================================

	/**
	 * List validation jobs.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function jobs_list( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$result = $this->api->list_jobs( array(
			'page'     => isset( $assoc_args['page'] ) ? absint( $assoc_args['page'] ) : 1,
			'per_page' => 20,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$jobs = isset( $result['jobs'] ) ? $result['jobs'] : array();
		if ( empty( $jobs ) ) {
			WP_CLI::line( 'No jobs found.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $jobs, array( 'id', 'status', 'total_count', 'processed_count', 'created_at' ) );
	}

	/**
	 * Create a bulk validation job from unvalidated users.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function jobs_create( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

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
			WP_CLI::error( 'No unvalidated users found.' );
		}

		$emails = array();
		foreach ( $users as $user ) {
			$emails[] = $user->user_email;
		}

		WP_CLI::line( sprintf( 'Creating job for %d emails...', count( $emails ) ) );

		$result = $this->api->create_job( $emails );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$job_id = isset( $result['id'] ) ? $result['id'] : '';
		WP_CLI::success( "Job created: $job_id" );
	}

	/**
	 * Check job status.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function jobs_status( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$result = $this->api->get_job( $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Get job results.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function jobs_results( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$page = isset( $assoc_args['page'] ) ? absint( $assoc_args['page'] ) : 1;
		$result = $this->api->get_job_results( $args[0], array( 'page' => $page, 'per_page' => 50 ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$items = isset( $result['results'] ) ? $result['results'] : array();
		if ( empty( $items ) ) {
			WP_CLI::line( 'No results yet.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'email', 'status', 'action' ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function jobs_cancel( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$result = $this->api->cancel_job( $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Job cancelled.' );
	}

	// =========================================================================
	// Policy commands
	// =========================================================================

	/**
	 * List validation policies.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function policies_list( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$result = $this->api->list_policies();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$policies = isset( $result['policies'] ) ? $result['policies'] : ( is_array( $result ) ? $result : array() );
		if ( empty( $policies ) ) {
			WP_CLI::line( 'No policies found.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $policies, array( 'id', 'name', 'rule_count' ) );
	}

	/**
	 * Create a policy.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function policies_create( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$name = $args[0];
		$preset = isset( $assoc_args['preset'] ) ? $assoc_args['preset'] : '';

		if ( ! empty( $preset ) ) {
			$result = $this->api->create_policy_from_preset( $preset, $name );
		} else {
			$result = $this->api->create_policy( array( 'name' => $name ) );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$id = isset( $result['id'] ) ? $result['id'] : '';
		WP_CLI::success( "Policy created: $id" );
	}

	/**
	 * Test a policy against an email.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function policies_test( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$email     = $args[0];
		$policy_id = absint( $args[1] );

		$result = $this->api->test_policy( array( 'email' => $email, 'policy_id' => $policy_id ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Delete a policy.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function policies_delete( $args, $assoc_args ) {
		if ( ! $this->api->has_key() ) {
			WP_CLI::error( 'API key not configured.' );
		}

		$id = absint( $args[0] );
		$result = $this->api->delete_policy( $id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Policy $id deleted." );
	}
}
