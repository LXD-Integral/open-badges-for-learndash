<?php
/**
 * Earned-badges block.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `obld/earned-badges` block.
 *
 * A **dynamic** block: the editor renders a server-side preview and the front
 * end is produced by BadgeList::render(), the same method the shortcode uses.
 * That is deliberate. The predecessor plugin's block saved markup in post
 * content and rendered it again on the front end, so an editor-set image size
 * could be — and was — silently dropped. With one render path there is nothing
 * to diverge.
 */
final class Block {

	public const NAME = 'obld/earned-badges';

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
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register from the built block.json.
	 *
	 * The build output is absent in a source checkout that has not run
	 * `npm run build`; registering nothing is better than a fatal, and the
	 * shortcode still works.
	 */
	public function register_block(): void {
		$metadata = OBLD_DIR . 'build/earned-badges/block.json';

		if ( ! file_exists( $metadata ) ) {
			return;
		}

		register_block_type(
			$metadata,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Render callback (front end and editor preview).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes = array() ): string {
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return $this->badge_list->render(
			array(
				// The block always renders for the visitor, never a fixed user.
				'user'      => 0,
				'columns'   => $attributes['columns'] ?? BadgeList::DEFAULT_COLUMNS,
				'size'      => $attributes['size'] ?? BadgeList::DEFAULT_SIZE,
				'show_name' => $attributes['showName'] ?? true,
				'link'      => $attributes['link'] ?? false,
				'export'    => $attributes['showExport'] ?? true,
				'verify'    => $attributes['showVerify'] ?? true,
			),
			$wrapper
		);
	}
}
