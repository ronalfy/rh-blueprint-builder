<?php
/**
 * Detect versions, themes, plugins, and options on this site.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

/**
 * Reads the current site for the wizard and the blueprint.
 */
class Detector {

	const DIRECTORY_TRANSIENT       = 'rh_blueprint_builder_directory';
	const DIRECTORY_ERROR_TRANSIENT = 'rh_blueprint_builder_directory_errors';

	/**
	 * Option names already known to exist during this request.
	 *
	 * @var array<string, bool>
	 */
	private $exists_cache = array();

	/**
	 * Cached plugin report.
	 *
	 * @var array<string, mixed>|null
	 */
	private $plugins_report = null;

	/**
	 * Cached theme report.
	 *
	 * @var array<string, mixed>|null
	 */
	private $theme_report = null;

	/**
	 * Cache key for the last option group list.
	 *
	 * @var string
	 */
	private $group_cache_key = '';

	/**
	 * Cached option groups.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $group_cache = null;

	/**
	 * PHP versions the blueprint file is allowed to name.
	 *
	 * @return string[]
	 */
	public function allowed_php_versions() {
		return array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', 'latest' );
	}

	/**
	 * PHP version to preselect, mapped onto the allowed list.
	 *
	 * Versions older than 7.4, including 7.2 and 7.3, become 7.4.
	 * Versions newer than 8.5 become latest.
	 *
	 * @return string
	 */
	public function detected_php_version() {
		$parts = explode( '.', PHP_VERSION );
		$major = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$minor = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$id    = $major . '.' . $minor;

		if ( in_array( $id, array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), true ) ) {
			return $id;
		}

		if ( $major > 8 || ( 8 === $major && $minor > 5 ) ) {
			return 'latest';
		}

