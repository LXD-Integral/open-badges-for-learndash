<?php
/**
 * HTTP transport abstraction.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal HTTP transport so the API client is unit-testable without network
 * I/O — the old plugin's untestable client is a mistake this build avoids.
 */
interface HttpTransportInterface {

	/**
	 * Perform an HTTP request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string, mixed> $args    Optional: headers (array), body (string|array), timeout (int).
	 *
	 * @return array{code: int, body: string}|\WP_Error Response status + raw body, or transport-level error.
	 */
	public function request( string $method, string $url, array $args = array() ): array|\WP_Error;
}
