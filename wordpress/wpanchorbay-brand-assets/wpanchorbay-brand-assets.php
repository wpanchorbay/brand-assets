<?php
/**
 * Plugin Name:       WPAnchorBay Brand Assets
 * Description:       Renders the WPAnchorBay brand assets grid from the remote brand.json manifest. Nothing is uploaded to the media library — every image is hotlinked from assets.wpanchorbay.com, so updating a file there updates it here.
 * Version:           1.0.0
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
			'user-agent' => 'WPAnchorBay Brand Assets/1.0.0; ' . home_url( '/' ),
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

/* -------------------------------------------------------------------------
 * Icons
 *
 * Static, plugin-authored glyphs — never derived from remote or user input,
 * so they are safe to output directly (no escaping needed). Same shapes as
 * the public brand-assets page, for visual parity between the two surfaces.
 * ---------------------------------------------------------------------- */

/**
 * @return array<string,string> Icon name => raw SVG markup.
 */
function icons(): array {
	static $icons = null;

	if ( null !== $icons ) {
		return $icons;
	}

	$icons = array(
		'copy'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
		'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
		'open'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
		'info'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
		'globe'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
		'book'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
		'code'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
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
 * Link metadata for the badge row: label + icon per manifest link key.
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
 * Plain-text summary for the "Copy Info" button: name, tagline, colours,
 * links. Mirrors the public brand-assets page's own version.
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

	$glyphs    = icons();
	$meta      = link_meta();
	$icon      = safe_asset_url( $assets['icon']['formats']['svg']['url'] ?? $assets['icon']['formats']['png']['url'] ?? '' );
	$logo      = safe_asset_url( $assets['logo']['formats']['svg']['url'] ?? $assets['logo']['formats']['png']['url'] ?? '' );
	// A "-dark" sibling is meant for dark backgrounds, so preview it on one:
	// a light-only plate would defeat the point of even having the file.
	$logo_dark = safe_asset_url( $assets['logo-dark']['formats']['svg']['url'] ?? $assets['logo-dark']['formats']['png']['url'] ?? '' );

	// WPAnchorBay the brand carries two identity colours (teal + navy); a
	// plugin's onColor is only ever a text-contrast helper for its badge,
	// never a second mark, so plugin cards show one chip.
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
		: array( array( 'hex' => $color, 'name' => '' ) );

	$badge_links = array_intersect_key( $links, $meta );

	// The brand card's own links hover to its secondary colour (WPAnchorBay's
	// navy) rather than the primary brand teal, which reads too pale for text.
	$link_accent = 'brand' === $kind ? $on_color : $color;
	$info_text   = product_info_text( $name, $tagline, $swatches, $badge_links, $meta, $kind );

	ob_start();
	?>
	<article class="wpab-ba-card" id="wpab-ba-<?php echo esc_attr( $slug ); ?>" style="--wpab-ba-brand:<?php echo esc_attr( $color ); ?>;--wpab-ba-on-brand:<?php echo esc_attr( $on_color ); ?>;--wpab-ba-link-accent:<?php echo esc_attr( $link_accent ); ?>">
		<div class="wpab-ba-card__head">
			<?php if ( $icon ) : ?>
				<img class="wpab-ba-card__icon" src="<?php echo esc_url( $icon ); ?>"
					alt="" width="48" height="48" decoding="async">
			<?php endif; ?>
			<div class="wpab-ba-card__head-text">
				<h3 class="wpab-ba-card__title"><?php echo esc_html( $name ); ?></h3>
				<?php if ( $tagline ) : ?>
					<p class="wpab-ba-card__tagline"><?php echo esc_html( $tagline ); ?></p>
				<?php endif; ?>
			</div>
			<button type="button" class="wpab-ba-copy-info wpab-ba-js-copy" data-copy="<?php echo esc_attr( $info_text ); ?>"
				title="<?php esc_attr_e( 'Copy name, colours and links', 'wpab-brand-assets' ); ?>"
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: product name. */
					__( 'Copy %s info', 'wpab-brand-assets' ),
					$name
				) ); ?>">
				<?php echo $glyphs['copy']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'Copy Info', 'wpab-brand-assets' ); ?></span>
			</button>
		</div>

		<?php if ( $logo || $logo_dark ) : ?>
			<div class="wpab-ba-plates">
				<?php if ( $logo ) : ?>
					<div class="wpab-ba-card__plate">
						<img src="<?php echo esc_url( $logo ); ?>"
							alt="<?php
								/* translators: %s: product name. */
								echo esc_attr( sprintf( __( '%s logo', 'wpab-brand-assets' ), $name ) );
							?>"
							decoding="async">
					</div>
				<?php endif; ?>
				<?php if ( $logo_dark ) : ?>
					<div class="wpab-ba-card__plate wpab-ba-card__plate--dark">
						<img src="<?php echo esc_url( $logo_dark ); ?>"
							alt="<?php
								/* translators: %s: product name. */
								echo esc_attr( sprintf( __( '%s logo, for dark backgrounds', 'wpab-brand-assets' ), $name ) );
							?>"
							decoding="async">
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="wpab-ba-assets">
			<?php foreach ( $assets as $asset ) : ?>
				<?php
				if ( ! is_array( $asset ) || empty( $asset['formats'] ) || ! is_array( $asset['formats'] ) ) {
					continue;
				}
				$asset_label = isset( $asset['label'] ) ? (string) $asset['label'] : '';
				$formats     = $asset['formats'];
				// SVG first, matching the public brand-assets page.
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
				?>
				<div class="wpab-ba-asset">
					<div class="wpab-ba-asset__name"><?php echo esc_html( $asset_label ); ?></div>
					<div class="wpab-ba-fmt-list">
						<?php foreach ( $formats as $ext => $format ) : ?>
							<?php
							$url = safe_asset_url( $format['url'] ?? '' );
							if ( ! $url ) {
								continue;
							}
							$ext_u = strtoupper( (string) $ext );
							$dims  = ( ! empty( $format['width'] ) && ! empty( $format['height'] ) )
								? sprintf( '%1$d×%2$d', (int) $format['width'], (int) $format['height'] )
								: '';
							$bytes = isset( $format['bytes'] )
								? size_format( (int) $format['bytes'], ( (int) $format['bytes'] < 10240 ) ? 1 : 0 )
								: '';
							$info = implode( ' · ', array_filter( array( $dims, $bytes ) ) );
							?>
							<div class="wpab-ba-fmt-row">
								<span class="wpab-ba-fmt-label"><?php echo esc_html( $ext_u ); ?></span>
								<?php if ( $info ) : ?>
									<button type="button" class="wpab-ba-info" title="<?php echo esc_attr( $info ); ?>" aria-label="<?php echo esc_attr( $info ); ?>"><?php echo $glyphs['info']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?></button>
								<?php endif; ?>
								<span class="wpab-ba-btn-group" role="group" aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s: file format, e.g. SVG. */
									__( '%s actions', 'wpab-brand-assets' ),
									$ext_u
								) ); ?>">
									<button type="button" class="wpab-ba-act wpab-ba-js-copy" data-copy="<?php echo esc_attr( $url ); ?>"
										title="<?php esc_attr_e( 'Copy URL', 'wpab-brand-assets' ); ?>"
										aria-label="<?php echo esc_attr( sprintf(
											/* translators: %s: file format, e.g. SVG. */
											__( 'Copy %s URL', 'wpab-brand-assets' ),
											$ext_u
										) ); ?>"><?php echo $glyphs['copy']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
									<a class="wpab-ba-act" href="<?php echo esc_url( $url ); ?>" download
										title="<?php echo esc_attr( sprintf(
											/* translators: %s: file format, e.g. SVG. */
											__( 'Download %s', 'wpab-brand-assets' ),
											$ext_u
										) ); ?>"
										aria-label="<?php echo esc_attr( sprintf( __( 'Download %s', 'wpab-brand-assets' ), $ext_u ) ); ?>"><?php echo $glyphs['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
									<a class="wpab-ba-act" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"
										title="<?php esc_attr_e( 'Open in new tab', 'wpab-brand-assets' ); ?>"
										aria-label="<?php echo esc_attr( sprintf(
											/* translators: %s: file format, e.g. SVG. */
											__( 'Open %s in new tab', 'wpab-brand-assets' ),
											$ext_u
										) ); ?>"><?php echo $glyphs['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="wpab-ba-swatches">
			<?php foreach ( $swatches as $s ) : ?>
				<?php
				$hex   = $s['hex'];
				$sname = $s['name'];
				$title = $sname
					? sprintf(
						/* translators: 1: swatch name, e.g. "primary". 2: hex value. */
						__( 'Copy %1$s hex (%2$s)', 'wpab-brand-assets' ),
						$sname,
						$hex
					)
					: sprintf(
						/* translators: %s: hex value. */
						__( 'Copy hex (%s)', 'wpab-brand-assets' ),
						$hex
					);
				?>
				<button type="button" class="wpab-ba-swatch wpab-ba-js-copy" data-copy="<?php echo esc_attr( $hex ); ?>" title="<?php echo esc_attr( $title ); ?>">
					<span class="wpab-ba-swatch__chip" style="background:<?php echo esc_attr( $hex ); ?>"></span><?php echo esc_html( $hex ); ?>
				</button>
			<?php endforeach; ?>
		</div>

		<?php
		// Every product with any asset files also gets its own brand.json (see
		// the per-product manifest step in scripts/build.mjs) — surface it
		// here, or it exists with no link to find it from.
		$has_own_manifest = ! empty( $assets ) && '' !== $slug;
		$total_badges     = count( $badge_links ) + ( $has_own_manifest ? 1 : 0 );
		?>
		<?php if ( $total_badges ) : ?>
			<nav class="wpab-ba-links" style="grid-template-columns:repeat(<?php echo (int) $total_badges; ?>,1fr)">
				<?php foreach ( $badge_links as $key => $href ) : ?>
					<?php
					// The brand's own "site" link goes to the company homepage,
					// not a per-product page, label it accordingly.
					$label = ( 'site' === $key && 'brand' === $kind )
						? __( 'Visit Website', 'wpab-brand-assets' )
						: $meta[ $key ]['label'];
					?>
					<a class="wpab-ba-badge" href="<?php echo esc_url( $href ); ?>" rel="noopener" title="<?php echo esc_attr( $label ); ?>">
						<?php echo $meta[ $key ]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?>
						<span><?php echo esc_html( $label ); ?></span>
					</a>
				<?php endforeach; ?>
				<?php if ( $has_own_manifest ) : ?>
					<?php $json_url = asset_origin() . '/brand/' . $slug . '/brand.json'; ?>
					<button type="button" class="wpab-ba-badge wpab-ba-js-copy" data-copy="<?php echo esc_attr( $json_url ); ?>"
						title="<?php esc_attr_e( "Copy this product's JSON URL", 'wpab-brand-assets' ); ?>"
						aria-label="<?php echo esc_attr( sprintf(
							/* translators: %s: product name. */
							__( 'Copy %s JSON URL', 'wpab-brand-assets' ),
							$name
						) ); ?>">
						<?php echo $glyphs['code']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see icons(). ?><span><?php esc_html_e( 'JSON', 'wpab-brand-assets' ); ?></span>
					</button>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Shortcode handler.
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
		// Never show a raw error to visitors; give editors something actionable.
		if ( current_user_can( 'edit_posts' ) ) {
			return '<p class="wpab-ba-notice">' . esc_html__(
				'Brand assets could not be loaded from assets.wpanchorbay.com. This message is only visible to editors.',
				'wpab-brand-assets'
			) . '</p>';
		}
		return '';
	}

	$only = array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['products'] ) ) );
	$show = in_array( $atts['show'], array( 'all', 'plugins', 'brand' ), true ) ? $atts['show'] : 'all';

	$cards = '';
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

		$cards .= render_card( $product );
	}

	if ( '' === $cards ) {
		return '';
	}

	wp_enqueue_style( STYLE_HANDLE );
	wp_enqueue_script( SCRIPT_HANDLE );

	return '<div class="wpab-ba-grid">' . $cards . '</div>';
}

