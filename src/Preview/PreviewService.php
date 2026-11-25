<?php
/**
 * Preview live (FR-28, decision 0022): a link that opens a real page with a campaign's
 * saved draft forced to show, to whoever opens it, for an hour, with no login.
 *
 * A link carries a random token. On this site the token is kept here; a hub's link to
 * a client site carries a token the hub keeps, and the client site asks its hub for
 * the campaign over the signed connection. Either way the visitor's browser fetches
 * the campaign without caching, so cached pages stay the same for everyone else.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Preview;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Connect\Connection;
use CampaignBandit\Connect\Connector;
use CampaignBandit\Connect\KeyPair;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Plugin;
use CampaignBandit\Package\ManifestBuilder;
use CampaignBandit\Package\PackageWriter;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\Version;
use CampaignBandit\Publishing\VersionRepository;
use CampaignBandit\Rest\RestController;
use CampaignBandit\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Preview links.
 */
final class PreviewService {

	/** The URL parameter a preview link carries. */
	public const PARAM = 'cbandit_preview';

	/** How long a link works (FR-28). */
	public const TTL = HOUR_IN_SECONDS;

	/** Transient prefix for this site's own links. */
	private const STORE = 'campaignbandit_preview_';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Whether a value looks like a preview token: "l" (this site) or "h" (a hub), then
	 * 32 hex characters.
	 *
	 * @param mixed $token Candidate.
	 */
	public static function is_token( $token ): bool {
		return is_string( $token ) && 1 === preg_match( '/^[lh][0-9a-f]{32}$/', $token );
	}

