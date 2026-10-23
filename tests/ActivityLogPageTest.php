<?php
/**
 * ActivityLogPage tests.
 *
 * @package OBLD\Tests
 */

declare( strict_types=1 );

namespace OBLD\Tests;

use OBLD\Admin\ActivityLogPage;
use PHPUnit\Framework\TestCase;

/**
 * CSV export hardening.
 */
final class ActivityLogPageTest extends TestCase {

	/**
	 * Cells that a spreadsheet would evaluate as a formula are prefixed.
	 *
	 * @dataProvider formula_cells
	 *
	 * @param string $input    Raw cell.
	 * @param string $expected Neutralised cell.
	 */
	public function test_csv_cell_neutralises_formula_prefixes( string $input, string $expected ): void {
		self::assertSame( $expected, ActivityLogPage::csv_cell( $input ) );
	}

	/**
	 * Cases.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function formula_cells(): array {
		return array(
			'equals'      => array( '=HYPERLINK("http://evil")', "'=HYPERLINK(\"http://evil\")" ),
			'plus'        => array( '+1+1', "'+1+1" ),
			'minus'       => array( '-2', "'-2" ),
			'at'          => array( '@SUM(1)', "'@SUM(1)" ),
			'tab'         => array( "\t=1", "'\t=1" ),
			'cr'          => array( "\r=1", "'\r=1" ),
			'plain'       => array( 'Course Completer', 'Course Completer' ),
			'empty'       => array( '', '' ),
			'inner_equal' => array( 'a=b', 'a=b' ),
		);
	}
}
