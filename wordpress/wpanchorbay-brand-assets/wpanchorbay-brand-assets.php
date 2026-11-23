<?php
/**
 * Plugin Name:       WPAnchorBay Brand Assets
 * Description:       Renders the WPAnchorBay brand assets page (hero, product cards with kit downloads, colours, usage) from the remote brand.json manifest. Nothing is uploaded to the media library — every image is hotlinked from assets.wpanchorbay.com, so updating a file there updates it here.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPAnchorBay
 * Author URI:        https://wpanchorbay.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpab-brand-assets
 *
 * External service: this plugin fetches https://assets.wpanchorbay.com/brand.json
 * (first-party) to build the grid. No user data is sent.
 *
 * @package WPAnchorBay\BrandAssets
 */

declare( strict_types = 1 );

namespace WPAnchorBay\BrandAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MANIFEST_URL  = 'https://assets.wpanchorbay.com/brand.json';
const CACHE_KEY     = 'wpab_brand_manifest';
const STALE_KEY     = 'wpab_brand_manifest_stale';
const CACHE_TTL     = HOUR_IN_SECONDS;
const STALE_TTL     = MONTH_IN_SECONDS;
const CRON_HOOK     = 'wpab_brand_assets_refresh';
const VERSION       = '1.5.0';
const STYLE_HANDLE  = 'wpab-brand-assets';
const SCRIPT_HANDLE = 'wpab-brand-assets';

/**
 * Manifest URL, filterable so a staging site can point elsewhere.
 */
function manifest_url(): string {
	/**
	 * Filters the brand manifest URL.
	 *
	 * @param string $url Absolute URL to a brand.json manifest.
	 */
	return (string) apply_filters( 'wpab_brand_assets_manifest_url', MANIFEST_URL );
}

/**
 * The asset origin (scheme + host) derived from the manifest URL, so a
 * per-product JSON link keeps working even if a filter points
 * manifest_url() somewhere with a different path shape.
 */
function asset_origin(): string {
	$parts = wp_parse_url( manifest_url() );

	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$origin = $parts['scheme'] . '://' . $parts['host'];

	if ( ! empty( $parts['port'] ) ) {
		$origin .= ':' . $parts['port'];
	}

	return $origin;
}

/* -------------------------------------------------------------------------
 * Fetching and caching
 * ---------------------------------------------------------------------- */

/**
 * Fetch the manifest over HTTP.
 *
 * @return array<string,mixed>|\WP_Error Decoded manifest, or an error.
 */
function fetch_manifest() {
	$response = wp_remote_get(
		manifest_url(),
		array(
			'timeout'    => 5,
			'user-agent' => 'WPAnchorBay Brand Assets/' . VERSION . '; ' . home_url( '/' ),
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new \WP_Error(
			'wpab_brand_http_status',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Brand manifest returned HTTP %d.', 'wpab-brand-assets' ),
				(int) $code
			)
		);
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) || empty( $data['products'] ) || ! is_array( $data['products'] ) ) {
		return new \WP_Error(
			'wpab_brand_bad_payload',
			__( 'Brand manifest was not valid JSON, or had no products.', 'wpab-brand-assets' )
		);
	}

	return $data;
}

/**
 * Refresh the cache. Keeps a long-lived stale copy so a failed fetch never
 * blanks the page.
 *
 * @return array<string,mixed>|null Manifest on success, null on failure.
 */
function refresh_manifest(): ?array {
	$data = fetch_manifest();

	if ( is_wp_error( $data ) ) {
		// Back off for a few minutes so a broken origin is not re-hit on every
		// page view, and leave the stale copy in place for the renderer.
		set_transient( CACHE_KEY, array( 'error' => $data->get_error_message() ), 5 * MINUTE_IN_SECONDS );
		return null;
	}

	// Jitter the TTL so multiple sites/keys do not expire in lockstep.
	$ttl = CACHE_TTL + wp_rand( 0, (int) round( CACHE_TTL * 0.1 ) );

	set_transient( CACHE_KEY, $data, $ttl );
	set_transient( STALE_KEY, $data, STALE_TTL );

	return $data;
}

/**
 * Get the manifest: fresh cache, else refetch, else the stale copy.
 *
 * @return array<string,mixed>|null
 */
function get_manifest(): ?array {
	$cached = get_transient( CACHE_KEY );

	if ( is_array( $cached ) && isset( $cached['products'] ) ) {
		return $cached;
	}

	// A cached error means we backed off recently — go straight to stale.
	if ( ! is_array( $cached ) || ! isset( $cached['error'] ) ) {
		$fresh = refresh_manifest();
		if ( null !== $fresh ) {
			return $fresh;
		}
	}

	$stale = get_transient( STALE_KEY );

	return is_array( $stale ) && isset( $stale['products'] ) ? $stale : null;
}

/* -------------------------------------------------------------------------
 * Validation of remote data
 *
 * The manifest is first-party but still arrives over the network, so nothing
 * from it reaches the page unvalidated — colours go into a style attribute and
 * URLs into src/href.
 * ---------------------------------------------------------------------- */

/**
 * Accept an asset URL only if it is http(s) and on the manifest's own host.
 */
function safe_asset_url( $url ): string {
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	$host  = wp_parse_url( manifest_url(), PHP_URL_HOST );

	if ( empty( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
		return '';
	}

	if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( (string) $host ) ) {
		return '';
	}

	return esc_url_raw( $url );
}

/**
 * Validate a hex colour before it is interpolated into a style attribute.
 */
function safe_hex( $color, string $fallback = '#001F3F' ): string {
	$clean = is_string( $color ) ? sanitize_hex_color( $color ) : null;

	return $clean ? $clean : $fallback;
}

/**
 * True unless the URL's host is exactly this site's own root domain (not a
 * subdomain, not another domain entirely) — used to decide which links open
 * in a new tab. docs.wpanchorbay.com and wordpress.org both count as
 * external; a link back to wpanchorbay.com itself does not. Compares
 * against home_url() rather than a hardcoded domain so this still behaves
 * correctly on a staging copy of the site.
 */
function is_external_link( string $url ): bool {
	$host      = wp_parse_url( $url, PHP_URL_HOST );
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! $host || ! $site_host ) {
		return true;
	}

	// Normalise a www/non-www mismatch: the manifest's links are all bare
	// wpanchorbay.com, so home_url() resolving with a "www." would otherwise
	// make every same-site link look external.
	$strip_www = static function ( string $h ): string {
		return preg_replace( '/^www\./i', '', $h );
	};

	return $strip_www( strtolower( $host ) ) !== $strip_www( strtolower( $site_host ) );
}


/* -------------------------------------------------------------------------
 * Icons
 *
 * Static, plugin-authored glyphs: never derived from remote or user input,
 * so they are safe to output directly (no escaping needed). One stroke set
 * (24px grid, 2px round stroke), chevrons for direction, no arrows.
 * ---------------------------------------------------------------------- */

/**
 * Wrap path data in the shared stroke-icon <svg>.
 *
 * @param string $paths Static SVG child markup.
 */
function stroke_icon( string $paths ): string {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}

/**
 * @return array<string,string> Icon name => raw SVG markup.
 */
function icons(): array {
	static $icons = null;

	if ( null !== $icons ) {
		return $icons;
	}

	$icons = array(
		'copy'      => stroke_icon( '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>' ),
		'download'  => stroke_icon( '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>' ),
		'open'      => stroke_icon( '<polyline points="9 6 15 12 9 18"/>' ),
		'link'      => stroke_icon( '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>' ),
		'check'     => stroke_icon( '<polyline points="5 13 9 17 19 7"/>' ),
		'x'         => stroke_icon( '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>' ),
		'globe'     => stroke_icon( '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>' ),
		'book'      => stroke_icon( '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' ),
		'code'      => stroke_icon( '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>' ),
		// The official WordPress "W" mark (source: WordPress Foundation, via
		// commons.wikimedia.org/wiki/File:Wordpress-Logo.svg), recoloured to
		// currentColor. Used only to identify a link to the product's own
		// WordPress.org listing: nominative use, the same as any
		// "on WordPress.org" badge, not a claim of endorsement.
		'wordpress' => '<svg viewBox="0 0 122.52 122.523" aria-hidden="true" focusable="false"><g fill="currentColor"><path d="m8.708 61.26c0 20.802 12.089 38.779 29.619 47.298l-25.069-68.686c-2.916 6.536-4.55 13.769-4.55 21.388z"/><path d="m96.74 58.608c0-6.495-2.333-10.993-4.334-14.494-2.664-4.329-5.161-7.995-5.161-12.324 0-4.831 3.664-9.328 8.825-9.328.233 0 .454.029.681.042-9.35-8.566-21.807-13.796-35.489-13.796-18.36 0-34.513 9.42-43.91 23.688 1.233.037 2.395.063 3.382.063 5.497 0 14.006-.667 14.006-.667 2.833-.167 3.167 3.994.337 4.329 0 0-2.847.335-6.015.501l19.138 56.925 11.501-34.493-8.188-22.434c-2.83-.166-5.511-.501-5.511-.501-2.832-.166-2.5-4.496.332-4.329 0 0 8.679.667 13.843.667 5.496 0 14.006-.667 14.006-.667 2.835-.167 3.168 3.994.337 4.329 0 0-2.853.335-6.015.501l18.992 56.494 5.242-17.517c2.272-7.269 4.001-12.49 4.001-16.989z"/><path d="m62.184 65.857-15.768 45.819c4.708 1.384 9.687 2.141 14.846 2.141 6.12 0 11.989-1.058 17.452-2.979-.141-.225-.269-.464-.374-.724z"/><path d="m107.376 36.046c.226 1.674.354 3.471.354 5.404 0 5.333-.996 11.328-3.996 18.824l-16.053 46.413c15.624-9.111 26.133-26.038 26.133-45.426.001-9.137-2.333-17.729-6.438-25.215z"/><path d="m61.262 0c-33.779 0-61.262 27.481-61.262 61.26 0 33.783 27.483 61.263 61.262 61.263 33.778 0 61.265-27.48 61.265-61.263-.001-33.779-27.487-61.26-61.265-61.26zm0 119.715c-32.23 0-58.453-26.223-58.453-58.455 0-32.23 26.222-58.451 58.453-58.451 32.229 0 58.45 26.221 58.45 58.451 0 32.232-26.221 58.455-58.45 58.455z"/></g></svg>',
	);

	return $icons;
}

/**
 * Link metadata for the link row: label + icon per manifest link key.
 *
 * @return array<string,array{label:string,icon:string}>
 */
function link_meta(): array {
	$glyphs = icons();

	return array(
		'wordpress' => array(
			'label' => __( 'WordPress.org', 'wpab-brand-assets' ),
			'icon'  => $glyphs['wordpress'],
		),
		'site'      => array(
			'label' => __( 'Product page', 'wpab-brand-assets' ),
			'icon'  => $glyphs['globe'],
		),
		'docs'      => array(
			'label' => __( 'Docs', 'wpab-brand-assets' ),
			'icon'  => $glyphs['book'],
		),
	);
}

/**
 * Plain-text summary for the "Copy info" button: name, tagline, colours,
 * links.
 *
 * @param array<int,array{hex:string,name:string}> $swatches    Same shape as render_card()'s $swatches.
 * @param array<string,string>                      $badge_links Link key => URL (wordpress/site/docs only).
 * @param array<string,array{label:string,icon:string}> $meta    From link_meta().
 */
