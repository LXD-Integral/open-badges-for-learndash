<?php
/**
 * BadgeList tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Display\BadgeList;
use OBLD\Issuance\AwardRepository;
use PHPUnit\Framework\TestCase;

/**
 * Award repository double returning canned earned badges.
 */
final class FakeEarnedAwardRepository extends AwardRepository {

	/**
	 * Rows to return.
	 *
	 * @var array<int, object>
	 */
	public array $rows = array();

	/**
	 * User ids queried.
	 *
	 * @var array<int, int>
	 */
	public array $queried = array();

	/**
	 * Return the canned rows.
	 *
	 * @param int $user_id Learner.
	 * @param int $limit   Maximum rows.
	 *
	 * @return array<int, object>
	 */
	public function issued_for_user( int $user_id, int $limit = 100 ): array {
		$this->queried[] = $user_id;

		return $this->rows;
	}
}

/**
 * The single renderer behind both the shortcode and the block.
 *
 * The attribute clamping matters more than it looks: the block and a
 * hand-typed shortcode both pass through normalise(), which is what keeps the
 * editor and the front end in agreement — the specific bug the predecessor
 * plugin shipped.
 */
final class BadgeListTest extends TestCase {

	private FakeEarnedAwardRepository $awards;
	private BadgeList $list;

	protected function setUp(): void {
		obld_test_reset();
		update_option( 'date_format', 'Y-m-d' );

		$this->awards = new FakeEarnedAwardRepository();
		$this->list   = new BadgeList( $this->awards );
	}

	/**
	 * A badge row.
	 *
	 * @param string $name  Badge name.
	 * @param string $image Image URL.
	 */
	private function badge( string $name = 'Test Badge', string $image = 'https://example.test/b.png' ): object {
		return (object) array(
			'id'                => 1,
			'issued_at'         => '2026-08-01 12:00:00',
			'provider_event_id' => 'EVENT1',
			'badge_name'        => $name,
			'badge_description' => 'A badge.',
			'badge_image_url'   => $image,
			'badge_provider'    => 'cancred',
		);
	}

	/**
	 * A badge row that has been enriched with export links.
	 *
	 * @param string $name Badge name.
	 */
	private function enriched_badge( string $name = 'Test Badge' ): object {
		$badge                     = $this->badge( $name );
		$badge->assertion_id       = 'ASSERT1';
		$badge->receive_url        = 'https://factory.cancred.ca/badge/receive/abc';
		$badge->assertion_json_url = 'https://factory.cancred.ca/assertion/abc.json';

		return $badge;
	}

