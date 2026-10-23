<?php
/**
 * Recorded connection health per provider.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Credentials;

use OBLD\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Persists the outcome of the most recent connection test for each provider.
 *
 * This is recorded state, not live probing: nothing here performs network
 * I/O, so it is safe to read on any admin page load.
 */
final class ConnectionStatus {

	public const CONNECTED      = 'connected';
	public const FAILED         = 'failed';
	public const NOT_CONFIGURED = 'not_configured';

	private const OPTION = 'obld_connection_status';

	/**
	 * Record a test outcome.
	 *
	 * @param Provider $provider Provider tested.
	 * @param string   $status   One of the class constants.
	 * @param string   $message  Human-readable detail (failure reason, or '').
	 */
	public static function record( Provider $provider, string $status, string $message = '' ): void {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $provider->value ] = array(
			'status'     => $status,
			'message'    => $message,
			'checked_at' => time(),
		);

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Forget a provider's recorded status (e.g. after credentials change).
	 *
	 * @param Provider $provider Provider.
	 */
	public static function clear( Provider $provider ): void {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $provider->value ] ) ) {
			unset( $all[ $provider->value ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Last recorded status for a provider.
	 *
	 * @param Provider $provider Provider.
	 *
	 * @return array{status: string, message: string, checked_at: int}
	 */
	public static function get( Provider $provider ): array {
		$all = get_option( self::OPTION, array() );
		$row = is_array( $all ) ? ( $all[ $provider->value ] ?? null ) : null;

		if ( ! is_array( $row ) ) {
			return array(
				'status'     => self::NOT_CONFIGURED,
				'message'    => '',
				'checked_at' => 0,
			);
		}

		return array(
			'status'     => (string) ( $row['status'] ?? self::NOT_CONFIGURED ),
			'message'    => (string) ( $row['message'] ?? '' ),
			'checked_at' => (int) ( $row['checked_at'] ?? 0 ),
		);
	}
}