function product_info_text( string $name, string $tagline, array $swatches, array $badge_links, array $meta, string $kind ): string {
	$lines = array( $name, $tagline, '' );

	if ( count( $swatches ) > 1 ) {
		$lines[] = __( 'Colours:', 'wpab-brand-assets' );
		foreach ( $swatches as $s ) {
			$lines[] = '  ' . ucfirst( $s['name'] ) . ': ' . $s['hex'];
		}
	} else {
		/* translators: %s: hex value. */
		$lines[] = sprintf( __( 'Colour: %s', 'wpab-brand-assets' ), $swatches[0]['hex'] );
	}

	if ( $badge_links ) {
		$lines[] = '';
		$lines[] = __( 'Links:', 'wpab-brand-assets' );
		foreach ( $badge_links as $key => $href ) {
			$label   = ( 'site' === $key && 'brand' === $kind )
				? __( 'Visit Website', 'wpab-brand-assets' )
				: $meta[ $key ]['label'];
			$lines[] = '  ' . $label . ': ' . $href;
		}
	}

	return implode( "\n", $lines );
}

/* -------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------- */

/**
 * Best file URL of one asset: SVG first, then PNG. Validated.
 *
 * @param mixed $asset One entry of a product's "assets".
 */
function asset_best_url( $asset ): string {
	if ( ! is_array( $asset ) || empty( $asset['formats'] ) || ! is_array( $asset['formats'] ) ) {
		return '';
	}

	return safe_asset_url( $asset['formats']['svg']['url'] ?? $asset['formats']['png']['url'] ?? '' );
}

/**
 * Download URL of a kit zip on the asset origin: one brand's, or the
 * all-brands kit when $slug is empty.
 */
function kit_url( string $slug = '' ): string {
	$origin = asset_origin();

	if ( '' === $origin ) {
		return '';
	}

	$file = '' === $slug ? 'wpanchorbay-all-brands' : sanitize_key( $slug );

	return safe_asset_url( $origin . '/kits/' . $file . '.zip' );
}

/**
 * The variants a card can preview, in display order: manifest asset key =>
 * label and one-line description.
 *
 * @return array<string,array{0:string,1:string}>
 */
function variant_defs(): array {
	return array(
		'logo'       => array( __( 'Logo', 'wpab-brand-assets' ), __( 'Tile and wordmark, the default', 'wpab-brand-assets' ) ),
		'icon'       => array( __( 'Icon', 'wpab-brand-assets' ), __( 'The rounded tile, for avatars and app icons', 'wpab-brand-assets' ) ),
		'icon-inner' => array( __( 'Inner', 'wpab-brand-assets' ), __( 'Glyph only, no tile', 'wpab-brand-assets' ) ),
		'icon-mono'  => array( __( 'Mono', 'wpab-brand-assets' ), __( 'One colour, for admin menus', 'wpab-brand-assets' ) ),
		'anchor'     => array( __( 'Anchor', 'wpab-brand-assets' ), __( 'The bare anchor glyph', 'wpab-brand-assets' ) ),
	);
}

/**
 * RGB components of a #rgb or #rrggbb colour (already validated).
 *
 * @return array{0:int,1:int,2:int}
 */
function hex_to_rgb( string $hex ): array {
	$h = ltrim( $hex, '#' );

	if ( 3 === strlen( $h ) ) {
		$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
	}

	return array(
		(int) hexdec( substr( $h, 0, 2 ) ),
		(int) hexdec( substr( $h, 2, 2 ) ),
		(int) hexdec( substr( $h, 4, 2 ) ),
	);
}

/**
 * WCAG contrast ratio of two colours.
 */
function contrast_ratio( string $a, string $b ): float {
	$lum = static function ( string $hex ): float {
		$out = 0.0;
		$mul = array( 0.2126, 0.7152, 0.0722 );
		foreach ( hex_to_rgb( $hex ) as $i => $v ) {
			$c    = $v / 255;
			$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$out += $mul[ $i ] * $c;
		}
		return $out;
	};

	$l1 = $lum( $a );
	$l2 = $lum( $b );

	return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
}

/**
 * Name of the WCAG band a contrast ratio reaches.
 */
function contrast_grade( float $ratio ): string {
	if ( $ratio >= 7 ) {
		return 'AAA';
	}
	if ( $ratio >= 4.5 ) {
		return 'AA';
	}
	if ( $ratio >= 3 ) {
		return __( 'AA large', 'wpab-brand-assets' );
	}

	return __( 'Fails', 'wpab-brand-assets' );
}

/**
 * The brand-kind product (WPAnchorBay itself) of a manifest, or the first
 * product when there is none.
 *
 * @param array<string,mixed> $manifest Decoded manifest.
 * @return array<string,mixed>|null
 */
function brand_product( array $manifest ): ?array {
	$first = null;

	foreach ( $manifest['products'] as $slug => $p ) {
		if ( ! is_array( $p ) ) {
			continue;
		}
		$p['slug'] = $p['slug'] ?? $slug;
		if ( null === $first ) {
			$first = $p;
		}
		if ( 'brand' === ( $p['kind'] ?? '' ) ) {
			return $p;
		}
	}

	return $first;
}

/**
 * Eyebrow + h2 + intro paragraph that opens a section.
 */
