<?php
/**
 * Build Blueprint v1 and v2 documents.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns wizard answers into Blueprint v1 and v2 JSON.
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
	 * Option names whose values are still serialized strings.
	 *
	 * v2 leaves these out. v1 applies them with maybe_unserialize().
	 *
	 * @var array<int, string>
	 */
	private $serialized_omitted = array();

	/**
	 * Warnings that apply only to the v1 file.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	private $v1_notes = array();

	/**
	 * Whether shared notes should be recorded.
	 *
	 * The v1 build runs after the v2 build and would repeat them.
	 *
	 * @var bool
	 */
	private $mute_notes = false;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Site detector.
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Both blueprint documents, pretty JSON, and review messages.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array{v1: array<string, mixed>, v2: array<string, mixed>}
	 */
	public function review( $draft ) {
		$this->notes              = array();
		$this->serialized_omitted = array();
		$this->v1_notes           = array();
		$this->mute_notes         = false;
		$blueprint_v2             = $this->build( $draft );
		$shared                   = $this->notes;
		$blocking                 = $this->blocking_messages( $draft );
		$serialized               = $this->omitted_serialized_messages();

		$this->mute_notes = true;
		$blueprint_v1     = $this->build_v1( $draft );
		$this->mute_notes = false;

		return array(
			'v1' => $this->version_result( $blueprint_v1, array_merge( $blocking, $shared, $serialized, $this->v1_notes ) ),
			'v2' => $this->version_result( $blueprint_v2, array_merge( $blocking, $shared, $serialized ) ),
		);
	}

	/**
	 * Encode one blueprint and decide whether its download is blocked.
	 *
	 * @param array<string, mixed>                            $blueprint Blueprint document.
	 * @param array<int, array{type: string, message: string}> $messages  Review messages.
	 * @return array{blueprint: array<string, mixed>, json: string, messages: array<int, array{type: string, message: string}>, blocked: bool}
	 */
	private function version_result( $blueprint, $messages ) {
		$json    = wp_json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$blocked = $this->is_blocked( $messages );

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
	 * Warnings for values the v2 file cannot represent.
	 *
	 * The same values are written into the v1 runPHP step.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	private function omitted_serialized_messages() {
		$messages = array();
		foreach ( $this->serialized_omitted as $name ) {
			$messages[] = array(
				'type'    => 'warning',
				/* translators: %s: option name. */
				'message' => sprintf( __( '%s still looks like a serialized PHP string, so the v2 file leaves it out. The v1 file applies it with maybe_unserialize().', 'rh-blueprint-builder' ), $name ),
			);
		}

		return $messages;
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
		$this->serialized_omitted = array();
		$blueprint                = array(
			'$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
			'version' => 2,
		);

		$meta = $this->meta( $draft );
		if ( $meta ) {
			$blueprint['blueprintMeta'] = $meta;
		}

		$blueprint['phpVersion']         = $this->php_version( $draft );
		$blueprint['wordpressVersion']   = $this->wordpress_version( $draft );
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
				$this->serialized_omitted[] = $name;
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

		$meta        = array(
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
	 * until after plugins activate. A string that is still serialized is
	 * omitted from v2 and applied by the v1 runPHP step.
	 *
	 * @param string $name Option name.
	 * @return array{place: string, value: mixed, php?: string}
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
					'value' => $value,
					'php'   => $value,
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
			$php     = maybe_serialize( $value );
			$encoded = wp_json_encode( $value );
			if ( ! is_string( $php ) || ! is_string( $encoded ) ) {
				$this->push_note(
					'warning',
					sprintf(
						/* translators: %s: option name. */
						__( '%s could not be converted to JSON, so it was left out.', 'rh-blueprint-builder' ),
						$name
					)
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
				'php'   => $php,
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
			$this->push_note( 'info', __( 'The front page cannot be resolved without the WXR import.', 'rh-blueprint-builder' ) );
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
		$code = $this->front_page_php();
		if ( '' === $code ) {
			return null;
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
	 * PHP that resolves the front page by path.
	 *
	 * An empty string means there is no step to emit.
	 *
	 * @return string
	 */
	private function front_page_php() {
		$paths = $this->detector->front_page_paths();
		if ( '' === $paths['front'] ) {
			if ( $this->detector->has_static_front_page() ) {
				$this->push_note( 'warning', __( 'This site is set to a static front page, but that page has no path, so the blueprint does not set one.', 'rh-blueprint-builder' ) );
			}
			return '';
		}

		$code = '<?php require \'/wordpress/wp-load.php\'; $page = get_page_by_path( \'' . $this->escape_php_single( $paths['front'] ) . '\', OBJECT, \'page\' ); if ( $page instanceof WP_Post ) { update_option( \'page_on_front\', (int) $page->ID ); update_option( \'show_on_front\', \'page\' ); }';

		if ( '' !== $paths['posts'] ) {
			$code .= ' $posts_page = get_page_by_path( \'' . $this->escape_php_single( $paths['posts'] ) . '\', OBJECT, \'page\' ); if ( $posts_page instanceof WP_Post ) { update_option( \'page_for_posts\', (int) $posts_page->ID ); }';
			$this->push_note(
				'info',
				sprintf(
					/* translators: 1: front page path, 2: posts page path. */
					__( 'After the import, the blueprint sets the front page to %1$s and the posts page to %2$s.', 'rh-blueprint-builder' ),
					$paths['front'],
					$paths['posts']
				)
			);
		} else {
			$this->push_note(
				'info',
				sprintf(
					/* translators: %s: front page path. */
					__( 'After the import, the blueprint sets the front page to %s.', 'rh-blueprint-builder' ),
					$paths['front']
				)
			);
		}

		return $code;
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
	 * Build the Blueprint v1 array.
	 *
	 * Arrays, objects, and values that are still serialized strings are
	 * applied in a final runPHP step with maybe_unserialize(). They are
	 * never written into setSiteOptions.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array<string, mixed>
	 */
	private function build_v1( $draft ) {
		$blueprint = array(
			'preferredVersions' => array(
				'php' => $this->php_version( $draft ),
				'wp'  => $this->wordpress_version( $draft ),
			),
			'features'          => array(
				'networking' => ! empty( $draft['network_access'] ),
			),
			'landingPage'       => $this->landing_page( $draft ),
		);

		$steps = array();

		if ( ! empty( $draft['login'] ) ) {
			$password = isset( $draft['login_password'] ) && '' !== (string) $draft['login_password'] ? (string) $draft['login_password'] : 'password';
			$steps[]  = array(
				'step'     => 'login',
				'username' => $this->playground_username( $draft ),
				'password' => $password,
			);
		}

		$language = $this->detector->site_language();
		if ( '' !== $language ) {
			$steps[] = array(
				'step'     => 'setSiteLanguage',
				'language' => $language,
			);
		}

		$scalars = array();
		$php     = array();
		foreach ( $this->detector->selected_option_names( $draft ) as $name ) {
			$classified = $this->classify_option( $name );
			if ( 'scalar' === $classified['place'] ) {
				$scalars[ $name ] = $classified['value'];
				continue;
			}
			if ( 'complex' !== $classified['place'] && 'serialized' !== $classified['place'] ) {
				continue;
			}
			$literal = $this->php_serialized_literal( isset( $classified['php'] ) ? $classified['php'] : '' );
			if ( '' === $literal ) {
				$this->v1_notes[] = array(
					'type'    => 'warning',
					/* translators: %s: option name. */
					'message' => sprintf( __( '%s could not be written into the v1 PHP step, so it was left out.', 'rh-blueprint-builder' ), $name ),
				);
				continue;
			}
			$php[ $name ] = $literal;
		}

		if ( $scalars ) {
			$steps[] = array(
				'step'    => 'setSiteOptions',
				'options' => $scalars,
			);
		}

		foreach ( $this->v1_theme_steps() as $step ) {
			$steps[] = $step;
		}

		foreach ( $this->detector->selected_plugin_slugs( $draft ) as $slug ) {
			$steps[] = array(
				'step'       => 'installPlugin',
				'pluginData' => array(
					'resource' => 'wordpress.org/plugins',
					'slug'     => $slug,
				),
				'options'    => array(
					'activate' => true,
				),
			);
		}

		if ( ! empty( $draft['content'] ) && $this->is_https_url( isset( $draft['wxr_url'] ) ? (string) $draft['wxr_url'] : '' ) ) {
			$steps[] = $this->import_wxr_step( $draft );
		}

		if ( ! empty( $draft['content'] ) ) {
			$code = $this->front_page_php();
			if ( '' !== $code ) {
				$steps[] = array(
					'step' => 'runPHP',
					'code' => $code,
				);
			}
		}

		foreach ( $this->v1_unserialize_steps( $php ) as $step ) {
			$steps[] = $step;
		}

		if ( $steps ) {
			$blueprint['steps'] = $steps;
		}

		return $blueprint;
	}

	/**
	 * Theme install steps. A parent is installed and not activated.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function v1_theme_steps() {
		$theme = $this->detector->theme_report();
		$steps = array();

		if ( ! empty( $theme['is_child'] ) && true === $theme['parent_on_org'] && '' !== $theme['template_slug'] ) {
			$steps[] = $this->install_theme_step( $theme['template_slug'], false );
		}

		if ( true === $theme['active_on_org'] && '' !== $theme['stylesheet_slug'] ) {
			$steps[] = $this->install_theme_step( $theme['stylesheet_slug'], true );
		}

		return $steps;
	}

	/**
	 * installTheme step using themeData.
	 *
	 * @param string $slug     WordPress.org theme slug.
	 * @param bool   $activate Whether to activate the theme.
	 * @return array<string, mixed>
	 */
	private function install_theme_step( $slug, $activate ) {
		return array(
			'step'      => 'installTheme',
			'themeData' => array(
				'resource' => 'wordpress.org/themes',
				'slug'     => $slug,
			),
			'options'   => array(
				'activate' => $activate,
			),
		);
	}

	/**
	 * v1 content import.
	 *
	 * @param array<string, mixed> $draft Saved wizard answers.
	 * @return array<string, mixed>
	 */
	private function import_wxr_step( $draft ) {
		$username = $this->playground_username( $draft );
		$authors  = new \stdClass();
		foreach ( $this->detector->author_logins( ! empty( $draft['include_nonpublic'] ) ) as $login ) {
			$authors->{$login} = $username;
		}

		return array(
			'step'                  => 'importWxr',
			'file'                  => array(
				'resource' => 'url',
				'url'      => (string) $draft['wxr_url'],
			),
			'rewriteUrls'           => ! empty( $draft['rewrite_urls'] ),
			'fetchAttachments'      => ! empty( $draft['fetch_attachments'] ),
			'authorsMap'            => $authors,
			'defaultAuthorUsername' => $username,
		);
	}

	/**
	 * runPHP steps that unserialize option values before saving them.
	 *
	 * One option is never split. A new step starts only when the next whole
	 * option would make the current step enormous.
	 *
	 * @param array<string, string> $options Option name to serialized payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function v1_unserialize_steps( $options ) {
		if ( ! $options ) {
			return array();
		}

		$limit    = 350000;
		$preamble = '<?php require \'/wordpress/wp-load.php\';';
		$groups   = array();
		$current  = array();
		$size     = strlen( $preamble ) + 3;

		foreach ( $options as $name => $payload ) {
			$statement = ' update_option( \'' . $this->escape_php_literal( $name ) . '\', maybe_unserialize( \'' . $this->escape_php_literal( $payload ) . '\' ) );';
			$item_size = strlen( $statement );
			if ( $current && ( $size + $item_size ) > $limit ) {
				$groups[] = $current;
				$current  = array();
				$size     = strlen( $preamble ) + 3;
			}
			$current[] = $statement;
			$size     += $item_size;
		}

		if ( $current ) {
			$groups[] = $current;
		}

		$steps = array();
		foreach ( $groups as $statements ) {
			$steps[] = array(
				'step' => 'runPHP',
				'code' => $preamble . implode( '', $statements ) . ' ?>',
			);
		}

		return $steps;
	}

	/**
	 * Serialized payload safe to embed in a single-quoted PHP string.
	 *
	 * A null byte cannot be represented in that string, so the option is skipped.
	 *
	 * @param mixed $payload Candidate serialized string.
	 * @return string
	 */
	private function php_serialized_literal( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload || false !== strpos( $payload, "\0" ) ) {
			return '';
		}

		return $payload;
	}

	/**
	 * Record a review note unless this pass is muted.
	 *
	 * @param string $type    info, warning, or error.
	 * @param string $message Note text.
	 * @return void
	 */
	private function push_note( $type, $message ) {
		if ( $this->mute_notes ) {
			return;
		}

		$this->notes[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Escape a value for a single-quoted PHP string.
	 *
	 * Unlike the path helper, this keeps newlines because a serialized
	 * payload can contain them and its length prefixes must stay intact.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function escape_php_literal( $value ) {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
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
