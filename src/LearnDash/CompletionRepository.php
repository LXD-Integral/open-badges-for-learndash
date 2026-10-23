<?php
/**
 * Historical LearnDash completions.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\LearnDash;

use OBLD\Triggers\EventType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see the class docblock below.

/**
 * Finds learners who already completed a LearnDash item.
 *
 * Reads `wp_learndash_user_activity`, which is LearnDash's own completion
 * index (its own reporting screens read the same table) and is indexed on
 * user_id, post_id, activity_type and activity_status. For quizzes,
 * `activity_status` means *passed*, and the attempt's full result set — score
 * percentage included — is in `wp_learndash_user_activity_meta`, which is what
 * lets a backfill honour a trigger's minimum-score condition.
 *
 * Interpolated values are LearnDash table names built from $wpdb->prefix;
 * every caller value is bound through $wpdb->prepare().
 *
 * Not final: tests substitute a fake so they need neither LearnDash nor a
 * database.
 */
class CompletionRepository {

	/**
	 * Maximum learners returned in one page of a backfill.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Whether LearnDash's activity table is present.
	 *
	 * Backfill is the one feature that reads LearnDash's own storage directly,
	 * so it states plainly when it cannot rather than reporting zero completers
	 * as though nobody qualified.
	 */
	public function available(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'learndash_user_activity';

		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}

	/**
	 * Learner ids who completed an item, oldest completion first.
	 *
	 * @param EventType  $event_type Completion event.
	 * @param int        $target_id  LearnDash item id.
	 * @param float|null $min_score  Minimum quiz percentage, or null.
	 * @param int        $limit      Page size.
	 * @param int        $offset     Rows to skip.
	 *
	 * @return array<int, int>
	 */
	public function completers(
		EventType $event_type,
		int $target_id,
		?float $min_score = null,
		int $limit = self::PAGE_SIZE,
		int $offset = 0
	): array {
		global $wpdb;

		$activity = $wpdb->prefix . 'learndash_user_activity';
		$meta     = $wpdb->prefix . 'learndash_user_activity_meta';

		$join  = '';
		$where = '';
		$args  = array( $event_type->activity_type(), $target_id );

		// A score condition only makes sense for quizzes, and only quizzes
		// record a percentage.
		if ( null !== $min_score && $event_type->supports_min_score() ) {
			$join   = "INNER JOIN {$meta} m ON m.activity_id = a.activity_id AND m.activity_meta_key = 'percentage'";
			$where  = 'AND CAST( m.activity_meta_value AS DECIMAL(10,4) ) >= %f';
			$args[] = $min_score;
		}

		$args[] = $limit;
		$args[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count spread placeholders.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT a.user_id
				FROM {$activity} a
				{$join}
				WHERE a.activity_type = %s
				AND a.post_id = %d
				AND a.activity_status = 1
				{$where}
				ORDER BY a.user_id ASC
				LIMIT %d OFFSET %d",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
