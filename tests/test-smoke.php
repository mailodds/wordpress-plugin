<?php
/**
 * Trivial test to verify the PHPUnit + Brain Monkey framework works.
 */

use Brain\Monkey\Functions;

class SmokeTest extends MailOdds_TestCase {

	public function test_api_class_exists() {
		$this->assertTrue( class_exists( 'MailOdds_API' ) );
	}

	public function test_validator_class_exists() {
		$this->assertTrue( class_exists( 'MailOdds_Validator' ) );
	}

	public function test_brain_monkey_stubs_work() {
		$this->assertSame( 'test@example.com', sanitize_email( 'test@example.com' ) );
		$this->assertSame( '', sanitize_email( 'not-an-email' ) );
	}

	public function test_brain_monkey_expect_works() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'mailodds_api_key', '' )
			->andReturn( 'mo_test_abc123' );

		$this->assertSame( 'mo_test_abc123', get_option( 'mailodds_api_key', '' ) );
	}

	public function test_api_instance_has_key() {
		$api = new MailOdds_API( 'mo_live_abc123' );
		$this->assertTrue( $api->has_key() );
	}

	public function test_api_instance_no_key() {
		$api = new MailOdds_API( '' );
		$this->assertFalse( $api->has_key() );
	}

	public function test_api_test_mode_detection() {
		$test_api = new MailOdds_API( 'mo_test_abc123' );
		$this->assertTrue( $test_api->is_test_mode() );

		$live_api = new MailOdds_API( 'mo_live_abc123' );
		$this->assertFalse( $live_api->is_test_mode() );
	}
}
