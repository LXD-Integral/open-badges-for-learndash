<?php
/**
 * BadgesPage action-handler tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Admin\BadgesPage;
use OBLD\Badges\BadgeRepository;
use OBLD\Badges\SyncService;
use OBLD\Credentials\CredentialStore;
use OBLD\Issuance\AwardRepository;
use OBLD\Issuance\StatusSync;
use OBLD\Log\Logger;
use OBLD_Test_Died;
use OBLD_Test_Redirected;
use PHPUnit\Framework\TestCase;

/**
 * The Refresh Award Status action (milestone 9): capability gate, the run,
 * and the redirect that carries the feedback flag.
 */
final class BadgesPageTest extends TestCase {

	private BadgesPage $page;

	protected function setUp(): void {
		obld_test_reset();

		// No provider configured: both services skip every provider, so the
		// handler can run end to end without a transport or a database.
		$store  = new CredentialStore();
		$logger = new Logger();

		$this->page = new BadgesPage(
			$store,
			new BadgeRepository(),
			new SyncService( $store, new BadgeRepository(), $logger, new MockTransport() ),
			new StatusSync( $store, new AwardRepository(), $logger, new MockTransport() )
		);
	}

	public function test_refresh_runs_the_status_check_and_redirects_with_its_flag(): void {
		try {
			$this->page->handle_refresh();
			$this->fail( 'Expected a redirect.' );
		} catch ( OBLD_Test_Redirected $redirect ) {
			$this->assertStringContainsString( 'page=obld-badges', $redirect->url );
			$this->assertStringContainsString( 'obld_status_refreshed=1', $redirect->url );
		}

		$summary = get_option( StatusSync::LAST_SYNC_OPTION );
		$this->assertIsArray( $summary, 'The run persisted its summary for the screen to show.' );
		$this->assertSame( StatusSync::STATUS_SKIPPED, $summary['obf']['status'] );
		$this->assertSame( StatusSync::STATUS_SKIPPED, $summary['cancred']['status'] );
	}

	public function test_refresh_requires_the_capability(): void {
		$GLOBALS['obld_test_can'] = false;

		$this->expectException( OBLD_Test_Died::class );
		$this->page->handle_refresh();
	}

	public function test_registers_both_admin_post_actions(): void {
		$this->page->register();

		$this->assertArrayHasKey( 'admin_post_' . BadgesPage::SYNC_ACTION, $GLOBALS['obld_test_actions'] );
		$this->assertArrayHasKey( 'admin_post_' . BadgesPage::REFRESH_ACTION, $GLOBALS['obld_test_actions'] );
	}
}