/* -------------------------------------------------------------------------
 * Assets
 *
 * Registered on every front-end request but only ENQUEUED by the shortcode, so
 * pages without it carry no extra CSS/JS.
 * ---------------------------------------------------------------------- */

function register_assets(): void {
	wp_register_style( STYLE_HANDLE, false, array(), '1.0.0' );
	wp_add_inline_style( STYLE_HANDLE, styles() );

	wp_register_script( SCRIPT_HANDLE, false, array(), '1.0.0', true );
	wp_add_inline_script( SCRIPT_HANDLE, script() );
}

/**
 * Light theme only, matching the public brand-assets page. Bordered, rounded
 * blocks throughout; a button group has no outer border (the tinted
 * background, inner dividers and rounded corners already read as one
 * grouped control); wordmarks are dark-ink only so the logo plate always
 * stays on a light background regardless of the surrounding theme.
 */
function styles(): string {
	return <<<'CSS'
.wpab-ba-grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));margin:2em 0}
.wpab-ba-card{box-sizing:border-box;display:flex;flex-direction:column;gap:16px;padding:22px;
border:1px solid #e2e7ee;border-radius:14px;background:#fff;color:#101828;
box-shadow:0 1px 2px rgba(16,24,40,.04),0 10px 26px -14px rgba(16,24,40,.16)}
.wpab-ba-card *{box-sizing:border-box}
/* Buttons don't inherit font-family or reset to cursor:pointer by default;
   every button class below sets its own border/background/padding, so this
   only fixes the gaps, it doesn't fight any of them. */
