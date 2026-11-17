<?php
/**
 * Plugin hooks.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads translations and the admin wizard.
 */
class Plugin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$detector = new Detector();
			$admin    = new Admin( $detector, new Blueprint( $detector ), new Wxr() );
			$admin->register_hooks();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'rh-blueprint-builder',
			false,
			dirname( plugin_basename( RH_BLUEPRINT_BUILDER_FILE ) ) . '/languages'
		);
	}
}
