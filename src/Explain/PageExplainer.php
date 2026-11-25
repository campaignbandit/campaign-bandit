<?php
/**
 * "Explain this page" (FR-34, decision 0023): what a page on this site is (its page
 * facts), and the campaigns live here. The admin's browser then runs the visitor
 * runtime's own rules on them. A hub asks a client site for its page facts over the
 * signed connection.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Explain;

use CampaignBandit\Connect\SyncClient;
use CampaignBandit\Frontend\Frontend;
use CampaignBandit\Plugin;
use CampaignBandit\Rest\RestController;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Page explainer.
 */
final class PageExplainer {

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * POST /connector/page-facts: a connected site tells its hub what one of its pages
	 * is. Signed by the hub; nothing about visitors.
	 */
	public static function routes(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/connector/page-facts',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$body = $request->get_json_params();
					$path  = self::path( is_array( $body ) && is_string( $body['path'] ?? null ) ? $body['path'] : '/' );
					$query = is_array( $body ) && is_string( $body['query'] ?? null ) ? $body['query'] : '';
					return new WP_REST_Response(
						array(
							'path'  => $path,
							'facts' => self::facts( $path, $query ),
						)
					);
				},
				'permission_callback' => array( SyncClient::class, 'from_hub' ),
			)
		);
	}

	/**
	 * A page path from what was typed: a full URL on any host, or a path. The query
	 * string is left out (it goes to URL parameters).
	 *
	 * @param string $input URL or path.
	 */
	public static function path( string $input ): string {
		$input = trim( $input );
		$path  = (string) ( wp_parse_url( $input, PHP_URL_PATH ) ?? '' );
		if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
			$path = '/' . ltrim( $path, '/' );
		}
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		// A full URL of this site includes its folder; rules use the path from home.
		if ( '' !== $home && str_starts_with( $path, $home . '/' ) && preg_match( '#^https?://#i', $input ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
	}

	/**
	 * What a page on this site is, as the visitor runtime sees it (decision 0011): its
	 * URL run through WordPress's own request parsing and main query, here, without
	 * fetching the page. The real request's state is put back afterwards.
	 *
	 * @param string $path  Path from the site's home, such as "/blog/hello-world".
	 * @param string $query The URL's query string, for pages it decides (search, ?p=12).
	 * @return array<string, mixed>
	 */
	public static function facts( string $path, string $query = '' ): array {
		global $wp, $wp_query, $wp_the_query, $post;
		$saved = array( $wp, $wp_query, $wp_the_query, $post, $_SERVER, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to put back, not read as input.

		$home                   = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$_SERVER['REQUEST_URI'] = $home . $path . ( '' !== $query ? '?' . $query : '' );
		// From the admin, WordPress would ignore pretty permalinks (wp-admin in PHP_SELF).
		$_SERVER['PHP_SELF'] = $home . '/index.php';
		parse_str( $query, $get );
		$_GET = $get;

		try {
			$request = new \WP();
			$request->parse_request();
			$query = new \WP_Query();
			$query->query( $request->query_vars );
			$wp           = $request; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back below.
			$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back below.
			$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back below.
			$request->handle_404();
			// get_post_type() and friends read the global post, as on the real page.
			$post  = $query->post ?? null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back below.
			$facts = Frontend::page_facts();
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- putting the real request back.
			list( $wp, $wp_query, $wp_the_query, $post, $_SERVER, $_GET ) = $saved;
		}
		return $facts;
	}

	/**
	 * What the browser needs to explain a page on this site.
	 *
	 * @param string $path  Path from the site's home.
	 * @param string $query The URL's query string.
	 * @return array{manifest: array<string, mixed>, facts: array<string, mixed>, path: string}
	 */
	public static function local( string $path, string $query = '' ): array {
		$writer = Plugin::package_writer();
		return array(
			'manifest' => $writer->manifest( $writer->live_entries() ),
			'facts'    => self::facts( $path, $query ),
			'path'     => $path,
		);
	}
}
