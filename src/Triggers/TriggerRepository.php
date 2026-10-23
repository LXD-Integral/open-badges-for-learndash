<?php
/**
 * Trigger-rule storage.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Triggers;

use OBLD\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see the class docblock below.

/**
 * Reads and writes the obld_triggers table.
 *
 * Deliberately logic-free in the same spirit as BadgeRepository: validation
 * and orchestration live in TriggersPage, so this stays a thin, obvious layer.
 *
 * Not final: tests substitute a stored-state fake for the $wpdb-bound parts.
 *
 * As in BadgeRepository, the only interpolated values are Schema::table()
 * names (built from $wpdb->prefix and a literal); every caller value is bound
 * through $wpdb->prepare(). InterpolatedNotPrepared is suppressed file-wide
 * rather than line by line — keep that invariant true.
 */
class TriggerRepository {

	/**
	 * Every trigger, newest first, joined to its badge.
	 *
	 * A LEFT JOIN on purpose: a badge row can go missing (deleted at the
	 * provider *and* pruned locally) and the screen must still render the
	 * orphaned rule rather than dropping it silently.
	 *
	 * @return array<int, object>
	 */
	public function all(): array {
		global $wpdb;

		$triggers = Schema::table( 'triggers' );
		$badges   = Schema::table( 'badges' );

		$rows = $wpdb->get_results(
			"SELECT t.*,
				b.name AS badge_name,
				b.image_url AS badge_image_url,
				b.provider AS badge_provider,
				b.provider_badge_id AS badge_provider_badge_id,
				b.is_draft AS badge_is_draft,
				b.missing_at_provider AS badge_missing_at_provider
			FROM {$triggers} t
			LEFT JOIN {$badges} b ON b.id = t.badge_id
			ORDER BY t.created_at DESC, t.id DESC"
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A single trigger by id, joined to its badge, or null.
	 *
	 * Joined rather than a bare SELECT so every caller gets the same row shape
	 * as all() and matching(). A backfill needs the badge name for its summary,
	 * and reading it off an unjoined row silently yielded an empty name.
	 *
	 * @param int $id Trigger id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$triggers = Schema::table( 'triggers' );
		$badges   = Schema::table( 'badges' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.*,
					b.name AS badge_name,
					b.provider AS badge_provider,
					b.provider_badge_id AS badge_provider_badge_id,
					b.is_draft AS badge_is_draft,
					b.missing_at_provider AS badge_missing_at_provider
				FROM {$triggers} t
				LEFT JOIN {$badges} b ON b.id = t.badge_id
				WHERE t.id = %d",
				$id
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Create a trigger. Returns the new id, or 0 on failure.
	 *
	 * @param EventType  $event_type Completion event to listen for.
	 * @param int        $target_id  LearnDash item id.
	 * @param int        $badge_id   Local badge id (wp_obld_badges.id).
	 * @param float|null $min_score  Minimum quiz score percentage, or null.
	 */
	public function create( EventType $event_type, int $target_id, int $badge_id, ?float $min_score ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'triggers' ),
			array(
				'badge_id'    => $badge_id,
				'event_type'  => $event_type->value,
				'target_id'   => $target_id,
				'min_score'   => $min_score,
				'enabled'     => 1,
				'fired_count' => 0,
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Whether an identical rule already exists.
	 *
	 * Several triggers may target the same LearnDash item (the PRD requires
	 * it) — what is rejected is the exact same badge on the same item, which
	 * is always a duplicate rather than a second rule.
	 *
	 * @param EventType $event_type Event type.
	 * @param int       $target_id  LearnDash item id.
	 * @param int       $badge_id   Local badge id.
	 */
	public function exists( EventType $event_type, int $target_id, int $badge_id ): bool {
		global $wpdb;

		$table = Schema::table( 'triggers' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE event_type = %s AND target_id = %d AND badge_id = %d",
				$event_type->value,
				$target_id,
				$badge_id
			)
		);
	}

	/**
	 * Delete a trigger.
	 *
	 * @param int $id Trigger id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'triggers' ), array( 'id' => $id ) );
	}

	/**
	 * Enable or disable a trigger.
	 *
	 * @param int  $id      Trigger id.
	 * @param bool $enabled Desired state.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			Schema::table( 'triggers' ),
			array( 'enabled' => $enabled ? 1 : 0 ),
			array( 'id' => $id )
		);
	}

	/**
	 * Enabled triggers matching a completion event, joined to their badges.
	 *
	 * More than one rule may target the same item — all enabled matches fire —
	 * so this deliberately returns every match rather than the first.
	 *
	 * @param EventType $event_type Completion event that occurred.
	 * @param int       $target_id  LearnDash item completed.
	 *
	 * @return array<int, object>
	 */
	public function matching( EventType $event_type, int $target_id ): array {
		global $wpdb;

		$triggers = Schema::table( 'triggers' );
		$badges   = Schema::table( 'badges' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*,
					b.provider AS badge_provider,
					b.provider_badge_id AS badge_provider_badge_id,
					b.name AS badge_name,
					b.is_draft AS badge_is_draft,
					b.missing_at_provider AS badge_missing_at_provider
				FROM {$triggers} t
				INNER JOIN {$badges} b ON b.id = t.badge_id
				WHERE t.enabled = 1 AND t.event_type = %s AND t.target_id = %d
				ORDER BY t.id ASC",
				$event_type->value,
				$target_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Increment a trigger's fired count.
	 *
	 * Counts successful issuances, not matches: a rule that matched but could
	 * not issue has not fired.
	 *
	 * @param int $id Trigger id.
	 */
	public function increment_fired( int $id ): void {
		global $wpdb;

		$table = Schema::table( 'triggers' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET fired_count = fired_count + 1 WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Number of triggers defined.
	 */
	public function count(): int {
		global $wpdb;

		$table = Schema::table( 'triggers' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
