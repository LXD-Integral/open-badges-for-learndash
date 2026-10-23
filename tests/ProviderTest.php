<?php
/**
 * Provider tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Base URL filtering.
 */
final class ProviderTest extends TestCase {

	protected function setUp(): void {
		obld_test_reset();
	}

	public function test_default_base_urls_are_https(): void {
		self::assertSame( 'https://openbadgefactory.com', Provider::OBF->base_url() );
		self::assertSame( 'https://factory.cancred.ca', Provider::CanCred->base_url() );
	}

	public function test_filter_may_substitute_an_https_host(): void {
		add_filter( 'obld_provider_base_url', static fn() => 'https://staging.example.test/' );

		self::assertSame( 'https://staging.example.test', Provider::OBF->base_url() );
	}

	/**
	 * @dataProvider unsafe_urls
	 *
	 * @param mixed $url Filtered value.
	 */
	public function test_filter_cannot_downgrade_to_a_non_https_url( mixed $url ): void {
		add_filter( 'obld_provider_base_url', static fn() => $url );

		self::assertSame( 'https://factory.cancred.ca', Provider::CanCred->base_url() );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function unsafe_urls(): array {
		return array(
			'http'       => array( 'http://evil.example' ),
			'scheme-less' => array( '//evil.example' ),
			'empty'      => array( '' ),
			'garbage'    => array( 'not a url' ),
		);
	}
}
