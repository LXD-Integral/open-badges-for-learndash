<?php
/**
 * Scripted HTTP transport for tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Api\HttpTransportInterface;

/**
 * Returns queued responses in order and records every request made.
 */
final class MockTransport implements HttpTransportInterface {

	/**
	 * Recorded requests: [method, url, args].
	 *
	 * @var array<int, array{method: string, url: string, args: array<string, mixed>}>
	 */
	public array $requests = array();

	/**
	 * Queued responses.
	 *
	 * @var array<int, array{code: int, body: string}|\WP_Error>
	 */
	private array $queue = array();

	/**
	 * Queue a response.
	 *
	 * @param array{code: int, body: string}|\WP_Error $response Response to return.
	 */
	public function queue( array|\WP_Error $response ): self {
		$this->queue[] = $response;
		return $this;
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param int                  $code HTTP status.
	 * @param array<string, mixed> $data Body payload.
	 */
	public function queue_json( int $code, array $data ): self {
		return $this->queue( array( 'code' => $code, 'body' => (string) json_encode( $data ) ) );
	}

	/**
	 * Return the next queued response.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    URL.
	 * @param array<string, mixed> $args   Args.
	 */
	public function request( string $method, string $url, array $args = array() ): array|\WP_Error {
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);

		if ( empty( $this->queue ) ) {
			throw new \RuntimeException( 'MockTransport: no response queued for ' . $method . ' ' . $url );
		}

		return array_shift( $this->queue );
	}
}
