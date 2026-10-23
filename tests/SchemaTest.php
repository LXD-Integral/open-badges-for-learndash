<?php
/**
 * Schema tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Structural checks on the table definitions (dbDelta runs against a real DB
 * only in manual verification; here we pin the invariants).
 */
final class SchemaTest extends TestCase {

	public function test_defines_all_four_tables(): void {
		$sql = Schema::table_definitions();

		$this->assertCount( 4, $sql );
		$joined = implode( "\n", $sql );
		foreach ( array( 'wp_obld_badges', 'wp_obld_triggers', 'wp_obld_awards', 'wp_obld_logs' ) as $table ) {
			$this->assertStringContainsString( "CREATE TABLE {$table}", $joined );
		}
	}

	public function test_no_foreign_keys_anywhere(): void {
		// DB-level FKs broke the old plugin's activation; they must never return.
		$this->assertStringNotContainsStringIgnoringCase( 'FOREIGN KEY', implode( ' ', Schema::table_definitions() ) );
		$this->assertStringNotContainsStringIgnoringCase( 'CONSTRAINT', implode( ' ', Schema::table_definitions() ) );
	}

	public function test_awards_enforce_trigger_idempotency(): void {
		$awards = null;
		foreach ( Schema::table_definitions() as $sql ) {
			if ( str_contains( $sql, 'obld_awards' ) ) {
				$awards = $sql;
			}
		}

		$this->assertNotNull( $awards );
		$this->assertStringContainsString( 'UNIQUE KEY user_trigger (user_id,trigger_id)', $awards );
	}

	public function test_awards_carry_the_export_link_columns(): void {
		// Milestone 7: per-recipient verifiable-export links live on the award.
		$awards = null;
		foreach ( Schema::table_definitions() as $sql ) {
			if ( str_contains( $sql, 'obld_awards' ) ) {
				$awards = $sql;
			}
		}

		$this->assertNotNull( $awards );
		$this->assertStringContainsString( 'assertion_id varchar(100) NULL', $awards );
		$this->assertStringContainsString( 'receive_url text NULL', $awards );
		$this->assertStringContainsString( 'assertion_json_url text NULL', $awards );
	}

	public function test_awards_carry_the_status_columns(): void {
		// Milestone 9: provider-side credential status lives on the award.
		$awards = null;
		foreach ( Schema::table_definitions() as $sql ) {
			if ( str_contains( $sql, 'obld_awards' ) ) {
				$awards = $sql;
			}
		}

		$this->assertNotNull( $awards );
		$this->assertStringContainsString( 'revoked tinyint(1) NOT NULL DEFAULT 0', $awards );
		$this->assertStringContainsString( 'expires_on datetime NULL', $awards );
		$this->assertStringContainsString( 'received tinyint(1) NOT NULL DEFAULT 0', $awards );
		$this->assertStringContainsString( 'in_passport tinyint(1) NOT NULL DEFAULT 0', $awards );
		$this->assertStringContainsString( 'status_checked_at datetime NULL', $awards );
	}

	public function test_schema_version_reflects_the_awards_columns(): void {
		$this->assertSame( '4', Schema::VERSION, 'New award columns need a version bump for maybe_upgrade() to run dbDelta.' );
	}

	public function test_badges_unique_per_provider(): void {
		$badges = Schema::table_definitions()[0];
		$this->assertStringContainsString( 'UNIQUE KEY provider_badge (provider,provider_badge_id)', $badges );
	}

	public function test_table_names_are_prefixed(): void {
		$this->assertSame( 'wp_obld_awards', Schema::table( 'awards' ) );
	}
}
