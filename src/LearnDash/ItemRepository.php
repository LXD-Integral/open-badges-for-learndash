<?php
/**
 * LearnDash item lookup.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\LearnDash;

use OBLD\Triggers\EventType;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the LearnDash courses/lessons/topics/quizzes a trigger can target.
 *
 * Every query here is bounded. The predecessor plugin enumerated LearnDash
 * content with `numberposts => -1`, which on a large site loaded every course
 * into memory to build one <select>; that was a flagged defect and is not
 * repeated. Sites past the cap are told so in the UI rather than silently
 * getting a truncated list.
 *
 * Not final: tests substitute a fake so they need no WordPress or LearnDash.
 */
class ItemRepository {

	/**
	 * Maximum items listed per event type in the trigger form.
	 */
	public const MAX_ITEMS = 200;

	/**
	 * Whether LearnDash is active.
	 *
	 * Trigger rules can be authored without it (they simply never fire), but
	 * the item lists come from LearnDash's post types, so the form needs to
	 * explain itself when it is missing.
	 */
	public function learndash_active(): bool {
		return defined( 'LEARNDASH_VERSION' );
	}

	/**
	 * Selectable items for one event type, ordered by title.
	 *
	 * @param EventType $event_type Event whose target items are wanted.
	 *
	 * @return array{items: array<int, array{id: int, title: string}>, truncated: bool}
	 */
	public function items( EventType $event_type ): array {
		$posts = get_posts(
			array(
				'post_type'        => $event_type->post_type(),
				'post_status'      => 'publish',
				// One extra row is fetched purely to detect that a cap was hit.
				'numberposts'      => self::MAX_ITEMS + 1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$posts     = is_array( $posts ) ? $posts : array();
		$truncated = count( $posts ) > self::MAX_ITEMS;
		$posts     = array_slice( $posts, 0, self::MAX_ITEMS );

		$items = array();
		foreach ( $posts as $post ) {
			$title = trim( (string) $post->post_title );

			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => '' !== $title
					/* translators: %d: post ID of an untitled LearnDash item. */
					? $title : sprintf( __( '(untitled #%d)', 'open-badges-for-learndash' ), (int) $post->ID ),
			);
		}

		return array(
			'items'     => $items,
			'truncated' => $truncated,
		);
	}

	/**
	 * Display title for a trigger's target item, whatever its current state.
	 *
	 * Returns null when the item no longer exists — the Triggers screen shows
	 * that as a deleted-item warning rather than a blank cell.
	 *
	 * @param int $item_id Target post ID.
	 */
	public function title( int $item_id ): ?string {
		$post = get_post( $item_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$title = trim( (string) $post->post_title );

		/* translators: %d: post ID of an untitled LearnDash item. */
		return '' !== $title ? $title : sprintf( __( '(untitled #%d)', 'open-badges-for-learndash' ), $item_id );
	}

	/**
	 * Whether an item exists and is of the post type the event type expects.
	 *
	 * Guards the add-trigger form against a tampered or stale item id.
	 *
	 * @param EventType $event_type Event type being saved.
	 * @param int       $item_id    Submitted target post ID.
	 */
	public function is_valid_target( EventType $event_type, int $item_id ): bool {
		$post = get_post( $item_id );

		return $post instanceof \WP_Post && $post->post_type === $event_type->post_type();
	}
}
