<?php
/**
 * Admin-only WXR download.
 *
 * @package RhBlueprintBuilder
 */

namespace RhBlueprintBuilder;

/**
 * Buffers a WordPress export and removes author email addresses.
 */
class Wxr {

	/**
	 * Post types written into the WXR file.
	 *
	 * Font library types are included only when this WordPress version has them.
	 *
	 * @return string[]
	 */
	public static function content_post_types() {
		$types = array(
			'post',
			'page',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_block',
		);

		foreach ( array( 'wp_font_family', 'wp_font_face' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Site-editor post types, without posts and pages.
	 *
	 * @return string[]
	 */
	public static function site_editor_post_types() {
		return array_values( array_diff( self::content_post_types(), array( 'post', 'page' ) ) );
	}

	/**
	 * Send the export and stop.
	 *
	 * The export includes published posts, pages, and site-editor objects
	 * (templates, template parts, global styles, navigation, synced patterns,
	 * and font-library posts when those types exist). Drafts, private content,
	 * and auto-drafts of site-editor objects are included only when requested.
	 * Comments stay in the file; the blueprint does not import them.
	 *
	 * @param bool $include_nonpublic Whether to include drafts and private content.
	 * @return void
	 */
	public function send( $include_nonpublic ) {
		require_once ABSPATH . 'wp-admin/includes/export.php';

		if ( ! headers_sent() ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		if ( $include_nonpublic ) {
			add_filter( 'query', array( $this, 'include_auto_drafts_in_export' ) );
		}

		ob_start();
		export_wp(
			array(
				'content' => 'all',
			)
		);
		$xml = ob_get_clean();

		if ( $include_nonpublic ) {
			remove_filter( 'query', array( $this, 'include_auto_drafts_in_export' ) );
		}

		if ( ! is_string( $xml ) || '' === $xml ) {
			wp_die(
				esc_html__( 'The export did not produce a file.', 'rh-blueprint-builder' ),
				esc_html__( 'Export failed', 'rh-blueprint-builder' ),
				array( 'response' => 500, 'back_link' => true )
			);
		}

		$statuses = array( 'publish' );
		if ( $include_nonpublic ) {
			$statuses[] = 'draft';
			$statuses[] = 'private';
		}

		$filtered = $this->filter_export( $xml, $statuses );
		if ( is_wp_error( $filtered ) ) {
			wp_die(
				esc_html( $filtered->get_error_message() ),
				esc_html__( 'Export failed', 'rh-blueprint-builder' ),
				array( 'response' => 500, 'back_link' => true )
			);
		}

		if ( ! headers_sent() ) {
			header_remove( 'Content-Description' );
			header_remove( 'Content-Disposition' );
			nocache_headers();
			header( 'Content-Type: application/xml; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Disposition: attachment; filename="rh-blueprint-builder-export.xml"' );
			header( 'Content-Length: ' . strlen( $filtered ) );
		}

		echo $filtered;
		exit;
	}

	/**
	 * Let export_wp() see auto-drafts when unpublished content was requested.
	 *
	 * The exporter otherwise drops every auto-draft before this class can
	 * choose which site-editor stubs to keep. Posts and pages are still
	 * removed later.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function include_auto_drafts_in_export( $query ) {
		if ( ! is_string( $query ) || false === strpos( $query, "post_status != 'auto-draft'" ) ) {
			return $query;
		}

		return str_replace( "post_status != 'auto-draft'", '1=1', $query );
	}

	/**
	 * Whether an item status belongs in this download.
	 *
	 * Published items are always included. Core treats publish as the live
	 * status for customized templates, template parts, and global styles.
	 * Drafts and private items follow the checkbox. Auto-drafts follow it
	 * only for site-editor types.
	 *
	 * @param string $type               Post type.
	 * @param string $status             Post status.
	 * @param bool   $include_nonpublic  Whether drafts and private content are included.
	 * @return bool
	 */
	public static function status_is_exported( $type, $status, $include_nonpublic ) {
		if ( 'publish' === $status ) {
			return true;
		}

		if ( ! $include_nonpublic ) {
			return false;
		}

		if ( in_array( $status, array( 'draft', 'private' ), true ) ) {
			return true;
		}

		return 'auto-draft' === $status && in_array( $type, self::site_editor_post_types(), true );
	}

	/**
	 * Keep the requested content, drop other items, and empty author emails.
	 *
	 * wp_theme terms used by the exported templates, template parts, or global
	 * styles are kept so Playground can assign that content to a theme. Other
	 * theme terms are removed. Template-part area terms used by exported parts
	 * are kept for the same reason.
	 *
	 * @param string   $xml      Raw export.
	 * @param string[] $statuses Allowed post statuses for published-or-draft content.
	 * @return string|\WP_Error
	 */
	private function filter_export( $xml, $statuses ) {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadXML( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $dom->documentElement ) {
			return new \WP_Error(
				'rh_blueprint_builder_wxr',
				__( 'The export could not be read as XML.', 'rh-blueprint-builder' )
			);
		}

		$xpath = new \DOMXPath( $dom );
		$wp_ns = $dom->documentElement->lookupNamespaceURI( 'wp' );
		$dc_ns = $dom->documentElement->lookupNamespaceURI( 'dc' );
		if ( ! is_string( $wp_ns ) || '' === $wp_ns || ! is_string( $dc_ns ) || '' === $dc_ns ) {
			return new \WP_Error(
				'rh_blueprint_builder_wxr',
				__( 'The export is missing the WordPress export namespaces.', 'rh-blueprint-builder' )
			);
		}
		$xpath->registerNamespace( 'wp', $wp_ns );
		$xpath->registerNamespace( 'dc', $dc_ns );

		$allowed           = array_fill_keys( self::content_post_types(), true );
		$include_nonpublic = in_array( 'draft', $statuses, true );
		$remove          = array();
		$creators        = array();
		$theme_slugs     = array();
		$area_slugs      = array();
		$items           = $xpath->query( '//item' );

		if ( false === $items ) {
			return new \WP_Error(
				'rh_blueprint_builder_wxr',
				__( 'The export could not be read as XML.', 'rh-blueprint-builder' )
			);
		}

		foreach ( $items as $item ) {
			$type   = trim( (string) $xpath->evaluate( 'string(wp:post_type)', $item ) );
			$status = trim( (string) $xpath->evaluate( 'string(wp:status)', $item ) );
			if ( ! isset( $allowed[ $type ] ) || ! self::status_is_exported( $type, $status, $include_nonpublic ) ) {
				$remove[] = $item;
				continue;
			}
			$creator = (string) $xpath->evaluate( 'string(dc:creator)', $item );
			if ( '' !== $creator ) {
				$creators[ $creator ] = true;
			}
			$this->collect_theme_terms( $xpath, $item, $type, $theme_slugs, $area_slugs );
		}

		foreach ( $remove as $item ) {
			if ( $item->parentNode ) {
				$item->parentNode->removeChild( $item );
			}
		}

		$authors = $xpath->query( '//wp:author' );
		if ( false === $authors ) {
			return new \WP_Error(
				'rh_blueprint_builder_wxr',
				__( 'The export could not be read as XML.', 'rh-blueprint-builder' )
			);
		}

		foreach ( $authors as $author ) {
			$login = (string) $xpath->evaluate( 'string(wp:author_login)', $author );
			$email = $xpath->query( 'wp:author_email', $author );
			if ( false !== $email && $email->length > 0 ) {
				$email->item( 0 )->nodeValue = '';
			}
			if ( '' === $login || ! isset( $creators[ $login ] ) ) {
				if ( $author->parentNode ) {
					$author->parentNode->removeChild( $author );
				}
			}
		}

		$post_taxonomies = array_fill_keys( get_object_taxonomies( array( 'post', 'page' ) ), true );
		$terms           = $xpath->query( '//wp:term' );
		if ( false !== $terms ) {
			$drop_terms = array();
			foreach ( $terms as $term ) {
				$taxonomy = trim( (string) $xpath->evaluate( 'string(wp:term_taxonomy)', $term ) );
				$slug     = trim( (string) $xpath->evaluate( 'string(wp:term_slug)', $term ) );
				if ( isset( $post_taxonomies[ $taxonomy ] ) ) {
					continue;
				}
				if ( 'wp_theme' === $taxonomy && isset( $theme_slugs[ $slug ] ) ) {
					continue;
				}
				if ( 'wp_template_part_area' === $taxonomy && isset( $area_slugs[ $slug ] ) ) {
					continue;
				}
				$drop_terms[] = $term;
			}
			foreach ( $drop_terms as $term ) {
				if ( $term->parentNode ) {
					$term->parentNode->removeChild( $term );
				}
			}
		}

		$output = $dom->saveXML();
		if ( ! is_string( $output ) || '' === $output ) {
			return new \WP_Error(
				'rh_blueprint_builder_wxr',
				__( 'The export could not be written.', 'rh-blueprint-builder' )
			);
		}

		return $output;
	}

	/**
	 * Record theme and template-part area slugs used by a kept item.
	 *
	 * @param \DOMXPath    $xpath      Export document.
	 * @param \DOMNode     $item       Item element.
	 * @param string       $type       Post type.
	 * @param array<string, bool> $theme_slugs Theme slugs to keep. Updated.
	 * @param array<string, bool> $area_slugs  Template-part area slugs to keep. Updated.
	 * @return void
	 */
	private function collect_theme_terms( $xpath, $item, $type, &$theme_slugs, &$area_slugs ) {
		$categories = $xpath->query( 'category', $item );
		if ( false === $categories ) {
			return;
		}

		$uses_theme = in_array( $type, array( 'wp_template', 'wp_template_part', 'wp_global_styles' ), true );
		foreach ( $categories as $category ) {
			if ( ! $category instanceof \DOMElement ) {
				continue;
			}
			$domain   = $category->getAttribute( 'domain' );
			$nicename = $category->getAttribute( 'nicename' );
			if ( '' === $nicename ) {
				continue;
			}
			if ( $uses_theme && 'wp_theme' === $domain ) {
				$theme_slugs[ $nicename ] = true;
			}
			if ( 'wp_template_part' === $type && 'wp_template_part_area' === $domain ) {
				$area_slugs[ $nicename ] = true;
			}
		}
	}
}
