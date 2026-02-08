<?php
/**
 * Tests for MailOdds_Admin class.
 *
 * Covers the sanitize_depth, sanitize_threshold, and sanitize_integrations
 * public methods used as WordPress settings sanitize callbacks.
 */

use Brain\Monkey\Functions;

class AdminTest extends MailOdds_TestCase {

	/**
	 * Create an Admin instance with a mocked API.
	 *
	 * The constructor registers hooks via add_action which Brain Monkey
	 * intercepts automatically.
	 *
	 * @return MailOdds_Admin
	 */
	private function create_admin() {
		$api = Mockery::mock( 'MailOdds_API' );
		return new MailOdds_Admin( $api );
	}

	public function test_sanitize_depth_accepts_standard() {
		$admin = $this->create_admin();
		$this->assertSame( 'standard', $admin->sanitize_depth( 'standard' ) );
	}

	public function test_sanitize_depth_accepts_enhanced() {
		$admin = $this->create_admin();
		$this->assertSame( 'enhanced', $admin->sanitize_depth( 'enhanced' ) );
	}

	public function test_sanitize_depth_rejects_invalid() {
		$admin = $this->create_admin();
		$this->assertSame( 'enhanced', $admin->sanitize_depth( 'bogus' ) );
	}

	public function test_sanitize_threshold_accepts_valid() {
		$admin = $this->create_admin();
		$this->assertSame( 'reject', $admin->sanitize_threshold( 'reject' ) );
		$this->assertSame( 'caution', $admin->sanitize_threshold( 'caution' ) );
	}

	public function test_sanitize_threshold_rejects_invalid() {
		$admin = $this->create_admin();
		$this->assertSame( 'reject', $admin->sanitize_threshold( 'bogus' ) );
	}

	public function test_sanitize_integrations_strips_unknown() {
		$admin = $this->create_admin();

		$result = $admin->sanitize_integrations( array(
			'wp_registration' => '1',
			'unknown_key'     => '1',
		) );

		$this->assertTrue( $result['wp_registration'] );
		$this->assertArrayNotHasKey( 'unknown_key', $result );

		// Non-array input returns empty array.
		$this->assertSame( array(), $admin->sanitize_integrations( 'not_array' ) );
	}
}
