<?php
/**
 * MailOdds uninstall handler.
 *
 * Removes all plugin data when the plugin is deleted (not deactivated).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options
delete_option( 'mailodds_api_key' );
delete_option( 'mailodds_depth' );
delete_option( 'mailodds_action_threshold' );
delete_option( 'mailodds_policy_id' );
delete_option( 'mailodds_cron_enabled' );
delete_option( 'mailodds_cron_stats' );
delete_option( 'mailodds_cron_job_id' );
delete_option( 'mailodds_daily_stats' );
delete_option( 'mailodds_integrations' );
delete_option( 'mailodds_check_suppression' );
delete_option( 'mailodds_webhook_secret' );
delete_option( 'mailodds_telemetry_dashboard' );

// Delete all transients (cached validation results + plugin state)
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mailodds_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mailodds_%'" );

// Delete user meta
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_mailodds_status', '_mailodds_action', '_mailodds_validated_at')" );

// Clear scheduled cron events
wp_clear_scheduled_hook( 'mailodds_cron_validate_users' );
wp_clear_scheduled_hook( 'mailodds_cron_check_job' );
