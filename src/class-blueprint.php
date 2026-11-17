<?php
/**
 * Build a schema-safe Blueprint v2 document.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns wizard answers into Blueprint v2 JSON.
 */
class Blueprint {

	/**
	 * Site detector.
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * Warnings collected while building.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	private $notes = array();

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Site detector.
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Blueprint document, pretty JSON, and review messages.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array{blueprint: array<string, mixed>, json: string, messages: array<int, array{type: string, message: string}>, blocked: bool}
	 */
	public function review( $draft ) {
		$this->notes = array();
		$blueprint   = $this->build( $draft );
		$messages    = array_merge( $this->blocking_messages( $draft ), $this->notes );
		$json        = wp_json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$blocked     = $this->is_blocked( $messages );

		if ( ! is_string( $json ) ) {
			$json       = '';
			$blocked    = true;
			$messages[] = array(
				'type'    => 'error',
				'message' => __( 'The blueprint could not be encoded as JSON.', 'rh-blueprint-builder' ),
			);
		}

		return array(
			'blueprint' => $blueprint,
			'json'      => $json,
			'messages'  => $messages,
			'blocked'   => $blocked,
		);
	}

	/**
	 * Build the Blueprint v2 array.
	 *
	 * Empty optional objects are omitted. A permalink_structure value in
	 * siteOptions is flushed by the Blueprint runner when it differs from
	 * the fresh site, so this file does not add a rewrite step.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array<string, mixed>
	 */
	public function build( $draft ) {
		$blueprint = array(
			'$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
			'version' => 2,
		);

		$meta = $this->meta( $draft );
		if ( $meta ) {
			$blueprint['blueprintMeta'] = $meta;
		}

		$blueprint['phpVersion']       = $this->php_version( $draft );
		$blueprint['wordpressVersion'] = $this->wordpress_version( $draft );
		$blueprint['applicationOptions'] = array(
			'wordpress-playground' => array(
				'landingPage'   => $this->landing_page( $draft ),
				'login'         => $this->login_value( $draft ),
				'networkAccess' => ! empty( $draft['network_access'] ),
			),
		);

		$language = $this->detector->site_language();
		if ( '' !== $language ) {
			$blueprint['siteLanguage'] = $language;
		}

		$this->add_theme( $blueprint );
		$this->add_plugins( $blueprint, $draft );

		if ( ! empty( $draft['content'] ) ) {
			$blueprint['contentBaseline'] = 'empty';
		}

		$scalars = array();
		$complex = array();
		foreach ( $this->detector->selected_option_names( $draft ) as $name ) {
			$classified = $this->classify_option( $name );
			if ( 'scalar' === $classified['place'] ) {
				$scalars[ $name ] = $classified['value'];
			} elseif ( 'complex' === $classified['place'] ) {
				$complex[ $name ] = $classified['value'];
			} elseif ( 'serialized' === $classified['place'] ) {
				$this->notes[] = array(
					'type'    => 'warning',
					/* translators: %s: option name. */
					'message' => sprintf( __( '%s still looks like a serialized PHP string, so it was left out.', 'rh-blueprint-builder' ), $name ),
				);
			}
		}

		if ( $scalars ) {
			$blueprint['siteOptions'] = $scalars;
		}

		$steps = $this->steps( $draft, $complex );
		if ( $steps ) {
			$blueprint['additionalStepsAfterExecution'] = $steps;
		}

		return $blueprint;
	}

	/**
	 * Playground username imported posts should belong to.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return string
	 */
	public function playground_username( $draft ) {
		$username = isset( $draft['login_username'] ) ? sanitize_user( (string) $draft['login_username'], true ) : '';

		return '' !== $username ? $username : 'admin';
	}

	/**
	 * Messages that stop the download.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array<int, array{type: string, message: string}>
	 */
	private function blocking_messages( $draft ) {
		$messages = array();

		if ( ! empty( $draft['gallery'] ) ) {
			if ( '' === trim( (string) $draft['meta_name'] ) || '' === trim( (string) $draft['meta_author'] ) ) {
				$messages[] = array(
					'type'    => 'error',
					'message' => __( 'Gallery metadata is on, but the name or GitHub username is missing. Fill in both, or turn gallery metadata off, before downloading.', 'rh-blueprint-builder' ),
				);
			}
		}

		if ( ! empty( $draft['content'] ) && ! $this->is_https_url( isset( $draft['wxr_url'] ) ? (string) $draft['wxr_url'] : '' ) ) {
			$messages[] = array(
				'type'    => 'error',
				'message' => __( 'Content import is on, but the WXR address is missing or is not an HTTPS URL.', 'rh-blueprint-builder' ),
			);
		}

		return $messages;
	}

