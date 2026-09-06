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
 * Rendering
 * ---------------------------------------------------------------------- */

/**
 * One product card.
 *
 * @param array<string,mixed> $product Product entry from the manifest.
 */
function render_card( array $product ): string {
	$name    = isset( $product['name'] ) ? (string) $product['name'] : '';
	$tagline = isset( $product['tagline'] ) ? (string) $product['tagline'] : '';
	$slug    = isset( $product['slug'] ) ? sanitize_key( (string) $product['slug'] ) : '';
	$color   = safe_hex( $product['color'] ?? null );
	$assets  = isset( $product['assets'] ) && is_array( $product['assets'] ) ? $product['assets'] : array();

	if ( '' === $name ) {
		return '';
	}

	$icon = safe_asset_url( $assets['icon']['formats']['svg']['url'] ?? $assets['icon']['formats']['png']['url'] ?? '' );
	$logo = safe_asset_url( $assets['logo']['formats']['svg']['url'] ?? $assets['logo']['formats']['png']['url'] ?? '' );

	ob_start();
	?>
	<article class="wpab-ba-card" id="wpab-ba-<?php echo esc_attr( $slug ); ?>" style="--wpab-ba-brand:<?php echo esc_attr( $color ); ?>">
		<div class="wpab-ba-card__head">
			<?php if ( $icon ) : ?>
				<img class="wpab-ba-card__icon" src="<?php echo esc_url( $icon ); ?>"
					alt="" width="48" height="48" loading="lazy" decoding="async">
			<?php endif; ?>
			<div>
				<h3 class="wpab-ba-card__title"><?php echo esc_html( $name ); ?></h3>
				<?php if ( $tagline ) : ?>
					<p class="wpab-ba-card__tagline"><?php echo esc_html( $tagline ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $logo ) : ?>
			<div class="wpab-ba-card__plate">
				<img src="<?php echo esc_url( $logo ); ?>"
					alt="<?php
						/* translators: %s: product name. */
						echo esc_attr( sprintf( __( '%s logo', 'wpab-brand-assets' ), $name ) );
					?>"
					loading="lazy" decoding="async">
			</div>
		<?php endif; ?>

		<div class="wpab-ba-card__assets">
			<?php foreach ( $assets as $asset ) : ?>
				<?php
				if ( ! is_array( $asset ) || empty( $asset['formats'] ) || ! is_array( $asset['formats'] ) ) {
					continue;
				}
				$label = isset( $asset['label'] ) ? (string) $asset['label'] : '';
				?>
				<div class="wpab-ba-asset">
					<span class="wpab-ba-asset__name"><?php echo esc_html( $label ); ?></span>
					<span class="wpab-ba-asset__formats">
						<?php foreach ( $asset['formats'] as $ext => $format ) : ?>
							<?php
							$url = safe_asset_url( $format['url'] ?? '' );
							if ( ! $url ) {
								continue;
							}
							?>
							<a class="wpab-ba-fmt" href="<?php echo esc_url( $url ); ?>" download
								title="<?php
									/* translators: 1: asset label, 2: file format, e.g. SVG. */
									echo esc_attr( sprintf( __( 'Download %1$s as %2$s', 'wpab-brand-assets' ), $label, strtoupper( (string) $ext ) ) );
								?>"><?php echo esc_html( strtoupper( (string) $ext ) ); ?></a>
						<?php endforeach; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<span class="wpab-ba-swatch"><span class="wpab-ba-swatch__chip"></span><?php echo esc_html( $color ); ?></span>
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

	return '<div class="wpab-ba-grid">' . $cards . '</div>';
}

/* -------------------------------------------------------------------------
 * Assets
 *
 * Registered on every front-end request but only ENQUEUED by the shortcode, so
 * pages without it carry no extra CSS.
 * ---------------------------------------------------------------------- */

function register_assets(): void {
	wp_register_style( STYLE_HANDLE, false, array(), '1.0.0' );
	wp_add_inline_style( STYLE_HANDLE, styles() );
}

function styles(): string {
	return <<<'CSS'
.wpab-ba-grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));margin:2em 0}
.wpab-ba-card{display:flex;flex-direction:column;gap:14px;padding:20px;border:1px solid rgba(0,31,63,.12);
border-radius:14px;background:#fff;color:#0e1b2a}
.wpab-ba-card__head{display:flex;gap:14px;align-items:center}
.wpab-ba-card__icon{border-radius:12px;flex:none;width:48px;height:48px}
.wpab-ba-card__title{margin:0;font-size:17px;line-height:1.3}
.wpab-ba-card__tagline{margin:3px 0 0;font-size:13.5px;line-height:1.45;opacity:.7}
/* Always light: the wordmarks are dark-ink only. */
.wpab-ba-card__plate{display:flex;align-items:center;justify-content:center;min-height:92px;padding:20px 16px;
border:1px solid rgba(0,31,63,.1);border-radius:10px;
background:linear-gradient(0deg,color-mix(in srgb,var(--wpab-ba-brand) 8%,transparent),
color-mix(in srgb,var(--wpab-ba-brand) 8%,transparent)),#fff}
.wpab-ba-card__plate img{max-width:100%;max-height:40px;width:auto;height:auto}
.wpab-ba-card__assets{display:flex;flex-direction:column;gap:9px}
.wpab-ba-asset{display:flex;flex-wrap:wrap;gap:5px 12px;align-items:baseline;justify-content:space-between}
.wpab-ba-asset__name{font-size:13px;font-weight:600}
.wpab-ba-asset__formats{display:flex;gap:7px;flex-wrap:wrap}
.wpab-ba-fmt{display:inline-block;padding:2px 9px;border:1px solid rgba(0,31,63,.16);border-radius:6px;
font-size:11.5px;font-weight:700;letter-spacing:.04em;text-decoration:none;color:inherit;box-shadow:none}
.wpab-ba-fmt:hover,.wpab-ba-fmt:focus-visible{border-color:var(--wpab-ba-brand);color:var(--wpab-ba-brand)}
.wpab-ba-swatch{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;margin-top:auto;
padding:5px 10px;border:1px solid rgba(0,31,63,.14);border-radius:7px;
font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.03em}
.wpab-ba-swatch__chip{width:13px;height:13px;border-radius:4px;background:var(--wpab-ba-brand);
box-shadow:inset 0 0 0 1px rgba(0,0,0,.12)}
.wpab-ba-notice{padding:12px 16px;border-left:3px solid #d63638;background:rgba(214,54,56,.06);font-size:14px}
@media (prefers-color-scheme:dark){
.wpab-ba-card{background:#101f31;color:#e6eef7;border-color:rgba(255,255,255,.1)}
.wpab-ba-fmt,.wpab-ba-swatch{border-color:rgba(255,255,255,.16)}
.wpab-ba-card__plate{border-color:rgba(255,255,255,.12)}
}
CSS;
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
