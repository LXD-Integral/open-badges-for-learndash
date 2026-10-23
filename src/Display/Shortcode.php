<?php
/**
 * [user_badges] shortcode.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Display;

defined( 'ABSPATH' ) || exit;

/**
 * The `[user_badges]` shortcode.
 *
 * Attributes (all optional):
 *   user      — user ID; defaults to the logged-in visitor
 *   columns   — 1–6, default 3
 *   size      — badge image size in px, 32–256, default 96
 *   show_name — "1"/"0", default on
 *   link      — "1"/"0", link the badge image, default off
 *   export    — "1"/"0", "Save & share" link to the provider's badge page,
 *               shown only to the badge's owner, default on
 *   verify    — "1"/"0", "Verify" link to the hosted Open Badges assertion,
 *               shown to everyone, default on
 */
final class Shortcode {

	public const TAG = 'user_badges';

	/**
	 * Constructor.
	 *
	 * @param BadgeList $badge_list Renderer.
	 */
	public function __construct( private readonly BadgeList $badge_list ) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'user'      => 0,
				'columns'   => BadgeList::DEFAULT_COLUMNS,
				'size'      => BadgeList::DEFAULT_SIZE,
				'show_name' => '1',
				'link'      => '0',
				'export'    => '1',
				'verify'    => '1',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		return $this->badge_list->render( $atts );
	}
}