function section_head( string $eyebrow, string $title, string $text = '' ): string {
	return '<div class="wpab-ba-sechead"><p class="wpab-ba-eyebrow">' . esc_html( $eyebrow ) . '</p><h2>' . esc_html( $title ) . '</h2>'
		. ( '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '' ) . '</div>';
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

/**
 * One product card.
 *
 * @param array<string,mixed> $product Product entry from the manifest.
 */
function render_card( array $product ): string {
	$name     = isset( $product['name'] ) ? (string) $product['name'] : '';
	$tagline  = isset( $product['tagline'] ) ? (string) $product['tagline'] : '';
	$slug     = isset( $product['slug'] ) ? sanitize_key( (string) $product['slug'] ) : '';
	$kind     = isset( $product['kind'] ) ? (string) $product['kind'] : 'plugin';
	$color    = safe_hex( $product['color'] ?? null );
	$on_color = safe_hex( $product['onColor'] ?? null, '#FFFFFF' );
	$assets   = isset( $product['assets'] ) && is_array( $product['assets'] ) ? $product['assets'] : array();
	$links    = isset( $product['links'] ) && is_array( $product['links'] ) ? $product['links'] : array();

	if ( '' === $name ) {
		return '';
	}

	$glyphs = icons();
	$meta   = link_meta();
	$icon   = asset_best_url( $assets['icon'] ?? null );
	$kit    = '' !== $slug ? kit_url( $slug ) : '';

	// The brand carries two identity colours (teal + navy); a plugin's onColor
	// is only ever a text-contrast helper, so a plugin card shows both as
	// "brand colour" and "text colour on it" and the brand card names them.
	$swatches = 'brand' === $kind
		? array(
			array(
				'hex'  => $color,
				'name' => __( 'primary', 'wpab-brand-assets' ),
			),
			array(
				'hex'  => $on_color,
				'name' => __( 'secondary', 'wpab-brand-assets' ),
			),
		)
		: array(
			array(
				'hex'  => $color,
				'name' => __( 'brand colour', 'wpab-brand-assets' ),
			),
			array(
				'hex'  => $on_color,
				'name' => __( 'text colour on it', 'wpab-brand-assets' ),
			),
		);

	$badge_links = array_intersect_key( $links, $meta );
	$info_text   = product_info_text( $name, $tagline, $swatches, $badge_links, $meta, $kind );

	// Variants that exist for this product, with the file shown on a light
	// panel and the one shown on a dark panel.
	$variants = array();
	foreach ( variant_defs() as $key => $def ) {
		$src = asset_best_url( $assets[ $key ] ?? null );
		if ( '' === $src ) {
			continue;
		}
		$dark = $src;
		if ( 'logo' === $key ) {
			$dark = asset_best_url( $assets['logo-dark'] ?? null );
			$dark = '' !== $dark ? $dark : ( '' !== $icon ? $icon : $src );
		}
		$note = ( 'brand' === $kind && 'icon-inner' === $key )
			? __( 'Navy glyph: use on light backgrounds', 'wpab-brand-assets' )
			: '';

		$variants[ $key ] = array(
			'label' => $def[0],
			'desc'  => $def[1],
			'src'   => $src,
			'dark'  => $dark,
			'note'  => $note,
			'shape' => 'logo' === $key ? 'wide' : 'sq',
		);
	}

	$default_key = isset( $variants['logo'] ) ? 'logo' : ( isset( $variants['icon'] ) ? 'icon' : (string) key( $variants ) );
	$default     = '' !== $default_key ? $variants[ $default_key ] : null;

	$h_id = 'wpab-ba-h-' . $slug;

	ob_start();
	?>
	<article class="wpab-ba-card" id="wpab-ba-<?php echo esc_attr( $slug ); ?>" aria-labelledby="<?php echo esc_attr( $h_id ); ?>" data-wpab-search="<?php echo esc_attr( strtolower( $name . ' ' . $slug . ' ' . $tagline ) ); ?>" data-wpab-kind="<?php echo esc_attr( $kind ); ?>" style="--wpab-ba-brand:<?php echo esc_attr( $color ); ?>;--wpab-ba-on-brand:<?php echo esc_attr( $on_color ); ?>">
		<header class="wpab-ba-card__head">
			<div class="wpab-ba-ident">
				<?php if ( $icon ) : ?>
					<img src="<?php echo esc_url( $icon ); ?>" alt="" width="56" height="56" decoding="async">
				<?php endif; ?>
				<div>
					<span class="wpab-ba-kind"><?php echo 'brand' === $kind ? esc_html__( 'Company brand', 'wpab-brand-assets' ) : esc_html__( 'Plugin', 'wpab-brand-assets' ); ?></span>
					<h3 id="<?php echo esc_attr( $h_id ); ?>"><?php echo esc_html( $name ); ?></h3>
					<?php if ( $tagline ) : ?>
						<p><?php echo esc_html( $tagline ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $kit ) : ?>
				<a class="wpab-ba-btn wpab-ba-btn--brand" href="<?php echo esc_url( $kit ); ?>" download
					aria-label="<?php echo esc_attr( sprintf(
						/* translators: %s: product name. */
						__( 'Download the %s kit (zip)', 'wpab-brand-assets' ),
						$name
					) ); ?>"><?php echo $glyphs['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Download the kit', 'wpab-brand-assets' ); ?></span></a>
			<?php endif; ?>
		</header>

		<div class="wpab-ba-card__body">
			<?php if ( $default ) : ?>
				<div class="wpab-ba-stage" data-wpab-stage data-default="<?php echo esc_attr( $default_key ); ?>" data-name="<?php echo esc_attr( $name ); ?>">
					<div class="wpab-ba-panel" data-wpab-panel>
						<img class="wpab-ba-panel__img wpab-ba-panel__img--<?php echo esc_attr( $default['shape'] ); ?>" src="<?php echo esc_url( $default['src'] ); ?>"
							alt="<?php echo esc_attr( sprintf(
								/* translators: 1: product name, 2: variant, e.g. Logo. */
								__( '%1$s %2$s', 'wpab-brand-assets' ),
								$name,
								strtolower( $default['label'] )
							) ); ?>" decoding="async">
						<p class="wpab-ba-panel__note" data-wpab-note hidden></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="wpab-ba-side">
				<?php if ( count( $variants ) > 1 ) : ?>
					<div>
						<h4><?php esc_html_e( 'Variants', 'wpab-brand-assets' ); ?></h4>
						<div class="wpab-ba-variants">
							<?php foreach ( $variants as $key => $v ) : ?>
								<a class="wpab-ba-variant" href="<?php echo esc_url( $v['src'] ); ?>" target="_blank" rel="noopener"
									data-wpab-variant="<?php echo esc_attr( $key ); ?>" data-src="<?php echo esc_url( $v['src'] ); ?>"
									data-dark="<?php echo esc_url( $v['dark'] ); ?>" data-shape="<?php echo esc_attr( $v['shape'] ); ?>"
									data-label="<?php echo esc_attr( $v['label'] ); ?>" data-note="<?php echo esc_attr( $v['note'] ); ?>">
									<span class="wpab-ba-variant__thumb"><img src="<?php echo esc_url( $v['src'] ); ?>" alt="" loading="lazy" decoding="async"></span>
									<span class="wpab-ba-variant__name"><?php echo esc_html( $v['label'] ); ?></span>
									<span class="wpab-ba-variant__desc"><?php echo esc_html( $v['desc'] ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<div>
					<h4><?php esc_html_e( 'Colour', 'wpab-brand-assets' ); ?></h4>
					<div class="wpab-ba-chips">
						<?php foreach ( $swatches as $s ) : ?>
							<button type="button" class="wpab-ba-chip wpab-ba-js-copy" data-copy="<?php echo esc_attr( $s['hex'] ); ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: 1: swatch name, e.g. "primary". 2: hex value. */
									__( 'Copy %1$s %2$s', 'wpab-brand-assets' ),
									$s['name'],
									$s['hex']
								) ); ?>">
								<i style="background:<?php echo esc_attr( $s['hex'] ); ?>"></i><span><?php echo esc_html( $s['hex'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<div>
					<h4><?php esc_html_e( 'Links', 'wpab-brand-assets' ); ?></h4>
					<div class="wpab-ba-links">
						<?php foreach ( $badge_links as $key => $href ) : ?>
							<?php
							// The brand's own "site" link goes to the company homepage,
							// not a per-product page, label it accordingly.
							$label    = ( 'site' === $key && 'brand' === $kind )
								? __( 'Visit Website', 'wpab-brand-assets' )
								: $meta[ $key ]['label'];
							$external = is_external_link( (string) $href );
							$tips     = array(
								'wordpress' => __( 'Opens the WordPress.org plugin page in a new tab', 'wpab-brand-assets' ),
								'site'      => 'brand' === $kind
									? __( 'Opens the WPAnchorBay website', 'wpab-brand-assets' )
									: __( 'Opens this plugin\'s page on the WPAnchorBay website', 'wpab-brand-assets' ),
								'docs'      => __( 'Opens the documentation for this plugin', 'wpab-brand-assets' ),
							);
							?>
							<a class="wpab-ba-ibtn" data-tip="<?php echo esc_attr( $tips[ $key ] ?? '' ); ?>" href="<?php echo esc_url( (string) $href ); ?>"
								<?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : 'rel="noopener"'; ?>>
								<?php echo $meta[ $key ]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?>
								<span><?php echo esc_html( $label ); ?></span>
							</a>
						<?php endforeach; ?>
						<?php if ( ! empty( $assets ) && '' !== $slug ) : ?>
							<button type="button" class="wpab-ba-ibtn wpab-ba-js-copy" data-tip="<?php esc_attr_e( 'Copies the link to this brand\'s JSON data: logos, colours and links', 'wpab-brand-assets' ); ?>" data-copy="<?php echo esc_attr( asset_origin() . '/brand/' . $slug . '/brand.json' ); ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s: product name. */
									__( 'Copy %s JSON URL', 'wpab-brand-assets' ),
									$name
								) ); ?>">
								<?php echo $glyphs['code']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'JSON', 'wpab-brand-assets' ); ?></span>
							</button>
						<?php endif; ?>
						<button type="button" class="wpab-ba-ibtn wpab-ba-js-copy" data-tip="<?php esc_attr_e( 'Copies the name, tagline and colours as plain text', 'wpab-brand-assets' ); ?>" data-copy="<?php echo esc_attr( $info_text ); ?>"
							aria-label="<?php echo esc_attr( sprintf(
								/* translators: %s: product name. */
								__( 'Copy %s info', 'wpab-brand-assets' ),
								$name
							) ); ?>">
							<?php echo $glyphs['copy']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Copy info', 'wpab-brand-assets' ); ?></span>
						</button>
					</div>
				</div>
			</div>
		</div>

		<?php
		// One row per file and format. Collected first so the summary can say how many.
		$rows = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['formats'] ) || ! is_array( $asset['formats'] ) ) {
				continue;
			}
			$formats = $asset['formats'];
			// SVG first.
			uksort(
				$formats,
				static function ( $a, $b ) {
					if ( 'svg' === $a ) {
						return -1;
					}
					if ( 'svg' === $b ) {
						return 1;
					}
					return strcmp( (string) $a, (string) $b );
				}
			);
			foreach ( $formats as $ext => $format ) {
				$url = is_array( $format ) ? safe_asset_url( $format['url'] ?? '' ) : '';
				if ( '' === $url ) {
					continue;
				}
				$dims    = ( ! empty( $format['width'] ) && ! empty( $format['height'] ) )
					? sprintf( '%1$s×%2$s', (string) round( (float) $format['width'] ), (string) round( (float) $format['height'] ) )
					: '';
				$bytes   = isset( $format['bytes'] )
					? size_format( (int) $format['bytes'], ( (int) $format['bytes'] < 10240 ) ? 1 : 0 )
					: '';
				$rows[] = array(
					'title' => ( isset( $asset['label'] ) ? (string) $asset['label'] : '' ) . ', ' . strtoupper( (string) $ext ),
					'ext'   => strtoupper( (string) $ext ),
					'info'  => implode( ' · ', array_filter( array( $dims, $bytes ) ) ),
					'url'   => $url,
				);
			}
		}
		?>
		<?php if ( $rows ) : ?>
			<details class="wpab-ba-files">
				<summary><?php echo esc_html( sprintf(
					/* translators: %d: number of files. */
					__( 'All files (%d)', 'wpab-brand-assets' ),
					count( $rows )
				) ); ?></summary>
				<div class="wpab-ba-rows">
					<?php foreach ( $rows as $r ) : ?>
						<div class="wpab-ba-row">
							<div class="wpab-ba-row__meta">
								<b><?php echo esc_html( $r['title'] ); ?></b>
								<?php if ( $r['info'] ) : ?>
									<small><?php echo esc_html( $r['info'] ); ?></small>
								<?php endif; ?>
							</div>
							<div class="wpab-ba-row__acts">
								<button type="button" class="wpab-ba-ibtn wpab-ba-js-copy" data-copy="<?php echo esc_attr( $r['url'] ); ?>"
									aria-label="<?php echo esc_attr( sprintf(
										/* translators: %s: file name and format, e.g. "Logo, SVG". */
										__( 'Copy link: %s', 'wpab-brand-assets' ),
										$r['title']
									) ); ?>"><?php echo $glyphs['link']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Copy link', 'wpab-brand-assets' ); ?></span></button>
								<a class="wpab-ba-ibtn" href="<?php echo esc_url( $r['url'] ); ?>" download
									aria-label="<?php echo esc_attr( sprintf(
										/* translators: %s: file name and format, e.g. "Logo, SVG". */
										__( 'Download: %s', 'wpab-brand-assets' ),
										$r['title']
									) ); ?>"><?php echo $glyphs['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Download', 'wpab-brand-assets' ); ?></span></a>
								<a class="wpab-ba-ibtn" href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"
									aria-label="<?php echo esc_attr( sprintf(
										/* translators: %s: file name and format, e.g. "Logo, SVG". */
										__( 'Open in new tab: %s', 'wpab-brand-assets' ),
										$r['title']
									) ); ?>"><span><?php esc_html_e( 'Open', 'wpab-brand-assets' ); ?></span><?php echo $glyphs['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		<?php endif; ?>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * A shared "brand.json could not be loaded" message: never shown to
 * ordinary visitors, only to anyone who can edit posts.
 */
function unavailable_notice(): string {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}

	return '<p class="wpab-ba wpab-ba-notice">' . esc_html__(
		'Brand assets could not be loaded from assets.wpanchorbay.com. This message is only visible to editors.',
		'wpab-brand-assets'
	) . '</p>';
}

/**
 * Shortcode: [wpanchorbay_brand_hero]. The opening block of the public
 * brand-assets page: heading, lede, the all-brands kit button, a mosaic of
 * every product icon. Meant to sit above [wpanchorbay_brand_assets].
 */
function hero_shortcode(): string {
	$manifest = get_manifest();

	if ( null === $manifest ) {
		return unavailable_notice();
	}

	$glyphs   = icons();
	$org      = isset( $manifest['organization'] ) && is_array( $manifest['organization'] ) ? $manifest['organization'] : array();
	$org_name = isset( $org['name'] ) ? (string) $org['name'] : __( 'WPAnchorBay', 'wpab-brand-assets' );

	// The brand-kind entry (WPAnchorBay itself) is the "Hotlink it" example;
	// the first product stands in if the manifest has no brand-kind entry.
	$sample        = brand_product( $manifest );
	$sample_logo   = $sample ? asset_best_url( $sample['assets']['logo'] ?? null ) : '';
	$sample_name   = $sample ? (string) ( $sample['name'] ?? '' ) : '';
	$manifest_self = isset( $manifest['self'] ) ? safe_asset_url( $manifest['self'] ) : '';
	$kit_all       = kit_url();

	// Mosaic tiles: every product icon, linking to its card.
	$tiles   = array();
	$plugins = 0;
	foreach ( $manifest['products'] as $slug => $p ) {
		if ( ! is_array( $p ) || empty( $p['name'] ) ) {
			continue;
		}
		$p_slug = sanitize_key( (string) ( $p['slug'] ?? $slug ) );
		$p_icon = asset_best_url( $p['assets']['icon'] ?? $p['assets']['logo'] ?? null );
		if ( 'brand' !== ( $p['kind'] ?? '' ) ) {
			++$plugins;
		}
		if ( '' !== $p_icon && '' !== $p_slug ) {
			$tiles[] = array(
				'slug' => $p_slug,
				'name' => (string) $p['name'],
				'icon' => $p_icon,
			);
		}
	}

	ob_start();
	?>
	<div class="wpab-ba wpab-ba-hero">
		<div class="wpab-ba-hero__grid">
			<div class="wpab-ba-hero__text">
				<p class="wpab-ba-eyebrow"><?php esc_html_e( 'Brand assets', 'wpab-brand-assets' ); ?></p>
				<h1><?php
					echo esc_html( sprintf(
						/* translators: %s: organization name. */
						__( 'Everything you need to show %s correctly.', 'wpab-brand-assets' ),
						$org_name
					) );
				?></h1>
				<p class="wpab-ba-lead"><?php
					echo esc_html( sprintf(
						/* translators: %s: organization name. */
						__( 'Logos, icons and colours for %s and its WooCommerce and WordPress plugins. Download the whole kit, or open a plugin and take just what you need. Every URL is permanent: we update the file behind it, never the address.', 'wpab-brand-assets' ),
						$org_name
					) );
				?></p>
				<div class="wpab-ba-actions">
					<?php if ( $kit_all ) : ?>
						<a class="wpab-ba-btn wpab-ba-btn--primary" href="<?php echo esc_url( $kit_all ); ?>" download><?php echo $glyphs['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Download all brands (.zip)', 'wpab-brand-assets' ); ?></span></a>
					<?php endif; ?>
					<a class="wpab-ba-btn" href="#wpab-ba-usage"><span><?php esc_html_e( 'Usage guidelines', 'wpab-brand-assets' ); ?></span></a>
				</div>
				<?php if ( $plugins ) : ?>
					<p class="wpab-ba-meta"><?php
						echo esc_html( sprintf(
							/* translators: %d: number of plugins. */
							_n( '%d plugin · SVG and PNG · permanent URLs', '%d plugins · SVG and PNG · permanent URLs', $plugins, 'wpab-brand-assets' ),
							$plugins
						) );
					?></p>
				<?php endif; ?>
			</div>

			<?php if ( $tiles ) : ?>
				<div class="wpab-ba-mosaic">
					<?php foreach ( $tiles as $t ) : ?>
						<a href="#wpab-ba-<?php echo esc_attr( $t['slug'] ); ?>" aria-label="<?php echo esc_attr( sprintf(
							/* translators: %s: product name. */
							__( '%s: jump to its assets', 'wpab-brand-assets' ),
							$t['name']
						) ); ?>">
							<img src="<?php echo esc_url( $t['icon'] ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async">
							<span class="wpab-ba-mosaic__cap" aria-hidden="true"><?php echo esc_html( $t['name'] ); ?></span>
						</a>
					<?php endforeach; ?>
					<?php if ( $plugins ) : ?>
						<span class="wpab-ba-mosaic__count"><span><b><?php echo (int) $plugins; ?></b><small><?php esc_html_e( 'plugins', 'wpab-brand-assets' ); ?></small></span></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	$html = (string) ob_get_clean();

	wp_enqueue_style( STYLE_HANDLE );
	wp_enqueue_script( SCRIPT_HANDLE );

	return $html;
}

