# RH Blueprint Builder

RH Blueprint Builder writes a [Blueprint v2](https://playground.wordpress.net/blueprint-schema.json) file for [WordPress Playground](https://playground.wordpress.net/). A blueprint is a JSON description of a WordPress site. Playground reads it and builds that site in the browser. Version 2 of the format uses declarative fields such as `plugins`, `activeTheme`, and `siteOptions`, plus `additionalStepsAfterExecution` for work that has to happen after those fields run.

The plugin reads the site you are logged in to and lets you choose what the file includes. It does not change that site. It does not open a public URL on it. Playground cannot fetch a file from a private or local address, so content you want to import has to be hosted by you.

Distribution is GitHub only. This is not a WordPress.org directory plugin.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- A single site. On multisite, or in network admin, the Tools menu explains that the wizard does not run.
- An account that can `manage_options`

## The wizard

Open **Tools → RH Blueprint Builder**. Answers are saved in your user account, so you can leave and come back. The screen has six steps:

1. **Site setup.** Gallery metadata is off until you turn it on. PHP and WordPress versions, network access, the landing page, and the Playground login are set here. The site language is included only when this site is not `en_US`.
2. **Theme.** The active WordPress.org theme is installed and activated. A WordPress.org parent theme is installed and not activated. A theme that is not on WordPress.org is left out.
3. **Plugins.** Choose WordPress.org plugins to install and activate. Active plugins start checked. This plugin is hidden. Plugins that are not on WordPress.org are listed as skipped.
4. **Options.** A curated set of core settings, the active theme’s theme mods, widget options, and options whose names match a selected plugin. You can remove a row or search for another option. Names that look like licenses, API keys, secrets, or tokens start unchecked. Salts, mail passwords, site addresses, transients, and the front-page IDs are never copied.
5. **Content.** Off by default. When you turn it on, download a WXR file of published posts and pages, plus customized templates, template parts, navigation, global styles, synced patterns, and font library entries when this site has them. Host that file at a public HTTPS URL, and paste the URL back. The download removes author email addresses. The file is meant to be published and can contain the full text of the exported content. Drafts and private content are optional. The blueprint does not import comments.
6. **Review.** Read the Blueprint v2 JSON, the validation messages, and the warning above the download. The file can contain site text and plugin settings, including license or API values you checked. Read it before committing it to a public repository.

## License

GPL-3.0-or-later
