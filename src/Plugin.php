<?php
/**
 * Plugin service wiring.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD;

use OBLD\Admin\ActivityLogPage;
use OBLD\Admin\Assets;
use OBLD\Admin\BadgesPage;
use OBLD\Admin\ConnectionTest;
use OBLD\Admin\IssuePage;
use OBLD\Admin\Menu;
use OBLD\Admin\SettingsPage;
use OBLD\Admin\TriggersPage;
use OBLD\Badges\BadgeRepository;
use OBLD\Badges\SyncService;
use OBLD\Credentials\CredentialStore;
use OBLD\Display\BadgeList;
use OBLD\Display\Block;
use OBLD\Display\Shortcode;
use OBLD\Health\Notices;
use OBLD\Issuance\AwardRepository;
use OBLD\Issuance\BulkIssuer;
use OBLD\Issuance\IssuanceService;
use OBLD\Issuance\StatusSync;
use OBLD\LearnDash\CompletionListener;
use OBLD\LearnDash\CompletionRepository;
use OBLD\LearnDash\ItemRepository;
use OBLD\Log\Logger;
use OBLD\Triggers\TriggerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root: builds the services and registers their hooks.
 *
 * Rule inherited from the old plugin's post-mortem: nothing in here (or in
 * any constructor it calls) performs network I/O. Network happens only inside
 * explicit user- or cron-initiated actions.
 */
final class Plugin {

	/**
	 * Booted singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Credential storage, shared by admin screens and (later) issuance.
	 *
	 * @var CredentialStore
	 */
	public readonly CredentialStore $credentials;

	/**
	 * Activity log writer.
	 *
	 * @var Logger
	 */
	public readonly Logger $logger;

	/**
	 * Synced-badge storage, shared by admin screens and triggers.
	 *
	 * @var BadgeRepository
	 */
	public readonly BadgeRepository $badges;

	/**
	 * Trigger-rule storage, shared by the admin screen and (later) issuance.
	 *
	 * @var TriggerRepository
	 */
	public readonly TriggerRepository $triggers;

	/**
	 * Award records, shared by issuance and (later) manual/bulk issuance.
	 *
	 * @var AwardRepository
	 */
	public readonly AwardRepository $awards;

	/**
	 * Issuance queue, reused by milestone 5's manual and backfill runs.
	 *
	 * @var IssuanceService
	 */
	public readonly IssuanceService $issuance;

	/**
	 * Provider-side award status refresh (revoked / expiry / Passport).
	 *
	 * @var StatusSync
	 */
	public readonly StatusSync $status_sync;

	/**
	 * Wire services.
	 */
	private function __construct() {
		$this->credentials = new CredentialStore();
		$this->logger      = new Logger();
		$this->badges      = new BadgeRepository();
		$this->triggers    = new TriggerRepository();
		$this->awards      = new AwardRepository();
		$this->issuance    = new IssuanceService(
			$this->credentials,
			$this->awards,
			$this->triggers,
			$this->logger
		);
		$this->status_sync = new StatusSync( $this->credentials, $this->awards, $this->logger );
	}

	/**
	 * Boot once on plugins_loaded.
	 */
	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}

		return self::$instance;
	}

	/**
	 * Booted instance (for later milestones' consumers).
	 */
	public static function instance(): self {
		return self::boot();
	}

	/**
	 * Hook registration.
	 */
	private function register(): void {
		// Issuance and the completion listener must run EVERYWHERE, not just in
		// admin: learners complete courses on the front end, and WP-Cron runs
		// outside the admin context entirely. Registering these behind the
		// is_admin() guard below would mean badges were never awarded.
		$this->issuance->register();
		// The status refresh rides the same hourly sweep, so it too must be
		// registered outside is_admin().
		$this->status_sync->register();

		( new CompletionListener(
			$this->triggers,
			$this->badges,
			$this->issuance,
			$this->logger
		) )->register();

		( new Privacy() )->register();

		// Display is front-end *and* editor: the block's server-side render runs
		// through the REST API, so it must be registered outside is_admin() too.
		$badge_list = new BadgeList( $this->awards );
		( new Shortcode( $badge_list ) )->register();
		( new Block( $badge_list ) )->register();

		if ( ! is_admin() ) {
			return;
		}

		// Catches updates deployed without a re-activation.
		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );

		$settings_page = new SettingsPage( $this->credentials );
		$settings_page->register();

		$sync        = new SyncService( $this->credentials, $this->badges, $this->logger );
		$badges_page = new BadgesPage( $this->credentials, $this->badges, $sync, $this->status_sync );
		$badges_page->register();

		$triggers_page = new TriggersPage( $this->triggers, $this->badges, new ItemRepository() );
		$triggers_page->register();

		$issue_page = new IssuePage(
			new BulkIssuer( $this->issuance, $this->triggers, new CompletionRepository(), $this->logger ),
			$this->badges,
			$this->triggers,
			new ItemRepository()
		);
		$issue_page->register();

		$log_page = new ActivityLogPage( $this->logger );
		$log_page->register();

		( new Menu( $settings_page, $badges_page, $triggers_page, $issue_page, $log_page ) )->register();
		( new Assets() )->register();
		( new ConnectionTest( $this->credentials, $this->logger ) )->register();
		( new Notices( $this->credentials, $this->badges, $this->awards ) )->register();
	}
}