/**
 * Shortcode: [wpanchorbay_brand_typography]. The two typefaces and what each is for.
 */
function typography_shortcode(): string {
	if ( null === get_manifest() ) {
		return '';
	}

	ob_start();
	?>
	<div class="wpab-ba wpab-ba-section" id="wpab-ba-typography">
		<?php
		echo section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside section_head().
			__( 'Typography', 'wpab-brand-assets' ),
			__( 'Two typefaces, one job each.', 'wpab-brand-assets' ),
			__( 'Both are free and open source (SIL Open Font License), so you can use them anywhere.', 'wpab-brand-assets' )
		);
		?>
		<div class="wpab-ba-type">
			<div class="wpab-ba-type__card">
				<p class="wpab-ba-eyebrow"><?php esc_html_e( 'Headings', 'wpab-brand-assets' ); ?></p>
				<h3><?php esc_html_e( 'Inter Tight', 'wpab-brand-assets' ); ?></h3>
				<p class="wpab-ba-type__sample wpab-ba-type__sample--display"><?php esc_html_e( 'Sell more, with less work.', 'wpab-brand-assets' ); ?></p>
				<p class="wpab-ba-type__abc wpab-ba-type__sample--display">Aa Bb Cc 0123456789</p>
			</div>
			<div class="wpab-ba-type__card">
				<p class="wpab-ba-eyebrow"><?php esc_html_e( 'Body copy and small text', 'wpab-brand-assets' ); ?></p>
				<h3><?php esc_html_e( 'Inter', 'wpab-brand-assets' ); ?></h3>
				<p class="wpab-ba-type__sample"><?php esc_html_e( 'Plugins for WooCommerce that each do one job well, from flash sales to loyalty points.', 'wpab-brand-assets' ); ?></p>
				<p class="wpab-ba-type__abc">Aa Bb Cc 0123456789</p>
			</div>
		</div>
	</div>
	<?php
	$html = (string) ob_get_clean();
	wp_enqueue_style( STYLE_HANDLE );

	return $html;
}

/**
 * Shortcode: [wpanchorbay_brand_closing]. The end of the page: the "Hotlink it" and
 * "Machine-readable" panels, the trademark note with the contact address, and a last
 * download button.
 */