	/**
	 * Whether any message blocks the download.
	 *
	 * @param array<int, array{type: string, message: string}> $messages Review messages.
	 * @return bool
	 */
	private function is_blocked( $messages ) {
		foreach ( $messages as $message ) {
			if ( 'error' === $message['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gallery metadata, or null when it should be omitted.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array<string, mixed>|null
	 */
	private function meta( $draft ) {
		if ( empty( $draft['gallery'] ) ) {
			return null;
		}

		$name   = trim( (string) $draft['meta_name'] );
		$author = trim( (string) $draft['meta_author'] );
		if ( '' === $name || '' === $author ) {
			return null;
		}

		$meta = array(
			'name'    => $name,
			'authors' => array( $author ),
		);
		$description = trim( (string) $draft['meta_description'] );
		if ( '' !== $description ) {
			$meta['description'] = $description;
		}

		return $meta;
	}

	/**
	 * Allowed PHP version from the draft.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return string
	 */
	private function php_version( $draft ) {
		$version = isset( $draft['php_version'] ) ? (string) $draft['php_version'] : '';
		if ( ! in_array( $version, $this->detector->allowed_php_versions(), true ) ) {
			return $this->detector->detected_php_version();
		}

		return $version;
	}

	/**
	 * latest, the site's major.minor, or nightly.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return string
	 */
	private function wordpress_version( $draft ) {
		if ( isset( $draft['wp_version'] ) && 'site' === $draft['wp_version'] ) {
			return $this->detector->site_wordpress_version()['id'];
		}

		return 'latest';
	}

	/**
	 * Landing path, or the site default when the saved value is unsafe.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return string
	 */
	private function landing_page( $draft ) {
		$path = isset( $draft['landing_page'] ) ? (string) $draft['landing_page'] : '';
		if ( $this->is_safe_path( $path ) ) {
			return $path;
		}

		return $this->detector->default_landing_page();
	}

	/**
	 * Login value. True means the Playground admin user and password.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return bool|array{username: string, password: string}
	 */
	private function login_value( $draft ) {
		if ( empty( $draft['login'] ) ) {
			return false;
		}

		$username = $this->playground_username( $draft );
		$password = isset( $draft['login_password'] ) && '' !== (string) $draft['login_password'] ? (string) $draft['login_password'] : 'password';

		if ( 'admin' === $username && 'password' === $password ) {
			return true;
		}

		return array(
			'username' => $username,
			'password' => $password,
		);
	}

	/**
	 * Add activeTheme and, when needed, the parent in themes.
	 *
	 * @param array<string, mixed> $blueprint Blueprint being built.
	 * @return void
	 */
	private function add_theme( &$blueprint ) {
		$theme = $this->detector->theme_report();

		if ( true === $theme['active_on_org'] && '' !== $theme['stylesheet_slug'] ) {
			$blueprint['activeTheme'] = $theme['stylesheet_slug'];
		} elseif ( null === $theme['active_on_org'] ) {
			$this->notes[] = array(
				'type'    => 'warning',
				/* translators: %s: theme name. */
				'message' => sprintf( __( 'WordPress.org could not be reached for %s, so the active theme was left out.', 'rh-blueprint-builder' ), $theme['name'] ),
			);
		} else {
			$this->notes[] = array(
				'type'    => 'warning',
				/* translators: %s: theme name. */
				'message' => sprintf( __( '%s is not on WordPress.org, so it was left out.', 'rh-blueprint-builder' ), $theme['name'] ),
			);
		}

		if ( empty( $theme['is_child'] ) ) {
			return;
		}

		if ( true === $theme['parent_on_org'] && '' !== $theme['template_slug'] ) {
			$blueprint['themes'] = array( $theme['template_slug'] );
			return;
		}

		if ( null === $theme['parent_on_org'] ) {
			$this->notes[] = array(
				'type'    => 'warning',
				/* translators: %s: parent theme name. */
				'message' => sprintf( __( 'WordPress.org could not be reached for the parent theme %s, so that parent was left out.', 'rh-blueprint-builder' ), $theme['parent_name'] ),
			);
			return;
		}

		$this->notes[] = array(
			'type'    => 'warning',
			/* translators: %s: parent theme name. */
			'message' => sprintf( __( 'The parent theme %s is not on WordPress.org, so it was left out.', 'rh-blueprint-builder' ), $theme['parent_name'] ),
		);
	}

	/**
	 * Add selected WordPress.org plugins.
	 *
	 * Slugs install and activate. There is no per-plugin activation flag.
	 *
	 * @param array<string, mixed> $blueprint Blueprint being built.
	 * @param array<string, mixed> $draft     Saved wizard answers.
	 * @return void
	 */
	private function add_plugins( &$blueprint, $draft ) {
		$slugs = $this->detector->selected_plugin_slugs( $draft );
		if ( $slugs ) {
			$blueprint['plugins'] = array_values( $slugs );
		}

		$report = $this->detector->plugins_report();
		if ( ! empty( $report['skipped'] ) ) {
			$this->notes[] = array(
				'type'    => 'info',
				'message' => __( 'Plugins that are not on WordPress.org, or that could not be confirmed there, are left out.', 'rh-blueprint-builder' ),
			);
		}
	}

	/**
	 * Place a stored option in siteOptions, the final step, or nowhere.
	 *
	 * Strings, numbers, and booleans are scalars. Arrays and objects wait
	 * until after plugins activate. Serialized strings are never written.
	 *
	 * @param string $name Option name.
	 * @return array{place: string, value: mixed}
	 */
	private function classify_option( $name ) {
		if ( $this->detector->is_denied_option( $name ) || ! $this->detector->option_exists( $name ) ) {
			return array(
				'place' => 'skip',
				'value' => null,
			);
		}

		$value = get_option( $name );

		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				return array(
					'place' => 'serialized',
					'value' => null,
				);
			}
			return array(
				'place' => 'scalar',
				'value' => $value,
			);
		}

		if ( is_bool( $value ) || is_int( $value ) ) {
			return array(
				'place' => 'scalar',
				'value' => $value,
			);
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return array(
					'place' => 'skip',
					'value' => null,
				);
			}
			return array(
				'place' => 'scalar',
				'value' => $value,
			);
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			if ( ! is_string( $encoded ) ) {
				$this->notes[] = array(
					'type'    => 'warning',
					/* translators: %s: option name. */
					'message' => sprintf( __( '%s could not be converted to JSON, so it was left out.', 'rh-blueprint-builder' ), $name ),
				);
				return array(
					'place' => 'skip',
					'value' => null,
				);
			}
			$decoded = json_decode( $encoded, true );
			return array(
				'place' => 'complex',
				'value' => $decoded,
			);
		}

		return array(
			'place' => 'skip',
			'value' => null,
		);
	}

	/**
	 * Steps that run after plugins, themes, and scalar site options.
	 *
	 * @param array<string, mixed> $draft   Saved wizard answers.
	 * @param array<string, mixed> $complex Array and object options.
	 * @return array<int, array<string, mixed>>
	 */
	private function steps( $draft, $complex ) {
		$steps = array();

		if ( ! empty( $draft['content'] ) && $this->is_https_url( isset( $draft['wxr_url'] ) ? (string) $draft['wxr_url'] : '' ) ) {
			$steps[] = $this->import_step( $draft );
		}

		if ( ! empty( $draft['content'] ) ) {
			$front = $this->front_page_step();
			if ( $front ) {
				$steps[] = $front;
			}
		} elseif ( $this->detector->has_static_front_page() ) {
			$this->notes[] = array(
				'type'    => 'info',
				'message' => __( 'The front page cannot be resolved without the WXR import.', 'rh-blueprint-builder' ),
			);
		}

		foreach ( $this->complex_steps( $complex ) as $step ) {
			$steps[] = $step;
		}

		return $steps;
	}

	/**
	 * Content import. Comments are left at the schema default, which is off.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array<string, mixed>
	 */
	private function import_step( $draft ) {
		$username = $this->playground_username( $draft );
		$authors  = new \stdClass();
		foreach ( $this->detector->author_logins( ! empty( $draft['include_nonpublic'] ) ) as $login ) {
			$authors->{$login} = $username;
		}

		return array(
			'step'    => 'importContent',
			'content' => array(
				array(
					'type'                  => 'wxr',
					'source'                => (string) $draft['wxr_url'],
					'authorsMode'           => 'map',
					'authorsMap'            => $authors,
					'defaultAuthorUsername' => $username,
					'urlsMode'              => empty( $draft['rewrite_urls'] ) ? 'preserve' : 'rewrite',
					'staticAssets'          => empty( $draft['fetch_attachments'] ) ? 'hotlink' : 'fetch',
				),
			),
		);
	}

	/**
	 * Front-page lookup, or null when there is nothing to resolve.
	 *
	 * The PHP matches the required shape. The code is an inline file because
	 * Blueprint v2 does not accept a raw PHP string.
	 *
	 * @return array<string, mixed>|null
	 */
	private function front_page_step() {
		$paths = $this->detector->front_page_paths();
		if ( '' === $paths['front'] ) {
			if ( $this->detector->has_static_front_page() ) {
				$this->notes[] = array(
					'type'    => 'warning',
					'message' => __( 'This site is set to a static front page, but that page has no path, so the blueprint does not set one.', 'rh-blueprint-builder' ),
				);
			}
			return null;
		}

		$code = '<?php require \'/wordpress/wp-load.php\'; $page = get_page_by_path( \'' . $this->escape_php_single( $paths['front'] ) . '\', OBJECT, \'page\' ); if ( $page instanceof WP_Post ) { update_option( \'page_on_front\', (int) $page->ID ); update_option( \'show_on_front\', \'page\' ); }';

		if ( '' !== $paths['posts'] ) {
			$code .= ' $posts_page = get_page_by_path( \'' . $this->escape_php_single( $paths['posts'] ) . '\', OBJECT, \'page\' ); if ( $posts_page instanceof WP_Post ) { update_option( \'page_for_posts\', (int) $posts_page->ID ); }';
			$this->notes[] = array(
				'type'    => 'info',
				/* translators: 1: front page path, 2: posts page path. */
				'message' => sprintf( __( 'After the import, the blueprint sets the front page to %1$s and the posts page to %2$s.', 'rh-blueprint-builder' ), $paths['front'], $paths['posts'] ),
			);
		} else {
			$this->notes[] = array(
				'type'    => 'info',
				/* translators: %s: front page path. */
				'message' => sprintf( __( 'After the import, the blueprint sets the front page to %s.', 'rh-blueprint-builder' ), $paths['front'] ),
			);
		}

		return array(
			'step' => 'runPHP',
			'code' => array(
				'filename' => 'front-page.php',
				'content'  => $code,
			),
		);
	}

	/**
	 * One or more setSiteOptions steps for arrays and objects.
	 *
	 * A single option is never split. A new step starts only when the next
	 * whole option would make the current step enormous.
	 *
	 * @param array<string, mixed> $options Complex options.
	 * @return array<int, array<string, mixed>>
	 */
	private function complex_steps( $options ) {
		if ( ! $options ) {
			return array();
		}

		$limit   = 350000;
		$groups  = array();
		$current = array();
		$size    = 2;

		foreach ( $options as $name => $value ) {
			$encoded   = wp_json_encode( array( $name => $value ) );
			$item_size = is_string( $encoded ) ? strlen( $encoded ) : $limit;
			if ( $current && ( $size + $item_size ) > $limit ) {
				$groups[] = $current;
				$current  = array();
				$size     = 2;
			}
			$current[ $name ] = $value;
			$size            += $item_size;
		}

		if ( $current ) {
			$groups[] = $current;
		}

		$steps = array();
		foreach ( $groups as $group ) {
			$steps[] = array(
				'step'    => 'setSiteOptions',
				'options' => $group,
			);
		}

		return $steps;
	}

	/**
	 * Escape a path for a single-quoted PHP string.
	 *
	 * @param string $value Path from get_page_uri().
	 * @return string
	 */
	private function escape_php_single( $value ) {
		$value = str_replace( array( "\0", "\r", "\n" ), '', $value );

		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}

	/**
	 * Whether a landing path is a relative path without traversal.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private function is_safe_path( $path ) {
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) || false !== strpos( $path, '..' ) ) {
			return false;
		}

		return 1 === preg_match( '#^/[A-Za-z0-9_./~%-]*$#', $path );
	}

	/**
	 * Playground address that loads a hosted blueprint file.
	 *
	 * An empty string means the hosted address is missing, not HTTPS, or local.
	 * The address is for the review screen only and is not written into the JSON.
	 *
	 * @param string $blueprint_url Public HTTPS URL of blueprint.json.
	 * @return string
	 */
	public function playground_test_url( $blueprint_url ) {
		$blueprint_url = trim( (string) $blueprint_url );
		if ( ! $this->is_public_blueprint_url( $blueprint_url ) ) {
			return '';
		}

		return 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $blueprint_url );
	}

	/**
	 * Whether a blueprint file URL is an HTTPS address Playground can fetch.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_public_blueprint_url( $url ) {
		if ( ! $this->is_https_url( $url ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $host && ! $this->is_local_host( $host );
	}

	/**
	 * Whether a host is a local address Playground cannot load.
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	private function is_local_host( $host ) {
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$suffixes = array( '.local', '.localhost' );
		foreach ( $suffixes as $suffix ) {
			$length = strlen( $suffix );
			if ( strlen( $host ) >= $length && substr( $host, - $length ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the content URL is a public HTTPS URL.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_https_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}

		return false !== wp_http_validate_url( $url );
	}
}
