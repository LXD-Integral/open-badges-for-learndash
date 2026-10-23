<?php
/**
 * Page-cache cooperation.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps full-page caches from serving wrong badge lists.
 *
 * Two failure modes exist (both observed live on 2026-08-22): a cache serving
 * one viewer's badge list to another, and a cache serving anyone a list
 * rendered before their badge was issued. nocache() prevents the first at
 * render time; purge() prevents the second at issuance time.
 *
 * LiteSpeed is addressed through its public actions; DONOTCACHEPAGE covers
 * the other major cache plugins. Both are no-ops when no cache is installed.
 */
final class PageCache {

	/**
	 * Whether this request already purged. Issuance and enrichment both ask
	 * for a purge, and a cron sweep can process many awards in one request —
	 * one purge covers them all.
	 *
	 * @var bool
	 */
	private static bool $purged = false;

	/**
	 * Exclude the current request's response from full-page caches.
	 *
	 * @param string $reason Shown in the cache plugin's debug log.
	 */
	public static function nocache( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the cache plugins' own contract.
			define( 'DONOTCACHEPAGE', true );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public API.
		do_action( 'litespeed_control_set_nocache', $reason );
	}

	/**
	 * Purge cached pages, at most once per request.
	 *
	 * The whole cache, not a URL: badge lists render wherever an admin placed
	 * the shortcode or block, so the set of stale URLs is unknowable from
	 * here. Issuance is rare enough that the blunt purge costs nothing.
	 */
	public static function purge(): void {
		if ( self::$purged ) {
			return;
		}

		self::$purged = true;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public API.
		do_action( 'litespeed_purge_all', 'open-badges-for-learndash: badge issued' );
	}

	/**
	 * Forget the per-request purge debounce (tests only).
	 */
	public static function reset(): void {
		self::$purged = false;
	}
}
