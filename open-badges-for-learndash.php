<?php
/**
 * Plugin Name:       Open Badges for LearnDash
 * Plugin URI:        https://github.com/LXD-Integral/open-badges-for-learndash
 * Description:       Automatically issue Open Badges to LearnDash learners via Open Badge Factory or CanCred.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            LXD Integral Learning Ltd.
 * Author URI:        https://lxdintegral.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-badges-for-learndash
 *
 * @package OBLD
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'OBLD_VERSION', '1.0.0' );
define( 'OBLD_FILE', __FILE__ );
define( 'OBLD_DIR', plugin_dir_path( __FILE__ ) );
define( 'OBLD_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 autoloader for the OBLD\ namespace (src/). The plugin has no runtime
// Composer dependencies, so the release ships no vendor/ directory; Composer
// is used only for development tooling (PHPUnit, PHPCS).
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'OBLD\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = OBLD_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \OBLD\Schema::class, 'activate' ) );

// Leave no orphaned cron entry behind when the plugin is switched off.
register_deactivation_hook( __FILE__, array( \OBLD\Issuance\IssuanceService::class, 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\OBLD\Plugin::boot();
	}
);
