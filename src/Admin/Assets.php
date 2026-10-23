<?php
/**
 * Admin script and style registration.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's (small) admin script and stylesheet through the
 * enqueue API, so nothing is printed inline by the screens themselves.
 *
 * Both handles are "alias" handles with no file: the whole payload is a few
 * lines attached with wp_add_inline_script() / wp_add_inline_style(). The
 * handles are enqueued only on the plugin's own screens.
 */
final class Assets {

	public const SCRIPT = 'obld-admin';
	public const STYLE  = 'obld-admin';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register and enqueue on the plugin's screens (admin_enqueue_scripts).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'obld-' ) ) {
			return;
		}

		wp_register_style( self::STYLE, false, array(), OBLD_VERSION );
		wp_add_inline_style( self::STYLE, '.obld-warning{color:#b32d2e;}' );
		wp_enqueue_style( self::STYLE );

		wp_register_script( self::SCRIPT, false, array(), OBLD_VERSION, true );
		wp_add_inline_script( self::SCRIPT, self::script() );
		wp_enqueue_script( self::SCRIPT );
	}

	/**
	 * The admin script: confirmation prompts on destructive links, and the
	 * trigger form's event-type → item list / minimum-score behaviour.
	 *
	 * The trigger form data is attached separately by TriggersPage as
	 * window.obldTriggerFormData; the form code is a no-op on other screens.
	 */
	private static function script(): string {
		return <<<'JS'
( function () {
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-obld-confirm]' );
		if ( link && ! window.confirm( link.getAttribute( 'data-obld-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	var data     = window.obldTriggerFormData;
	var eventSel = document.getElementById( 'obld-event-type' );
	var itemSel  = document.getElementById( 'obld-target-id' );
	var scoreRow = document.getElementById( 'obld-min-score-row' );
	var scoreIn  = document.getElementById( 'obld-min-score' );

	if ( ! data || ! eventSel || ! itemSel || ! scoreRow || ! scoreIn ) {
		return;
	}

	function sync() {
		var eventType = eventSel.value;
		var items     = data.items[ eventType ] || [];

		itemSel.innerHTML = '';

		if ( ! items.length ) {
			var empty = document.createElement( 'option' );
			empty.value       = '';
			empty.textContent = data.noItemsMessage;
			itemSel.appendChild( empty );
			itemSel.disabled = true;
		} else {
			itemSel.disabled = false;
			items.forEach( function ( item ) {
				var option = document.createElement( 'option' );
				option.value       = item.id;
				option.textContent = item.title;
				itemSel.appendChild( option );
			} );
		}

		var scored = data.scoreEvents.indexOf( eventType ) !== -1;
		scoreRow.style.display = scored ? '' : 'none';
		if ( ! scored ) {
			scoreIn.value = '';
		}
	}

	eventSel.addEventListener( 'change', sync );
	sync();
}() );
JS;
	}
}
