<?php
/**
 * Wizard screens and draft user meta.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools screen that collects a blueprint and serves the downloads.
 */
class Admin {

	const DRAFT_META = 'rh_blueprint_builder_draft';

	/**
	 * Site detector.
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * Blueprint builder.
	 *
	 * @var Blueprint
	 */
	private $blueprint;

	/**
	 * WXR exporter.
	 *
	 * @var Wxr
	 */
	private $wxr;

	/**
	 * Constructor.
	 *
	 * @param Detector  $detector  Site detector.
	 * @param Blueprint $blueprint Blueprint builder.
	 * @param Wxr       $wxr       WXR exporter.
	 */
	public function __construct( Detector $detector, Blueprint $blueprint, Wxr $wxr ) {
		$this->detector  = $detector;
		$this->blueprint = $blueprint;
		$this->wxr       = $wxr;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_rh_blueprint_builder_download_blueprint', array( $this, 'download_blueprint' ) );
		add_action( 'admin_post_rh_blueprint_builder_download_wxr', array( $this, 'download_wxr' ) );
		add_action( 'wp_ajax_rh_blueprint_builder_save_draft', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_rh_blueprint_builder_search_options', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_rh_blueprint_builder_preview', array( $this, 'ajax_preview' ) );
	}

	/**
	 * Add the single-site Tools page.
	 *
	 * @return void
	 */
	public function register_menu() {
		$hook = add_management_page(
			__( 'RH Blueprint Builder', 'rh-blueprint-builder' ),
			__( 'RH Blueprint Builder', 'rh-blueprint-builder' ),
			'manage_options',
			'rh-blueprint-builder',
			array( $this, 'render_page' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_save' ) );
		}
	}

	/**
	 * Add the same page in network admin so the limitation is visible there.
	 *
	 * @return void
	 */
	public function register_network_menu() {
		add_management_page(
			__( 'RH Blueprint Builder', 'rh-blueprint-builder' ),
			__( 'RH Blueprint Builder', 'rh-blueprint-builder' ),
			'manage_network',
			'rh-blueprint-builder',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Scripts and styles for the wizard.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'tools_page_rh-blueprint-builder' !== $hook || is_multisite() ) {
			return;
		}

		wp_enqueue_style(
			'rh-blueprint-builder-wizard',
			plugins_url( 'assets/wizard.css', RH_BLUEPRINT_BUILDER_FILE ),
			array(),
			RH_BLUEPRINT_BUILDER_VERSION
		);
		wp_enqueue_script(
			'rh-blueprint-builder-wizard',
			plugins_url( 'assets/wizard.js', RH_BLUEPRINT_BUILDER_FILE ),
			array(),
			RH_BLUEPRINT_BUILDER_VERSION,
			true
		);
		wp_localize_script(
			'rh-blueprint-builder-wizard',
			'rhBlueprintBuilder',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'adminPost' => admin_url( 'admin-post.php' ),
				'pageUrl'   => $this->page_url(),
				'step'      => $this->current_step(),
				'actions'   => array(
					'save'    => 'rh_blueprint_builder_save_draft',
					'search'  => 'rh_blueprint_builder_search_options',
					'preview' => 'rh_blueprint_builder_preview',
				),
				'strings'   => array(
					'searching'     => __( 'Searching…', 'rh-blueprint-builder' ),
					'noResults'     => __( 'No options matched that search.', 'rh-blueprint-builder' ),
					'alreadyListed' => __( 'Already listed', 'rh-blueprint-builder' ),
					'add'           => __( 'Add', 'rh-blueprint-builder' ),
					'saved'         => __( 'Draft saved.', 'rh-blueprint-builder' ),
					'saveFailed'    => __( 'The draft could not be saved. Reload the page and try again.', 'rh-blueprint-builder' ),
					'previewFailed' => __( 'The preview could not be refreshed.', 'rh-blueprint-builder' ),
					'likelySecret'  => __( 'Likely secret', 'rh-blueprint-builder' ),
				),
			)
		);
	}

