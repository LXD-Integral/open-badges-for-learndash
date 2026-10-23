<?php
/**
 * ApiClient tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Api\ApiClient;
use OBLD\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Token caching, provider isolation, and error mapping — the exact bug
 * cluster that killed the old plugin.
 */
final class ApiClientTest extends TestCase {

	protected function setUp(): void {
		obld_test_reset();
	}

	private function client( MockTransport $transport, Provider $provider = Provider::OBF ): ApiClient {
		return new ApiClient( $provider, 'CLIENT123', 'SECRET456', $transport );
	}

	public function test_ping_fetches_token_then_pings_and_caches_token(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok-1', 'token_type' => 'bearer', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, array( 'success' => true ) );

		$client = $this->client( $transport );

		$this->assertTrue( $client->ping() );
		$this->assertCount( 2, $transport->requests );
		$this->assertStringContainsString( '/v2/client/oauth2/token', $transport->requests[0]['url'] );
		$this->assertStringContainsString( '/v2/client/CLIENT123/ping', $transport->requests[1]['url'] );
		$this->assertSame( 'Bearer tok-1', $transport->requests[1]['args']['headers']['Authorization'] );

		// Second ping: token comes from cache — exactly one more request.
		$transport->queue_json( 200, array( 'success' => true ) );
		$this->assertTrue( $client->ping() );
		$this->assertCount( 3, $transport->requests );
	}

	public function test_token_cache_is_isolated_per_provider(): void {
		$obf = new MockTransport();
		$obf->queue_json( 200, array( 'access_token' => 'obf-token', 'expires_in' => 18000 ) );
		$obf->queue_json( 200, array( 'success' => true ) );
		$this->assertTrue( $this->client( $obf, Provider::OBF )->ping() );

		// A CanCred client must fetch its own token even though OBF's is cached —
		// the old plugin's cross-provider token collision made this impossible.
		$cancred = new MockTransport();
		$cancred->queue_json( 200, array( 'access_token' => 'cancred-token', 'expires_in' => 18000 ) );
		$cancred->queue_json( 200, array( 'success' => true ) );
		$this->assertTrue( $this->client( $cancred, Provider::CanCred )->ping() );

		$this->assertStringContainsString( 'factory.cancred.ca', $cancred->requests[0]['url'] );
		$this->assertSame( 'Bearer cancred-token', $cancred->requests[1]['args']['headers']['Authorization'] );
		$this->assertSame( 'obf-token', get_transient( 'obld_token_obf' ) );
		$this->assertSame( 'cancred-token', get_transient( 'obld_token_cancred' ) );
	}

	public function test_rejected_credentials_produce_specific_auth_error(): void {
		$transport = new MockTransport();
		$transport->queue_json( 400, array( 'error' => 'invalid_client' ) );

		$result = $this->client( $transport )->ping();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_auth_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Open Badge Factory', $result->get_error_message() );
	}

	public function test_unreachable_host_produces_specific_error(): void {
		$transport = new MockTransport();
		$transport->queue( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );

		$result = $this->client( $transport )->ping();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_unreachable', $result->get_error_code() );
		$this->assertStringContainsString( 'Could not resolve host', $result->get_error_message() );
	}

	public function test_stale_cached_token_is_refreshed_once_on_401(): void {
		set_transient( 'obld_token_obf', 'stale-token', 18000 );

		$transport = new MockTransport();
		$transport->queue_json( 401, array( 'error' => 'invalid_token' ) );  // Ping with stale token.
		$transport->queue_json( 200, array( 'access_token' => 'fresh-token', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, array( 'success' => true ) );           // Retried ping.

		$this->assertTrue( $this->client( $transport )->ping() );
		$this->assertCount( 3, $transport->requests );
		$this->assertSame( 'fresh-token', get_transient( 'obld_token_obf' ) );
	}

	public function test_unexpected_ping_body_is_an_error(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue( array( 'code' => 200, 'body' => 'not json at all' ) );

		$result = $this->client( $transport )->ping();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_unexpected_response', $result->get_error_code() );
	}

	public function test_list_badges_single_page(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue_json(
			200,
			array(
				'result' => array(
					array( 'id' => 'B1', 'name' => 'One' ),
					array( 'id' => 'B2', 'name' => 'Two' ),
				),
				'total'  => 2,
			)
		);

		$badges = $this->client( $transport )->list_badges();

		$this->assertIsArray( $badges );
		$this->assertCount( 2, $badges );
		$this->assertSame( 'B1', $badges[0]['id'] );
		$this->assertStringContainsString( '/v2/badge/CLIENT123?', $transport->requests[1]['url'] );
		$this->assertStringContainsString( 'draft=0', $transport->requests[1]['url'] );
	}

	public function test_list_badges_paginates_until_total_reached(): void {
		$page = static fn( array $ids, int $total ) => array(
			'result' => array_map( static fn( string $id ) => array( 'id' => $id ), $ids ),
			'total'  => $total,
		);

		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, $page( array( 'A' ), 2 ) );  // Page 1 of a 2-badge total.
		$transport->queue_json( 200, $page( array( 'B' ), 2 ) );  // Page 2.

		$badges = $this->client( $transport )->list_badges();

		$this->assertIsArray( $badges );
		$this->assertSame( array( 'A', 'B' ), array_column( $badges, 'id' ) );
		$this->assertStringContainsString( 'offset=0', $transport->requests[1]['url'] );
		$this->assertStringContainsString( 'offset=1000', $transport->requests[2]['url'] );
	}

