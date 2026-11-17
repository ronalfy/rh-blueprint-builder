<?php
/**
 * Plugin Name: RH Blueprint Builder
 * Description: Guided export of this WordPress site to a Playground Blueprint v2 file. It does not change the site or open a public URL on it.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: rh-blueprint-builder
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RH_BLUEPRINT_BUILDER_FILE', __FILE__ );
define( 'RH_BLUEPRINT_BUILDER_DIR', __DIR__ );
define( 'RH_BLUEPRINT_BUILDER_VERSION', '1.0.0' );

require_once RH_BLUEPRINT_BUILDER_DIR . '/src/autoload.php';

/**
 * Boot the plugin after WordPress has loaded its APIs.
 *
 * @return void
 */
function rh_blueprint_builder_boot() {
	$plugin = new Plugin();
	$plugin->register_hooks();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\rh_blueprint_builder_boot' );