	/**
	 * A new token.
	 *
	 * @param string $kind "l" or "h".
	 */
	public static function new_token( string $kind ): string {
		return $kind . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Register the REST routes.
	 */
	public static function routes(): void {
		$uid = '(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';
		$can = static fn (): bool => current_user_can( Capabilities::EDIT_CAMPAIGNS );

		register_rest_route(
			RestController::NAMESPACE,
			'/campaigns/' . $uid . '/preview-sites',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'sites' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/campaigns/' . $uid . '/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => $can,
				'args'                => array(
					'site' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
		// Public: the token is the permission. Answers only for a valid, unexpired one.
		register_rest_route(
			RestController::NAMESPACE,
			'/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'resolve_route' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET the sites a campaign can be previewed on: this site, and on a hub the
	 * campaign's client sites. Pro and above (FR-28).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function sites( WP_REST_Request $request ): WP_REST_Response {
		$uid = (string) $request['uid'];
		if ( ! Plan::current()->includes( Plan::Pro ) ) {
			return new WP_REST_Response( array( 'sites' => array() ) );
		}
		$sites = array(
			array(
				'id'    => 0,
				'label' => __( 'This site', 'campaign-bandit' ),
			),
		);
		/**
		 * Filters the sites a campaign can be previewed on. The hub adds the campaign's
		 * client sites.
		 *
		 * @param array<int, array{id: int, label: string}> $sites Sites.
		 * @param string                                    $uid   Campaign ULID.
		 */
		$sites = (array) apply_filters( 'campaignbandit_preview_sites', $sites, $uid );
		return new WP_REST_Response( array( 'sites' => array_values( $sites ) ) );
	}

	/**
	 * POST: a preview link for a campaign's saved draft on a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$uid = (string) $request['uid'];
		if ( ! Plan::current()->includes( Plan::Pro ) ) {
			return new WP_Error( 'campaignbandit_locked', __( 'Preview live comes with Pro.', 'campaign-bandit' ), array( 'status' => 403 ) );
		}
		if ( null === Plugin::campaigns()->get( $uid ) ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No campaign has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		$site = (int) $request['site'];
		if ( 0 === $site ) {
			$token = self::new_token( 'l' );
			set_transient( self::STORE . $token, array( 'campaign' => $uid ), self::TTL );
			$url = add_query_arg( self::PARAM, $token, home_url( '/' ) );
		} else {
			/**
			 * Filters the preview link for a site other than this one. The hub makes it.
			 *
			 * @param string|WP_Error|null $url  Link, an error, or null when there is none.
			 * @param string               $uid  Campaign ULID.
			 * @param int                  $site Site.
			 */
			$url = apply_filters( 'campaignbandit_preview_url', null, $uid, $site );
			if ( $url instanceof WP_Error ) {
				return $url;
			}
			if ( ! is_string( $url ) || '' === $url ) {
				return new WP_Error( 'campaignbandit_preview_site', __( 'That site can’t show a preview of this campaign.', 'campaign-bandit' ), array( 'status' => 400 ) );
			}
		}
		return new WP_REST_Response(
			array(
				'url'     => $url,
				'expires' => time() + self::TTL,
			),
			201
		);
	}

	/**
	 * GET /preview: what the runtime shows for a token. Never cached.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function resolve_route( WP_REST_Request $request ): WP_REST_Response {
		$preview  = self::resolve( (string) $request['token'] );
		$response = null === $preview
			? new WP_REST_Response( array( 'code' => 'campaignbandit_preview_expired' ), 404 )
			: new WP_REST_Response( $preview );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The campaign a token shows, as the runtime takes it: one deployment and its
	 * content. Null when the token is unknown or expired.
	 *
	 * @param string $token Token.
	 * @return array{name: string, deployments: array<int, mixed>, content: array<string, string>}|null
	 */
	public static function resolve( string $token ): ?array {
		if ( ! self::is_token( $token ) ) {
			return null;
		}
		if ( 'l' === $token[0] ) {
			$stored = get_transient( self::STORE . $token );
			return is_array( $stored ) && is_string( $stored['campaign'] ?? null ) ? self::local( $stored['campaign'] ) : null;
		}
		return self::from_hub( $token );
	}

	/**
	 * A campaign's saved draft on this site.
	 *
	 * @param string $uid Campaign ULID.
	 * @return array{name: string, deployments: array<int, mixed>, content: array<string, string>}|null
	 */
	private static function local( string $uid ): ?array {
		$campaign = Plugin::campaigns()->get( $uid );
		$post     = null === $campaign ? null : get_post( $campaign->post_id );
		if ( null === $campaign || null === $post ) {
			return null;
		}
		$version = new Version(
			0,
			$uid,
			( new VersionRepository() )->latest_number( $uid ) + 1,
			$post->post_content,
			CampaignSettings::from_json( (string) get_post_meta( $post->ID, CampaignPostType::META_SETTINGS, true ) ),
			'',
			0,
			gmdate( 'Y-m-d H:i:s' )
		);
		return self::payload( $version, $campaign->name );
	}

	/**
	 * A hub campaign's preview for this client site: asked of the hub, which signs its
	 * answer, so only what the hub sent is shown.
	 *
	 * @param string $token Hub token.
	 * @return array{name: string, deployments: array<int, mixed>, content: array<string, string>}|null
	 */
	private static function from_hub( string $token ): ?array {
		$connection = Connection::get();
		if ( null === $connection ) {
			return null;
		}
		$response = Connector::signed( 'POST', '/hub/preview', array( 'token' => $token ), $connection );
		if ( is_wp_error( $response ) || 200 !== $response['code'] || ! is_array( $response['body'] ) ) {
			return null;
		}
		$envelope = $response['body']['preview'] ?? null;
		if ( ! is_array( $envelope ) || ! is_string( $envelope['payload'] ?? null ) || ! is_string( $envelope['signature'] ?? null )
			|| ! KeyPair::verify( (string) $connection['hub_public_key'], $envelope['payload'], $envelope['signature'] ) ) {
			return null;
		}
		$data = json_decode( $envelope['payload'], true );
		if ( ! is_array( $data ) || ( $data['site'] ?? null ) !== $connection['site_uid'] || ( $data['token'] ?? null ) !== $token
			|| ! is_string( $data['campaign'] ?? null ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
			return null;
		}
		$version = new Version(
			0,
			$data['campaign'],
			max( 1, (int) ( $data['version'] ?? 1 ) ),
			(string) ( $data['content'] ?? '' ),
			new CampaignSettings( (array) ( $data['settings'] ?? array() ) ),
			'',
			0,
			gmdate( 'Y-m-d H:i:s' )
		);
		return self::payload( $version, (string) ( $data['name'] ?? '' ) );
	}

	/**
	 * One deployment and its content, rendered on this site as a live campaign would be.
	 *
	 * @param Version $version Version (or draft).
	 * @param string  $name    Campaign name.
	 * @return array{name: string, deployments: array<int, mixed>, content: array<string, string>}
	 */
	private static function payload( Version $version, string $name ): array {
		$entry = array(
			'deployment' => new Deployment( 0, $version->campaign_uid, 0, 0, $version->number, Deployment::LIVE, gmdate( 'Y-m-d H:i:s' ), null ),
			'version'    => $version,
			'name'       => $name,
		);
		return array(
			'name'        => $name,
			'deployments' => Plugin::package_writer()->manifest( array( $entry ) )['deployments'],
			'content'     => array( ManifestBuilder::content_path( $version ) => PackageWriter::content_html( $version ) ),
		);
	}
}
