<?php
/**
 * The connector on a client site (FR-10, FR-11, decision 0014): pairs with a hub
 * using a pasted key, sends signed requests to it, and disconnects.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Rest\RestController;
use CampaignBandit\Site\ModeStore;
use CampaignBandit\Site\SiteMode;
use WP_Error;

/**
 * Talks to the hub from the site.
 */
final class Connector {

	/**
	 * Seconds to wait for the hub.
	 */
	public const TIMEOUT = 15;

	/**
	 * Pair this site with the hub named in a pairing key.
	 *
	 * @param string $key Pasted pairing key.
	 * @return true|WP_Error
	 */
	public static function pair( string $key ) {
		$mode = ModeStore::current();
		if ( SiteMode::Standalone !== $mode ) {
			return new WP_Error(
				'campaignbandit_mode',
				SiteMode::Hub === $mode
					? __( 'This site is a hub. A hub can’t connect to another hub.', 'campaign-bandit' )
					: __( 'This site is already connected to a hub. Disconnect it first.', 'campaign-bandit' )
			);
		}
		$parsed = PairingKey::parse( $key );
		if ( null === $parsed ) {
			return new WP_Error( 'campaignbandit_key', __( 'That is not a pairing key. Copy the whole key from the hub’s Sites screen.', 'campaign-bandit' ) );
		}
		if ( ! self::secure_enough( $parsed['hub_url'] ) ) {
			return new WP_Error( 'campaignbandit_https', __( 'The hub must use https.', 'campaign-bandit' ) );
		}

		$keys     = KeyPair::generate();
		$response = self::post(
			$parsed['hub_url'],
			'/hub/pair',
			array(
				'token'             => $parsed['token'],
				'url'               => Connection::current_url(),
				'name'              => (string) get_bloginfo( 'name' ),
				'connector_version' => CAMPAIGNBANDIT_VERSION,
				'public_key'        => $keys['public'],
			),
			array()
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = $response['body'];
		if ( 201 !== $response['code'] || ! is_string( $body['site_uid'] ?? null ) || ! KeyPair::is_public_key( (string) ( $body['hub_public_key'] ?? '' ) ) ) {
			return self::hub_error( $response['code'], $body );
		}

		Connection::save(
			array(
				'hub_url'         => $parsed['hub_url'],
				'hub_name'        => sanitize_text_field( (string) ( $body['hub_name'] ?? '' ) ),
				'hub_public_key'  => (string) $body['hub_public_key'],
				'site_uid'        => $body['site_uid'],
				'site_public_key' => $keys['public'],
				'site_secret'     => $keys['secret'],
				'paired_url'      => Connection::current_url(),
				'connection'      => 'staging' === ( $body['connection'] ?? '' ) ? 'staging' : 'live',
				'workspace'       => sanitize_text_field( (string) ( $body['workspace'] ?? '' ) ),
				'last_sequence'   => 0,
				'last_sync_at'    => null,
				'connected_at'    => time(),
			)
		);
		ModeStore::change_to( SiteMode::Connected );
		( new AuditLog() )->record( 'site.connected', 'hub', $parsed['hub_url'], AuditLog::CHANNEL_SCREEN );

		/**
		 * Fires after this site pairs with a hub, so the first sync can start at once.
		 */
		do_action( 'campaignbandit_connected' );
		return true;
	}

	/**
	 * Become a site of its own after the hub split this copy off the site it was copied
	 * from (BR-34: Mark as staging or Pair as new site; decision 0016). The hub's signed
	 * instruction must name this site and the URL it runs at. New keys are made here and
	 * the request is signed with the old ones, which the copy shares with the original.
	 * Held leads are forwarded under the new site once its URL matches again.
	 *
	 * @param mixed                $envelope   Signed instruction from the hub.
	 * @param array<string, mixed> $connection Current connection.
	 * @return true|WP_Error
	 */
	public static function reidentify( $envelope, array $connection ) {
		$valid = is_array( $envelope ) && is_string( $envelope['payload'] ?? null ) && is_string( $envelope['signature'] ?? null )
			&& KeyPair::verify( (string) $connection['hub_public_key'], $envelope['payload'], $envelope['signature'] );
		$order = $valid ? json_decode( $envelope['payload'], true ) : null;
		if ( ! is_array( $order ) || ( $order['site'] ?? null ) !== $connection['site_uid'] || ( $order['url'] ?? null ) !== Connection::current_url() ) {
			return new WP_Error( 'campaignbandit_reidentify', __( 'The hub sent an instruction this site could not verify. It keeps its connection as it is.', 'campaign-bandit' ) );
		}

		$keys     = KeyPair::generate();
		$response = self::signed(
			'POST',
			'/hub/reidentify',
			array(
				'url'               => Connection::current_url(),
				'name'              => (string) get_bloginfo( 'name' ),
				'connector_version' => CAMPAIGNBANDIT_VERSION,
				'public_key'        => $keys['public'],
			),
			$connection
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = $response['body'];
		if ( 201 !== $response['code'] || ! is_string( $body['site_uid'] ?? null ) || ( $body['hub_public_key'] ?? null ) !== $connection['hub_public_key'] ) {
			return self::hub_error( $response['code'], $body );
		}

		$saved                    = $connection;
		$saved['site_uid']        = $body['site_uid'];
		$saved['site_public_key'] = $keys['public'];
		$saved['site_secret']     = $keys['secret'];
		$saved['paired_url']      = Connection::current_url();
		$saved['connection']      = 'staging' === ( $body['connection'] ?? '' ) ? 'staging' : 'live';
		$saved['workspace']       = sanitize_text_field( (string) ( $body['workspace'] ?? '' ) );
		$saved['last_sequence']   = 0;
		$saved['connected_at']    = time();
		Connection::save( $saved );
		( new AuditLog() )->record( 'site.reidentified', 'hub', (string) $connection['hub_url'], AuditLog::CHANNEL_SYSTEM, array( 'connection' => $saved['connection'] ) );
		if ( 'staging' === $saved['connection'] ) {
			// Held leads become test leads (BR-34).
			( new LeadRepository() )->mark_held_as_test();
		}
		// Hub campaigns resume once the new package arrives; held leads go now.
		PackageApplier::reconcile();
		LeadForwarder::run();
		return true;
	}

	/**
	 * Disconnect from the hub (FR-11). The hub is told, if it can be reached; the site
	 * becomes standalone either way. Leads already forwarded stay on the hub.
	 */
	public static function disconnect(): void {
		$connection = Connection::get();
		if ( null !== $connection ) {
			self::signed( 'POST', '/hub/disconnect', array(), $connection );
			( new AuditLog() )->record( 'site.disconnected', 'hub', $connection['hub_url'], AuditLog::CHANNEL_SCREEN );
		}
		self::forget();
	}

	/**
	 * Drop the connection and return to standalone: used on disconnect from either side.
	 */
	public static function forget(): void {
		// Send queued leads while this site can still sign requests (FR-11, BR-41).
		LeadForwarder::run();
		Connection::clear();
		if ( SiteMode::Connected === ModeStore::current() ) {
			ModeStore::change_to( SiteMode::Standalone );
		}

		/**
		 * Fires after this site stops being connected to a hub.
		 */
		do_action( 'campaignbandit_disconnected' );
	}

	/**
	 * A signed request to the hub.
	 *
	 * @param string                    $method     GET or POST.
	 * @param string                    $route      Route under the namespace, such as /hub/sync.
	 * @param array<string, mixed>      $data       JSON body (POST) or query values (GET).
	 * @param array<string, mixed>|null $connection Connection, or null to load it.
	 * @return array{code: int, body: mixed, headers: array<string, string>}|WP_Error
	 */
	public static function signed( string $method, string $route, array $data, ?array $connection = null ) {
		$connection ??= Connection::get();
		if ( null === $connection ) {
			return new WP_Error( 'campaignbandit_not_connected', __( 'This site is not connected to a hub.', 'campaign-bandit' ) );
		}
		$full    = '/' . RestController::NAMESPACE . $route;
		$body    = 'POST' === $method ? (string) wp_json_encode( $data ) : '';
		$headers = RequestSigner::headers( (string) $connection['site_uid'], (string) $connection['site_secret'], $method, $full, $body, time() );
		$url     = self::endpoint( (string) $connection['hub_url'], $route );
		if ( 'GET' === $method && array() !== $data ) {
			$url = add_query_arg( $data, $url );
		}
		return self::request( $method, $url, $body, $headers );
	}

	/**
	 * An unsigned JSON POST to a hub.
	 *
	 * @param string                $hub_url Hub URL.
	 * @param string                $route   Route.
	 * @param array<string, mixed>  $data    Body.
	 * @param array<string, string> $headers Extra headers.
	 * @return array{code: int, body: mixed, headers: array<string, string>}|WP_Error
	 */
	private static function post( string $hub_url, string $route, array $data, array $headers ) {
		return self::request( 'POST', self::endpoint( $hub_url, $route ), (string) wp_json_encode( $data ), $headers );
	}

	/**
	 * Send a request and decode the JSON answer.
	 *
	 * @param string                $method  Method.
	 * @param string                $url     URL.
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Headers.
	 * @return array{code: int, body: mixed, headers: array<string, string>}|WP_Error
	 */
	private static function request( string $method, string $url, string $body, array $headers ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array_merge(
					array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					$headers
				),
				'body'        => 'POST' === $method ? $body : null,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'campaignbandit_unreachable',
				/* translators: %s: error from the HTTP request. */
				sprintf( __( 'Couldn’t reach the hub: %s', 'campaign-bandit' ), $response->get_error_message() )
			);
		}
		$out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
			'headers' => $out,
		);
	}

	/**
	 * A hub REST URL that works whatever its permalink setting.
	 *
	 * @param string $hub_url Hub URL.
	 * @param string $route   Route under the namespace.
	 */
	public static function endpoint( string $hub_url, string $route ): string {
		/**
		 * Filters the address this site uses to reach its hub, for setups where the
		 * hub's public URL isn't reachable from the site (local containers).
		 *
		 * @param string $hub_url Hub URL from the pairing key.
		 */
		$base = (string) apply_filters( 'campaignbandit_hub_address', $hub_url );
		return add_query_arg( 'rest_route', '/' . RestController::NAMESPACE . $route, trailingslashit( $base ) );
	}

	/**
	 * The hub's refusal as an error people can act on.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $body Decoded body.
	 */
	private static function hub_error( int $code, $body ): WP_Error {
		if ( is_array( $body ) && is_string( $body['message'] ?? null ) && '' !== $body['message'] ) {
			return new WP_Error( 'campaignbandit_hub', sanitize_text_field( $body['message'] ) );
		}
		return new WP_Error(
			'campaignbandit_hub',
			404 === $code
				? __( 'The hub didn’t answer as a Campaign Bandit hub. Check that its Agency licence is active.', 'campaign-bandit' )
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'The hub refused the request (HTTP %d).', 'campaign-bandit' ), $code )
		);
	}

	/**
	 * Hubs must use https, except on local and development sites.
	 *
	 * @param string $hub_url Hub URL.
	 */
	private static function secure_enough( string $hub_url ): bool {
		/**
		 * Filters whether this site may pair with a hub over plain http.
		 *
		 * @param bool $allow Default true on local and development environments only.
		 */
		$allow = (bool) apply_filters( 'campaignbandit_allow_insecure_hub', in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );
		return str_starts_with( $hub_url, 'https://' ) || $allow;
	}
}