.wpab-ba-card button{font-family:inherit;cursor:pointer;-webkit-appearance:none;appearance:none;
border:0;background:none;color:inherit;padding:0;margin:0}
.wpab-ba-card__head{display:flex;gap:14px;align-items:center}
.wpab-ba-card__head-text{flex:1;min-width:0}
.wpab-ba-copy-info{display:inline-flex;align-items:center;gap:6px;flex:none;align-self:flex-start;
border:1px solid #e2e7ee;background:#f5f7fa;color:#5b6472;border-radius:7px;padding:6px 10px;
font-size:12px;font-weight:600;white-space:nowrap}
.wpab-ba-copy-info svg{width:13px;height:13px;display:block}
.wpab-ba-copy-info:hover,.wpab-ba-copy-info:focus-visible{border-color:var(--wpab-ba-link-accent);
color:var(--wpab-ba-link-accent);background:#fff}
.wpab-ba-copy-info.wpab-ba-done{color:#0d8a5f;border-color:#0d8a5f;background:#eafbf3}
.wpab-ba-card__icon{border-radius:14px;flex:none}
.wpab-ba-card__title{margin:0;font-size:17px;line-height:1.3;letter-spacing:-.01em}
.wpab-ba-card__tagline{margin:3px 0 0;font-size:13.5px;line-height:1.45;color:#5b6472}
.wpab-ba-plates{display:grid;grid-template-columns:1fr;gap:10px}
.wpab-ba-plates:has(.wpab-ba-card__plate--dark){grid-template-columns:1fr 1fr}
.wpab-ba-card__plate{display:flex;align-items:center;justify-content:center;min-height:92px;padding:20px 16px;
border:1px solid #e2e7ee;border-radius:10px;
background:linear-gradient(0deg,color-mix(in srgb,var(--wpab-ba-brand) 8%,transparent),
color-mix(in srgb,var(--wpab-ba-brand) 8%,transparent)),#fff}
.wpab-ba-card__plate--dark{background:linear-gradient(0deg,color-mix(in srgb,var(--wpab-ba-brand) 14%,transparent),
color-mix(in srgb,var(--wpab-ba-brand) 14%,transparent)),#001F3F;border-color:#001F3F}
.wpab-ba-card__plate img{max-width:100%;max-height:40px;width:auto;height:auto}
/* Stacked on small screens; side by side (Logo | Icon) once there is room. */
.wpab-ba-assets{display:grid;grid-template-columns:1fr;gap:14px 16px}
@media (min-width:560px){.wpab-ba-assets{grid-template-columns:1fr 1fr}}
.wpab-ba-asset{border:1px solid #e2e7ee;border-radius:10px;padding:12px 14px 14px}
.wpab-ba-asset__name{font-size:13px;font-weight:700;margin-bottom:8px}
.wpab-ba-fmt-list{display:flex;flex-direction:column;gap:7px}
.wpab-ba-fmt-row{display:flex;align-items:center}
.wpab-ba-fmt-label{font:700 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.05em;
color:#5b6472;min-width:32px;margin-right:6px}
.wpab-ba-info{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;
border-radius:999px;border:1px solid #e2e7ee;background:#fff;color:#5b6472;flex:none;margin-right:auto;
cursor:default;padding:0}
.wpab-ba-info svg{width:12px;height:12px;display:block}
.wpab-ba-info:hover,.wpab-ba-info:focus-visible{color:var(--wpab-ba-brand);border-color:var(--wpab-ba-brand)}
.wpab-ba-btn-group{display:inline-flex;border-radius:8px;overflow:hidden;background:#fbfcfe}
.wpab-ba-act{display:inline-flex;align-items:center;justify-content:center;width:30px;height:28px;border:0;
background:transparent;color:#5b6472;cursor:pointer;text-decoration:none}
.wpab-ba-act+.wpab-ba-act{border-left:1px solid #e2e7ee}
.wpab-ba-act svg{width:14px;height:14px;display:block}
.wpab-ba-act:hover,.wpab-ba-act:focus-visible{background:#fff;color:var(--wpab-ba-brand)}
.wpab-ba-act.wpab-ba-done{color:#0d8a5f;background:#eafbf3}
.wpab-ba-swatches{display:flex;flex-wrap:wrap;gap:8px}
.wpab-ba-swatch{display:inline-flex;align-items:center;gap:8px;border:1px solid #e2e7ee;background:#fbfcfe;
color:#101828;border-radius:7px;padding:5px 10px;font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;
letter-spacing:.03em;cursor:pointer}
.wpab-ba-swatch:hover{border-color:var(--wpab-ba-brand)}
.wpab-ba-swatch.wpab-ba-done{background:var(--wpab-ba-brand);color:var(--wpab-ba-on-brand);border-color:var(--wpab-ba-brand)}
.wpab-ba-swatch__chip{width:13px;height:13px;border-radius:4px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.14);flex:none}
.wpab-ba-links{display:grid;gap:8px;margin-top:auto;padding-top:14px;border-top:1px solid #e2e7ee}
/* text-overflow:ellipsis has no effect set directly on a flex container (a
   known gotcha) — it has to sit on the text run itself, which also needs
   min-width:0 to be allowed to shrink below its content size in a flex row. */
.wpab-ba-badge{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 6px;
border:1px solid #e2e7ee;border-radius:8px;background:#fbfcfe;color:#5b6472;text-decoration:none;
font-size:12px;font-weight:600}
.wpab-ba-badge svg{width:14px;height:14px;flex:none}
.wpab-ba-badge span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wpab-ba-badge:hover,.wpab-ba-badge:focus-visible{border-color:var(--wpab-ba-link-accent);color:var(--wpab-ba-link-accent);background:#fff}
.wpab-ba-badge.wpab-ba-done{color:#0d8a5f;border-color:#0d8a5f;background:#eafbf3}
.wpab-ba-notice{padding:12px 16px;border-left:3px solid #d63638;background:rgba(214,54,56,.06);font-size:14px}
.wpab-ba-toast{position:fixed;left:50%;bottom:26px;transform:translate(-50%,14px);background:#001F3F;color:#fff;
padding:9px 16px;border-radius:999px;font-size:13px;opacity:0;pointer-events:none;transition:.18s;z-index:9999}
.wpab-ba-toast.wpab-ba-show{opacity:1;transform:translate(-50%,0)}
@media (prefers-reduced-motion:reduce){.wpab-ba-act,.wpab-ba-swatch,.wpab-ba-badge,.wpab-ba-toast{transition:none}}
CSS;
}

/**
 * Click-to-copy for every element carrying `.wpab-ba-js-copy` (format URLs,
 * colour swatches) plus a small toast, created on first use so the shortcode
 * can appear more than once on a page without duplicating it.
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
		}, 1600 );
	}

	function fallbackCopy( text, onDone ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.cssText = 'position:fixed;opacity:0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
			onDone();
		} catch ( err ) {
			toast( 'Copy failed. Select the URL manually' );
		}
		ta.remove();
	}

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
			toast( 'Copied ' + text );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onDone, function () {
				fallbackCopy( text, onDone );
			} );
		} else {
			fallbackCopy( text, onDone );
		}
	} );
})();
JS;
}

/* -------------------------------------------------------------------------
 * Wiring
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\register_assets' );
add_shortcode( 'wpanchorbay_brand_assets', __NAMESPACE__ . '\\shortcode' );

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
