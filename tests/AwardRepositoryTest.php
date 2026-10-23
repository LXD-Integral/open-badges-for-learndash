<?php
/**
 * AwardRepository query-shape tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Issuance\AwardRepository;
use OBLD\Provider;
use PHPUnit\Framework\TestCase;

/**
 * The repository is deliberately logic-free, so these pin the SQL invariants
 * that the display layer relies on rather than exercising a database.
 */
final class AwardRepositoryTest extends TestCase {

	private AwardRepository $awards;

	protected function setUp(): void {
		obld_test_reset();
		$this->awards = new AwardRepository();
	}

	private function last_query(): string {
		$queries = $GLOBALS['wpdb']->queries;
		$this->assertNotEmpty( $queries, 'Expected a query to have been issued.' );

		return (string) end( $queries );
	}

	public function test_front_end_list_excludes_revoked_awards(): void {
		// Milestone 9: a revoked credential must never render — on the owner's
		// own list or on a public showcase — so the exclusion sits in the
		// query every renderer shares, not in any one renderer.
		$this->awards->issued_for_user( 7 );

		$sql = $this->last_query();
		$this->assertStringContainsString( 'a.revoked = 0', $sql );
		$this->assertStringContainsString( "a.status = 'issued'", $sql );
		$this->assertStringContainsString( 'a.user_id = 7', $sql );
	}

	public function test_front_end_list_selects_the_status_columns(): void {
		$this->awards->issued_for_user( 7 );

		$sql = $this->last_query();
		foreach ( array( 'a.expires_on', 'a.received', 'a.in_passport' ) as $column ) {
			$this->assertStringContainsString( $column, $sql );
		}
	}

	public function test_provider_listing_covers_only_issued_awards_of_that_provider(): void {
		$this->awards->issued_for_provider( Provider::CanCred );

		$sql = $this->last_query();
		$this->assertStringContainsString( "a.status = 'issued'", $sql );
		$this->assertStringContainsString( "b.provider = 'cancred'", $sql );
		$this->assertStringNotContainsString( 'revoked = 0', $sql, 'A refresh must see revoked rows too, or it could never un-revoke.' );
	}

	public function test_record_status_writes_the_flags_and_the_check_time(): void {
		$this->awards->record_status( 42, true, '2027-01-01 00:00:00', true, false );

		$updates = $GLOBALS['wpdb']->updates;
		$this->assertCount( 1, $updates );
		[ $table, $data, $where ] = $updates[0];

		$this->assertSame( 'wp_obld_awards', $table );
		$this->assertSame( array( 'id' => 42 ), $where );
		$this->assertSame( 1, $data['revoked'] );
		$this->assertSame( '2027-01-01 00:00:00', $data['expires_on'] );
		$this->assertSame( 1, $data['received'] );
		$this->assertSame( 0, $data['in_passport'] );
		$this->assertArrayHasKey( 'status_checked_at', $data );
	}

	public function test_record_status_can_clear_an_expiry(): void {
		$this->awards->record_status( 42, false, null, false, false );

		$this->assertNull( $GLOBALS['wpdb']->updates[0][1]['expires_on'] );
	}

	public function test_mark_status_checked_stamps_a_whole_provider_in_one_statement(): void {
		$this->awards->mark_status_checked( Provider::OBF );

		$sql = $this->last_query();
		$this->assertStringStartsWith( 'UPDATE wp_obld_awards', $sql );
		$this->assertStringContainsString( 'status_checked_at', $sql );
		$this->assertStringContainsString( "b.provider = 'obf'", $sql );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
	}
}