function closing_shortcode(): string {
	$manifest = get_manifest();

	if ( null === $manifest ) {
		return '';
	}

	$glyphs        = icons();
	$sample        = brand_product( $manifest );
	$sample_logo   = $sample ? asset_best_url( $sample['assets']['logo'] ?? null ) : '';
	$sample_name   = $sample ? (string) ( $sample['name'] ?? '' ) : '';
	$manifest_self = isset( $manifest['self'] ) ? safe_asset_url( $manifest['self'] ) : '';
	$kit_all       = kit_url();
	$contact       = 'contact@wpanchorbay.com';

	ob_start();
	?>
	<div class="wpab-ba wpab-ba-closing">
	<?php if ( $sample_logo || $manifest_self ) : ?>
		<div class="wpab-ba-dev">
			<?php if ( $sample_logo ) : ?>
				<?php $snippet = sprintf( '<img src="%s" alt="%s" height="40">', $sample_logo, $sample_name ); ?>
				<div class="wpab-ba-box">
					<h2><?php esc_html_e( 'Hotlink it', 'wpab-brand-assets' ); ?></h2>
					<p><?php esc_html_e( 'Point straight at the URL. No copy in your media library, no version to keep in sync.', 'wpab-brand-assets' ); ?></p>
					<div class="wpab-ba-code">
						<pre tabindex="0"><?php echo esc_html( $snippet ); ?></pre>
						<button type="button" class="wpab-ba-ibtn wpab-ba-js-copy" data-copy="<?php echo esc_attr( $snippet ); ?>"
							aria-label="<?php esc_attr_e( 'Copy snippet', 'wpab-brand-assets' ); ?>"><?php echo $glyphs['copy']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Copy', 'wpab-brand-assets' ); ?></span></button>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $manifest_self ) : ?>
				<div class="wpab-ba-box">
					<h2><?php esc_html_e( 'Machine-readable', 'wpab-brand-assets' ); ?></h2>
					<p><?php esc_html_e( 'Every product, asset URL and colour, as JSON, or fetch one product on its own at /brand/<product>/brand.json.', 'wpab-brand-assets' ); ?></p>
					<div class="wpab-ba-code">
						<pre tabindex="0"><?php echo esc_html( $manifest_self ); ?></pre>
						<button type="button" class="wpab-ba-ibtn wpab-ba-js-copy" data-copy="<?php echo esc_attr( $manifest_self ); ?>"
							aria-label="<?php esc_attr_e( 'Copy URL', 'wpab-brand-assets' ); ?>"><?php echo $glyphs['copy']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Copy', 'wpab-brand-assets' ); ?></span></button>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

		<div class="wpab-ba-note">
			<p><?php esc_html_e( 'WPAnchorBay is an independent company and is not affiliated with or endorsed by WooCommerce, Automattic or the WordPress Foundation. WooCommerce is a trademark of WooCommerce, Inc. and WordPress is a registered trademark of the WordPress Foundation. Use their names only to say what our plugins work with.', 'wpab-brand-assets' ); ?></p>
			<p><?php
				echo wp_kses(
					sprintf(
						/* translators: %s: email link. */
						__( 'Questions about using the brand? Write to %s.', 'wpab-brand-assets' ),
						'<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				);
			?></p>
		</div>

		<?php if ( $kit_all ) : ?>
			<div class="wpab-ba-end">
				<h2><?php esc_html_e( 'Take the whole kit.', 'wpab-brand-assets' ); ?></h2>
				<a class="wpab-ba-btn wpab-ba-btn--primary" href="<?php echo esc_url( $kit_all ); ?>" download><?php echo $glyphs['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Download all brands (.zip)', 'wpab-brand-assets' ); ?></span></a>
			</div>
		<?php endif; ?>
	</div>
	<?php
	$html = (string) ob_get_clean();

	wp_enqueue_style( STYLE_HANDLE );
	wp_enqueue_script( SCRIPT_HANDLE );

	return $html;
}

/**
 * The colours section: one swatch per product plus the WPAnchorBay navy, each
 * with its text colour and the WCAG contrast of that pair.
 *
 * @param array<string,mixed> $manifest Decoded manifest.
 */
function colours_section( array $manifest ): string {
	$list = array();

	foreach ( $manifest['products'] as $p ) {
		if ( ! is_array( $p ) || empty( $p['name'] ) ) {
			continue;
		}
		$list[] = array(
			'name' => (string) $p['name'],
			'hex'  => safe_hex( $p['color'] ?? null ),
			'on'   => safe_hex( $p['onColor'] ?? null, '#FFFFFF' ),
		);
	}

	if ( ! $list ) {
		return '';
	}

	$palette = isset( $manifest['palette'] ) && is_array( $manifest['palette'] ) ? $manifest['palette'] : array();
	if ( ! empty( $palette['ink'] ) ) {
		array_splice(
			$list,
			1,
			0,
			array(
				array(
					'name' => __( 'WPAnchorBay Navy', 'wpab-brand-assets' ),
					'hex'  => safe_hex( $palette['ink'] ),
					'on'   => '#FFFFFF',
				),
			)
		);
	}

	ob_start();
	?>
	<div class="wpab-ba-section" id="wpab-ba-colours">
		<?php
		echo section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside section_head().
			__( 'Colours', 'wpab-brand-assets' ),
			__( 'One colour per product.', 'wpab-brand-assets' ),
			__( 'Select a swatch to copy its HEX. Each colour is paired with the text colour that stays readable on it; the ratio is the WCAG contrast of that pair.', 'wpab-brand-assets' )
		);
		?>
		<div class="wpab-ba-colours">
			<?php foreach ( $list as $c ) : ?>
				<?php
				$ratio = contrast_ratio( $c['hex'], $c['on'] );
				$rgb   = 'rgb(' . implode( ', ', hex_to_rgb( $c['hex'] ) ) . ')';
				?>
				<div class="wpab-ba-swatch">
					<button type="button" class="wpab-ba-swatch__big wpab-ba-js-copy" data-copy="<?php echo esc_attr( $c['hex'] ); ?>"
						style="background:<?php echo esc_attr( $c['hex'] ); ?>;color:<?php echo esc_attr( $c['on'] ); ?>"
						aria-label="<?php echo esc_attr( sprintf(
							/* translators: 1: colour name, 2: hex value. */
							__( 'Copy %1$s %2$s', 'wpab-brand-assets' ),
							$c['name'],
							$c['hex']
						) ); ?>">
						<span class="wpab-ba-swatch__aa">Aa</span>
						<span class="wpab-ba-swatch__pair"><?php echo esc_html( number_format_i18n( $ratio, 1 ) . ':1 · ' . contrast_grade( $ratio ) ); ?></span>
					</button>
					<div class="wpab-ba-swatch__info">
						<h3><?php echo esc_html( $c['name'] ); ?></h3>
						<div class="wpab-ba-swatch__vals">
							<button type="button" class="wpab-ba-js-copy" data-copy="<?php echo esc_attr( $c['hex'] ); ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s: hex value. */
									__( 'Copy HEX %s', 'wpab-brand-assets' ),
									$c['hex']
								) ); ?>"><?php echo esc_html( $c['hex'] ); ?></button>
							<button type="button" class="wpab-ba-js-copy" data-copy="<?php echo esc_attr( $rgb ); ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s: RGB value. */
									__( 'Copy RGB %s', 'wpab-brand-assets' ),
									$rgb
								) ); ?>"><?php echo esc_html( $rgb ); ?></button>
						</div>
						<p><?php echo esc_html( sprintf(
							/* translators: 1: text colour hex, 2: contrast ratio. */
							__( 'Text on it: %1$s (contrast %2$s:1)', 'wpab-brand-assets' ),
							strtoupper( $c['on'] ),
							number_format_i18n( $ratio, 2 )
						) ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Shortcode: [wpanchorbay_brand_usage]. The usage section: clear space,
 * minimum sizes, placement, do and don't. The rule lists come from the
 * manifest's own usage guidelines so they never drift from the public page.
 */
function usage_shortcode(): string {
	$manifest = get_manifest();

	if ( null === $manifest ) {
		return unavailable_notice();
	}

	$usage       = isset( $manifest['usage'] ) && is_array( $manifest['usage'] ) ? $manifest['usage'] : array();
	$allowed     = isset( $usage['allowed'] ) && is_array( $usage['allowed'] ) ? $usage['allowed'] : array();
	$not_allowed = isset( $usage['notAllowed'] ) && is_array( $usage['notAllowed'] ) ? $usage['notAllowed'] : array();

	if ( ! $allowed && ! $not_allowed ) {
		return '';
	}

	$glyphs = icons();
	$brand  = brand_product( $manifest );
	$logo   = $brand ? asset_best_url( $brand['assets']['logo'] ?? null ) : '';
	$dark   = $brand ? asset_best_url( $brand['assets']['logo-dark'] ?? null ) : '';
	$icon   = $brand ? asset_best_url( $brand['assets']['icon'] ?? null ) : '';
	$bname  = $brand ? (string) ( $brand['name'] ?? '' ) : '';

	// Clear-space diagram geometry (same numbers as the public page).
	$x  = 30;
	$lx = 60;
	$ly = 60;
	$lw = 619;
	$lh = 120;

	// "Do and don't" figures: [correct?, caption, modifier class, uses dark logo].
	$figs = array(
		array( true, __( 'Place it on white or a plain light colour.', 'wpab-brand-assets' ), 'light', false ),
		array( true, __( 'Use the dark logo on navy.', 'wpab-brand-assets' ), 'navy', true ),
		array( true, __( 'Scale proportionally.', 'wpab-brand-assets' ), 'small', false ),
		array( false, __( "Don't stretch or squash it.", 'wpab-brand-assets' ), 'stretch', false ),
		array( false, __( "Don't recolour it.", 'wpab-brand-assets' ), 'recolour', false ),
		array( false, __( "Don't place it on a busy background.", 'wpab-brand-assets' ), 'busy', false ),
		array( false, __( "Don't add shadows, outlines or rotation.", 'wpab-brand-assets' ), 'effects', false ),
	);

	ob_start();
	?>
	<div class="wpab-ba wpab-ba-usage wpab-ba-section" id="wpab-ba-usage">
		<?php
		echo section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside section_head().
			__( 'Usage', 'wpab-brand-assets' ),
			__( 'Use the marks as they are.', 'wpab-brand-assets' ),
			__( 'A few rules keep the brand recognisable everywhere it appears.', 'wpab-brand-assets' )
		);
		?>
		<div class="wpab-ba-usage__grid">
			<?php if ( $logo ) : ?>
				<div class="wpab-ba-box">
					<h3><?php esc_html_e( 'Clear space', 'wpab-brand-assets' ); ?></h3>
					<p class="wpab-ba-sub"><?php esc_html_e( "Keep a margin of x around the logo, where x is a quarter of the icon tile's height (the tile's corner radius). Nothing else enters that area.", 'wpab-brand-assets' ); ?></p>
					<svg class="wpab-ba-clear" viewBox="0 0 739 280" role="img" aria-labelledby="wpab-ba-cs-t wpab-ba-cs-d">
						<title id="wpab-ba-cs-t"><?php echo esc_html( sprintf(
							/* translators: %s: brand name. */
							__( 'Clear space around the %s logo', 'wpab-brand-assets' ),
							$bname
						) ); ?></title>
						<desc id="wpab-ba-cs-d"><?php esc_html_e( 'The logo with a margin on every side equal to x, a quarter of the icon tile height.', 'wpab-brand-assets' ); ?></desc>
						<path fill="#39CCCC" fill-opacity=".22" fill-rule="evenodd" d="M<?php echo (int) ( $lx - $x ); ?> <?php echo (int) ( $ly - $x ); ?>h<?php echo (int) ( $lw + 2 * $x ); ?>v<?php echo (int) ( $lh + 2 * $x ); ?>h-<?php echo (int) ( $lw + 2 * $x ); ?>zM<?php echo (int) $lx; ?> <?php echo (int) $ly; ?>v<?php echo (int) $lh; ?>h<?php echo (int) $lw; ?>v-<?php echo (int) $lh; ?>z"/>
						<image href="<?php echo esc_url( $logo ); ?>" x="<?php echo (int) $lx; ?>" y="<?php echo (int) $ly; ?>" width="<?php echo (int) $lw; ?>" height="<?php echo (int) $lh; ?>"/>
						<g fill="#001F3F">
							<rect x="<?php echo (int) ( $lx - $x ); ?>" y="<?php echo (int) ( $ly - $x ); ?>" width="<?php echo (int) $x; ?>" height="<?php echo (int) $x; ?>" fill-opacity=".16"/>
							<text x="<?php echo (int) ( $lx - $x / 2 ); ?>" y="<?php echo (int) ( $ly - $x / 2 + 7 ); ?>" text-anchor="middle" font-size="20" font-weight="700">x</text>
							<rect x="<?php echo (int) ( $lx - $x ); ?>" y="<?php echo (int) ( $ly + $lh / 2 - $x / 2 ); ?>" width="<?php echo (int) $x; ?>" height="<?php echo (int) $x; ?>" fill-opacity=".16"/>
							<text x="<?php echo (int) ( $lx - $x / 2 ); ?>" y="<?php echo (int) ( $ly + $lh / 2 + 7 ); ?>" text-anchor="middle" font-size="20" font-weight="700">x</text>
							<rect x="<?php echo (int) ( $lx + $lw ); ?>" y="<?php echo (int) ( $ly + $lh ); ?>" width="<?php echo (int) $x; ?>" height="<?php echo (int) $x; ?>" fill-opacity=".16"/>
							<text x="<?php echo (int) ( $lx + $lw + $x / 2 ); ?>" y="<?php echo (int) ( $ly + $lh + $x / 2 + 7 ); ?>" text-anchor="middle" font-size="20" font-weight="700">x</text>
						</g>
						<path d="M<?php echo (int) $lx; ?> <?php echo (int) ( $ly + $lh + $x + 14 ); ?>h120M<?php echo (int) $lx; ?> <?php echo (int) ( $ly + $lh + $x + 8 ); ?>v12M<?php echo (int) ( $lx + 120 ); ?> <?php echo (int) ( $ly + $lh + $x + 8 ); ?>v12" stroke="#001F3F" stroke-width="1.5" fill="none"/>
						<text x="<?php echo (int) ( $lx + 132 ); ?>" y="<?php echo (int) ( $ly + $lh + $x + 20 ); ?>" font-size="16" fill="#001F3F"><?php esc_html_e( 'Icon tile height = 4x', 'wpab-brand-assets' ); ?></text>
					</svg>
				</div>
			<?php endif; ?>

			<div class="wpab-ba-box">
				<?php if ( $logo || $icon ) : ?>
					<h3><?php esc_html_e( 'Minimum size', 'wpab-brand-assets' ); ?></h3>
					<p class="wpab-ba-sub"><?php esc_html_e( 'Below these sizes the mark loses detail.', 'wpab-brand-assets' ); ?></p>
					<div class="wpab-ba-minsizes">
						<?php if ( $icon ) : ?>
							<div class="wpab-ba-ms">
								<div class="wpab-ba-ms__sample"><img src="<?php echo esc_url( $icon ); ?>" alt="" width="24" height="24" loading="lazy" decoding="async"></div>
								<div><b><?php esc_html_e( 'Icon: 24 px', 'wpab-brand-assets' ); ?></b><span><?php esc_html_e( 'Smallest size for the tile on screen.', 'wpab-brand-assets' ); ?></span></div>
							</div>
						<?php endif; ?>
						<?php if ( $logo ) : ?>
							<div class="wpab-ba-ms">
								<div class="wpab-ba-ms__sample"><img src="<?php echo esc_url( $logo ); ?>" alt="" width="120" loading="lazy" decoding="async"></div>
								<div><b><?php esc_html_e( 'Logo: 120 px wide', 'wpab-brand-assets' ); ?></b><span><?php esc_html_e( 'Smallest width for tile and wordmark.', 'wpab-brand-assets' ); ?></span></div>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( $allowed ) : ?>
					<h3 class="wpab-ba-gap"><?php esc_html_e( 'Placement', 'wpab-brand-assets' ); ?></h3>
					<ul class="wpab-ba-rules wpab-ba-rules--yes">
						<?php foreach ( $allowed as $rule ) : ?>
							<li><?php echo $glyphs['check']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php echo esc_html( (string) $rule ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $logo ) : ?>
			<h3 class="wpab-ba-subhead"><?php esc_html_e( "Do and don't", 'wpab-brand-assets' ); ?></h3>
			<div class="wpab-ba-dd">
				<?php foreach ( $figs as $f ) : ?>
					<?php
					$src = ( $f[3] && $dark ) ? $dark : $logo;
					$alt = $f[0]
						/* translators: %s: brand name. */
						? sprintf( __( '%s logo, correct use', 'wpab-brand-assets' ), $bname )
						/* translators: 1: brand name, 2: what is wrong. */
						: sprintf( __( '%1$s logo, incorrect use: %2$s', 'wpab-brand-assets' ), $bname, $f[1] );
					?>
					<figure>
						<div class="wpab-ba-dd__stage wpab-ba-dd__stage--<?php echo esc_attr( $f[2] ); ?>">
							<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async">
							<span class="wpab-ba-dd__mark<?php echo $f[0] ? ' is-ok' : ''; ?>"><?php echo $f[0] ? $glyphs['check'] : $glyphs['x']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?></span>
						</div>
						<figcaption>
							<span class="wpab-ba-tag <?php echo $f[0] ? 'is-do' : 'is-dont'; ?>"><?php echo $f[0] ? esc_html__( 'Do', 'wpab-brand-assets' ) : esc_html__( "Don't", 'wpab-brand-assets' ); ?></span>
							<span><?php echo esc_html( $f[1] ); ?></span>
						</figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $not_allowed ) : ?>
			<ul class="wpab-ba-rules wpab-ba-rules--no">
				<?php foreach ( $not_allowed as $rule ) : ?>
					<li><?php echo $glyphs['x']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php echo esc_html( (string) $rule ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
	$html = (string) ob_get_clean();

	wp_enqueue_style( STYLE_HANDLE );

	return $html;
}

/**
 * Shortcode handler for [wpanchorbay_brand_assets].
 *
 * @param array<string,string>|string $atts Shortcode attributes.
 */
function shortcode( $atts = array() ): string {
	$atts = shortcode_atts(
		array(
			'show'     => 'all',      // all | plugins | brand
			'products' => '',         // optional comma-separated slug allowlist
		),
		$atts,
		'wpanchorbay_brand_assets'
	);

	$manifest = get_manifest();

	if ( null === $manifest ) {
		return unavailable_notice();
	}

	$only = array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['products'] ) ) );
	$show = in_array( $atts['show'], array( 'all', 'plugins', 'brand' ), true ) ? $atts['show'] : 'all';

	$cards = '';
	$count = 0;
	foreach ( $manifest['products'] as $slug => $product ) {
		if ( ! is_array( $product ) ) {
			continue;
		}

		$product['slug'] = $product['slug'] ?? $slug;
		$kind            = isset( $product['kind'] ) ? (string) $product['kind'] : 'plugin';

		if ( 'plugins' === $show && 'brand' === $kind ) {
			continue;
		}
		if ( 'brand' === $show && 'brand' !== $kind ) {
			continue;
		}
		if ( $only && ! in_array( sanitize_key( (string) $product['slug'] ), $only, true ) ) {
			continue;
		}

		$card = render_card( $product );
		if ( '' !== $card ) {
			++$count;
		}
		$cards .= $card;
	}

	if ( '' === $cards ) {
		return '';
	}

	// The full page (no filters): section headings, the card filter and the
	// colours grid. A filtered use of the shortcode (one product page, say)
	// gets just the cards.
	$full = 'all' === $show && ! $only;

	$out = '<div class="wpab-ba wpab-ba-assets">';
	if ( $full ) {
		$out .= '<div class="wpab-ba-section" id="wpab-ba-plugins">'
			. section_head(
				__( 'Plugins', 'wpab-brand-assets' ),
				__( 'Pick a brand, take what you need.', 'wpab-brand-assets' ),
				__( 'Each card has the logo, its variants, its colours and links. Use the preview toggle to check a mark on light and dark.', 'wpab-brand-assets' )
			);
	}
	$out .= '<div class="wpab-ba-cards"' . ( $full && $count > 3 ? ' data-wpab-filter' : '' ) . '>' . $cards . '</div>';
	if ( $full ) {
		$out .= '</div>' . colours_section( $manifest );
	}
	$out .= '</div>';

	wp_enqueue_style( STYLE_HANDLE );
	wp_enqueue_script( SCRIPT_HANDLE );

	return $out;
}

