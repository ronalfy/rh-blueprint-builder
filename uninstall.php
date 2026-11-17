<?php
/**
 * Remove draft user meta when the plugin is deleted.
 *
 * Transients are left to expire.
 *
 * @package RhBlueprintBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
		'rh_blueprint_builder_draft'
	)
);
