<?php
/**
 * Admin menu.
 *
 * @package OBLD
 */

declare( strict_types=1 );

namespace OBLD\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level "Open Badges" menu and its submenus.
 */
final class Menu {

	/**
	 * Constructor.
	 *
	 * @param SettingsPage    $settings_page Settings screen renderer.
	 * @param BadgesPage      $badges_page   Badges screen renderer.
	 * @param TriggersPage    $triggers_page Triggers screen renderer.
	 * @param IssuePage       $issue_page    Issue Badges screen renderer.
	 * @param ActivityLogPage $log_page      Activity Log screen renderer.
	 */
	public function __construct(
		private readonly SettingsPage $settings_page,
		private readonly BadgesPage $badges_page,
		private readonly TriggersPage $triggers_page,
		private readonly IssuePage $issue_page,
		private readonly ActivityLogPage $log_page,
	) {
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Add the menu and the Settings submenu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Open Badges', 'open-badges-for-learndash' ),
			__( 'Open Badges', 'open-badges-for-learndash' ),
			'manage_options',
			SettingsPage::SLUG,
			array( $this->settings_page, 'render' ),
			'dashicons-awards',
			58
		);

		add_submenu_page(
			SettingsPage::SLUG,
			__( 'Settings', 'open-badges-for-learndash' ),
			__( 'Settings', 'open-badges-for-learndash' ),
			'manage_options',
			SettingsPage::SLUG,
			array( $this->settings_page, 'render' )
		);

		add_submenu_page(
			SettingsPage::SLUG,
			__( 'Badges', 'open-badges-for-learndash' ),
			__( 'Badges', 'open-badges-for-learndash' ),
			'manage_options',
			BadgesPage::SLUG,
			array( $this->badges_page, 'render' )
		);

		add_submenu_page(
			SettingsPage::SLUG,
			__( 'Triggers', 'open-badges-for-learndash' ),
			__( 'Triggers', 'open-badges-for-learndash' ),
			'manage_options',
			TriggersPage::SLUG,
			array( $this->triggers_page, 'render' )
		);

		add_submenu_page(
			SettingsPage::SLUG,
			__( 'Issue Badges', 'open-badges-for-learndash' ),
			__( 'Issue Badges', 'open-badges-for-learndash' ),
			'manage_options',
			IssuePage::SLUG,
			array( $this->issue_page, 'render' )
		);

		add_submenu_page(
			SettingsPage::SLUG,
			__( 'Activity Log', 'open-badges-for-learndash' ),
			__( 'Activity Log', 'open-badges-for-learndash' ),
			'manage_options',
			ActivityLogPage::SLUG,
			array( $this->log_page, 'render' )
		);
	}
}
