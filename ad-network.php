<?php
/**
 * Plugin Name:       Ad Network
 * Description:       Run your own ad network: image and HTML ads in header, footer, in-content and sidebar placements, served in random rotation (page-cache safe) with impression, view and click tracking.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Arbe
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ad-network
 *
 * @package AdNetwork
 */

defined( 'ABSPATH' ) || exit;

// Keep this file PHP 5.6-compatible so old hosts get a notice instead of a parse error.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ad Network requires PHP 8.1 or newer.', 'ad-network' ) . '</p></div>';
		}
	);
	return;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ad Network is missing its dependencies. Run "composer install" in the plugin folder, or install a release zip.', 'ad-network' ) . '</p></div>';
		}
	);
	return;
}

if ( file_exists( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
}
require_once __DIR__ . '/vendor/autoload.php';

define( 'ADN_FILE', __FILE__ );

AdNetwork\Bootstrap::plugin( __FILE__ )->boot();

/**
 * Template tag: print an ad slot for a placement, or for a size.
 *
 *   adn_slot( 'sidebar' );
 *   adn_slot( array( 'size' => 'medium_rectangle', 'mobile' => 'mobile_banner' ) );
 *
 * @param string|array<string, string> $placement Placement key, or slot attributes.
 */
function adn_slot( $placement ) {
	if ( class_exists( 'AdNetwork\\Serving\\Slot' ) && AdNetwork\App::ready() ) {
		echo AdNetwork\Serving\Slot::fromArgs( is_array( $placement ) ? $placement : array( 'placement' => (string) $placement ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
