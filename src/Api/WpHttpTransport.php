<?php
/**
 * WordPress HTTP API transport.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Production transport backed by wp_remote_request().
 */
final class WpHttpTransport implements HttpTransportInterface {

	/**
	 * Request timeout in seconds. Deliberately short: the old plugin's 300s
	 * timeout let one unreachable host hang wp-admin for five minutes.
	 */
	private const TIMEOUT = 15;

	/**
	 * Perform an HTTP request via the WordPress HTTP API.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    Absolute URL.
	 * @param array<string, mixed> $args   headers / body / timeout.
	 *
	 * @return array{code: int, body: string}|\WP_Error
	 */
	public function request( string $method, string $url, array $args = array() ): array|\WP_Error {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => $args['timeout'] ?? self::TIMEOUT,
				// A JSON API never legitimately redirects; following one would
				// re-send the bearer token to whatever host answered.
				'redirection' => 0,
				'headers'     => $args['headers'] ?? array(),
				'body'        => $args['body'] ?? null,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
