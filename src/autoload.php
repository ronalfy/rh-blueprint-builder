<?php
/**
 * Autoload plugin classes.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'RhBlueprintBuilder\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
		$file     = RH_BLUEPRINT_BUILDER_DIR . '/src/class-' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
