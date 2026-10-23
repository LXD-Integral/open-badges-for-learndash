<?php
/**
 * Open Badge Factory / CanCred API v2 client.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Api;

use OBLD\Provider;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One client instance per provider, credentials and transport injected.
 *
 * Token handling rules (both learned from the old plugin's failures and from
 * the v2 API contract):
 *  - Cache keys are strictly per-provider; OBF and CanCred must never share
 *    token state (the old plugin's cross-provider collision).
 *  - Tokens are reused until expiry: v2 rate-limits token generation to one
 *    per ten seconds per client, so fetching per-request would break quickly.
 *  - Never perform network I/O at construction time.
 */
final class ApiClient {

	/**
	 * Identifies this integration to the provider (required by the v2 issue endpoint).
	 */
	public const API_CONSUMER_ID = 'open-badges-for-learndash';

	/**
	 * Constructor: pure wiring, no I/O.
	 *
	 * @param Provider               $provider      Which provider this client talks to.
	 * @param string                 $client_id     API client id.
	 * @param string                 $client_secret API client secret (decrypted).
	 * @param HttpTransportInterface $transport     HTTP transport.
	 */
	public function __construct(
		private readonly Provider $provider,
		private readonly string $client_id,
		private readonly string $client_secret,
		private readonly HttpTransportInterface $transport,
	) {
	}

	/**
	 * Transient key holding this provider's cached bearer token.
	 *
	 * @param Provider $provider Provider.
	 */
	public static function token_transient_key( Provider $provider ): string {
		return 'obld_token_' . $provider->value;
	}

	/**
	 * Verify the connection: obtain a token and call the authenticated ping.
	 *
	 * @return bool|WP_Error True when the provider confirms the connection
	 *                       (never returns false — the literal `true` type would
	 *                       require PHP 8.2; we target 8.1).
	 */
	public function ping(): bool|WP_Error {
		$result = $this->request_authed( 'GET', '/v2/client/' . rawurlencode( $this->client_id ) . '/ping' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['body'], true );

		if ( is_array( $data ) && true === ( $data['success'] ?? null ) ) {
			return true;
		}

		return new WP_Error(
			'obld_unexpected_response',
			sprintf(
				/* translators: 1: provider name, 2: HTTP status code. */
				__( '%1$s answered the connection test with an unexpected response (HTTP %2$d). The credentials may lack API access.', 'open-badges-for-learndash' ),
				$this->provider->label(),
				$result['code']
			)
		);
	}

	/**
	 * List the provider's badge classes (short format).
	 *
	 * Pages through the v2 list endpoint until the reported total is reached.
	 * The `draft` query parameter FILTERS: one call returns published badges,
	 * a second (with $drafts = true) returns drafts — callers wanting both
	 * make both calls.
	 *
	 * @param bool $drafts Fetch draft badges instead of published ones.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error Raw badge arrays as returned by the API.
	 */
	public function list_badges( bool $drafts = false ): array|WP_Error {
		$badges   = array();
		$per_page = 1000;
		$offset   = 0;

		// Hard page cap: 30 pages = 30k badges, far beyond any real tenant.
		for ( $page = 0; $page < 30; $page++ ) {
			$query = http_build_query(
				array(
					'draft'  => $drafts ? '1' : '0',
					'limit'  => $per_page,
					'offset' => $offset,
				)
			);

			$result = $this->request_authed( 'GET', '/v2/badge/' . rawurlencode( $this->client_id ) . '?' . $query );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$data = json_decode( $result['body'], true );

			if ( ! is_array( $data ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
				return new WP_Error(
					'obld_unexpected_response',
					sprintf(
						/* translators: %s: provider name. */
						__( '%s returned an unexpected badge-list format.', 'open-badges-for-learndash' ),
						$this->provider->label()
					)
				);
			}

			$badges = array_merge( $badges, $data['result'] );
			$total  = (int) ( $data['total'] ?? count( $badges ) );

			if ( 0 === count( $data['result'] ) || count( $badges ) >= $total ) {
				break;
			}

			$offset += $per_page;
		}

		return $badges;
	}

	/**
	 * Issue one badge to one recipient.
	 *
	 * POST /v2/event/{client_id}/{badge_id}/issue — the provider creates an
	 * "issuing event" and emails the recipient (v1 default behaviour, which the
	 * PRD keeps: custom notification wording is out of scope). The returned
	 * resource_id is that event's id, stored as the award's provider
	 * confirmation.
	 *
	 * @param string $badge_id Provider badge id (NOT the local row id).
	 * @param string $email    Recipient email.
	 * @param string $name     Recipient display name.
	 *
	 * @return string|WP_Error Issuing-event id on success ('' when the provider
	 *                         reports success without one).
	 */
	public function issue_badge( string $badge_id, string $email, string $name ): string|WP_Error {
		$result = $this->request_authed(
			'POST',
			'/v2/event/' . rawurlencode( $this->client_id ) . '/' . rawurlencode( $badge_id ) . '/issue',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'recipient'       => array(
							array(
								'email' => $email,
								'name'  => $name,
							),
						),
						'api_consumer_id' => self::API_CONSUMER_ID,
						'issued_on'       => time(),
					)
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['body'], true );

		// The endpoint answers 200 with an OperationResult. Treat an explicit
		// success === false as a failure even on a 200, rather than recording a
		// badge as issued that never was.
		if ( ! is_array( $data ) || false === ( $data['success'] ?? true ) ) {
			return new WP_Error(
				'obld_issue_failed',
				sprintf(
					/* translators: 1: provider name, 2: provider message. */
					__( '%1$s did not issue the badge: %2$s', 'open-badges-for-learndash' ),
					$this->provider->label(),
					is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'no reason given.', 'open-badges-for-learndash' )
				)
			);
		}

