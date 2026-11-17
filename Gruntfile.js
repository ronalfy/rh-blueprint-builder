/**
 * Build a plugin zip for distribution.
 *
 * What belongs in the zip
 * - Ship: the main plugin file, uninstall.php, README, license, assets, and src.
 * - Omit development files: Gruntfile.js, package.json, and node_modules.
 *
 * Tasks
 * - `grunt` or `grunt release` — zip the plugin files. Assets are copied as they are.
 */
'use strict';

module.exports = function( grunt ) {
	grunt.initConfig( {
		compress: {
			main: {
				options: {
					archive: 'rh-blueprint-builder.zip',
				},
				files: [
					{ src: [ 'rh-blueprint-builder.php' ], dest: '/', filter: 'isFile' },
					{ src: [ 'uninstall.php' ], dest: '/', filter: 'isFile' },
					{ src: [ 'README.md' ], dest: '/', filter: 'isFile' },
					{ src: [ 'LICENSE' ], dest: '/', filter: 'isFile' },
					{ src: [ 'assets/**' ], dest: '/' },
					{ src: [ 'src/**' ], dest: '/' },
				],
			},
		},
	} );

	grunt.registerTask( 'default', [ 'compress' ] );
	grunt.registerTask( 'release', [ 'compress' ] );

	grunt.loadNpmTasks( 'grunt-contrib-compress' );
};
