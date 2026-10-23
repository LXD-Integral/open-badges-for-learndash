<?php
/**
 * Badge provider identity.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD;

defined( 'ABSPATH' ) || exit;

/**
 * The two supported badge providers. Same API, different host — the host is
 * the ONLY thing allowed to vary by provider, so it lives here and nowhere else.
 */
enum Provider: string {

	case OBF     = 'obf';
	case CanCred = 'cancred';

	/**
	 * Human-readable provider name.
	 */
	public function label(): string {
		return match ( $this ) {
			self::OBF     => 'Open Badge Factory',
			self::CanCred => 'CanCred Factory',
		};
	}

	/**
	 * API base URL (no trailing slash). Filterable so a staging or test host
	 * can be substituted without code edits — a documented gap in the old plugin.
	 */
	public function base_url(): string {
		$url = match ( $this ) {
			self::OBF     => 'https://openbadgefactory.com',
			self::CanCred => 'https://factory.cancred.ca',
		};

		/**
		 * Filters a provider's API base URL.
		 *
		 * Credentials and bearer tokens travel on every request, so a filtered
		 * value is honoured only when it is an https URL; anything else falls
		 * back to the provider's real host.
		 *
		 * @param string   $url      Base URL, no trailing slash.
		 * @param Provider $provider The provider.
		 */
		$filtered = (string) apply_filters( 'obld_provider_base_url', $url, $this );

		return 'https' === wp_parse_url( $filtered, PHP_URL_SCHEME ) ? untrailingslashit( $filtered ) : $url;
	}
}