		return '7.4';
	}

	/**
	 * The site's WordPress version as a wizard choice.
	 *
	 * Numeric releases expose major.minor. Anything else is nightly.
	 *
	 * @return array{id: string, label: string, nightly: bool}
	 */
	public function site_wordpress_version() {
		$raw   = (string) get_bloginfo( 'version' );
		$parts = explode( '.', $raw );

		if ( ! isset( $parts[0], $parts[1] ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
			return array(
				'id'      => 'nightly',
				'label'   => __( 'Nightly (this site is not on a numeric release)', 'rh-blueprint-builder' ),
				'nightly' => true,
			);
		}

		$id = $parts[0] . '.' . $parts[1];

		return array(
			'id'      => $id,
			'label'   => $id,
			'nightly' => false,
		);
	}

	/**
	 * Locale to write as siteLanguage, or an empty string for en_US.
	 *
	 * @return string
	 */
	public function site_language() {
		$locale = get_locale();

		if ( ! is_string( $locale ) || '' === $locale || 'en_US' === $locale ) {
			return '';
		}

		return $locale;
	}

	/**
	 * Whether this site shows a static page on the front.
	 *
	 * @return bool
	 */
	public function has_static_front_page() {
		return 'page' === get_option( 'show_on_front' ) && $this->page_post( (int) get_option( 'page_on_front' ) ) instanceof \WP_Post;
	}

	/**
	 * Default Playground landing path for this site.
	 *
	 * @return string
	 */
	public function default_landing_page() {
		return $this->has_static_front_page() ? '/' : '/wp-admin/';
	}

	/**
	 * Paths used by the front-page step.
	 *
	 * The front path is empty when this site does not show a static page.
	 * The posts path is set only in that same case, when a posts page exists.
	 *
	 * @return array{front: string, posts: string}
	 */
	public function front_page_paths() {
		$paths = array(
			'front' => '',
			'posts' => '',
		);

		if ( ! $this->has_static_front_page() ) {
			return $paths;
		}

		$front = $this->page_post( (int) get_option( 'page_on_front' ) );
		if ( $front instanceof \WP_Post ) {
			$paths['front'] = (string) get_page_uri( $front );
		}

		$posts = $this->page_post( (int) get_option( 'page_for_posts' ) );
		if ( $posts instanceof \WP_Post ) {
			$paths['posts'] = (string) get_page_uri( $posts );
		}

		return $paths;
	}

	/**
	 * Active theme and, when it is a child, its parent.
	 *
	 * on_org is true, false, or null when WordPress.org could not be reached.
	 *
	 * @return array<string, mixed>
	 */
	public function theme_report() {
		if ( null !== $this->theme_report ) {
			return $this->theme_report;
		}

		$theme      = wp_get_theme();
		$stylesheet = $this->directory_slug( $theme->get_stylesheet() );
		$template   = $this->directory_slug( $theme->get_template() );
		$is_child   = '' !== $stylesheet && '' !== $template && $stylesheet !== $template;
		$lookups    = array();

		if ( '' !== $stylesheet ) {
			$lookups[] = $stylesheet;
		}
		if ( $is_child ) {
			$lookups[] = $template;
		}

		$this->prime_directory( 'themes', $lookups );

		$parent = $is_child ? wp_get_theme( $theme->get_template() ) : null;

		$this->theme_report = array(
			'name'            => $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $theme->get_stylesheet(),
			'stylesheet'      => $theme->get_stylesheet(),
			'stylesheet_slug' => $stylesheet,
			'template'        => $theme->get_template(),
			'template_slug'   => $template,
			'parent_name'     => ( $parent instanceof \WP_Theme ) ? ( $parent->get( 'Name' ) ? $parent->get( 'Name' ) : $theme->get_template() ) : '',
			'is_child'        => $is_child,
			'active_on_org'   => '' === $stylesheet ? false : $this->wordpress_org_status( 'themes', $stylesheet ),
			'parent_on_org'   => $is_child ? $this->wordpress_org_status( 'themes', $template ) : null,
		);

		return $this->theme_report;
	}

	/**
	 * Installed plugins split into WordPress.org installs and skipped plugins.
	 *
	 * This plugin is omitted. Must-use plugins are out of scope.
	 *
	 * @return array{installable: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
	 */
	public function plugins_report() {
		if ( null !== $this->plugins_report ) {
			return $this->plugins_report;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$own      = plugin_basename( RH_BLUEPRINT_BUILDER_FILE );
		$plugins  = get_plugins();
		$pending  = array();
		$records  = array();

		foreach ( $plugins as $file => $headers ) {
			$slug = $this->plugin_slug( $file );
			if ( $file === $own || 'rh-blueprint-builder' === $slug || '' === $slug ) {
				continue;
			}

			$records[] = array(
				'file'   => $file,
				'slug'   => $slug,
				'name'   => isset( $headers['Name'] ) && '' !== $headers['Name'] ? $headers['Name'] : $slug,
				'active' => is_plugin_active( $file ),
			);
			$pending[] = $slug;
		}

		$this->prime_directory( 'plugins', $pending );

		$installable = array();
		$skipped     = array();

		foreach ( $records as $record ) {
			$status = $this->wordpress_org_status( 'plugins', $record['slug'] );
			if ( true === $status ) {
				$record['on_org'] = true;
				$installable[]    = $record;
				continue;
			}

			$record['reason'] = ( null === $status ) ? 'unconfirmed' : 'not_on_org';
			$skipped[]        = $record;
		}

		usort(
			$installable,
			function ( $a, $b ) {
				if ( $a['active'] !== $b['active'] ) {
					return $a['active'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		usort(
			$skipped,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$this->plugins_report = array(
			'installable' => $installable,
			'skipped'     => $skipped,
		);

		return $this->plugins_report;
	}

	/**
	 * Plugin slugs the blueprint should install.
	 *
	 * Before the plugins step has been saved, active WordPress.org plugins are selected.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return string[]
	 */
	public function selected_plugin_slugs( $draft ) {
		$report      = $this->plugins_report();
		$installable = array();
		$active      = array();

		foreach ( $report['installable'] as $plugin ) {
			$installable[] = $plugin['slug'];
			if ( ! empty( $plugin['active'] ) ) {
				$active[] = $plugin['slug'];
			}
		}

		if ( empty( $draft['plugins_saved'] ) ) {
			return $active;
		}

		$checked = isset( $draft['plugins_checked'] ) && is_array( $draft['plugins_checked'] ) ? $draft['plugins_checked'] : array();
		$chosen  = array();

		foreach ( $installable as $slug ) {
			if ( in_array( $slug, $checked, true ) ) {
				$chosen[] = $slug;
			}
		}

		return $chosen;
	}

	/**
	 * Curated and matching options, grouped for the wizard.
	 *
	 * @param string[] $slugs  Selected plugin slugs.
	 * @param string[] $added  Options the user searched for.
	 * @param string[] $hidden Options the user removed.
	 * @return array<int, array<string, mixed>>
	 */
	public function option_groups( $slugs, $added, $hidden ) {
		$slugs = $this->string_list( $slugs );
		$added = $this->string_list( $added );
		$hidden = $this->string_list( $hidden );
		$key    = md5( (string) wp_json_encode( array( $slugs, $added, $hidden ) ) );
		if ( $this->group_cache_key === $key && is_array( $this->group_cache ) ) {
			return $this->group_cache;
		}

		$hidden_map = array_fill_keys( $hidden, true );
		$seen       = array();
		$groups     = array();

		foreach ( $this->core_groups() as $group ) {
			$rows = $this->rows_for_names( $group['options'], $seen, $hidden_map, false );
			if ( $rows ) {
				$groups[] = array(
					'id'        => $group['id'],
					'label'     => $group['label'],
					'rows'      => $rows,
					'truncated' => false,
				);
			}
		}

		$theme_mods = 'theme_mods_' . get_stylesheet();
		$theme_rows = $this->rows_for_names( array( $theme_mods ), $seen, $hidden_map, false );
		if ( $theme_rows ) {
			$groups[] = array(
				'id'        => 'theme',
				'label'     => __( 'Theme', 'rh-blueprint-builder' ),
				'rows'      => $theme_rows,
				'truncated' => false,
			);
		}

		$widget_rows = $this->rows_for_names( $this->widget_option_names(), $seen, $hidden_map, false );
		if ( $widget_rows ) {
			$groups[] = array(
				'id'        => 'widgets',
				'label'     => __( 'Widgets', 'rh-blueprint-builder' ),
				'rows'      => $widget_rows,
				'truncated' => false,
			);
		}

		foreach ( $this->string_list( $slugs ) as $slug ) {
			$match = $this->options_matching_slug( $slug, $seen, $hidden_map );
			if ( ! $match['names'] && ! $match['truncated'] ) {
				continue;
			}
			$groups[] = array(
				'id'        => 'plugin-' . $slug,
				'label'     => sprintf(
					/* translators: %s: WordPress.org plugin slug. */
					__( 'Options matching %s', 'rh-blueprint-builder' ),
					$slug
				),
				'rows'      => $this->rows_for_names( $match['names'], $seen, array(), false ),
				'truncated' => $match['truncated'],
			);
		}

		$added_rows = $this->rows_for_names( $this->string_list( $added ), $seen, $hidden_map, true );
		if ( $added_rows ) {
			$groups[] = array(
				'id'        => 'added',
				'label'     => __( 'Added options', 'rh-blueprint-builder' ),
				'rows'      => $added_rows,
				'truncated' => false,
			);
		}

		$this->group_cache_key = $key;
		$this->group_cache     = $groups;

		return $groups;
	}

	/**
	 * Option names that should be copied for this draft.
	 *
	 * A name the user has not accepted or dismissed is included unless it looks like a secret.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return string[]
	 */
	public function selected_option_names( $draft ) {
		$groups = $this->option_groups(
			$this->selected_plugin_slugs( $draft ),
			isset( $draft['options_added'] ) ? $draft['options_added'] : array(),
			isset( $draft['options_hidden'] ) ? $draft['options_hidden'] : array()
		);
		$names  = array();

		foreach ( $groups as $group ) {
			foreach ( $group['rows'] as $row ) {
				if ( $this->option_is_selected( $row['name'], $row['secret'], $draft ) ) {
					$names[] = $row['name'];
				}
			}
		}

		return $names;
	}

	/**
	 * Whether a listed option is checked.
	 *
	 * @param string               $name   Option name.
	 * @param bool                 $secret Whether the name looks like a secret.
	 * @param array<string, mixed> $draft  Saved wizard answers.
	 * @return bool
	 */
	public function option_is_selected( $name, $secret, $draft ) {
		$unchecked = isset( $draft['options_unchecked'] ) && is_array( $draft['options_unchecked'] ) ? $draft['options_unchecked'] : array();
		$checked   = isset( $draft['options_checked'] ) && is_array( $draft['options_checked'] ) ? $draft['options_checked'] : array();

		if ( in_array( $name, $unchecked, true ) ) {
			return false;
		}

		if ( in_array( $name, $checked, true ) ) {
			return true;
		}

		return ! $secret;
	}

	/**
	 * Search options by name for the add field.
	 *
	 * @param string $term Search text.
	 * @return array<int, array<string, mixed>>
	 */
	public function search_options( $term ) {
		global $wpdb;

		$term = trim( wp_unslash( (string) $term ) );
		if ( strlen( $term ) < 2 ) {
			return array();
		}

		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 80",
				$like
			)
		);

		$results = array();
		foreach ( $names as $name ) {
			if ( $this->is_denied_option( $name ) ) {
				continue;
			}
			$this->exists_cache[ $name ] = true;
			$results[]                   = array(
				'name'    => $name,
				'preview' => $this->option_preview( $name ),
				'secret'  => $this->is_likely_secret( $name ),
			);
			if ( count( $results ) >= 20 ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Whether this option must never be offered or copied.
	 *
	 * WPLANG is refused because site setup writes siteLanguage instead.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function is_denied_option( $name ) {
		static $exact = array(
			'auth_key'                => true,
			'auth_salt'               => true,
			'logged_in_key'           => true,
			'logged_in_salt'          => true,
			'nonce_key'               => true,
			'nonce_salt'              => true,
			'secure_auth_key'         => true,
			'secure_auth_salt'        => true,
			'mailserver_url'          => true,
			'mailserver_login'        => true,
			'mailserver_pass'         => true,
			'mailserver_port'         => true,
			'cron'                    => true,
			'rewrite_rules'           => true,
			'active_plugins'          => true,
			'recently_activated'      => true,
			'uninstall_plugins'       => true,
			'siteurl'                 => true,
			'home'                    => true,
			'template'                => true,
			'stylesheet'              => true,
			'page_on_front'           => true,
			'page_for_posts'          => true,
			'show_on_front'           => true,
			'recovery_keys'           => true,
			'db_version'              => true,
			'initial_db_version'      => true,
			'core_updater.lock'       => true,
			'auto_updater.lock'       => true,
			'auto_core_update_lock'   => true,
			'WPLANG'                  => true,
		);

		if ( isset( $exact[ $name ] ) ) {
			return true;
		}

		if ( 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
			return true;
		}

		if ( 0 === strpos( $name, 'rh_blueprint_builder' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the option name looks like a license or API secret.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function is_likely_secret( $name ) {
		return 1 === preg_match( '/license|api_key|apikey|secret|token/i', $name );
	}

	/**
	 * Short, unserialized preview of an option value.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public function option_preview( $name ) {
		if ( ! $this->option_exists( $name ) ) {
			return '';
		}

		$value = get_option( $name );

		if ( is_string( $value ) && is_serialized( $value ) ) {
			return __( '(unreadable serialized value)', 'rh-blueprint-builder' );
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			return is_string( $encoded ) ? $this->truncate( $encoded ) : '';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $this->truncate( (string) $value );
		}

		return '';
	}

	/**
	 * Whether the option row exists.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function option_exists( $name ) {
		global $wpdb;

		if ( array_key_exists( $name, $this->exists_cache ) ) {
			return $this->exists_cache[ $name ];
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		$this->exists_cache[ $name ] = null !== $found && '' !== $found;

		return $this->exists_cache[ $name ];
	}

	/**
	 * Keep only names that exist and are not denied.
	 *
	 * @param string[] $names Candidate names.
	 * @return string[]
	 */
	public function existing_option_names( $names ) {
		global $wpdb;

		$clean = array();
		foreach ( $this->string_list( $names ) as $name ) {
			if ( ! $this->is_denied_option( $name ) ) {
				$clean[] = $name;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( ! $clean ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $clean ), '%s' ) );
		$sql          = "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
		$found        = $wpdb->get_col( $wpdb->prepare( $sql, $clean ) );
		$found_map    = array_fill_keys( $found, true );
		$existing     = array();

		foreach ( $clean as $name ) {
			$exists                      = isset( $found_map[ $name ] );
			$this->exists_cache[ $name ] = $exists;
			if ( $exists ) {
				$existing[] = $name;
			}
		}

		return $existing;
	}

	/**
	 * User logins that will appear as authors of the exported content.
	 *
	 * The types and statuses match the WXR download, including site-editor
	 * posts. Auto-draft authors are included only for site-editor types when
	 * unpublished content is requested.
	 *
	 * @param bool $include_nonpublic Whether drafts and private content are included.
	 * @return string[]
	 */
	public function author_logins( $include_nonpublic ) {
		$statuses = array( 'publish' );
		if ( $include_nonpublic ) {
			$statuses[] = 'draft';
			$statuses[] = 'private';
		}

		$logins = $this->author_logins_for( Wxr::content_post_types(), $statuses );
		if ( $include_nonpublic ) {
			$logins = array_merge( $logins, $this->author_logins_for( Wxr::site_editor_post_types(), array( 'auto-draft' ) ) );
		}

		$logins = array_values( array_unique( $logins ) );
		sort( $logins, SORT_STRING );

		return $logins;
	}

	/**
	 * Distinct author logins for the given post types and statuses.
	 *
	 * @param string[] $types    Post types.
	 * @param string[] $statuses Post statuses.
	 * @return string[]
	 */
	private function author_logins_for( $types, $statuses ) {
		global $wpdb;

		$types    = array_values( array_filter( $types ) );
		$statuses = array_values( array_filter( $statuses ) );
		if ( ! $types || ! $statuses ) {
			return array();
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql                 = "SELECT DISTINCT u.user_login
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->posts} p ON p.post_author = u.ID
			WHERE p.post_type IN ($type_placeholders)
			AND p.post_status IN ($status_placeholders)";
		$logins              = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $types, $statuses ) ) );
		$clean               = array();

		foreach ( $logins as $login ) {
			$login = (string) $login;
			if ( '' !== $login ) {
				$clean[] = $login;
			}
		}

		return $clean;
	}

	/**
	 * Curated core options, in display order.
	 *
	 * @return array<int, array{id: string, label: string, options: string[]}>
	 */
	private function core_groups() {
		return array(
			array(
				'id'      => 'identity',
				'label'   => __( 'Site title and tagline', 'rh-blueprint-builder' ),
				'options' => array( 'blogname', 'blogdescription' ),
			),
			array(
				'id'      => 'datetime',
				'label'   => __( 'Timezone, date, and time', 'rh-blueprint-builder' ),
				'options' => array( 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week' ),
			),
			array(
				'id'      => 'permalinks',
				'label'   => __( 'Permalinks', 'rh-blueprint-builder' ),
				'options' => array( 'permalink_structure', 'category_base', 'tag_base' ),
			),
			array(
				'id'      => 'discussion',
				'label'   => __( 'Discussion', 'rh-blueprint-builder' ),
				'options' => array(
					'default_comment_status',
					'default_ping_status',
					'comment_moderation',
					'comment_previously_approved',
					'comment_max_links',
					'moderation_keys',
					'disallowed_keys',
					'comment_registration',
					'require_name_email',
					'show_comments_cookies_opt_in',
					'thread_comments',
					'thread_comments_depth',
					'page_comments',
					'comments_per_page',
					'default_comments_page',
					'comment_order',
					'close_comments_for_old_posts',
					'close_comments_days_old',
					'show_avatars',
					'avatar_rating',
					'avatar_default',
				),
			),
			array(
				'id'      => 'media',
				'label'   => __( 'Media sizes', 'rh-blueprint-builder' ),
				'options' => array(
					'thumbnail_size_w',
					'thumbnail_size_h',
					'thumbnail_crop',
					'medium_size_w',
					'medium_size_h',
					'medium_large_size_w',
					'medium_large_size_h',
					'large_size_w',
					'large_size_h',
					'uploads_use_yearmonth_folders',
				),
			),
		);
	}

	/**
	 * Widget option names stored for this site.
	 *
	 * @return string[]
	 */
	private function widget_option_names() {
		global $wpdb;

		$like = $wpdb->esc_like( 'widget_' ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s ORDER BY option_name ASC",
				'sidebars_widgets',
				$like
			)
		);

		$names = array();
		foreach ( $rows as $name ) {
			if ( ! $this->is_denied_option( $name ) ) {
				$this->exists_cache[ $name ] = true;
				$names[]                     = $name;
			}
		}

		return $names;
	}

	/**
	 * Options whose names contain a plugin slug.
	 *
	 * @param string              $slug       Plugin slug.
	 * @param array<string, bool> $seen       Names already listed.
	 * @param array<string, bool> $hidden_map Names the user removed.
	 * @return array{names: string[], truncated: bool}
	 */
	private function options_matching_slug( $slug, &$seen, $hidden_map ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $slug ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 301",
				$like
			)
		);

		$names     = array();
		$truncated = count( $rows ) > 300;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, 300 );
		}

		foreach ( $rows as $name ) {
			if ( isset( $seen[ $name ] ) || isset( $hidden_map[ $name ] ) || $this->is_denied_option( $name ) ) {
				continue;
			}
			$this->exists_cache[ $name ] = true;
			$names[]                     = $name;
		}

		return array(
			'names'     => $names,
			'truncated' => $truncated,
		);
	}

	/**
	 * Build display rows, skipping missing, denied, hidden, and duplicate names.
	 *
	 * @param string[]            $names      Candidate names.
	 * @param array<string, bool> $seen       Names already listed. Updated.
	 * @param array<string, bool> $hidden_map Names the user removed.
	 * @param bool                $added      Whether these rows came from search.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for_names( $names, &$seen, $hidden_map, $added ) {
		$existing = $this->existing_option_names( $names );
		$rows     = array();

		foreach ( $existing as $name ) {
			if ( isset( $seen[ $name ] ) || isset( $hidden_map[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$rows[]        = array(
				'name'    => $name,
				'preview' => $this->option_preview( $name ),
				'secret'  => $this->is_likely_secret( $name ),
				'added'   => $added,
			);
		}

		return $rows;
	}

	/**
	 * A page post, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|null
	 */
	private function page_post( $post_id ) {
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'page' === $post->post_type ) {
			return $post;
		}

		return null;
	}

	/**
	 * WordPress.org directory slug for a plugin file.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	private function plugin_slug( $plugin_file ) {
		$parts = explode( '/', $plugin_file );
		$raw   = count( $parts ) > 1 ? $parts[0] : basename( $plugin_file, '.php' );

		return $this->directory_slug( $raw );
	}

	/**
	 * A slug that can be sent to the WordPress.org API.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private function directory_slug( $slug ) {
		$slug = strtolower( (string) $slug );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Cached WordPress.org result for a slug.
	 *
	 * @param string $type plugins or themes.
	 * @param string $slug Directory slug.
	 * @return bool|null True, false, or null when the lookup failed recently.
	 */
	private function wordpress_org_status( $type, $slug ) {
		$cache = get_transient( self::DIRECTORY_TRANSIENT );
		if ( is_array( $cache ) && isset( $cache[ $type ] ) && is_array( $cache[ $type ] ) && array_key_exists( $slug, $cache[ $type ] ) ) {
			return (bool) $cache[ $type ][ $slug ];
		}

		$errors = get_transient( self::DIRECTORY_ERROR_TRANSIENT );
		if ( is_array( $errors ) && ! empty( $errors[ $type ][ $slug ] ) ) {
			return null;
		}

		return null;
	}

	/**
	 * Look up uncached slugs and store the results.
	 *
	 * Transport failures are remembered for ten minutes so a refresh does not
	 * call the API again. A definite answer is kept for twelve hours.
	 *
	 * @param string   $type  plugins or themes.
	 * @param string[] $slugs Directory slugs.
	 * @return void
	 */
	private function prime_directory( $type, $slugs ) {
		$cache  = get_transient( self::DIRECTORY_TRANSIENT );
		$errors = get_transient( self::DIRECTORY_ERROR_TRANSIENT );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		if ( ! isset( $cache[ $type ] ) || ! is_array( $cache[ $type ] ) ) {
			$cache[ $type ] = array();
		}
		if ( ! is_array( $errors ) ) {
			$errors = array();
		}
		if ( ! isset( $errors[ $type ] ) || ! is_array( $errors[ $type ] ) ) {
			$errors[ $type ] = array();
		}

		$missing = array();
		foreach ( array_unique( $slugs ) as $slug ) {
			if ( '' === $slug || array_key_exists( $slug, $cache[ $type ] ) || ! empty( $errors[ $type ][ $slug ] ) ) {
				continue;
			}
			$missing[] = $slug;
		}

		if ( ! $missing ) {
			return;
		}

		$results = $this->fetch_directory( $type, $missing );
		$changed = false;
		$failed  = false;

		foreach ( $missing as $slug ) {
			if ( ! array_key_exists( $slug, $results ) || null === $results[ $slug ] ) {
				$errors[ $type ][ $slug ] = 1;
				$failed                   = true;
				continue;
			}
			$cache[ $type ][ $slug ] = (bool) $results[ $slug ];
			unset( $errors[ $type ][ $slug ] );
			$changed = true;
		}

		if ( $changed ) {
			set_transient( self::DIRECTORY_TRANSIENT, $cache, 12 * HOUR_IN_SECONDS );
		}
		if ( $failed ) {
			set_transient( self::DIRECTORY_ERROR_TRANSIENT, $errors, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Ask WordPress.org which slugs exist.
	 *
	 * @param string   $type  plugins or themes.
	 * @param string[] $slugs Directory slugs.
	 * @return array<string, bool|null>
	 */
	private function fetch_directory( $type, $slugs ) {
		$requests = array();
		foreach ( $slugs as $slug ) {
			$requests[ $slug ] = array(
				'url'     => $this->directory_url( $type, $slug ),
				'type'    => 'GET',
				'timeout' => 8,
			);
		}

		$responses = array();
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			try {
				$responses = \WpOrg\Requests\Requests::request_multiple( $requests );
			} catch ( \Exception $exception ) {
				$responses = array();
			}
		}

		$results = array();
		foreach ( $slugs as $slug ) {
			if ( isset( $responses[ $slug ] ) ) {
				if ( $responses[ $slug ] instanceof \WpOrg\Requests\Response ) {
					$results[ $slug ] = $this->interpret_directory_response( (int) $responses[ $slug ]->status_code, (string) $responses[ $slug ]->body );
				} else {
					$results[ $slug ] = null;
				}
				continue;
			}

			$response = wp_remote_get(
				$this->directory_url( $type, $slug ),
				array(
					'timeout' => 8,
				)
			);
			if ( is_wp_error( $response ) ) {
				$results[ $slug ] = null;
				continue;
			}
			$results[ $slug ] = $this->interpret_directory_response(
				(int) wp_remote_retrieve_response_code( $response ),
				(string) wp_remote_retrieve_body( $response )
			);
		}

		return $results;
	}

	/**
	 * WordPress.org information URL for a slug.
	 *
	 * @param string $type plugins or themes.
	 * @param string $slug Directory slug.
	 * @return string
	 */
	private function directory_url( $type, $slug ) {
		$base = ( 'themes' === $type ) ? 'https://api.wordpress.org/themes/info/1.2/' : 'https://api.wordpress.org/plugins/info/1.2/';

		return $base . '?action=' . ( 'themes' === $type ? 'theme_information' : 'plugin_information' ) . '&request[slug]=' . rawurlencode( $slug );
	}

	/**
	 * Turn an API response into found, missing, or unknown.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return bool|null
	 */
	private function interpret_directory_response( $code, $body ) {
		if ( $code < 200 || $code >= 500 ) {
			return null;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		if ( ! empty( $decoded['error'] ) || 404 === $code ) {
			return false;
		}

		return 200 === $code && ! empty( $decoded['slug'] );
	}

	/**
	 * Trim a list of strings.
	 *
	 * @param mixed $values Raw list.
	 * @return string[]
	 */
	private function string_list( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$clean = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = str_replace( "\0", '', $value );
			if ( '' === $value || strlen( $value ) > 191 ) {
				continue;
			}
			$clean[] = $value;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Shorten a preview string.
	 *
	 * @param string $text Full text.
	 * @return string
	 */
	private function truncate( $text ) {
		$limit = 180;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limit ) {
			return mb_substr( $text, 0, $limit ) . '…';
		}
		if ( strlen( $text ) > $limit ) {
			return substr( $text, 0, $limit ) . '…';
		}

		return $text;
	}
}
