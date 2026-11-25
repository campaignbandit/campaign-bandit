<?php
/**
 * Visitor-facing output: the runtime script and inline placements.
 *
 * Nothing is added to any page until a campaign is live (FR-01).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Frontend;

use CampaignBandit\Campaign\PageDecision;
use CampaignBandit\Package\PackageWriter;
use CampaignBandit\Connect\Connection;
use CampaignBandit\Settings\AnalyticsSettings;
use CampaignBandit\Settings\ConsentSettings;
use CampaignBandit\Privacy\ConsentTools;
use CampaignBandit\Plugin;
use CampaignBandit\Rest\RestController;
use CampaignBandit\Support\Ulid;

/**
 * Enqueues the runtime and renders the inline placement shortcode.
 */
final class Frontend {

	public const HANDLE    = 'campaign-bandit-runtime';
	public const SHORTCODE = 'campaign_bandit';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/**
	 * Enqueue the runtime while a deployment is live. Deferred, so it never blocks rendering (05 §3).
	 */
	public static function enqueue(): void {
		$file = CAMPAIGNBANDIT_DIR . '/build/runtime.js';
		// A preview link (FR-28) needs the runtime even where nothing is live yet.
		$preview = isset( $_GET[ \CampaignBandit\Preview\PreviewService::PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to load the script; the token is checked by the REST route.
		if ( ( ! $preview && ! get_option( PackageWriter::OPTION_HAS_LIVE ) ) || ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/runtime.js', CAMPAIGNBANDIT_FILE ),
			array(),
			(string) filemtime( $file ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.campaignBanditConfig=' . wp_json_encode( self::config() ) . ';window.campaignBanditPage=' . wp_json_encode( self::page_facts() ) . ';',
			'before'
		);
		// Styles of the core blocks campaigns use (cover, columns, buttons…), which this
		// page may not load on its own.
		foreach ( (array) get_option( PackageWriter::OPTION_BLOCKS, array() ) as $name ) {
			if ( is_string( $name ) && wp_style_is( 'wp-block-' . $name, 'registered' ) ) {
				wp_enqueue_style( 'wp-block-' . $name );
			}
		}
	}

	/**
	 * What this page is, for page rules (BR-01, decision 0011). Built from the main query,
	 * so it is the same for every visitor of this URL and safe in cached pages.
	 *
	 * @return array<string, mixed>
	 */
	public static function page_facts(): array {
		$facts = array(
			'front'         => is_front_page(),
			'postsPage'     => is_home(),
			'singular'      => is_singular(),
			'postType'      => is_singular() ? (string) get_post_type() : '',
			'terms'         => array(),
			'ancestors'     => array(),
			// Ancestor paths, so "child of" works by path across sites (decision 0021).
			'ancestorPaths' => array(),
			'archive'       => is_archive(),
			'search'        => is_search(),
			'notFound'      => is_404(),
		);

		$object = get_queried_object();
		if ( is_singular() && $object instanceof \WP_Post ) {
			foreach ( get_object_taxonomies( $object->post_type ) as $taxonomy ) {
				$terms = get_the_terms( $object, $taxonomy );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$facts['terms'][] = $taxonomy . ':' . $term->slug;
				}
			}
			$facts['ancestors']     = array_map( 'intval', get_post_ancestors( $object ) );
			$facts['ancestorPaths'] = array_map( array( \CampaignBandit\Connect\StructureCollector::class, 'page_path' ), $facts['ancestors'] );
		} elseif ( $object instanceof \WP_Term ) {
			$facts['terms'][] = $object->taxonomy . ':' . $object->slug;
		}

		return $facts;
	}

	/**
	 * Runtime configuration. The same for every visitor, so cached pages stay correct.
	 *
	 * @return array{base: string, rest: string, delivery: string, messages: array<string, string>}
	 */
	public static function config(): array {
		$config = array(
			'base'      => PackageWriter::base_url(),
			'rest'      => trailingslashit( rest_url( RestController::NAMESPACE ) ),
			'delivery'  => (string) get_option( PackageWriter::OPTION_DELIVERY, PackageWriter::DELIVERY_FILES ),
			// Cookie consent (BR-48): the setting and the consent tool this site has.
			'consent'   => array(
				'mode' => ConsentSettings::mode(),
				'tool' => ConsentTools::detect(),
			),
			// Analytics events (FR-62); a staging site sends none.
			'analytics' => Connection::is_staging() ? AnalyticsSettings::OFF : AnalyticsSettings::mode(),
			'messages'  => array(
				'required'    => __( 'This field is required.', 'campaign-bandit' ),
				'email'       => __( 'Enter a valid email address.', 'campaign-bandit' ),
				'phone'       => __( 'Enter a valid phone number.', 'campaign-bandit' ),
				'date'        => __( 'Enter a valid date.', 'campaign-bandit' ),
				'date_range'  => __( 'Choose a date in the range allowed.', 'campaign-bandit' ),
				'check'       => __( 'Please check the highlighted fields.', 'campaign-bandit' ),
				'failed'      => __( 'Your details could not be sent. Please try again.', 'campaign-bandit' ),
				'sending'     => __( 'Sending…', 'campaign-bandit' ),
				/* translators: %s: campaign name. */
				'preview'     => __( 'Preview of “%s”. Only you see it, and nothing is sent or counted.', 'campaign-bandit' ),
				'previewExit' => __( 'Exit preview', 'campaign-bandit' ),
				/* translators: %s: DB-IP, as a link. */
				'dbip'        => __( 'IP geolocation by %s', 'campaign-bandit' ),
			),
		);
		// A staging site shows hub campaigns to logged-in editors only (BR-33).
		$preview = current_user_can( 'edit_posts' ) ? Plugin::package_writer()->editor_preview() : null;
		if ( null !== $preview ) {
			$config['preview'] = $preview;
		}
		return $config;
	}

	/**
	 * This request's path from the site's home, as the runtime computes it in the
	 * browser from `window.location`. A property of the URL, so it is the same for
	 * every visitor and safe to use behind a page cache (decision 0032).
	 */
	public static function current_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?? '/' );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		// A site in a folder: rules are written against the path from home.
		if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
	}

	/**
	 * `[campaign_bandit id="…"]`: where an inline campaign goes (FR-22). The runtime
	 * fills it if the campaign qualifies for this visitor and hides it otherwise.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), (array) $atts, self::SHORTCODE );
		$uid  = strtoupper( trim( $atts['id'] ) );
		if ( ! Ulid::is_valid( $uid ) ) {
			return '';
		}
		// Render the live version here, so the space is reserved before the runtime runs (05 §3).
		$version = Plugin::live_version( $uid );
		if ( null === $version ) {
			return '';
		}
		// ...but not on a page this campaign can never show on, whoever the visitor is
		// (decision 0032). Page and path rules depend only on the URL, so the answer is
		// the same for every visitor and a cache can hold it. Without this the slot is
		// painted and then hidden a moment later, which is what a visitor sees as a
		// flash. Anything about the visitor is still decided in the browser.
		if ( PageDecision::never_here( $version->settings->get( 'condition' ), self::page_facts(), self::current_path() ) ) {
			return '';
		}
		return sprintf(
			'<div class="cbandit-slot" data-cbandit-campaign="%s" data-cbandit-version="%d">%s</div>',
			esc_attr( $uid ),
			$version->number,
			PackageWriter::content_html( $version )
		);
	}
}
