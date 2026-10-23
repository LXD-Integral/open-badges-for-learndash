<?php
/**
 * Privacy exporter/eraser tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Privacy;
use PHPUnit\Framework\TestCase;

/**
 * Personal-data tools integration.
 */
final class PrivacyTest extends TestCase {

	protected function setUp(): void {
		obld_test_reset();
	}

	public function test_registers_an_exporter_and_an_eraser(): void {
		$privacy = new Privacy();

		$exporters = $privacy->register_exporter( array() );
		$erasers   = $privacy->register_eraser( array() );

		self::assertArrayHasKey( 'open-badges-for-learndash', $exporters );
		self::assertIsCallable( $exporters['open-badges-for-learndash']['callback'] );
		self::assertArrayHasKey( 'open-badges-for-learndash', $erasers );
		self::assertIsCallable( $erasers['open-badges-for-learndash']['callback'] );
	}

	public function test_export_queries_awards_by_user_id_and_email(): void {
		obld_test_add_user( 7, 'learner@example.test' );

		$result = ( new Privacy() )->export( 'learner@example.test' );

		self::assertTrue( $result['done'] );
		self::assertSame( array(), $result['data'] );
		$sql = $GLOBALS['wpdb']->queries[0];
		self::assertStringContainsString( 'FROM wp_obld_awards a', $sql );
		self::assertStringContainsString( "WHERE a.user_id = 7 OR a.recipient_email = 'learner@example.test'", $sql );
	}

	public function test_erase_blanks_award_emails_and_deletes_the_users_log_rows(): void {
		obld_test_add_user( 7, 'learner@example.test' );

		$result = ( new Privacy() )->erase( 'learner@example.test' );

		self::assertTrue( $result['done'] );
		$queries = $GLOBALS['wpdb']->queries;
		self::assertCount( 2, $queries );
		self::assertStringContainsString( "UPDATE wp_obld_awards SET recipient_email = '' WHERE ( user_id = 7 OR recipient_email = 'learner@example.test' )", $queries[0] );
		self::assertSame( 'DELETE FROM wp_obld_logs WHERE user_id = 7', $queries[1] );
	}

	public function test_erase_for_an_unknown_email_touches_awards_only(): void {
		$result = ( new Privacy() )->erase( 'nobody@example.test' );

		self::assertTrue( $result['done'] );
		self::assertCount( 1, $GLOBALS['wpdb']->queries );
		self::assertStringContainsString( "user_id = 0 OR recipient_email = 'nobody@example.test'", $GLOBALS['wpdb']->queries[0] );
	}
}