		return (string) ( $data['resource_id'] ?? '' );
	}

	/**
	 * The recipient rows of one issuing event, optionally narrowed to one email.
	 *
	 * GET /v2/event/{client_id}/recipient — each row carries the per-recipient
	 * verifiable-export links: receive_url (the provider's badge page for this
	 * recipient, the same URL sent in issuing emails) and assertion_json_url
	 * (the hosted Open Badges 3.0 assertion). An empty result is NOT an error:
	 * it is how the provider answers for an event that was deleted or whose
	 * recipient was removed — callers decide what that means.
	 *
	 * @param string $event_id Provider issuing-event id.
	 * @param string $email    Recipient email ('' for all recipients).
	 *
	 * @return array<int, array<string, mixed>>|WP_Error Raw recipient arrays as returned by the API.
	 */
	public function event_recipients( string $event_id, string $email = '' ): array|WP_Error {
		$query = array( 'event_id' => $event_id );

		if ( '' !== $email ) {
			$query['email'] = $email;
		}

		$result = $this->request_authed(
			'GET',
			'/v2/event/' . rawurlencode( $this->client_id ) . '/recipient?' . http_build_query( $query )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['body'], true );

		if ( ! is_array( $data ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
			return new WP_Error(
				'obld_unexpected_response',
				sprintf(
					/* translators: %s: provider name. */
					__( '%s returned an unexpected recipient-list format.', 'open-badges-for-learndash' ),
					$this->provider->label()
				)
			);
		}

		return $data['result'];
	}

	/**
	 * Every recipient row this plugin's issuances created at the provider.
	 *
	 * GET /v2/event/{client_id}/recipient filtered by api_consumer_id: each
	 * issuance stamps API_CONSUMER_ID on its event, so the listing covers
	 * exactly the awards this plugin made and nothing the tenant issued by
	 * other means. Rows carry the milestone 9 status facts — revoked,
	 * expires_on, received, in_passport — alongside the export links. Pages
	 * like list_badges(): 1000 per call until the reported total is reached.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error Raw recipient arrays as returned by the API.
	 */
	public function list_recipients(): array|WP_Error {
		$rows     = array();
		$per_page = 1000;
		$offset   = 0;

		// Same hard page cap as list_badges(): 30k recipient rows.
		for ( $page = 0; $page < 30; $page++ ) {
			$query = http_build_query(
				array(
					'api_consumer_id' => self::API_CONSUMER_ID,
					'limit'           => $per_page,
					'offset'          => $offset,
				)
			);

			$result = $this->request_authed(
				'GET',
				'/v2/event/' . rawurlencode( $this->client_id ) . '/recipient?' . $query
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$data = json_decode( $result['body'], true );

			if ( ! is_array( $data ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
				return new WP_Error(
					'obld_unexpected_response',
					sprintf(
						/* translators: %s: provider name. */
						__( '%s returned an unexpected recipient-list format.', 'open-badges-for-learndash' ),
						$this->provider->label()
					)
				);
			}

			$rows  = array_merge( $rows, $data['result'] );
			$total = (int) ( $data['total'] ?? count( $rows ) );

			if ( 0 === count( $data['result'] ) || count( $rows ) >= $total ) {
				break;
			}

			$offset += $per_page;
		}

		return $rows;
	}

	/**
	 * Perform an authenticated API request, refreshing the token once if the
	 * cached one has been revoked or expired server-side.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path beginning with /v2/.
	 * @param array<string, mixed> $args   Extra transport args.
	 *
	 * @return array{code: int, body: string}|WP_Error
	 */
	private function request_authed( string $method, string $path, array $args = array() ): array|WP_Error {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->request_with_token( $method, $path, $token, $args );

		// A 401 with a cached token means the token died early — refresh once.
		if ( ! is_wp_error( $result ) && 401 === $result['code'] ) {
			delete_transient( self::token_transient_key( $this->provider ) );

			$token = $this->token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$result = $this->request_with_token( $method, $path, $token, $args );
		}

		if ( is_wp_error( $result ) ) {
			return $this->unreachable_error( $result );
		}

		if ( $result['code'] >= 400 ) {
			return $this->api_error( $result );
		}

		return $result;
	}

	/**
	 * Single request with a bearer token.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   API path.
	 * @param string               $token  Bearer token.
	 * @param array<string, mixed> $args   Extra transport args.
	 *
	 * @return array{code: int, body: string}|WP_Error
	 */
	private function request_with_token( string $method, string $path, string $token, array $args = array() ): array|WP_Error {
		$headers                  = $args['headers'] ?? array();
		$headers['Authorization'] = 'Bearer ' . $token;
		$args['headers']          = $headers;

		return $this->transport->request( $method, $this->provider->base_url() . $path, $args );
	}

	/**
	 * Return a valid bearer token, from cache when possible.
	 *
	 * @return string|WP_Error
	 */
	private function token(): string|WP_Error {
		$cached = get_transient( self::token_transient_key( $this->provider ) );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$result = $this->transport->request(
			'POST',
			$this->provider->base_url() . '/v2/client/oauth2/token',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->unreachable_error( $result );
		}

		$data = json_decode( $result['body'], true );

		if ( 200 !== $result['code'] || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			if ( in_array( $result['code'], array( 400, 401, 403 ), true ) ) {
				return new WP_Error(
					'obld_auth_failed',
					sprintf(
						/* translators: %s: provider name. */
						__( '%s rejected the credentials. Check the Client ID and Client Secret, and confirm the account has API access (Pro subscription).', 'open-badges-for-learndash' ),
						$this->provider->label()
					)
				);
			}

			return $this->api_error( $result );
		}

		$expires_in = max( 60, (int) ( $data['expires_in'] ?? 0 ) );

		// Renew a minute early so a token never dies mid-request.
		set_transient( self::token_transient_key( $this->provider ), (string) $data['access_token'], $expires_in - 60 );

		return (string) $data['access_token'];
	}

	/**
	 * Transport-level failure → specific, human-readable error.
	 *
	 * @param WP_Error $error Transport error.
	 */
	private function unreachable_error( WP_Error $error ): WP_Error {
		return new WP_Error(
			'obld_unreachable',
			sprintf(
				/* translators: 1: provider name, 2: transport error detail. */
				__( 'Could not reach %1$s: %2$s', 'open-badges-for-learndash' ),
				$this->provider->label(),
				$error->get_error_message()
			)
		);
	}

	/**
	 * HTTP-level API failure → error carrying the provider's own message when present.
	 *
	 * @param array{code: int, body: string} $result Response.
	 */
	private function api_error( array $result ): WP_Error {
		$data   = json_decode( $result['body'], true );
		$detail = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : '';

		return new WP_Error(
			'obld_api_error',
			sprintf(
				/* translators: 1: provider name, 2: HTTP status code, 3: provider error detail. */
				__( '%1$s returned an error (HTTP %2$d). %3$s', 'open-badges-for-learndash' ),
				$this->provider->label(),
				$result['code'],
				$detail
			)
		);
	}
}
