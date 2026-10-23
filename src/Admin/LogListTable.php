<?php
/**
 * Activity log list table.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Log\Logger;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Native admin table for activity-log entries. Read-only.
 *
 * Pagination is server-side (the query is already limited and offset), unlike
 * the badge and trigger tables which slice an in-memory array — a log can grow
 * without bound, so it must never be loaded whole.
 */
final class LogListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $entries  Rows for the current page.
	 * @param int                $total    Total rows matching the filter.
	 * @param int                $page     Current page number.
	 * @param int                $per_page Rows per page.
	 */
	public function __construct(
		private readonly array $entries,
		private readonly int $total,
		private readonly int $page,
		private readonly int $per_page,
	) {
		parent::__construct(
			array(
				'singular' => 'log-entry',
				'plural'   => 'log-entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at' => __( 'When', 'open-badges-for-learndash' ),
			'outcome'    => __( 'Outcome', 'open-badges-for-learndash' ),
			'user'       => __( 'Learner', 'open-badges-for-learndash' ),
			'badge'      => __( 'Badge', 'open-badges-for-learndash' ),
			'message'    => __( 'Message', 'open-badges-for-learndash' ),
		);
	}

	/**
	 * Adopt the already-paginated query result.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->entries;

		$this->set_pagination_args(
			array(
				'total_items' => $this->total,
				'per_page'    => $this->per_page,
				'total_pages' => (int) ceil( $this->total / max( 1, $this->per_page ) ),
			)
		);
	}

	/**
	 * Current page number (server-side pagination).
	 */
	public function get_pagenum(): int {
		return $this->page;
	}

	/**
	 * When column: absolute UTC time plus a relative hint.
	 *
	 * @param object $item Log row.
	 */
	public function column_created_at( object $item ): string {
		$timestamp = strtotime( (string) $item->created_at . ' UTC' );

		if ( false === $timestamp ) {
			return esc_html( (string) $item->created_at );
		}

		return esc_html( gmdate( 'Y-m-d H:i', $timestamp ) )
			. '<br /><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "2 mins". */
					__( '%s ago', 'open-badges-for-learndash' ),
					human_time_diff( $timestamp )
				)
			) . '</span>';
	}

	/**
	 * Outcome column.
	 *
	 * @param object $item Log row.
	 */
	public function column_outcome( object $item ): string {
		if ( Logger::ERROR === $item->outcome ) {
			return '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Error', 'open-badges-for-learndash' ) . '</span>';
		}

		return '<span style="color:#00794b;">' . esc_html__( 'Success', 'open-badges-for-learndash' ) . '</span>';
	}

	/**
	 * Learner column.
	 *
	 * @param object $item Log row.
	 */
	public function column_user( object $item ): string {
		if ( empty( $item->user_id ) ) {
			return '—';
		}

		if ( empty( $item->user_name ) ) {
			// The account was deleted after the entry was written; the log is a
			// history, so keep the row and show what is still known.
			return esc_html(
				sprintf(
					/* translators: %d: user ID of a deleted account. */
					__( 'Deleted user #%d', 'open-badges-for-learndash' ),
					(int) $item->user_id
				)
			);
		}

		return esc_html( (string) $item->user_name )
			. '<br /><span class="description">' . esc_html( (string) $item->user_email ) . '</span>';
	}

	/**
	 * Badge column.
	 *
	 * @param object $item Log row.
	 */
	public function column_badge( object $item ): string {
		return empty( $item->badge_name ) ? '—' : esc_html( (string) $item->badge_name );
	}

	/**
	 * Message column.
	 *
	 * @param object $item Log row.
	 */
	public function column_message( object $item ): string {
		return esc_html( (string) $item->message );
	}

	/**
	 * Fallback column renderer (unused columns).
	 *
	 * @param object $item        Log row.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}
}
