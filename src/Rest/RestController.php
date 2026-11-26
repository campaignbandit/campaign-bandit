<?php
/**
 * REST routes under campaign-bandit/v1: publishing for the editor, plus the public
 * package fallback and country lookup for the visitor runtime (decision 0009).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Rest;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Geo\CountryDatabase;
use CampaignBandit\Geo\CountryHeaders;
use CampaignBandit\Geo\CountryIndex;
use CampaignBandit\Leads\SpamGuard;
use CampaignBandit\Package\PackageWriter;
use CampaignBandit\Settings\CountrySettings;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\PublishRequest;
use CampaignBandit\Security\Capabilities;
use CampaignBandit\Support\Ulid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Thin adapters over the shared services.
 */
final class RestController {

	public const NAMESPACE = 'campaign-bandit/v1';

	private const UID = '(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function register_routes(): void {
		$can_publish = static fn (): bool => current_user_can( Capabilities::PUBLISH_CAMPAIGNS );

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/' . self::UID . '/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'publish' ),
				'permission_callback' => $can_publish,
				'args'                => array(
					'reset_shows'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'change_summary' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/' . self::UID . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::EDIT_CAMPAIGNS ),
			)
		);

		foreach ( array( 'switch-off', 'switch-on' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/campaigns/' . self::UID . '/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'switch_' . ( 'switch-on' === $action ? 'on' : 'off' ) ),
					'permission_callback' => $can_publish,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/' . self::UID . '/request-publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'request_publish' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::EDIT_CAMPAIGNS ),
				'args'                => array(
					'note' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Public: used by the visitor runtime when uploads aren't writable.
		register_rest_route(
			self::NAMESPACE,
			'/package/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'manifest' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/package/content/' . self::UID . '/(?P<version>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'content' ),
				'permission_callback' => '__return_true',
			)
		);
		// Public: lead submissions from the visitor runtime (same origin, FR-50).
		register_rest_route(
			self::NAMESPACE,
			'/leads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'lead' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'event' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/country',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'country' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST publish.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function publish( WP_REST_Request $request ) {
		$result = Plugin::publishing()->publish(
			(string) $request['uid'],
			(bool) $request['reset_shows'],
			(string) $request['change_summary'],
			AuditLog::CHANNEL_REST
		);
		return self::deployment_response( $result );
	}

	/**
	 * GET status for the editor: what is live on this install, the latest version, and
	 * the pre-publish checks for the current draft (S4).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function status( WP_REST_Request $request ) {
		$uid      = (string) $request['uid'];
		$campaign = Plugin::campaigns()->get( $uid );
		if ( null === $campaign ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No campaign has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		$deployment = ( new \CampaignBandit\Publishing\DeploymentRepository() )->find( $uid, 0 );
		return new WP_REST_Response(
			array(
				'campaign'       => $uid,
				'latest_version' => ( new \CampaignBandit\Publishing\VersionRepository() )->latest_number( $uid ),
				'live'           => null === $deployment || Deployment::REMOVED === $deployment->state ? null : array(
					'version' => $deployment->version_number,
					'state'   => $deployment->state,
				),
				'can_publish'    => current_user_can( Capabilities::PUBLISH_CAMPAIGNS ),
				// What the draft has that the live version does not, so the editor can
				// say the rules on screen are not the rules visitors are getting.
				'draft_changes'  => Plugin::publishing()->draft_changes( $uid ) ?? array(),
				'checks'         => Plugin::publishing()->checks( $campaign->post_id ),
			)
		);
	}

	/**
	 * POST switch-off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function switch_off( WP_REST_Request $request ) {
		return self::deployment_response( Plugin::publishing()->switch_off( (string) $request['uid'], AuditLog::CHANNEL_REST ) );
	}

	/**
	 * POST switch-on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function switch_on( WP_REST_Request $request ) {
		return self::deployment_response( Plugin::publishing()->switch_on( (string) $request['uid'], AuditLog::CHANNEL_REST ) );
	}

	/**
	 * POST a request to publish, for an Editor who may not publish themselves (S4).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function request_publish( WP_REST_Request $request ) {
		$campaign = Plugin::campaigns()->get( (string) $request['uid'] );
		if ( null === $campaign ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No campaign has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		$result = PublishRequest::send( $campaign, (string) $request['note'], AuditLog::CHANNEL_REST );
		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result );
	}

	/**
	 * GET manifest (fallback delivery). Short cache, like the static file's revalidation.
	 */
	public static function manifest(): WP_REST_Response {
		$response = new WP_REST_Response( Plugin::package_writer()->manifest() );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}

	/**
	 * GET content of a version that is live on this install. Other versions are never served.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function content( WP_REST_Request $request ) {
		$uid = (string) $request['uid'];
		foreach ( Plugin::package_writer()->public_entries() as $entry ) {
			if ( $entry['version']->campaign_uid === $uid && $entry['version']->number === (int) $request['version'] ) {
				$response = new WP_REST_Response( PackageWriter::content( $entry['version'] ) );
				$response->header( 'Cache-Control', 'public, max-age=31536000, immutable' );
				return $response;
			}
		}
		return new WP_Error( 'campaignbandit_not_found', __( 'Not found.', 'campaign-bandit' ), array( 'status' => 404 ) );
	}

	/**
	 * POST a lead. Stored on this site before success is reported (BR-40).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lead( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = json_decode( (string) $request->get_body(), true );
		}
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$result = Plugin::leads()->capture( is_array( $data ) ? $data : array(), $ip );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$response = new WP_REST_Response( array( 'ok' => true ), null === $result['lead'] ? 200 : 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST a view or conversion from the runtime (BR-15). Sent with sendBeacon, so the
	 * body is JSON with a text/plain content type. Answers 204 either way.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function event( WP_REST_Request $request ): WP_REST_Response {
		// Public and unauthenticated, so it is rate limited like a submission (05 §8).
		// Silently, with the same 204: the runtime does not read the response, and a
		// different answer would only tell somebody probing it that they had been seen.
		if ( ! SpamGuard::within_event_limit( self::visitor_ip() ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$data = json_decode( (string) $request->get_body(), true );
		if ( is_array( $data ) && is_string( $data['campaign'] ?? null ) && Ulid::is_valid( $data['campaign'] ) ) {
			Plugin::metrics()->record(
				$data['campaign'],
				(int) ( $data['version'] ?? 0 ),
				(string) ( $data['type'] ?? '' ),
				(string) ( $data['device'] ?? '' ),
				(string) ( $data['pageGroup'] ?? '/' )
			);
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * GET the visitor's country (BR-04, BR-05). The IP address is never stored.
	 */
	public static function country(): WP_REST_Response {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CountryHeaders validates every value.
		$country = CountryHeaders::from( (array) wp_unslash( $_SERVER ) );
		$source  = '' !== $country ? 'header' : '';

		if ( '' === $country && CountrySettings::database_enabled() ) {
			$directory = CountryDatabase::directory();
			$ip        = self::visitor_ip();
			if ( '' !== $directory && '' !== $ip ) {
				$country = ( new CountryIndex( $directory ) )->country( $ip );
				$source  = '' !== $country ? 'database' : '';
			}
		}

		$response = new WP_REST_Response(
			array(
				'country' => '' !== $country ? $country : 'unknown',
				'source'  => '' !== $source ? $source : 'none',
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * The address to look up: the connecting one, or the first in a forwarding header
	 * when the site sits behind a proxy it trusts. Used for the lookup and not stored.
	 */
	private static function visitor_ip(): string {
		/**
		 * Filters whether to trust a forwarding header for the country lookup. Off by
		 * default: a visitor can send X-Forwarded-For, so trust it only behind a proxy
		 * that overwrites it.
		 *
		 * @param bool $trust Whether to read X-Forwarded-For.
		 */
		if ( apply_filters( 'campaignbandit_trust_forwarded_for', false ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$first     = trim( $forwarded[0] );
			if ( '' !== $first ) {
				return $first;
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Shape a deployment (or error) for the editor.
	 *
	 * @param Deployment|WP_Error $result Service result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function deployment_response( $result ) {
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'campaign' => $result->campaign_uid,
				'version'  => $result->version_number,
				'state'    => $result->state,
			)
		);
	}
}