	/**
	 * Save a normal form post and return to the wizard.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( empty( $_POST['rh_bb_form'] ) ) {
			return;
		}

		$this->assert_request();
		$this->save_posted_draft();
		wp_safe_redirect( add_query_arg( 'saved', '1', $this->page_url( $this->posted_step() ) ) );
		exit;
	}

	/**
	 * Render the wizard, or the multisite notice.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( is_multisite() || is_network_admin() ) {
			$this->render_multisite_notice();
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to build a blueprint on this site.', 'rh-blueprint-builder' ),
				esc_html__( 'Permission denied', 'rh-blueprint-builder' ),
				array( 'response' => 403 )
			);
		}

		$draft  = $this->get_draft();
		$step   = $this->current_step();
		$review = $this->blueprint->review( $draft );
		?>
		<div class="wrap rh-bb">
			<h1><?php esc_html_e( 'RH Blueprint Builder', 'rh-blueprint-builder' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Draft saved.', 'rh-blueprint-builder' ); ?></p></div>
			<?php endif; ?>
			<noscript><style>.rh-bb-panel[hidden], .rh-bb-conditional[hidden] { display: block !important; }</style></noscript>
			<form id="rh-bb-form" method="post" action="<?php echo esc_url( $this->page_url( $step ) ); ?>">
				<?php wp_nonce_field( 'rh_blueprint_builder_wizard', 'rh_blueprint_builder_nonce' ); ?>
				<input type="hidden" name="rh_bb_form" value="1" />
				<input type="hidden" name="rh_bb_step" value="<?php echo esc_attr( $step ); ?>" />
				<button type="submit" name="rh_bb_save" value="1" hidden><?php esc_html_e( 'Save draft', 'rh-blueprint-builder' ); ?></button>

				<ol class="rh-bb-nav">
					<?php foreach ( $this->steps() as $id => $label ) : ?>
						<li>
							<button type="button" class="rh-bb-jump<?php echo $step === $id ? ' is-current' : ''; ?>" data-step="<?php echo esc_attr( $id ); ?>" <?php echo $step === $id ? 'aria-current="step"' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</button>
						</li>
					<?php endforeach; ?>
				</ol>

				<?php
				$this->render_setup( $draft, $step );
				$this->render_theme( $step );
				$this->render_plugins( $draft, $step );
				$this->render_options( $draft, $step );
				$this->render_content( $draft, $step );
				$this->render_review( $review, $draft, $step );
				?>

				<p class="rh-bb-actions">
					<button type="button" class="button rh-bb-prev"><?php esc_html_e( 'Back', 'rh-blueprint-builder' ); ?></button>
					<button type="button" class="button button-primary rh-bb-next"><?php esc_html_e( 'Next', 'rh-blueprint-builder' ); ?></button>
					<button type="submit" class="button" name="rh_bb_save" value="1"><?php esc_html_e( 'Save draft', 'rh-blueprint-builder' ); ?></button>
					<span class="rh-bb-status" role="status"></span>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Explain that the wizard does not run on multisite.
	 *
	 * @return void
	 */
	private function render_multisite_notice() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RH Blueprint Builder', 'rh-blueprint-builder' ); ?></h1>
			<div class="notice notice-info"><p><?php esc_html_e( 'RH Blueprint Builder runs on a single site only. The wizard is turned off for multisite and network admin.', 'rh-blueprint-builder' ); ?></p></div>
		</div>
		<?php
	}

	/**
	 * Site setup step.
	 *
	 * @param array<string, mixed> $draft Saved answers.
	 * @param string               $step  Current step.
	 * @return void
	 */
	private function render_setup( $draft, $step ) {
		$language = $this->detector->site_language();
		$wp       = $this->detector->site_wordpress_version();
		?>
		<section id="rh-bb-panel-setup" class="rh-bb-panel<?php echo 'setup' === $step ? ' is-active' : ''; ?>" data-step="setup" tabindex="-1" <?php echo 'setup' === $step ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Site setup', 'rh-blueprint-builder' ); ?></h2>
			<p><?php esc_html_e( 'This builds a WordPress Playground demo of the current site. Playground runs that demo in the browser. Nothing here changes this site, and the plugin does not open a public link to it.', 'rh-blueprint-builder' ); ?></p>
			<p><?php esc_html_e( 'You choose what to copy: gallery text, versions, the theme, WordPress.org plugins, selected settings, and content you host yourself. The file leaves out secrets, mail passwords, server addresses, transients, and plugins or themes that are not on WordPress.org.', 'rh-blueprint-builder' ); ?></p>

			<p>
				<label>
					<input type="checkbox" name="rh_bb_gallery" value="1" data-toggle="rh-bb-gallery-fields" <?php checked( ! empty( $draft['gallery'] ) ); ?> />
					<?php esc_html_e( 'Include gallery metadata', 'rh-blueprint-builder' ); ?>
				</label>
			</p>
			<p class="description"><?php esc_html_e( 'Leave this off unless you will publish the blueprint in a gallery. The name and GitHub username are both required before download. If either one is missing, the metadata is left out of the file.', 'rh-blueprint-builder' ); ?></p>
			<div id="rh-bb-gallery-fields" class="rh-bb-conditional" <?php echo empty( $draft['gallery'] ) ? 'hidden' : ''; ?>>
				<p>
					<label for="rh-bb-meta-name"><?php esc_html_e( 'Name', 'rh-blueprint-builder' ); ?></label><br />
					<input type="text" class="regular-text" id="rh-bb-meta-name" name="rh_bb_meta_name" value="<?php echo esc_attr( $draft['meta_name'] ); ?>" />
				</p>
				<p>
					<label for="rh-bb-meta-author"><?php esc_html_e( 'GitHub username', 'rh-blueprint-builder' ); ?></label><br />
					<input type="text" class="regular-text" id="rh-bb-meta-author" name="rh_bb_meta_author" value="<?php echo esc_attr( $draft['meta_author'] ); ?>" />
				</p>
				<p>
					<label for="rh-bb-meta-description"><?php esc_html_e( 'Description (optional)', 'rh-blueprint-builder' ); ?></label><br />
					<textarea class="large-text" rows="3" id="rh-bb-meta-description" name="rh_bb_meta_description"><?php echo esc_textarea( $draft['meta_description'] ); ?></textarea>
				</p>
			</div>

			<p>
				<label for="rh-bb-php-version"><?php esc_html_e( 'PHP version', 'rh-blueprint-builder' ); ?></label><br />
				<select id="rh-bb-php-version" name="rh_bb_php_version">
					<?php foreach ( $this->detector->allowed_php_versions() as $version ) : ?>
						<option value="<?php echo esc_attr( $version ); ?>" <?php selected( $draft['php_version'], $version ); ?>><?php echo esc_html( $version ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="description"><?php esc_html_e( 'Prefilled from this server. PHP 7.2 and 7.3 are saved as 7.4, and anything newer than 8.5 is saved as latest.', 'rh-blueprint-builder' ); ?></p>

			<fieldset>
				<legend><?php esc_html_e( 'WordPress version', 'rh-blueprint-builder' ); ?></legend>
				<p>
					<label>
						<input type="radio" name="rh_bb_wp_version" value="latest" <?php checked( $draft['wp_version'], 'latest' ); ?> />
						<?php esc_html_e( 'Latest', 'rh-blueprint-builder' ); ?>
					</label><br />
					<label>
						<input type="radio" name="rh_bb_wp_version" value="site" <?php checked( $draft['wp_version'], 'site' ); ?> />
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: this site's WordPress version, or Nightly. */
								__( 'This site (%s)', 'rh-blueprint-builder' ),
								$wp['label']
							)
						);
						?>
					</label>
				</p>
			</fieldset>

			<p>
				<label>
					<input type="checkbox" name="rh_bb_network_access" value="1" <?php checked( ! empty( $draft['network_access'] ) ); ?> />
					<?php esc_html_e( 'Allow network access', 'rh-blueprint-builder' ); ?>
				</label>
			</p>
			<p class="description"><?php esc_html_e( 'Playground blocks network access unless the blueprint turns it on. Plugin installs and the hosted content file need it.', 'rh-blueprint-builder' ); ?></p>

			<p>
				<label for="rh-bb-landing"><?php esc_html_e( 'Landing page', 'rh-blueprint-builder' ); ?></label><br />
				<input type="text" class="regular-text" id="rh-bb-landing" name="rh_bb_landing_page" value="<?php echo esc_attr( $draft['landing_page'] ); ?>" />
			</p>
			<p class="description"><?php esc_html_e( 'Defaults to the front of the site when this site has a static front page, and to /wp-admin/ otherwise.', 'rh-blueprint-builder' ); ?></p>

			<p>
				<label>
					<input type="checkbox" name="rh_bb_login" value="1" data-toggle="rh-bb-login-fields" <?php checked( ! empty( $draft['login'] ) ); ?> />
					<?php esc_html_e( 'Log in when Playground opens', 'rh-blueprint-builder' ); ?>
				</label>
			</p>
			<div id="rh-bb-login-fields" class="rh-bb-conditional" <?php echo empty( $draft['login'] ) ? 'hidden' : ''; ?>>
				<p>
					<label for="rh-bb-login-username"><?php esc_html_e( 'Playground username', 'rh-blueprint-builder' ); ?></label><br />
					<input type="text" class="regular-text" id="rh-bb-login-username" name="rh_bb_login_username" value="<?php echo esc_attr( $draft['login_username'] ); ?>" />
				</p>
				<p>
					<label for="rh-bb-login-password"><?php esc_html_e( 'Playground password', 'rh-blueprint-builder' ); ?></label><br />
					<input type="text" class="regular-text" id="rh-bb-login-password" name="rh_bb_login_password" value="<?php echo esc_attr( $draft['login_password'] ); ?>" autocomplete="off" />
				</p>
				<p class="description"><?php esc_html_e( 'The default logs in as admin with Playground’s password. That password belongs to the demo, not to this site. A different username is written together with the password because the blueprint format requires both.', 'rh-blueprint-builder' ); ?></p>
			</div>

			<?php if ( '' === $language ) : ?>
				<p class="description"><?php esc_html_e( 'This site uses English (United States), so the blueprint does not set a site language.', 'rh-blueprint-builder' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: locale code, such as fr_FR. */
							__( 'The blueprint sets the site language to %s.', 'rh-blueprint-builder' ),
							$language
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Theme step.
	 *
	 * @param string $step Current step.
	 * @return void
	 */
	private function render_theme( $step ) {
		$theme = $this->detector->theme_report();
		?>
		<section id="rh-bb-panel-theme" class="rh-bb-panel<?php echo 'theme' === $step ? ' is-active' : ''; ?>" data-step="theme" tabindex="-1" <?php echo 'theme' === $step ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Theme', 'rh-blueprint-builder' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: theme name, 2: theme directory. */
						__( 'Active theme: %1$s (%2$s).', 'rh-blueprint-builder' ),
						$theme['name'],
						$theme['stylesheet']
					)
				);
				?>
			</p>
			<?php if ( true === $theme['active_on_org'] ) : ?>
				<p><?php esc_html_e( 'It is on WordPress.org, so the blueprint installs and activates it.', 'rh-blueprint-builder' ); ?></p>
			<?php elseif ( null === $theme['active_on_org'] ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WordPress.org could not be reached, so the active theme will be left out.', 'rh-blueprint-builder' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'This theme is not on WordPress.org, so it will be left out.', 'rh-blueprint-builder' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $theme['is_child'] ) ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: parent theme name, 2: parent directory. */
							__( 'Parent theme: %1$s (%2$s).', 'rh-blueprint-builder' ),
							$theme['parent_name'],
							$theme['template']
						)
					);
					?>
				</p>
				<?php if ( true === $theme['parent_on_org'] ) : ?>
					<p><?php esc_html_e( 'The parent is on WordPress.org. It will be installed and not activated.', 'rh-blueprint-builder' ); ?></p>
				<?php elseif ( null === $theme['parent_on_org'] ) : ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'WordPress.org could not be reached for the parent theme, so that parent will be left out.', 'rh-blueprint-builder' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'The parent theme is not on WordPress.org, so it will be left out.', 'rh-blueprint-builder' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Plugins step.
	 *
	 * @param array<string, mixed> $draft Saved answers.
	 * @param string               $step  Current step.
	 * @return void
	 */
	private function render_plugins( $draft, $step ) {
		$report = $this->detector->plugins_report();
		?>
		<section id="rh-bb-panel-plugins" class="rh-bb-panel<?php echo 'plugins' === $step ? ' is-active' : ''; ?>" data-step="plugins" tabindex="-1" <?php echo 'plugins' === $step ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Plugins', 'rh-blueprint-builder' ); ?></h2>
			<p><?php esc_html_e( 'Selected plugins are installed from WordPress.org and activated. Active plugins start checked. Inactive WordPress.org plugins start unchecked. This plugin is hidden. Plugins that are not on WordPress.org are skipped, with no install entry.', 'rh-blueprint-builder' ); ?></p>
			<input type="hidden" name="rh_bb_has_plugins" value="1" />
			<?php if ( ! $report['installable'] ) : ?>
				<p><?php esc_html_e( 'No WordPress.org plugins were found to install.', 'rh-blueprint-builder' ); ?></p>
			<?php else : ?>
				<ul class="rh-bb-choices">
					<?php foreach ( $report['installable'] as $plugin ) : ?>
						<?php $checked = $this->plugin_is_checked( $plugin, $draft ); ?>
						<li>
							<label>
								<input type="checkbox" name="rh_bb_plugins[]" value="<?php echo esc_attr( $plugin['slug'] ); ?>" <?php checked( $checked ); ?> />
								<?php echo esc_html( $plugin['name'] ); ?>
								<code><?php echo esc_html( $plugin['slug'] ); ?></code>
								<?php if ( ! empty( $plugin['active'] ) ) : ?>
									<span class="rh-bb-flag"><?php esc_html_e( 'Active', 'rh-blueprint-builder' ); ?></span>
								<?php endif; ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $report['skipped'] ) : ?>
				<h3><?php esc_html_e( 'Skipped', 'rh-blueprint-builder' ); ?></h3>
				<ul class="rh-bb-skipped">
					<?php foreach ( $report['skipped'] as $plugin ) : ?>
						<li>
							<?php echo esc_html( $plugin['name'] ); ?>
							<code><?php echo esc_html( $plugin['slug'] ); ?></code>
							<?php if ( 'unconfirmed' === $plugin['reason'] ) : ?>
								<span><?php esc_html_e( 'WordPress.org could not be reached.', 'rh-blueprint-builder' ); ?></span>
							<?php else : ?>
								<span><?php esc_html_e( 'Not on WordPress.org.', 'rh-blueprint-builder' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Options step.
	 *
	 * @param array<string, mixed> $draft Saved answers.
	 * @param string               $step  Current step.
	 * @return void
	 */
	private function render_options( $draft, $step ) {
		$groups    = $this->detector->option_groups(
			$this->detector->selected_plugin_slugs( $draft ),
			$draft['options_added'],
			$draft['options_hidden']
		);
		$saw_added = false;
		?>
		<section id="rh-bb-panel-options" class="rh-bb-panel<?php echo 'options' === $step ? ' is-active' : ''; ?>" data-step="options" tabindex="-1" <?php echo 'options' === $step ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Options', 'rh-blueprint-builder' ); ?></h2>
			<p><?php esc_html_e( 'Plain values are written with the blueprint. Arrays and objects are applied in a final step, after plugins activate, so an activation routine cannot replace them with defaults. Serialized values are shown as shortened JSON. Options whose names look like licenses, API keys, secrets, or tokens start unchecked.', 'rh-blueprint-builder' ); ?></p>
			<p><?php esc_html_e( 'Salts, mail server settings, site addresses, transients, updater locks, the front-page IDs, and this site’s language are never offered. The front page is set from the imported page path, and the language is set in site setup.', 'rh-blueprint-builder' ); ?></p>
			<input type="hidden" name="rh_bb_has_options" value="1" />
			<div id="rh-bb-hidden-options">
				<?php foreach ( $draft['options_hidden'] as $hidden ) : ?>
					<input type="hidden" name="rh_bb_options_hidden[]" value="<?php echo esc_attr( $hidden ); ?>" />
				<?php endforeach; ?>
			</div>

			<?php foreach ( $groups as $group ) : ?>
				<?php
				if ( 'added' === $group['id'] ) {
					$saw_added = true;
				}
				?>
				<div class="rh-bb-group" <?php echo 'added' === $group['id'] ? 'id="rh-bb-added-group"' : ''; ?>>
					<h3><?php echo esc_html( $group['label'] ); ?></h3>
					<?php if ( ! empty( $group['truncated'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Only the first 300 matching options are shown. Search if the one you need is missing.', 'rh-blueprint-builder' ); ?></p>
					<?php endif; ?>
					<div class="rh-bb-options" <?php echo 'added' === $group['id'] ? 'id="rh-bb-added-options"' : ''; ?>>
						<?php
						foreach ( $group['rows'] as $row ) {
							$this->render_option_row( $row, $this->detector->option_is_selected( $row['name'], $row['secret'], $draft ) );
						}
						?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $saw_added ) : ?>
				<div class="rh-bb-group" id="rh-bb-added-group" hidden>
					<h3><?php esc_html_e( 'Added options', 'rh-blueprint-builder' ); ?></h3>
					<div class="rh-bb-options" id="rh-bb-added-options"></div>
				</div>
			<?php endif; ?>

			<div class="rh-bb-search">
				<label for="rh-bb-option-search"><?php esc_html_e( 'Add option', 'rh-blueprint-builder' ); ?></label>
				<input type="search" id="rh-bb-option-search" class="regular-text" />
				<button type="button" class="button" id="rh-bb-option-search-button"><?php esc_html_e( 'Search', 'rh-blueprint-builder' ); ?></button>
				<ul id="rh-bb-search-results"></ul>
			</div>

			<template id="rh-bb-option-template">
				<div class="rh-bb-option">
					<label>
						<input type="checkbox" name="rh_bb_options[]" value="" />
						<code class="rh-bb-option-name"></code>
						<span class="rh-bb-flag"><?php esc_html_e( 'Likely secret', 'rh-blueprint-builder' ); ?></span>
					</label>
					<p class="rh-bb-preview"><code></code></p>
					<button type="button" class="button-link rh-bb-remove-option"><?php esc_html_e( 'Remove', 'rh-blueprint-builder' ); ?></button>
					<input type="hidden" name="rh_bb_options_present[]" value="" />
					<input type="hidden" name="rh_bb_options_added[]" value="" />
				</div>
			</template>
		</section>
		<?php
	}

	/**
	 * One option row.
	 *
	 * @param array<string, mixed> $row     Option display data.
	 * @param bool                 $checked Whether it is included.
	 * @return void
	 */
	private function render_option_row( $row, $checked ) {
		$id = 'rh-bb-opt-' . substr( md5( $row['name'] ), 0, 12 );
		?>
		<div class="rh-bb-option<?php echo ! empty( $row['secret'] ) ? ' is-secret' : ''; ?>" data-option="<?php echo esc_attr( $row['name'] ); ?>">
			<label for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="rh_bb_options[]" value="<?php echo esc_attr( $row['name'] ); ?>" <?php checked( $checked ); ?> />
				<code class="rh-bb-option-name"><?php echo esc_html( $row['name'] ); ?></code>
				<?php if ( ! empty( $row['secret'] ) ) : ?>
					<span class="rh-bb-flag"><?php esc_html_e( 'Likely secret', 'rh-blueprint-builder' ); ?></span>
				<?php endif; ?>
			</label>
			<?php if ( '' !== $row['preview'] ) : ?>
				<p class="rh-bb-preview"><code><?php echo esc_html( $row['preview'] ); ?></code></p>
			<?php endif; ?>
			<button type="button" class="button-link rh-bb-remove-option"><?php esc_html_e( 'Remove', 'rh-blueprint-builder' ); ?></button>
			<input type="hidden" name="rh_bb_options_present[]" value="<?php echo esc_attr( $row['name'] ); ?>" />
			<?php if ( ! empty( $row['added'] ) ) : ?>
				<input type="hidden" name="rh_bb_options_added[]" value="<?php echo esc_attr( $row['name'] ); ?>" />
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Content step.
	 *
	 * @param array<string, mixed> $draft Saved answers.
	 * @param string               $step  Current step.
	 * @return void
	 */
	private function render_content( $draft, $step ) {
		?>
		<section id="rh-bb-panel-content" class="rh-bb-panel<?php echo 'content' === $step ? ' is-active' : ''; ?>" data-step="content" tabindex="-1" <?php echo 'content' === $step ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Content', 'rh-blueprint-builder' ); ?></h2>
			<p>
				<label>
					<input type="checkbox" name="rh_bb_content" value="1" data-toggle="rh-bb-content-fields" <?php checked( ! empty( $draft['content'] ) ); ?> />
					<?php esc_html_e( 'Import posts and pages', 'rh-blueprint-builder' ); ?>
				</label>
			</p>
			<p class="description"><?php esc_html_e( 'Content import is off until you turn it on. Turning it on clears Playground’s sample posts, pages, and comments before the import.', 'rh-blueprint-builder' ); ?></p>
			<div id="rh-bb-content-fields" class="rh-bb-conditional" <?php echo empty( $draft['content'] ) ? 'hidden' : ''; ?>>
				<p><?php esc_html_e( 'Download a WXR file of published posts and pages. It also includes customized templates, template parts, navigation, global styles, and synced patterns, plus font library entries when this site has them. The file is meant to be hosted in public. It can contain the full text of the exported content. Author email addresses are removed before download. Comments remain in the file, but the blueprint does not import them.', 'rh-blueprint-builder' ); ?></p>
				<p><?php esc_html_e( 'Very large sites can hit the server’s PHP time limit during export.', 'rh-blueprint-builder' ); ?></p>
				<p>
					<label>
						<input type="checkbox" name="rh_bb_include_nonpublic" value="1" <?php checked( ! empty( $draft['include_nonpublic'] ) ); ?> />
						<?php esc_html_e( 'Include drafts and private content', 'rh-blueprint-builder' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" class="button" id="rh-bb-wxr" name="action" value="rh_blueprint_builder_download_wxr" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php esc_html_e( 'Download WXR', 'rh-blueprint-builder' ); ?>
					</button>
				</p>
				<p><?php esc_html_e( 'Publish that file at a public HTTPS address, such as a GitHub file, then paste the address here.', 'rh-blueprint-builder' ); ?></p>
				<p>
					<label for="rh-bb-wxr-url"><?php esc_html_e( 'Public HTTPS URL of the WXR file', 'rh-blueprint-builder' ); ?></label><br />
					<input type="url" class="large-text" id="rh-bb-wxr-url" name="rh_bb_wxr_url" value="<?php echo esc_attr( $draft['wxr_url'] ); ?>" placeholder="https://" />
				</p>
				<p>
					<label>
						<input type="checkbox" name="rh_bb_rewrite_urls" value="1" <?php checked( ! empty( $draft['rewrite_urls'] ) ); ?> />
						<?php esc_html_e( 'Rewrite content URLs to the Playground site', 'rh-blueprint-builder' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="rh_bb_fetch_attachments" value="1" <?php checked( ! empty( $draft['fetch_attachments'] ) ); ?> />
						<?php esc_html_e( 'Fetch attachments', 'rh-blueprint-builder' ); ?>
					</label>
				</p>
				<p class="description"><?php esc_html_e( 'Attachments only work when Playground can download the original media files.', 'rh-blueprint-builder' ); ?></p>
				<p>
					<?php
					printf(
						/* translators: %s: Playground username. */
						esc_html__( 'Author usernames on this site are mapped to the Playground login username. Imported posts will belong to %s.', 'rh-blueprint-builder' ),
						'<code id="rh-bb-playground-user">' . esc_html( $this->blueprint->playground_username( $draft ) ) . '</code>'
					);
					?>
				</p>
			</div>
		</section>
		<?php
	}

	/**
	 * Review step.
	 *
	 * @param array<string, mixed> $review Review payload.
	 * @param array<string, mixed> $draft  Saved answers.
	 * @param string               $step   Current step.
	 * @return void
	 */
	private function render_review( $review, $draft, $step ) {
		$placeholder = 'https://playground.wordpress.net/?blueprint-url=https://example.com/blueprint.json';
		$test_url    = $this->blueprint->playground_test_url( isset( $draft['blueprint_url'] ) ? (string) $draft['blueprint_url'] : '' );
		?>
		<section id="rh-bb-panel-review" class="rh-bb-panel<?php echo 'review' === $step ? ' is-active' : ''; ?>" data-step="review" tabindex="-1" <?php echo 'review' === $step ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Review', 'rh-blueprint-builder' ); ?></h2>
			<ul id="rh-bb-messages" class="rh-bb-messages">
				<?php foreach ( $review['messages'] as $message ) : ?>
					<li class="is-<?php echo esc_attr( $message['type'] ); ?>"><?php echo esc_html( $message['message'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<pre id="rh-bb-json" class="rh-bb-json"><?php echo esc_html( $review['json'] ); ?></pre>
			<p class="rh-bb-warning"><?php esc_html_e( 'This file can contain site text and plugin settings, including license or API values you checked. Read it before you commit it to a public repository.', 'rh-blueprint-builder' ); ?></p>
			<p>
				<button type="submit" class="button button-primary" id="rh-bb-download" name="action" value="rh_blueprint_builder_download_blueprint" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php disabled( ! empty( $review['blocked'] ) ); ?>>
					<?php esc_html_e( 'Download blueprint', 'rh-blueprint-builder' ); ?>
				</button>
			</p>
			<p><?php esc_html_e( 'To try the blueprint in Playground, host the downloaded JSON at a public HTTPS address, such as a GitHub raw URL. Playground cannot load localhost, and this plugin does not open a public URL on this site.', 'rh-blueprint-builder' ); ?></p>
			<p>
				<label for="rh-bb-blueprint-url"><?php esc_html_e( 'Public HTTPS URL of the blueprint file', 'rh-blueprint-builder' ); ?></label><br />
				<input type="url" class="large-text" id="rh-bb-blueprint-url" name="rh_bb_blueprint_url" value="<?php echo esc_attr( $draft['blueprint_url'] ); ?>" placeholder="https://" />
			</p>
			<p><?php esc_html_e( 'Open the hosted file in Playground with this address:', 'rh-blueprint-builder' ); ?></p>
			<p id="rh-bb-playground-test" class="rh-bb-playground-test" data-placeholder="<?php echo esc_attr( $placeholder ); ?>">
				<?php if ( '' !== $test_url ) : ?>
					<a href="<?php echo esc_url( $test_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $test_url ); ?></a>
				<?php else : ?>
					<code><?php echo esc_html( $placeholder ); ?></code>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}

	/**
	 * Save the draft from an AJAX request.
	 *
	 * @return void
	 */
	public function ajax_save() {
		$this->assert_ajax();
		$this->save_posted_draft();
		wp_send_json_success(
			array(
				'message' => __( 'Draft saved.', 'rh-blueprint-builder' ),
			)
		);
	}

	/**
	 * Search options.
	 *
	 * @return void
	 */
	public function ajax_search() {
		$this->assert_ajax();
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		wp_send_json_success(
			array(
				'results' => $this->detector->search_options( $term ),
			)
		);
	}

	/**
	 * Save the draft and return the review JSON.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		$this->assert_ajax();
		$draft  = $this->save_posted_draft();
		$review = $this->blueprint->review( $draft );
		wp_send_json_success(
			array(
				'json'     => $review['json'],
				'messages' => $review['messages'],
				'blocked'  => $review['blocked'],
			)
		);
	}

	/**
	 * Download the blueprint file.
	 *
	 * @return void
	 */
	public function download_blueprint() {
		$this->assert_request();
		$draft  = $this->save_posted_draft();
		$review = $this->blueprint->review( $draft );

		if ( ! empty( $review['blocked'] ) || '' === $review['json'] ) {
			$this->die_blocked( $review );
		}

		if ( ! headers_sent() ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="blueprint.json"' );
			header( 'Content-Length: ' . strlen( $review['json'] ) );
		}

		echo $review['json'];
		exit;
	}

	/**
	 * Download the WXR file.
	 *
	 * @return void
	 */
	public function download_wxr() {
		$this->assert_request();
		$draft = $this->save_posted_draft();
		$this->wxr->send( ! empty( $draft['include_nonpublic'] ) );
	}

	/**
	 * Stop when the blueprint is not ready to download.
	 *
	 * @param array<string, mixed> $review Review payload.
	 * @return void
	 */
	private function die_blocked( $review ) {
		$items = '';
		foreach ( $review['messages'] as $message ) {
			if ( 'error' !== $message['type'] ) {
				continue;
			}
			$items .= '<li>' . esc_html( $message['message'] ) . '</li>';
		}
		if ( '' === $items ) {
			$items = '<li>' . esc_html__( 'The blueprint is not ready to download.', 'rh-blueprint-builder' ) . '</li>';
		}

		wp_die(
			'<ul>' . $items . '</ul>',
			esc_html__( 'Blueprint not ready', 'rh-blueprint-builder' ),
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	/**
	 * Require a logged-in administrator on a single site.
	 *
	 * @return void
	 */
	private function assert_request() {
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
			wp_die(
				esc_html__( 'You do not have permission to build a blueprint on this site.', 'rh-blueprint-builder' ),
				esc_html__( 'Permission denied', 'rh-blueprint-builder' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'rh_blueprint_builder_wizard', 'rh_blueprint_builder_nonce' );
	}

	/**
	 * Require a valid AJAX request from an administrator on a single site.
	 *
	 * @return void
	 */
	private function assert_ajax() {
		if ( ! check_ajax_referer( 'rh_blueprint_builder_wizard', 'rh_blueprint_builder_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The form expired. Reload the page and try again.', 'rh-blueprint-builder' ),
				),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to build a blueprint on this site.', 'rh-blueprint-builder' ),
				),
				403
			);
		}
	}

	/**
	 * Read and normalize the current user's draft.
	 *
	 * @return array<string, mixed>
	 */
	private function get_draft() {
		$stored = get_user_meta( get_current_user_id(), self::DRAFT_META, true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->normalize_draft( array_merge( $this->default_draft(), $stored ) );
	}

	/**
	 * Default answers for a first visit.
	 *
	 * @return array<string, mixed>
	 */
	private function default_draft() {
		return array(
			'gallery'             => false,
			'meta_name'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'meta_author'         => '',
			'meta_description'    => '',
			'php_version'         => $this->detector->detected_php_version(),
			'wp_version'          => 'latest',
			'network_access'      => true,
			'landing_page'        => $this->detector->default_landing_page(),
			'login'               => true,
			'login_username'      => 'admin',
			'login_password'      => 'password',
			'plugins_checked'     => array(),
			'plugins_saved'       => false,
			'options_checked'     => array(),
			'options_unchecked'   => array(),
			'options_hidden'      => array(),
			'options_added'       => array(),
			'content'             => false,
			'include_nonpublic'   => false,
			'wxr_url'             => '',
			'blueprint_url'       => '',
			'rewrite_urls'        => true,
			'fetch_attachments'   => true,
		);
	}

	/**
	 * Store the posted wizard answers.
	 *
	 * @return array<string, mixed>
	 */
	private function save_posted_draft() {
		$current = $this->get_draft();
		if ( empty( $_POST['rh_bb_form'] ) ) {
			return $current;
		}

		$input                     = $current;
		$input['gallery']          = ! empty( $_POST['rh_bb_gallery'] );
		$input['meta_name']        = isset( $_POST['rh_bb_meta_name'] ) ? wp_unslash( $_POST['rh_bb_meta_name'] ) : '';
		$input['meta_author']      = isset( $_POST['rh_bb_meta_author'] ) ? wp_unslash( $_POST['rh_bb_meta_author'] ) : '';
		$input['meta_description'] = isset( $_POST['rh_bb_meta_description'] ) ? wp_unslash( $_POST['rh_bb_meta_description'] ) : '';
		$input['php_version']      = isset( $_POST['rh_bb_php_version'] ) ? wp_unslash( $_POST['rh_bb_php_version'] ) : $current['php_version'];
		$input['wp_version']       = isset( $_POST['rh_bb_wp_version'] ) ? wp_unslash( $_POST['rh_bb_wp_version'] ) : 'latest';
		$input['network_access']   = ! empty( $_POST['rh_bb_network_access'] );
		$input['landing_page']     = isset( $_POST['rh_bb_landing_page'] ) ? wp_unslash( $_POST['rh_bb_landing_page'] ) : $current['landing_page'];
		$input['login']            = ! empty( $_POST['rh_bb_login'] );
		$input['login_username']   = isset( $_POST['rh_bb_login_username'] ) ? wp_unslash( $_POST['rh_bb_login_username'] ) : 'admin';
		$input['login_password']   = isset( $_POST['rh_bb_login_password'] ) ? wp_unslash( $_POST['rh_bb_login_password'] ) : 'password';
		$input['content']          = ! empty( $_POST['rh_bb_content'] );
		$input['include_nonpublic'] = ! empty( $_POST['rh_bb_include_nonpublic'] );
		$input['wxr_url']          = isset( $_POST['rh_bb_wxr_url'] ) ? wp_unslash( $_POST['rh_bb_wxr_url'] ) : '';
		$input['blueprint_url']    = isset( $_POST['rh_bb_blueprint_url'] ) ? wp_unslash( $_POST['rh_bb_blueprint_url'] ) : '';
		$input['rewrite_urls']     = ! empty( $_POST['rh_bb_rewrite_urls'] );
		$input['fetch_attachments'] = ! empty( $_POST['rh_bb_fetch_attachments'] );

		if ( ! empty( $_POST['rh_bb_has_plugins'] ) ) {
			$input['plugins_saved']   = true;
			$input['plugins_checked'] = $this->sanitize_plugin_selection( $this->posted_list( 'rh_bb_plugins' ) );
		}

		if ( ! empty( $_POST['rh_bb_has_options'] ) ) {
			$input = $this->merge_option_post( $input );
		}

		$draft = $this->normalize_draft( $input );
		update_user_meta( get_current_user_id(), self::DRAFT_META, $draft );

		return $draft;
	}

	/**
	 * Apply the options checkboxes to the draft lists.
	 *
	 * @param array<string, mixed> $input Draft being updated.
	 * @return array<string, mixed>
	 */
	private function merge_option_post( $input ) {
		$present       = $this->detector->existing_option_names( $this->posted_names( 'rh_bb_options_present' ) );
		$checked       = array_values( array_intersect( $this->detector->existing_option_names( $this->posted_names( 'rh_bb_options' ) ), $present ) );
		$added         = $this->detector->existing_option_names( $this->posted_names( 'rh_bb_options_added' ) );
		$hidden        = array();

		foreach ( $this->posted_names( 'rh_bb_options_hidden' ) as $name ) {
			if ( ! $this->detector->is_denied_option( $name ) ) {
				$hidden[] = $name;
			}
		}

		$hidden          = array_values( array_diff( array_unique( $hidden ), $added ) );
		$unchecked_now   = array_values( array_diff( $present, $checked ) );
		$previous_check  = isset( $input['options_checked'] ) && is_array( $input['options_checked'] ) ? $input['options_checked'] : array();
		$previous_clear  = isset( $input['options_unchecked'] ) && is_array( $input['options_unchecked'] ) ? $input['options_unchecked'] : array();
		$input['options_checked']   = array_values( array_unique( array_merge( array_diff( $previous_check, $present ), $checked ) ) );
		$input['options_unchecked'] = array_values( array_unique( array_merge( array_diff( $previous_clear, $present ), $unchecked_now ) ) );
		$input['options_checked']   = array_values( array_diff( $input['options_checked'], $input['options_unchecked'], $hidden ) );
		$input['options_unchecked'] = array_values( array_diff( $input['options_unchecked'], $checked, $hidden ) );
		$input['options_hidden']    = $hidden;
		$input['options_added']     = array_values( array_diff( $added, $hidden ) );

		return $input;
	}

	/**
	 * Coerce a draft into the stored shape.
	 *
	 * @param array<string, mixed> $input Raw draft.
	 * @return array<string, mixed>
	 */
	private function normalize_draft( $input ) {
		$defaults = $this->default_draft();
		$php      = isset( $input['php_version'] ) ? (string) $input['php_version'] : $defaults['php_version'];
		$username = sanitize_user( isset( $input['login_username'] ) ? (string) $input['login_username'] : 'admin', true );
		$password = sanitize_text_field( isset( $input['login_password'] ) ? (string) $input['login_password'] : 'password' );

		return array(
			'gallery'            => ! empty( $input['gallery'] ),
			'meta_name'          => sanitize_text_field( isset( $input['meta_name'] ) ? (string) $input['meta_name'] : $defaults['meta_name'] ),
			'meta_author'        => $this->sanitize_github_username( isset( $input['meta_author'] ) ? (string) $input['meta_author'] : '' ),
			'meta_description'   => sanitize_textarea_field( isset( $input['meta_description'] ) ? (string) $input['meta_description'] : '' ),
			'php_version'        => in_array( $php, $this->detector->allowed_php_versions(), true ) ? $php : $defaults['php_version'],
			'wp_version'         => ( isset( $input['wp_version'] ) && 'site' === $input['wp_version'] ) ? 'site' : 'latest',
			'network_access'     => ! empty( $input['network_access'] ),
			'landing_page'       => $this->sanitize_landing( isset( $input['landing_page'] ) ? (string) $input['landing_page'] : $defaults['landing_page'] ),
			'login'              => ! empty( $input['login'] ),
			'login_username'     => '' !== $username ? $username : 'admin',
			'login_password'     => '' !== $password ? $password : 'password',
			'plugins_checked'    => $this->string_list( isset( $input['plugins_checked'] ) ? $input['plugins_checked'] : array() ),
			'plugins_saved'      => ! empty( $input['plugins_saved'] ),
			'options_checked'    => $this->limit_list( $this->string_list( isset( $input['options_checked'] ) ? $input['options_checked'] : array() ) ),
			'options_unchecked'  => $this->limit_list( $this->string_list( isset( $input['options_unchecked'] ) ? $input['options_unchecked'] : array() ) ),
			'options_hidden'     => $this->limit_list( $this->string_list( isset( $input['options_hidden'] ) ? $input['options_hidden'] : array() ) ),
			'options_added'      => $this->limit_list( $this->string_list( isset( $input['options_added'] ) ? $input['options_added'] : array() ) ),
			'content'            => ! empty( $input['content'] ),
			'include_nonpublic'  => ! empty( $input['include_nonpublic'] ),
			'wxr_url'            => esc_url_raw( isset( $input['wxr_url'] ) ? (string) $input['wxr_url'] : '' ),
			'blueprint_url'      => esc_url_raw( isset( $input['blueprint_url'] ) ? (string) $input['blueprint_url'] : '' ),
			'rewrite_urls'       => ! empty( $input['rewrite_urls'] ),
			'fetch_attachments'  => ! empty( $input['fetch_attachments'] ),
		);
	}

	/**
	 * Keep plugin slugs that can actually be installed.
	 *
	 * @param string[] $slugs Posted slugs.
	 * @return string[]
	 */
	private function sanitize_plugin_selection( $slugs ) {
		$allowed = array();
		foreach ( $this->detector->plugins_report()['installable'] as $plugin ) {
			$allowed[] = $plugin['slug'];
		}

		$clean = array();
		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( $slug );
			if ( in_array( $slug, $allowed, true ) ) {
				$clean[] = $slug;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether a plugin checkbox starts checked.
	 *
	 * @param array<string, mixed> $plugin Plugin row.
	 * @param array<string, mixed> $draft  Saved answers.
	 * @return bool
	 */
	private function plugin_is_checked( $plugin, $draft ) {
		if ( empty( $draft['plugins_saved'] ) ) {
			return ! empty( $plugin['active'] );
		}

		return in_array( $plugin['slug'], $draft['plugins_checked'], true );
	}

	/**
	 * A GitHub username, or an empty string when it is not one.
	 *
	 * @param string $value Raw username.
	 * @return string
	 */
	private function sanitize_github_username( $value ) {
		$value = ltrim( trim( sanitize_text_field( $value ) ), '@' );
		if ( preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * A relative landing path.
	 *
	 * @param string $value Raw path.
	 * @return string
	 */
	private function sanitize_landing( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return $this->detector->default_landing_page();
		}
		if ( '/' !== substr( $value, 0, 1 ) ) {
			$value = '/' . ltrim( $value, '/' );
		}
		if ( false !== strpos( $value, '..' ) || ! preg_match( '#^/[A-Za-z0-9_./~%-]*$#', $value ) ) {
			return $this->detector->default_landing_page();
		}

		return $value;
	}

	/**
	 * Posted option names with unsafe bytes removed.
	 *
	 * @param string $key Request key.
	 * @return string[]
	 */
	private function posted_names( $key ) {
		$names = array();
		foreach ( $this->posted_list( $key ) as $name ) {
			$name = str_replace( "\0", '', $name );
			if ( '' !== $name && strlen( $name ) <= 191 ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * A posted list of strings.
	 *
	 * @param string $key Request key.
	 * @return string[]
	 */
	private function posted_list( $key ) {
		if ( empty( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$values = array();
		foreach ( wp_unslash( $_POST[ $key ] ) as $value ) {
			if ( is_string( $value ) ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * Unique strings with a modest cap.
	 *
	 * @param mixed $values Raw values.
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
	 * Cap a stored list.
	 *
	 * @param string[] $values Values.
	 * @return string[]
	 */
	private function limit_list( $values ) {
		return array_slice( $values, 0, 2000 );
	}

	/**
	 * Wizard steps in order.
	 *
	 * @return array<string, string>
	 */
	private function steps() {
		return array(
			'setup'   => __( 'Site setup', 'rh-blueprint-builder' ),
			'theme'   => __( 'Theme', 'rh-blueprint-builder' ),
			'plugins' => __( 'Plugins', 'rh-blueprint-builder' ),
			'options' => __( 'Options', 'rh-blueprint-builder' ),
			'content' => __( 'Content', 'rh-blueprint-builder' ),
			'review'  => __( 'Review', 'rh-blueprint-builder' ),
		);
	}

	/**
	 * Step requested in the query string.
	 *
	 * @return string
	 */
	private function current_step() {
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'setup';
		return array_key_exists( $step, $this->steps() ) ? $step : 'setup';
	}

	/**
	 * Step posted with the form.
	 *
	 * @return string
	 */
	private function posted_step() {
		$step = isset( $_POST['rh_bb_step'] ) ? sanitize_key( wp_unslash( $_POST['rh_bb_step'] ) ) : 'setup';
		return array_key_exists( $step, $this->steps() ) ? $step : 'setup';
	}

	/**
	 * URL of the wizard.
	 *
	 * @param string $step Optional step.
	 * @return string
	 */
	private function page_url( $step = '' ) {
		$args = array(
			'page' => 'rh-blueprint-builder',
		);
		if ( '' !== $step && array_key_exists( $step, $this->steps() ) ) {
			$args['step'] = $step;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}
}
