<?php
/**
 * Triggers list table.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

use OBLD\LearnDash\ItemRepository;
use OBLD\Provider;
use OBLD\Triggers\EventType;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Native admin table for trigger rules, with per-row enable/disable and
 * delete actions. Rows whose badge or LearnDash item has gone away are shown
 * with an explicit warning rather than being hidden — a rule that cannot fire
 * must be visible, not silent.
 */
final class TriggerListTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $triggers  Trigger rows joined to their badges.
	 * @param ItemRepository     $item_repo LearnDash item lookup. NOT named
	 *                                      $items: WP_List_Table already owns
	 *                                      an $items property, and redeclaring
	 *                                      it readonly is a fatal error.
	 */
	public function __construct(
		private readonly array $triggers,
		private readonly ItemRepository $item_repo,
	) {
		parent::__construct(
			array(
				'singular' => 'trigger',
				'plural'   => 'triggers',
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
			'badge'       => __( 'Badge', 'open-badges-for-learndash' ),
			'event'       => __( 'When', 'open-badges-for-learndash' ),
			'item'        => __( 'LearnDash item', 'open-badges-for-learndash' ),
			'min_score'   => __( 'Min. score', 'open-badges-for-learndash' ),
			'status'      => __( 'Status', 'open-badges-for-learndash' ),
			'fired_count' => __( 'Times fired', 'open-badges-for-learndash' ),
		);
	}

	/**
	 * Slice rows into the current page.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$total   = count( $this->triggers );
		$current = $this->get_pagenum();

		$this->items = array_slice( $this->triggers, ( $current - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Message shown when no triggers exist yet.
	 */
	public function no_items(): void {
		esc_html_e( 'No triggers yet — add one below to start awarding badges on completion.', 'open-badges-for-learndash' );
	}

	/**
	 * Badge column: thumbnail, name, provider, and the delete row action.
	 *
	 * @param object $item Trigger row.
	 */
	public function column_badge( object $item ): string {
		if ( null === $item->badge_name ) {
			$cell = '<strong>' . esc_html__( 'Badge no longer available', 'open-badges-for-learndash' ) . '</strong>'
				. '<br /><span class="obld-warning">'
				. esc_html__( 'This badge was removed locally. The trigger cannot issue — delete it or re-sync your badges.', 'open-badges-for-learndash' )
				. '</span>';
		} else {
			$provider = Provider::tryFrom( (string) $item->badge_provider );
			$image    = '';

			if ( ! empty( $item->badge_image_url ) ) {
				$image = sprintf(
					'<img src="%s" alt="" width="32" height="32" style="object-fit:contain;vertical-align:middle;margin-right:8px;" loading="lazy" />',
					esc_url( (string) $item->badge_image_url )
				);
			}

			$cell = $image . '<strong>' . esc_html( (string) $item->badge_name ) . '</strong>';

			if ( $provider instanceof Provider ) {
				$cell .= '<br /><span class="description">' . esc_html( $provider->label() ) . '</span>';
			}

			if ( ! empty( $item->badge_missing_at_provider ) ) {
				$cell .= '<br /><span class="obld-warning">'
					. esc_html__( 'No longer available at the provider — this trigger cannot issue until it returns.', 'open-badges-for-learndash' )
					. '</span>';
			}
		}

		return $cell . $this->row_actions( $this->actions_for( $item ) );
	}

	/**
	 * Event column.
	 *
	 * @param object $item Trigger row.
	 */
	public function column_event( object $item ): string {
		$event = EventType::tryFrom( (string) $item->event_type );

		return esc_html( $event instanceof EventType ? $event->label() : (string) $item->event_type );
	}

	/**
	 * Target-item column.
	 *
	 * @param object $item Trigger row.
	 */
	public function column_item( object $item ): string {
		$title = $this->item_repo->title( (int) $item->target_id );

		if ( null === $title ) {
			return '<span class="obld-warning">' . esc_html__( 'Item deleted — this trigger cannot fire.', 'open-badges-for-learndash' ) . '</span>';
		}

		$event = EventType::tryFrom( (string) $item->event_type );
		$cell  = esc_html( $title );

		if ( $event instanceof EventType ) {
			$cell .= '<br /><span class="description">' . esc_html( $event->item_label() ) . '</span>';
		}

		return $cell;
	}

	/**
	 * Minimum-score column.
	 *
	 * @param object $item Trigger row.
	 */
	public function column_min_score( object $item ): string {
		if ( null === $item->min_score ) {
			return '—';
		}

		return esc_html(
			sprintf(
				/* translators: %s: percentage, e.g. "80". */
				__( '%s%%', 'open-badges-for-learndash' ),
				// Trailing zeros from DECIMAL(5,2) read as noise ("80.00%").
				rtrim( rtrim( number_format( (float) $item->min_score, 2 ), '0' ), '.' )
			)
		);
	}

	/**
	 * Status column.
	 *
	 * @param object $item Trigger row.
	 */
	public function column_status( object $item ): string {
		if ( empty( $item->enabled ) ) {
			return '<span class="description">' . esc_html__( 'Disabled', 'open-badges-for-learndash' ) . '</span>';
		}

		return '<strong>' . esc_html__( 'Enabled', 'open-badges-for-learndash' ) . '</strong>';
	}

	/**
	 * Fired-count column.
	 *
	 * @param object $item Trigger row.
	 */
	public function column_fired_count( object $item ): string {
		return esc_html( number_format_i18n( (int) $item->fired_count ) );
	}

	/**
	 * Row actions: enable/disable and delete.
	 *
	 * @param object $item Trigger row.
	 *
	 * @return array<string, string>
	 */
	private function actions_for( object $item ): array {
		$id     = (int) $item->id;
		$toggle = empty( $item->enabled )
			? __( 'Enable', 'open-badges-for-learndash' )
			: __( 'Disable', 'open-badges-for-learndash' );

		return array(
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( TriggersPage::action_url( TriggersPage::TOGGLE_ACTION, $id ) ),
				esc_html( $toggle )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" data-obld-confirm="%s">%s</a>',
				esc_url( TriggersPage::action_url( TriggersPage::DELETE_ACTION, $id ) ),
				esc_attr( __( 'Delete this trigger? Badges already awarded are not affected.', 'open-badges-for-learndash' ) ),
				esc_html__( 'Delete', 'open-badges-for-learndash' )
			),
		);
	}

	/**
	 * Fallback column renderer (unused columns).
	 *
	 * @param object $item        Trigger row.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}
}