	public function test_list_badges_drafts_call_filters_on_draft(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, array( 'result' => array(), 'total' => 0 ) );

		$this->client( $transport )->list_badges( true );

		$this->assertStringContainsString( 'draft=1', $transport->requests[1]['url'] );
	}

	public function test_event_recipients_queries_by_event_and_email(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue_json(
			200,
			array(
				'result' => array(
					array(
						'event_id'           => 'EVENT99',
						'assertion_id'       => 'ASSERT1',
						'email'              => 'learner@example.test',
						'receive_url'        => 'https://openbadgefactory.com/badge/receive/abc',
						'assertion_json_url' => 'https://openbadgefactory.com/assertion/abc.json',
					),
				),
				'total'  => 1,
			)
		);

		$recipients = $this->client( $transport )->event_recipients( 'EVENT99', 'learner@example.test' );

		$this->assertIsArray( $recipients );
		$this->assertCount( 1, $recipients );
		$this->assertSame( 'ASSERT1', $recipients[0]['assertion_id'] );
		$this->assertSame( 'https://openbadgefactory.com/badge/receive/abc', $recipients[0]['receive_url'] );

		$request = $transport->requests[1];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertStringContainsString( '/v2/event/CLIENT123/recipient?', $request['url'] );
		$this->assertStringContainsString( 'event_id=EVENT99', $request['url'] );
		$this->assertStringContainsString( 'email=learner%40example.test', $request['url'] );
	}

	public function test_event_recipients_empty_result_is_not_an_error(): void {
		// A deleted event answers with an empty list, not a failure — the
		// caller (enrichment) uses this to stop asking.
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue_json( 200, array( 'result' => array(), 'total' => 0 ) );

		$recipients = $this->client( $transport )->event_recipients( 'GONE', 'learner@example.test' );

		$this->assertSame( array(), $recipients );
	}

	public function test_event_recipients_propagates_api_errors(): void {
		$transport = new MockTransport();
		$transport->queue_json( 400, array( 'error' => 'invalid_client' ) );

		$result = $this->client( $transport )->event_recipients( 'EVENT99', 'learner@example.test' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_auth_failed', $result->get_error_code() );
	}

	public function test_event_recipients_rejects_malformed_body(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue( array( 'code' => 200, 'body' => 'not json' ) );

		$result = $this->client( $transport )->event_recipients( 'EVENT99', 'learner@example.test' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_unexpected_response', $result->get_error_code() );
	}

	public function test_list_recipients_filters_on_this_plugins_consumer_id(): void {
		// Milestone 9: the status refresh lists every recipient row this plugin
		// created — scoped by the consumer id each issuance stamps — rather
		// than asking about awards one at a time.
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json(
			200,
			array(
				'result' => array(
					array( 'assertion_id' => 'A1', 'revoked' => false, 'in_passport' => true ),
				),
				'total'  => 1,
			)
		);

		$rows = $this->client( $transport )->list_recipients();

		$this->assertIsArray( $rows );
		$this->assertSame( 'A1', $rows[0]['assertion_id'] );

		$url = $transport->requests[1]['url'];
		$this->assertStringContainsString( '/v2/event/CLIENT123/recipient?', $url );
		$this->assertStringContainsString( 'api_consumer_id=open-badges-for-learndash', $url );
		$this->assertStringContainsString( 'limit=1000', $url );
		$this->assertStringContainsString( 'offset=0', $url );
	}

	public function test_list_recipients_paginates_until_total_reached(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue_json( 200, array( 'result' => array( array( 'assertion_id' => 'A1' ) ), 'total' => 2 ) );
		$transport->queue_json( 200, array( 'result' => array( array( 'assertion_id' => 'A2' ) ), 'total' => 2 ) );

		$rows = $this->client( $transport )->list_recipients();

		$this->assertCount( 2, $rows );
		$this->assertStringContainsString( 'offset=1000', $transport->requests[2]['url'] );
	}

	public function test_list_recipients_rejects_malformed_body(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
		$transport->queue( array( 'code' => 200, 'body' => '"nope"' ) );

		$result = $this->client( $transport )->list_recipients();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_unexpected_response', $result->get_error_code() );
	}

	public function test_list_badges_propagates_api_errors(): void {
		$transport = new MockTransport();
		$transport->queue_json( 400, array( 'error' => 'invalid_client' ) );

		$result = $this->client( $transport )->list_badges();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_auth_failed', $result->get_error_code() );
	}

	public function test_list_badges_rejects_malformed_body(): void {
		$transport = new MockTransport();
		$transport->queue_json( 200, array( 'access_token' => 'tok', 'expires_in' => 18000 ) );
		$transport->queue( array( 'code' => 200, 'body' => '"just a string"' ) );

		$result = $this->client( $transport )->list_badges();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'obld_unexpected_response', $result->get_error_code() );
	}
}