/* -------------------------------------------------------------------------
 * Assets
 *
 * Registered on every front-end request but only ENQUEUED by the shortcode, so
 * pages without it carry no extra CSS/JS.
 * ---------------------------------------------------------------------- */

function register_assets(): void {
	wp_register_style( STYLE_HANDLE, false, array(), VERSION );
	wp_add_inline_style( STYLE_HANDLE, styles() );

	wp_register_script( SCRIPT_HANDLE, false, array(), VERSION, true );
	wp_add_inline_script( SCRIPT_HANDLE, script() );
}

/**
 * The layout of the public brand site (assets.wpanchorbay.com), in the theme's
 * own colours and fonts. Tokens come from the active theme's palette
 * (--wp--preset--*) with the ThemeBay One values as fallbacks. Hierarchy is
 * built from surface steps, not borders: page < card < inner panel < hover.
 * Light only. Everything is scoped under .wpab-ba so nothing reaches the
 * theme, and layout switches use container queries, so it behaves the same in
 * a full-width page and a narrow column.
 */
function styles(): string {
	return <<<'CSS'
.wpab-ba{--wpab-ink:var(--wp--preset--color--ink,#001F3F);--wpab-text:var(--wp--preset--color--text,#1E2D40);
--wpab-muted:var(--wp--preset--color--muted,#4F6177);--wpab-card:var(--wp--preset--color--soft,#EDF3F9);
--wpab-inner:#fff;--wpab-hover:var(--wp--preset--color--line,#D6E2EE);--wpab-sep:rgba(0,31,63,.1);
--wpab-link:var(--wp--preset--color--teal-text,#0F7776);--wpab-teal:var(--wp--preset--color--teal-light,#39CCCC);
--wpab-good:#116B3A;--wpab-bad:#A31D2B;--wpab-r:24px;--wpab-r-md:16px;--wpab-r-sm:12px;
--wpab-font:var(--wp--preset--font-family--inter,inherit);
--wpab-display:var(--wp--preset--font-family--inter-tight,var(--wpab-font));
--wpab-mono:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
container-type:inline-size;box-sizing:border-box;color:var(--wpab-text);font-family:var(--wpab-font);
font-size:1rem;line-height:1.6;margin:0 auto 56px;max-width:1240px;padding-inline:clamp(16px,4vw,40px);text-align:left}
.wpab-ba *,.wpab-ba *::before,.wpab-ba *::after{box-sizing:border-box}
.wpab-ba h1,.wpab-ba h2,.wpab-ba h3,.wpab-ba h4,.wpab-ba p,.wpab-ba ul,.wpab-ba figure,.wpab-ba pre{margin:0}
.wpab-ba h1,.wpab-ba h2,.wpab-ba h3,.wpab-ba h4{font-family:var(--wpab-display);color:var(--wpab-ink);text-transform:none}
.wpab-ba img,.wpab-ba svg{max-width:100%}
.wpab-ba a:focus-visible,.wpab-ba button:focus-visible,.wpab-ba summary:focus-visible,.wpab-ba pre:focus-visible{
outline:3px solid var(--wpab-link);outline-offset:2px;border-radius:8px}
.wpab-ba button{font-family:inherit;cursor:pointer;-webkit-appearance:none;appearance:none;border:0;background:none;color:inherit;padding:0;margin:0;text-transform:none;letter-spacing:normal}
.wpab-ba a{cursor:pointer}
.wpab-ba-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.wpab-ba [hidden]{display:none!important}

/* eyebrow, section heads */
.wpab-ba .wpab-ba-eyebrow{font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--wpab-muted)}
.wpab-ba-section{margin-top:80px}
.wpab-ba-sechead{margin-bottom:40px;max-width:640px}
.wpab-ba .wpab-ba-sechead h2{font-size:clamp(1.75rem,1.3rem + 1.6vw,2.25rem);line-height:1.15;letter-spacing:-.02em;font-weight:700;margin:8px 0 16px}
.wpab-ba .wpab-ba-sechead p:not(.wpab-ba-eyebrow){color:var(--wpab-muted);font-size:1.0625rem}

/* buttons: surface fills, no outlines */
.wpab-ba .wpab-ba-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:8px 24px;
border-radius:999px;background:var(--wpab-card);color:var(--wpab-ink);font-size:1rem;font-weight:600;text-decoration:none;line-height:1.2;transition:background .15s}
.wpab-ba .wpab-ba-btn:hover{background:var(--wpab-hover);color:var(--wpab-ink)}
.wpab-ba .wpab-ba-btn--primary{background:var(--wpab-ink);color:#fff}
.wpab-ba .wpab-ba-btn--primary:hover{background:#0B3358;color:#fff}
.wpab-ba .wpab-ba-btn--brand{background:var(--wpab-ba-brand,#39CCCC);color:var(--wpab-ba-on-brand,#001F3F)}
.wpab-ba .wpab-ba-btn--brand:hover{background:color-mix(in srgb,var(--wpab-ba-brand,#39CCCC) 88%,#000);color:var(--wpab-ba-on-brand,#001F3F)}
.wpab-ba .wpab-ba-btn svg,.wpab-ba .wpab-ba-ibtn svg{width:16px;height:16px;flex:none;display:block}
.wpab-ba .wpab-ba-ibtn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:4px 16px;
border-radius:999px;background:var(--wpab-inner);color:var(--wpab-ink);font-size:.875rem;font-weight:600;line-height:1.2;text-decoration:none;transition:background .15s}
.wpab-ba .wpab-ba-ibtn:hover{background:var(--wpab-hover);color:var(--wpab-ink)}
.wpab-ba .wpab-ba-ibtn.wpab-ba-done,.wpab-ba .wpab-ba-chip.wpab-ba-done{background:#DDF3E6;color:var(--wpab-good)}

/* hero */
.wpab-ba.wpab-ba-hero{margin-bottom:0;padding-top:clamp(32px,6vw,72px)}
.wpab-ba-hero__grid{display:grid;gap:48px;align-items:center;grid-template-columns:minmax(0,1fr)}
@container (min-width:880px){.wpab-ba-hero__grid{grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr)}}
.wpab-ba.wpab-ba-hero h1{font-size:clamp(2.25rem,1.4rem + 3.4vw,3.5rem);line-height:1.06;letter-spacing:-.03em;font-weight:700;margin:16px 0 24px}
.wpab-ba .wpab-ba-lead{font-size:1.1875rem;color:var(--wpab-muted);max-width:32em}
.wpab-ba-actions{display:flex;flex-wrap:wrap;gap:16px;margin-top:40px}
.wpab-ba .wpab-ba-meta{margin-top:24px;font-size:.875rem;color:var(--wpab-muted)}
.wpab-ba-mosaic{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));background:var(--wpab-card);border-radius:var(--wpab-r);overflow:hidden}
.wpab-ba-mosaic>*{position:relative;aspect-ratio:1;display:grid;place-items:center}
.wpab-ba-mosaic>:not(:nth-child(4n))::before{content:"";position:absolute;right:0;top:0;bottom:0;width:1px;pointer-events:none;
background:linear-gradient(to bottom,transparent,var(--wpab-hover) 22%,var(--wpab-hover) 78%,transparent)}
.wpab-ba-mosaic>:nth-last-child(n+5)::after{content:"";position:absolute;left:0;right:0;bottom:0;height:1px;pointer-events:none;
background:linear-gradient(to right,transparent,var(--wpab-hover) 22%,var(--wpab-hover) 78%,transparent)}
.wpab-ba .wpab-ba-mosaic a{color:var(--wpab-ink);text-decoration:none;transition:background .14s}
.wpab-ba .wpab-ba-mosaic a:hover,.wpab-ba .wpab-ba-mosaic a:focus-visible{background:#fff}
.wpab-ba .wpab-ba-mosaic a:focus-visible{outline-offset:-3px;border-radius:0}
.wpab-ba-mosaic img{width:54%;height:54%;display:block}
.wpab-ba-mosaic__cap{position:absolute;left:0;right:0;bottom:0;padding:0 6px 10px;text-align:center;font-size:.75rem;font-weight:700;
line-height:1.2;opacity:0;transform:translateY(4px);transition:opacity .14s,transform .14s}
.wpab-ba-mosaic a:hover .wpab-ba-mosaic__cap,.wpab-ba-mosaic a:focus-visible .wpab-ba-mosaic__cap{opacity:1;transform:none}
.wpab-ba-mosaic__count{background:var(--wpab-ink);color:#fff;text-align:center;line-height:1.1}
.wpab-ba-mosaic__count b{display:block;font-size:2rem;letter-spacing:-.02em;font-family:var(--wpab-display)}
.wpab-ba-mosaic__count small{font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:#B9C8D8}
.wpab-ba-dev{display:grid;gap:16px;margin-top:64px;grid-template-columns:minmax(0,1fr)}
@container (min-width:760px){.wpab-ba-dev{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* boxes */
.wpab-ba-box{background:var(--wpab-card);border-radius:var(--wpab-r);padding:32px;min-width:0}
.wpab-ba .wpab-ba-box h2,.wpab-ba .wpab-ba-box h3{font-size:1.1875rem;line-height:1.3;letter-spacing:-.01em;font-weight:700;margin-bottom:8px}
.wpab-ba .wpab-ba-box>p,.wpab-ba .wpab-ba-sub{color:var(--wpab-muted);font-size:.9375rem;margin-bottom:24px}
/* typography, trademark note and closing download */
.wpab-ba-type{display:grid;gap:16px;grid-template-columns:minmax(0,1fr)}
@container (min-width:760px){.wpab-ba-type{grid-template-columns:repeat(2,minmax(0,1fr))}}
.wpab-ba-type__card{background:var(--wpab-card);border-radius:var(--wpab-r,24px);padding:32px}
.wpab-ba .wpab-ba-type__card h3{font-size:2rem;line-height:1.1;letter-spacing:-.02em;margin:8px 0 24px}
.wpab-ba-type__sample{font-size:1.25rem;line-height:1.5;color:var(--wpab-ink);margin:0 0 16px}
.wpab-ba-type__sample--display{font-family:var(--wpab-display);font-weight:700;letter-spacing:-.02em}
.wpab-ba-type__abc{color:var(--wpab-muted);font-size:1rem;margin:0}
.wpab-ba-closing .wpab-ba-dev{margin-top:80px}
.wpab-ba-note{margin-top:16px;background:var(--wpab-card);border-radius:var(--wpab-r,24px);padding:24px 32px;color:var(--wpab-muted);font-size:.9375rem;line-height:1.6}
.wpab-ba-note p{margin:0 0 8px}.wpab-ba-note p:last-child{margin:0}
.wpab-ba-note a{color:var(--wpab-ink);font-weight:600}
.wpab-ba-end{margin-top:48px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px}
.wpab-ba .wpab-ba-end h2{font-size:1.5rem;margin:0}
.wpab-ba-code{display:flex;align-items:stretch;gap:8px}
.wpab-ba-code pre{flex:1;min-width:0;padding:12px 16px;border-radius:var(--wpab-r-sm);background:var(--wpab-inner);overflow-x:auto;
font:.8125rem/1.6 var(--wpab-mono);color:var(--wpab-ink);scrollbar-width:thin;scrollbar-color:var(--wpab-hover) transparent}
.wpab-ba-code pre::-webkit-scrollbar{height:6px}
.wpab-ba-code pre::-webkit-scrollbar-thumb{background:var(--wpab-hover);border-radius:999px}
.wpab-ba-code .wpab-ba-ibtn{flex:none;align-self:center}

/* filter */
.wpab-ba-tools{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;margin-bottom:32px}
.wpab-ba-search{flex:1 1 280px;max-width:440px}
.wpab-ba .wpab-ba-search input{width:100%;min-height:48px;padding:8px 24px;border-radius:999px;border:2px solid transparent;background:var(--wpab-card);
color:var(--wpab-ink);font:inherit;box-shadow:none;outline:0;-webkit-appearance:none;appearance:none}
.wpab-ba .wpab-ba-search input::placeholder{color:var(--wpab-muted);opacity:1}
.wpab-ba .wpab-ba-search input:focus,.wpab-ba .wpab-ba-search input:focus-visible{outline:0;box-shadow:none;background:#fff;border-color:var(--wpab-link)}
.wpab-ba .wpab-ba-count{color:var(--wpab-muted);font-size:.875rem}
.wpab-ba-empty{padding:64px 16px;text-align:center;color:var(--wpab-muted);background:var(--wpab-card);border-radius:var(--wpab-r)}

/* product card */
.wpab-ba-cards{display:grid;gap:40px}
.wpab-ba-card{background:var(--wpab-card);border-radius:var(--wpab-r);overflow:clip;scroll-margin-top:96px}
.wpab-ba-card[hidden]{display:none}
.wpab-ba-card__head{display:flex;flex-wrap:wrap;gap:24px;align-items:center;justify-content:space-between;padding:32px 32px 24px;
background:var(--wpab-card);background:color-mix(in srgb,var(--wpab-ba-brand,#39CCCC) 14%,var(--wpab-card))}
.wpab-ba-ident{display:flex;gap:16px;align-items:center;min-width:0}
.wpab-ba-ident>img{width:56px;height:56px;flex:none;display:block}
.wpab-ba .wpab-ba-ident h3{font-size:1.5rem;line-height:1.15;letter-spacing:-.02em;font-weight:700}
.wpab-ba .wpab-ba-ident p{color:var(--wpab-muted);margin-top:4px;font-size:.9375rem;max-width:44ch}
.wpab-ba-kind{display:block;font-size:.6875rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--wpab-muted);margin-bottom:4px}
.wpab-ba-card__body{display:grid;gap:32px;padding:32px;grid-template-columns:minmax(0,1fr)}
@container (min-width:860px){.wpab-ba-card__body{grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:40px}}
.wpab-ba-stage{position:relative;display:flex}
.wpab-ba-panel{flex:1;position:relative;min-height:260px;border-radius:var(--wpab-r-md);display:grid;place-items:center;padding:56px 32px 48px;background:#fff;transition:background .2s}
@container (min-width:860px){.wpab-ba-panel{min-height:360px}}
.wpab-ba-stage.is-dark .wpab-ba-panel{background:#001F3F}
.wpab-ba-panel__img{display:block;max-width:100%;height:auto}
.wpab-ba-panel__img--wide{width:min(100%,400px)}
.wpab-ba-panel__img--sq{width:min(44%,168px)}
.wpab-ba .wpab-ba-panel__note{position:absolute;left:16px;right:16px;bottom:16px;font-size:.75rem;text-align:center;color:var(--wpab-muted)}
.wpab-ba-stage.is-dark .wpab-ba-panel__note{color:#B9C8D8}
.wpab-ba-seg{position:absolute;top:16px;left:16px;z-index:2;display:inline-flex;gap:2px;padding:4px;border-radius:999px;background:var(--wpab-card)}
.wpab-ba-stage.is-dark .wpab-ba-seg{background:rgba(255,255,255,.14)}
.wpab-ba .wpab-ba-seg button{min-height:36px;padding:4px 16px;border-radius:999px;color:var(--wpab-muted);font-size:.8125rem;font-weight:600;transition:background .15s}
.wpab-ba-stage.is-dark .wpab-ba-seg button{color:#D5E0EB}
.wpab-ba .wpab-ba-seg button[aria-pressed="true"]{background:#fff;color:var(--wpab-ink)}
.wpab-ba-side{display:flex;flex-direction:column;gap:32px;min-width:0}
.wpab-ba .wpab-ba-side h4,.wpab-ba-files summary{font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--wpab-muted);font-family:var(--wpab-display)}
.wpab-ba .wpab-ba-side h4{margin-bottom:16px}
.wpab-ba-variants{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.wpab-ba .wpab-ba-variant{display:flex;flex-direction:column;gap:8px;padding:8px 8px 16px;border-radius:var(--wpab-r-md);background:var(--wpab-inner);
color:var(--wpab-ink);text-align:left;text-decoration:none;transition:background .15s}
.wpab-ba .wpab-ba-variant:hover{background:var(--wpab-hover)}
.wpab-ba .wpab-ba-variant[aria-pressed="true"]{background:#fff;background:color-mix(in srgb,var(--wpab-ba-brand,#39CCCC) 26%,#fff)}
.wpab-ba-variant__thumb{height:72px;border-radius:var(--wpab-r-sm);background:var(--wpab-card);display:grid;place-items:center}
.wpab-ba-variant__thumb img{max-width:78%;max-height:48px;display:block}
.wpab-ba-variant__name{font-weight:600;font-size:.9375rem;padding:0 8px}
.wpab-ba-variant__desc{font-size:.8125rem;line-height:1.45;color:var(--wpab-muted);padding:0 8px;margin-top:-4px}
.wpab-ba-chips,.wpab-ba-links{display:flex;flex-wrap:wrap;gap:8px}
.wpab-ba .wpab-ba-chip{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:8px 24px 8px 8px;border-radius:999px;background:var(--wpab-inner);transition:background .15s}
.wpab-ba .wpab-ba-chip:hover{background:var(--wpab-hover)}
.wpab-ba-chip i{width:32px;height:32px;border-radius:999px;display:block;flex:none;box-shadow:inset 0 0 0 1px rgba(0,31,63,.2)}
.wpab-ba-chip span{font-family:var(--wpab-mono);font-size:.875rem}

/* all files */
.wpab-ba-files{background:#edf3f9;border-top:1px solid var(--wpab-sep)}
.wpab-ba-files summary{list-style:none;cursor:pointer;padding:24px 32px;display:flex;align-items:center;justify-content:space-between;transition:background .15s}
.wpab-ba-files summary:hover{background:var(--wpab-hover)}
.wpab-ba-files summary::-webkit-details-marker{display:none}
.wpab-ba-files summary::after{content:"";width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);margin-right:4px;transition:transform .15s}
.wpab-ba-files[open] summary::after{transform:rotate(-135deg)}
.wpab-ba-rows{padding:0 32px 16px}
.wpab-ba-row{display:grid;gap:8px;padding:16px 0;border-top:1px solid var(--wpab-sep)}
.wpab-ba-row__meta{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline}
.wpab-ba-row__meta b{font-size:.9375rem;color:var(--wpab-ink)}
.wpab-ba-row__meta small{color:var(--wpab-muted);font-size:.8125rem}
.wpab-ba-row__acts{display:flex;flex-wrap:wrap;gap:8px}
.wpab-ba .wpab-ba-row .wpab-ba-ibtn{background:#fff}
.wpab-ba .wpab-ba-row .wpab-ba-ibtn:hover{background:var(--wpab-hover)}
/* a short hint on hover or focus for the link buttons */
.wpab-ba .wpab-ba-ibtn[data-tip]{position:relative}
.wpab-ba .wpab-ba-ibtn[data-tip]::after{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 8px);z-index:5;width:max-content;max-width:240px;padding:8px 12px;border-radius:10px;background:#001F3F;color:#fff;font-size:.75rem;font-weight:500;line-height:1.4;text-align:center;pointer-events:none;opacity:0;visibility:hidden;transform:translate(-50%,4px);transition:opacity .15s,transform .15s,visibility 0s linear .15s}
.wpab-ba .wpab-ba-ibtn[data-tip]:is(:hover,:focus-visible)::after{opacity:1;visibility:visible;transform:translate(-50%,0);transition-delay:0s}
@container (min-width:720px){.wpab-ba-row{grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:24px}}

/* colours */
.wpab-ba-colours{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(min(248px,100%),1fr))}
.wpab-ba-swatch{display:flex;flex-direction:column;border-radius:var(--wpab-r);overflow:clip;background:var(--wpab-card)}
.wpab-ba .wpab-ba-swatch__big{display:flex;flex-direction:column;justify-content:space-between;align-items:flex-start;min-height:168px;padding:24px;text-align:left;width:100%;transition:filter .15s}
.wpab-ba .wpab-ba-swatch__big:hover{filter:brightness(.93)}
.wpab-ba-swatch__aa{font-size:2.5rem;font-weight:700;line-height:1;letter-spacing:-.02em;font-family:var(--wpab-display)}
.wpab-ba-swatch__pair{font-size:.8125rem;font-weight:600;padding:2px 12px;border-radius:999px;background:color-mix(in srgb,currentColor 18%,transparent)}
.wpab-ba-swatch__info{padding:24px;display:grid;gap:16px}
.wpab-ba .wpab-ba-swatch__info h3{font-size:1.0625rem;letter-spacing:-.01em;font-weight:700}
.wpab-ba-swatch__vals{display:flex;flex-wrap:wrap;gap:8px}
.wpab-ba .wpab-ba-swatch__vals button{font-family:var(--wpab-mono);font-size:.8125rem;padding:4px 16px;min-height:44px;border-radius:999px;background:var(--wpab-inner);color:var(--wpab-ink);transition:background .15s;overflow-wrap:anywhere}
.wpab-ba .wpab-ba-swatch__vals button:hover{background:var(--wpab-hover)}
.wpab-ba .wpab-ba-swatch__info p{font-size:.8125rem;color:var(--wpab-muted)}

/* usage */
.wpab-ba-usage{margin-bottom:0}
.wpab-ba-usage__grid{display:grid;gap:16px;align-items:start;grid-template-columns:minmax(0,1fr)}
@container (min-width:860px){.wpab-ba-usage__grid{grid-template-columns:minmax(0,1.4fr) minmax(0,1fr)}}
.wpab-ba-clear{width:100%;height:auto;display:block;border-radius:var(--wpab-r-md);background:#fff}
.wpab-ba-minsizes{display:grid;gap:8px}
.wpab-ba-ms{display:flex;align-items:center;gap:24px;padding:16px 24px;border-radius:var(--wpab-r-md);background:#fff;color:var(--wpab-ink)}
.wpab-ba-ms__sample{display:flex;align-items:center;justify-content:center;min-width:128px;flex:none}
.wpab-ba-ms b{display:block}
.wpab-ba-ms span{font-size:.875rem;color:var(--wpab-muted)}
.wpab-ba .wpab-ba-gap{margin-top:32px}
.wpab-ba-rules{list-style:none;padding:0;display:grid;gap:8px;font-size:.9375rem}
.wpab-ba-rules li{display:flex;gap:8px;list-style:none;margin:0;padding:0}
.wpab-ba-rules svg{width:18px;height:18px;flex:none;margin-top:3px}
.wpab-ba-rules--yes svg{color:var(--wpab-good)}
.wpab-ba-rules--no svg{color:var(--wpab-bad)}
.wpab-ba-rules--no{margin-top:40px}
.wpab-ba .wpab-ba-subhead{margin:64px 0 24px;font-size:1.375rem;letter-spacing:-.01em;font-weight:700}
.wpab-ba-dd{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(min(232px,100%),1fr))}
.wpab-ba-dd figure{display:flex;flex-direction:column;background:var(--wpab-card);border-radius:var(--wpab-r);padding:8px 8px 16px;gap:16px}
.wpab-ba-dd__stage{height:144px;border-radius:var(--wpab-r-md);display:grid;place-items:center;overflow:hidden;position:relative;background:#fff}
.wpab-ba-dd__stage img{display:block;width:140px;height:auto}
.wpab-ba-dd__stage--navy{background:#001F3F}
.wpab-ba-dd__stage--small img{width:96px}
.wpab-ba-dd__stage--stretch img{transform:scaleX(1.45) scaleY(.8)}
.wpab-ba-dd__stage--recolour img{filter:hue-rotate(150deg) saturate(2.2)}
.wpab-ba-dd__stage--busy{background:repeating-conic-gradient(#f5c000 0 25%,#F02A74 0 50%,#0000F2 0 75%,#22CF95 0 100%) 0 0/28px 28px}
.wpab-ba-dd__stage--effects img{transform:rotate(-8deg);filter:drop-shadow(6px 8px 3px rgba(0,0,0,.5))}
.wpab-ba-dd__mark{position:absolute;top:8px;right:8px;width:24px;height:24px;border-radius:999px;background:var(--wpab-bad);color:#fff;display:grid;place-items:center}
.wpab-ba-dd__mark.is-ok{background:var(--wpab-good)}
.wpab-ba-dd__mark svg{width:14px;height:14px}
.wpab-ba-dd figcaption{display:flex;gap:8px;font-size:.875rem;align-items:flex-start;padding:0 8px}
.wpab-ba-tag{flex:none;font-size:.6875rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px;border-radius:999px;color:#fff;margin-top:2px}
.wpab-ba-tag.is-do{background:var(--wpab-good)}
.wpab-ba-tag.is-dont{background:var(--wpab-bad)}

.wpab-ba-notice{padding:12px 16px;border-radius:var(--wpab-r-sm);background:#FBE9EB;color:var(--wpab-bad);font-size:.875rem}
.wpab-ba-toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,16px);background:#001F3F;color:#fff;max-width:calc(100vw - 32px);
padding:12px 24px;border-radius:999px;font-size:.9375rem;font-weight:500;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:9999}
.wpab-ba-toast.wpab-ba-show{opacity:1;transform:translate(-50%,0)}

/* phones (container width) */
@container (max-width:520px){
.wpab-ba-section{margin-top:64px}
.wpab-ba-box{padding:24px 16px}
.wpab-ba-card__head{padding:24px 16px 16px}
.wpab-ba-card__head .wpab-ba-btn{width:100%}
.wpab-ba-card__body{padding:16px;gap:24px}
.wpab-ba-panel{padding:56px 16px 48px}
.wpab-ba-files summary{padding:16px}
.wpab-ba-rows{padding:0 16px 8px}
.wpab-ba-row__acts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}
.wpab-ba .wpab-ba-row .wpab-ba-ibtn{padding:4px 8px;font-size:.8125rem;gap:6px}
.wpab-ba-colours{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.wpab-ba-swatch__big{min-height:120px;padding:16px}
.wpab-ba-swatch__aa{font-size:2rem}
.wpab-ba-swatch__info{padding:16px;gap:12px}
.wpab-ba .wpab-ba-swatch__vals button{max-width:100%;text-align:left;font-size:.75rem}
.wpab-ba-code{flex-wrap:wrap}
.wpab-ba-code pre{flex-basis:100%}
.wpab-ba-ms{gap:16px;padding:16px}
.wpab-ba-ms__sample{min-width:96px}
.wpab-ba-actions .wpab-ba-btn{width:100%}
}
CSS;
}

/**
 * Click-to-copy for every element carrying `.wpab-ba-js-copy`, a small toast,
 * the light/dark preview and variant picker on each card, and the card filter.
 * Without JavaScript the cards still show the default preview and every file
 * link works; only the copy buttons and these controls need it.
 */
function script(): string {
	return <<<'JS'
(function () {
	function toast( message ) {
		var el = document.getElementById( 'wpab-ba-toast' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'wpab-ba-toast';
			el.className = 'wpab-ba-toast';
			el.setAttribute( 'role', 'status' );
			el.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( el );
		}
		el.textContent = message;
		el.classList.add( 'wpab-ba-show' );
		clearTimeout( el._wpabTimer );
		el._wpabTimer = setTimeout( function () {
			el.classList.remove( 'wpab-ba-show' );
		}, 2000 );
	}

	function fallbackCopy( text, onDone ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'aria-hidden', 'true' );
		ta.style.cssText = 'position:fixed;opacity:0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
			onDone();
		} catch ( err ) {
			toast( 'Copy failed' );
		}
		ta.remove();
	}

	// The files live on another origin, where the download attribute is ignored and the
	// browser would open the file. Fetch it and save it from here: one click, no new page.
	document.addEventListener( 'click', function ( event ) {
		var a = event.target.closest && event.target.closest( '.wpab-ba-row a[download]' );
		if ( ! a || ! window.fetch || ! window.URL || ! URL.createObjectURL ) {
			return;
		}
		event.preventDefault();
		fetch( a.href ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( r.status ); }
			return r.blob();
		} ).then( function ( blob ) {
			var u = URL.createObjectURL( blob );
			var l = document.createElement( 'a' );
			l.href = u;
			l.download = a.href.split( '/' ).pop().split( '?' )[0];
			document.body.appendChild( l );
			l.click();
			l.remove();
			setTimeout( function () { URL.revokeObjectURL( u ); }, 4000 );
		} ).catch( function () {
			window.open( a.href, '_blank', 'noopener' );
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest && event.target.closest( '.wpab-ba-js-copy' );
		if ( ! btn ) {
			return;
		}
		var text = btn.dataset.copy;
		var onDone = function () {
			btn.classList.add( 'wpab-ba-done' );
			setTimeout( function () {
				btn.classList.remove( 'wpab-ba-done' );
			}, 900 );
			toast( 'Copied to clipboard' );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onDone, function () {
				fallbackCopy( text, onDone );
			} );
		} else {
			fallbackCopy( text, onDone );
		}
	} );

	/* Preview stage: light/dark toggle and the variant picker. */
	function initStage( stage ) {
		var card = stage.closest( '.wpab-ba-card' );
		var panel = stage.querySelector( '[data-wpab-panel]' );
		var img = panel.querySelector( 'img' );
		var note = panel.querySelector( '[data-wpab-note]' );
		var variants = card.querySelectorAll( '[data-wpab-variant]' );
		var name = stage.getAttribute( 'data-name' ) || '';
		var state = { v: stage.getAttribute( 'data-default' ), dark: false };

		var seg = document.createElement( 'div' );
		seg.className = 'wpab-ba-seg';
		seg.setAttribute( 'role', 'group' );
		seg.setAttribute( 'aria-label', 'Preview background' );
		var light = document.createElement( 'button' );
		var dark = document.createElement( 'button' );
		light.type = dark.type = 'button';
		light.textContent = 'Light';
		dark.textContent = 'Dark';
		seg.appendChild( light );
		seg.appendChild( dark );
		stage.appendChild( seg );

		function paint() {
			var el = card.querySelector( '[data-wpab-variant="' + state.v + '"]' );
			if ( ! el ) {
				return;
			}
			var label = el.getAttribute( 'data-label' ) || '';
			img.src = state.dark ? el.getAttribute( 'data-dark' ) : el.getAttribute( 'data-src' );
			img.alt = name + ' ' + label.toLowerCase() + ( state.dark ? ' on a dark background' : ' on a light background' );
			img.className = 'wpab-ba-panel__img wpab-ba-panel__img--' + el.getAttribute( 'data-shape' );
			var n = el.getAttribute( 'data-note' );
			note.textContent = n || '';
			note.hidden = ! n;
			stage.classList.toggle( 'is-dark', state.dark );
			light.setAttribute( 'aria-pressed', String( ! state.dark ) );
			dark.setAttribute( 'aria-pressed', String( state.dark ) );
			Array.prototype.forEach.call( variants, function ( v ) {
				v.setAttribute( 'aria-pressed', String( v.getAttribute( 'data-wpab-variant' ) === state.v ) );
			} );
		}

		light.addEventListener( 'click', function () { state.dark = false; paint(); } );
		dark.addEventListener( 'click', function () { state.dark = true; paint(); } );
		Array.prototype.forEach.call( variants, function ( v ) {
			// The link still opens the file without JavaScript; with it, it picks the preview.
			v.setAttribute( 'role', 'button' );
			v.removeAttribute( 'target' );
			v.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				state.v = v.getAttribute( 'data-wpab-variant' );
				paint();
			} );
			v.addEventListener( 'keydown', function ( e ) {
				if ( ' ' === e.key ) {
					e.preventDefault();
					v.click();
				}
			} );
		} );
		paint();
	}
	Array.prototype.forEach.call( document.querySelectorAll( '[data-wpab-stage]' ), initStage );

	/* Card filter. */
	var list = document.querySelector( '[data-wpab-filter]' );
	if ( list ) {
		var cards = Array.prototype.slice.call( list.querySelectorAll( '.wpab-ba-card' ) );
		var plugins = cards.filter( function ( c ) { return 'brand' !== c.getAttribute( 'data-wpab-kind' ); } ).length;
		var tools = document.createElement( 'div' );
		tools.className = 'wpab-ba-tools';
		tools.innerHTML = '<div class="wpab-ba-search"><label class="wpab-ba-sr" for="wpab-ba-q">Filter brands</label>' +
			'<input id="wpab-ba-q" type="search" placeholder="Filter by name or what it does" autocomplete="off"></div>' +
			'<p class="wpab-ba-count" aria-live="polite"></p>';
		var empty = document.createElement( 'div' );
		empty.className = 'wpab-ba-empty';
		empty.hidden = true;
		empty.textContent = 'No brand matches that search.';
		list.parentNode.insertBefore( tools, list );
		list.parentNode.insertBefore( empty, list.nextSibling );
		var input = tools.querySelector( 'input' );
		var count = tools.querySelector( '.wpab-ba-count' );
		var run = function () {
			var t = input.value.trim().toLowerCase();
			var shown = 0;
			var any = false;
			cards.forEach( function ( c ) {
				var hit = ! t || c.getAttribute( 'data-wpab-search' ).indexOf( t ) > -1;
				c.hidden = ! hit;
				any = any || hit;
				if ( hit && 'brand' !== c.getAttribute( 'data-wpab-kind' ) ) {
					shown++;
				}
			} );
			empty.hidden = any;
			count.textContent = t ? shown + ' of ' + plugins + ' plugins' : plugins + ' plugins';
		};
		input.addEventListener( 'input', run );
		run();
	}
})();
JS;
}

/* -------------------------------------------------------------------------
 * Wiring
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\register_assets' );
add_shortcode( 'wpanchorbay_brand_hero', __NAMESPACE__ . '\\hero_shortcode' );
add_shortcode( 'wpanchorbay_brand_assets', __NAMESPACE__ . '\\shortcode' );
add_shortcode( 'wpanchorbay_brand_typography', __NAMESPACE__ . '\\typography_shortcode' );
add_shortcode( 'wpanchorbay_brand_closing', __NAMESPACE__ . '\\closing_shortcode' );
add_shortcode( 'wpanchorbay_brand_usage', __NAMESPACE__ . '\\usage_shortcode' );

// Warm the cache out of band so a visitor rarely pays for the fetch.
add_action( CRON_HOOK, __NAMESPACE__ . '\\refresh_manifest' );

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! wp_next_scheduled( CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', CRON_HOOK );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( CRON_HOOK );
		delete_transient( CACHE_KEY );
		delete_transient( STALE_KEY );
	}
);
