<?php
/**
 * Badges list table.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Native admin table for synced badges. Read-only: no bulk actions, no row
 * actions — search/filtering is explicitly out of scope for v1.
 */
final class BadgeListTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $badges All synced badge rows.
	 */
	public function __construct( private readonly array $badges ) {
		parent::__construct(
			array(
				'singular' => 'badge',
				'plural'   => 'badges',
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
			'image'       => __( 'Image', 'open-badges-for-learndash' ),
			'name'        => __( 'Name', 'open-badges-for-learndash' ),
			'description' => __( 'Description', 'open-badges-for-learndash' ),
			'provider'    => __( 'Provider', 'open-badges-for-learndash' ),
			'synced_at'   => __( 'Last synced', 'open-badges-for-learndash' ),
		);
	}

	/**
	 * Slice rows into the current page.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$total   = count( $this->badges );
		$current = $this->get_pagenum();

		$this->items = array_slice( $this->badges, ( $current - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Image column: 48px thumbnail hotlinked from the provider's public URL.
	 *
	 * @param object $item Badge row.
	 */
	public function column_image( object $item ): string {
		if ( empty( $item->image_url ) ) {
			return '—';
		}

		return sprintf(
			'<img src="%s" alt="%s" width="48" height="48" style="object-fit:contain;" loading="lazy" />',
			esc_url( $item->image_url ),
			esc_attr( $item->name )
		);
	}

	/**
	 * Name column, with a Draft tag where applicable.
	 *
	 * @param object $item Badge row.
	 */
	public function column_name( object $item ): string {
		$name = '<strong>' . esc_html( $item->name ) . '</strong>';

		if ( ! empty( $item->is_draft ) ) {
			$name .= ' <span style="color:#787c82;">— ' . esc_html__( 'Draft', 'open-badges-for-learndash' ) . '</span>';
		}

		return $name;
	}

	/**
	 * Description column, trimmed.
	 *
	 * @param object $item Badge row.
	 */
	public function column_description( object $item ): string {
		return esc_html( wp_trim_words( (string) $item->description, 25 ) );
	}

	/**
	 * Provider column.
	 *
	 * @param object $item Badge row.
	 */
	public function column_provider( object $item ): string {
		$provider = Provider::tryFrom( (string) $item->provider );

		return esc_html( $provider ? $provider->label() : (string) $item->provider );
	}

	/**
	 * Last-synced column.
	 *
	 * @param object $item Badge row.
	 */
	public function column_synced_at( object $item ): string {
		$timestamp = strtotime( (string) $item->synced_at . ' UTC' );

		if ( false === $timestamp ) {
			return '—';
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 mins". */
			esc_html__( '%s ago', 'open-badges-for-learndash' ),
			esc_html( human_time_diff( $timestamp ) )
		);
	}

	/**
	 * Fallback column renderer (unused columns).
	 *
	 * @param object $item        Badge row.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}
}
