<?php
/**
 * Activity log writer.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Log;

use OBLD\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see the class docblock below.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count spread placeholders in entries().

/**
 * Writes and reads the obld_logs table.
 *
 * Every issuance attempt and every connection problem is recorded — "never
 * fail silently" is this plugin's founding principle — and ActivityLogPage
 * reads back through entries()/count()/clear().
 *
 * As in the other repositories, the only interpolated values are
 * Schema::table() names and locally-built placeholder fragments; every caller
 * value is bound through $wpdb->prepare(). Keep that invariant true.
 */
final class Logger {

	public const SUCCESS = 'success';
	public const ERROR   = 'error';

	/**
	 * Record a successful operation.
	 *
	 * @param string             $message Human-readable message.
	 * @param array<string, int> $context Optional user_id / badge_id / trigger_id.
	 */
	public function success( string $message, array $context = array() ): void {
		$this->write( self::SUCCESS, $message, $context );
	}

	/**
	 * Record a failure.
	 *
	 * @param string             $message Human-readable message.
	 * @param array<string, int> $context Optional user_id / badge_id / trigger_id.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( self::ERROR, $message, $context );
	}

	/**
	 * Recent log entries, newest first, joined to badge and learner names.
	 *
	 * @param string $outcome Filter: self::SUCCESS, self::ERROR, or '' for all.
	 * @param int    $limit   Maximum rows.
	 * @param int    $offset  Rows to skip.
	 *
	 * @return array<int, object>
	 */
	public function entries( string $outcome = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$logs   = Schema::table( 'logs' );
		$badges = Schema::table( 'badges' );

		$where  = '';
		$values = array();

		if ( self::SUCCESS === $outcome || self::ERROR === $outcome ) {
			$where    = 'WHERE l.outcome = %s';
			$values[] = $outcome;
		}

		$values[] = $limit;
		$values[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, b.name AS badge_name, u.display_name AS user_name, u.user_email AS user_email
				FROM {$logs} l
				LEFT JOIN {$badges} b ON b.id = l.badge_id
				LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				{$where}
				ORDER BY l.id DESC
				LIMIT %d OFFSET %d",
				...$values
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of log entries, optionally filtered by outcome.
	 *
	 * @param string $outcome Filter: self::SUCCESS, self::ERROR, or '' for all.
	 */
	public function count( string $outcome = '' ): int {
		global $wpdb;

		$table = Schema::table( 'logs' );

		if ( self::SUCCESS === $outcome || self::ERROR === $outcome ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE outcome = %s",
					$outcome
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete every log entry. Returns the number removed.
	 */
	public function clear(): int {
		global $wpdb;

		$table = Schema::table( 'logs' );

		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Insert a log row.
	 *
	 * @param string             $outcome self::SUCCESS or self::ERROR.
	 * @param string             $message Message.
	 * @param array<string, int> $context Optional related ids.
	 */
	private function write( string $outcome, string $message, array $context ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'logs' ),
			array(
				'created_at' => current_time( 'mysql', true ),
				'outcome'    => $outcome,
				'user_id'    => isset( $context['user_id'] ) ? (int) $context['user_id'] : null,
				'badge_id'   => isset( $context['badge_id'] ) ? (int) $context['badge_id'] : null,
				'trigger_id' => isset( $context['trigger_id'] ) ? (int) $context['trigger_id'] : null,
				'message'    => $message,
			)
		);
	}
}