	public function test_renders_each_earned_badge(): void {
		$this->awards->rows = array( $this->badge( 'First' ), $this->badge( 'Second' ) );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'First', $html );
		$this->assertStringContainsString( 'Second', $html );
		$this->assertSame( array( 7 ), $this->awards->queried );
	}

	public function test_shows_a_friendly_empty_state(): void {
		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'No badges earned yet', $html );
		$this->assertStringNotContainsString( '<ul', $html );
	}

	public function test_prompts_a_signed_out_visitor(): void {
		// No user attribute and no logged-in user.
		$html = $this->list->render( array() );

		$this->assertStringContainsString( 'Sign in', $html );
		$this->assertSame( array(), $this->awards->queried, 'A signed-out visitor should not be queried for.' );
	}

	public function test_layout_attributes_reach_the_markup(): void {
		$this->awards->rows = array( $this->badge() );

		$html = $this->list->render(
			array(
				'user'    => 7,
				'columns' => 4,
				'size'    => 128,
			)
		);

		$this->assertStringContainsString( 'repeat(4,minmax(0,1fr))', $html );
		$this->assertStringContainsString( 'width="128"', $html );
		$this->assertStringContainsString( 'height:128px', $html );
	}

	/**
	 * @dataProvider out_of_range_layouts
	 *
	 * @param int $columns  Submitted columns.
	 * @param int $size     Submitted size.
	 * @param int $expect_c Clamped columns.
	 * @param int $expect_s Clamped size.
	 */
	public function test_out_of_range_layout_values_are_clamped( int $columns, int $size, int $expect_c, int $expect_s ): void {
		$config = $this->list->normalise(
			array(
				'user'    => 7,
				'columns' => $columns,
				'size'    => $size,
			)
		);

		$this->assertSame( $expect_c, $config['columns'] );
		$this->assertSame( $expect_s, $config['size'] );
	}

	/**
	 * Layout values that must be clamped.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
	 */
	public static function out_of_range_layouts(): array {
		return array(
			'zero and tiny'  => array( 0, 1, 1, BadgeList::MIN_SIZE ),
			'negative'       => array( -5, -20, 1, BadgeList::MIN_SIZE ),
			'absurdly large' => array( 99, 5000, BadgeList::MAX_COLUMNS, BadgeList::MAX_SIZE ),
		);
	}

	public function test_names_are_shown_by_default_and_can_be_turned_off(): void {
		$this->awards->rows = array( $this->badge( 'Hidden Name' ) );

		$this->assertStringContainsString(
			'obld-earned-badges__name',
			$this->list->render( array( 'user' => 7 ) )
		);
		$this->assertStringNotContainsString(
			'obld-earned-badges__name',
			$this->list->render( array( 'user' => 7, 'show_name' => '0' ) )
		);
	}

	public function test_linking_is_off_by_default_and_opt_in(): void {
		$this->awards->rows = array( $this->badge() );

		$this->assertStringNotContainsString( '<a class="obld-earned-badges__link"', $this->list->render( array( 'user' => 7 ) ) );
		$this->assertStringContainsString( '<a class="obld-earned-badges__link"', $this->list->render( array( 'user' => 7, 'link' => '1' ) ) );
	}

	public function test_linking_prefers_the_credential_page_over_the_image_file(): void {
		$this->awards->rows = array( $this->enriched_badge() );

		$html = $this->list->render( array( 'user' => 7, 'link' => '1' ) );

		// The assertion URL minus `.json` is the provider's human-readable
		// badge page; the image href would waste the click on a bare PNG.
		$this->assertStringContainsString( '<a class="obld-earned-badges__link" href="https://factory.cancred.ca/assertion/abc"', $html );
	}

	public function test_linking_falls_back_to_the_image_when_unenriched(): void {
		$this->awards->rows = array( $this->badge() );

		$html = $this->list->render( array( 'user' => 7, 'link' => '1' ) );

		$this->assertStringContainsString( '<a class="obld-earned-badges__link" href="https://example.test/b.png"', $html );
	}

	public function test_shortcode_string_booleans_are_understood(): void {
		// Shortcode attributes always arrive as strings, never real booleans.
		foreach ( array( '1', 'true', 'yes', 'on' ) as $truthy ) {
			$this->assertTrue( $this->list->normalise( array( 'user' => 7, 'link' => $truthy ) )['link'], $truthy );
		}

		foreach ( array( '0', 'false', 'no', '' ) as $falsy ) {
			$this->assertFalse( $this->list->normalise( array( 'user' => 7, 'link' => $falsy ) )['link'], $falsy );
		}
	}

	public function test_a_badge_without_an_image_still_renders(): void {
		$this->awards->rows = array( $this->badge( 'No Image', '' ) );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'No Image', $html );
		$this->assertStringContainsString( 'obld-earned-badges__placeholder', $html );
	}

	public function test_the_owner_sees_the_save_and_share_link(): void {
		$GLOBALS['obld_test_current_user'] = 7;
		$this->awards->rows                = array( $this->enriched_badge() );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'obld-earned-badges__action--save', $html );
		$this->assertStringContainsString( 'https://factory.cancred.ca/badge/receive/abc', $html );
		$this->assertStringContainsString( 'Save & share', $html );
	}

	public function test_the_save_link_is_never_shown_to_another_viewer(): void {
		// The receive URL is a claim link: anyone holding it could save the
		// badge to their own Passport. It must not leak from a public showcase.
		$this->awards->rows = array( $this->enriched_badge() );

		$logged_out = $this->list->render( array( 'user' => 7 ) );
		$this->assertStringNotContainsString( 'obld-earned-badges__action--save', $logged_out );
		$this->assertStringNotContainsString( 'receive/abc', $logged_out );

		$GLOBALS['obld_test_current_user'] = 9; // A different learner.
		$other_user = $this->list->render( array( 'user' => 7 ) );
		$this->assertStringNotContainsString( 'obld-earned-badges__action--save', $other_user );
		$this->assertStringNotContainsString( 'receive/abc', $other_user );
	}

	public function test_the_verify_link_is_public_and_targets_the_hosted_validator(): void {
		// No logged-in user at all — the validator is the provider's own
		// public verification page, so every visitor may see it.
		$this->awards->rows = array( $this->enriched_badge() );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'obld-earned-badges__action--verify', $html );
		$this->assertStringContainsString( 'https://factory.cancred.ca/validator?id=abc', $html );
		$this->assertStringNotContainsString( 'abc.json', $html );
	}

	public function test_verify_falls_back_to_the_assertion_url_when_underivable(): void {
		// The validator URL is a convention derived from the JSON URL, not an
		// API field. If a provider ever changes the URL shape, the raw
		// assertion must keep rendering rather than a broken derivation.
		$badge                     = $this->enriched_badge();
		$badge->assertion_json_url = 'https://factory.cancred.ca/assertion/abc';
		$this->awards->rows        = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'href="https://factory.cancred.ca/assertion/abc"', $html );
		$this->assertStringNotContainsString( 'validator?id=', $html );
	}

	public function test_action_links_can_be_disabled_by_attribute(): void {
		$GLOBALS['obld_test_current_user'] = 7;
		$this->awards->rows                = array( $this->enriched_badge() );

		$html = $this->list->render( array( 'user' => 7, 'export' => '0', 'verify' => '0' ) );

		$this->assertStringNotContainsString( 'obld-earned-badges__action', $html );
	}

	public function test_an_unenriched_badge_renders_no_action_links(): void {
		// Rows issued before milestone 7 (or whose lookup failed) have no URLs
		// yet; nothing may render until the sweep fills them in.
		$GLOBALS['obld_test_current_user'] = 7;
		$this->awards->rows                = array( $this->badge() );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringNotContainsString( 'obld-earned-badges__action', $html );
	}

	public function test_the_checked_sentinel_renders_no_action_links(): void {
		$GLOBALS['obld_test_current_user'] = 7;

		$badge                     = $this->badge();
		$badge->receive_url        = '';
		$badge->assertion_json_url = '';
		$this->awards->rows        = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringNotContainsString( 'obld-earned-badges__action', $html );
	}

	public function test_export_and_verify_default_on_in_normalise(): void {
		$config = $this->list->normalise( array( 'user' => 7 ) );

		$this->assertTrue( $config['export'] );
		$this->assertTrue( $config['verify'] );
	}

	public function test_own_empty_state_explains_how_badges_arrive(): void {
		$GLOBALS['obld_test_current_user'] = 7;

		$html = $this->list->render( array() );

		$this->assertStringContainsString( 'a few minutes', $html );
	}

	public function test_third_person_empty_state_stays_short(): void {
		// A showcase of someone else's (empty) list gets the plain message —
		// the guidance copy talks to the earner, and this viewer isn't them.
		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'No badges earned yet.', $html );
		$this->assertStringNotContainsString( 'a few minutes', $html );
	}

	public function test_viewer_specific_renders_are_excluded_from_page_caches(): void {
		// Without an explicit user the output follows the viewer (even the
		// signed-out prompt differs from a signed-in render), so a page cache
		// would serve one viewer's list to another.
		$this->list->render( array() );
		$this->assertSame( 1, obld_test_fired_count( 'litespeed_control_set_nocache' ), 'signed-out, no user attribute' );

		obld_test_reset();
		$GLOBALS['obld_test_current_user'] = 7;
		$this->list->render( array() );
		$this->assertSame( 1, obld_test_fired_count( 'litespeed_control_set_nocache' ), 'signed-in, no user attribute' );

		obld_test_reset();
		$GLOBALS['obld_test_current_user'] = 7;
		$this->list->render( array( 'user' => 7 ) );
		$this->assertSame( 1, obld_test_fired_count( 'litespeed_control_set_nocache' ), 'owner viewing their own showcased list (claim links present)' );
	}

	public function test_an_anonymous_explicit_user_render_stays_cacheable(): void {
		// The showcase case: a fixed user attribute and a viewer who is not
		// that user render identically for everyone, so caching is safe and
		// desirable.
		$this->awards->rows = array( $this->enriched_badge() );

		$this->list->render( array( 'user' => 7 ) );

		$GLOBALS['obld_test_current_user'] = 9;
		$this->list->render( array( 'user' => 7 ) );

		$this->assertSame( 0, obld_test_fired_count( 'litespeed_control_set_nocache' ) );
	}

	public function test_a_future_expiry_is_shown_as_expires(): void {
		$badge             = $this->enriched_badge();
		$badge->expires_on = '2099-06-30 00:00:00';
		$this->awards->rows = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'obld-earned-badges__expiry', $html );
		$this->assertStringNotContainsString( 'obld-earned-badges__expiry--expired', $html );
		$this->assertStringContainsString( 'Expires 2099-06-30', $html );
	}

	public function test_an_expired_badge_stays_listed_with_an_expired_label(): void {
		// Decision (2026-09-05): expiry is a fact about the credential, not
		// a retraction of the achievement — keep the badge, say it lapsed.
		$badge              = $this->enriched_badge();
		$badge->expires_on  = '2020-01-01 00:00:00';
		$this->awards->rows = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'Test Badge', $html );
		$this->assertStringContainsString( 'obld-earned-badges__expiry--expired', $html );
		$this->assertStringContainsString( 'Expired 2020-01-01', $html );
	}

	public function test_an_expired_badge_offers_no_claim_link_but_keeps_verify(): void {
		$GLOBALS['obld_test_current_user'] = 7;

		$badge              = $this->enriched_badge();
		$badge->expires_on  = '2020-01-01 00:00:00';
		$this->awards->rows = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringNotContainsString( 'obld-earned-badges__action--save', $html, 'No point nudging someone to claim a lapsed credential.' );
		$this->assertStringContainsString( 'obld-earned-badges__action--verify', $html, 'The validator truthfully reports the expiry.' );
	}

	public function test_no_expiry_renders_no_expiry_line(): void {
		$badge              = $this->enriched_badge();
		$badge->expires_on  = null;
		$this->awards->rows = array( $badge );

		$this->assertStringNotContainsString( 'obld-earned-badges__expiry', $this->list->render( array( 'user' => 7 ) ) );
	}

	public function test_saved_to_passport_replaces_the_claim_link_for_the_owner(): void {
		$GLOBALS['obld_test_current_user'] = 7;

		$badge               = $this->enriched_badge();
		$badge->received     = '1';
		$badge->in_passport  = '1';
		$this->awards->rows  = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'obld-earned-badges__action--saved', $html );
		$this->assertStringContainsString( 'Saved to Passport', $html );
		$this->assertStringNotContainsString( 'obld-earned-badges__action--save"', $html );
		$this->assertStringNotContainsString( 'Save &amp; share', $html );
		$this->assertStringNotContainsString( 'Save & share', $html );
	}

	public function test_received_without_passport_keeps_the_claim_link(): void {
		// Opening the claim page is not the same as saving the badge; the
		// nudge stays until the Passport actually has it.
		$GLOBALS['obld_test_current_user'] = 7;

		$badge              = $this->enriched_badge();
		$badge->received    = '1';
		$badge->in_passport = '0';
		$this->awards->rows = array( $badge );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringContainsString( 'obld-earned-badges__action--save"', $html );
		$this->assertStringNotContainsString( 'obld-earned-badges__action--saved', $html );
	}

	public function test_the_passport_mark_is_owner_only(): void {
		// Like the claim link it replaces, the mark is about the owner's own
		// Passport; a showcase visitor has no business seeing it.
		$badge              = $this->enriched_badge();
		$badge->in_passport = '1';
		$this->awards->rows = array( $badge );

		$logged_out = $this->list->render( array( 'user' => 7 ) );
		$this->assertStringNotContainsString( 'Saved to Passport', $logged_out );

		$GLOBALS['obld_test_current_user'] = 9;
		$other = $this->list->render( array( 'user' => 7 ) );
		$this->assertStringNotContainsString( 'Saved to Passport', $other );
	}

	public function test_badge_names_are_escaped(): void {
		$this->awards->rows = array( $this->badge( '<script>alert(1)</script>' ) );

		$html = $this->list->render( array( 'user' => 7 ) );

		$this->assertStringNotContainsString( '<script>', $html );
	}
}
