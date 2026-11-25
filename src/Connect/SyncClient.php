<?php
/**
 * Keeps a connected site in step with its hub (decision 0014, BR-30, BR-34).
 *
 * The site pulls every 5 minutes through WP-Cron, at once after pairing, and when the
 * hub sends a signed refresh signal. If the hub can't be reached, the last package
 * keeps running.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

use CampaignBandit\Metrics\MetricsReport;
use CampaignBandit\Plugin;
use CampaignBandit\Rest\RestController;
use CampaignBandit\Site\ModeStore;
use CampaignBandit\Site\SiteMode;
use WP_Error;
use WP_REST_Request;

/**
 * Pulls packages from the hub and reports health.
 */
final class SyncClient {

	public const HOOK     = 'campaignbandit_hub_sync';
	public const SCHEDULE = 'campaignbandit_five_minutes';
	public const INTERVAL = 300;

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- BR-30 needs a 5-minute pull.
		$run = static function (): void {
			self::sync();
		};
		add_action( self::HOOK, $run );
		add_action( 'campaignbandit_connected', $run );
		add_action(
			'campaignbandit_disconnected',
			static function (): void {
				wp_clear_scheduled_hook( self::HOOK );
			}
		);
		add_action( 'rest_api_init', array( self::class, 'routes' ) );

		if ( SiteMode::Connected !== ModeStore::current() ) {
			return;
		}
		add_action( 'init', array( self::class, 'check_url' ) );
		// On init: scheduling reads the schedule's translated label.
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_event( time() + self::INTERVAL, self::SCHEDULE, self::HOOK );
				}
			}
		);
	}

	/**
	 * Add the 5-minute schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 5 minutes (Campaign Bandit hub sync)', 'campaign-bandit' ),
		);
		return $schedules;
	}

	/**
	 * Pull from the hub once.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		$connection = Connection::get();
		if ( null === $connection ) {
			return new WP_Error( 'campaignbandit_not_connected', __( 'This site is not connected to a hub.', 'campaign-bandit' ) );
		}
		$metrics = self::metrics();
		$report  = array(
			'sequence'          => $connection['last_sequence'],
			'url'               => Connection::current_url(),
			'connector_version' => CAMPAIGNBANDIT_VERSION,
			'environment'       => self::environment(),
			// Daily totals for hub campaigns (FR-61, decision 0020).
			'metrics'           => $metrics['rows'],
		);
		// The site's public structure, only when it changed since the hub confirmed it
		// (BR-35, decision 0021).
		$structure = StructureCollector::structure();
		$hash      = SiteStructure::hash( $structure );
		$stored    = (array) get_option( Connection::OPTION, array() );
		if ( ( $stored['structure_hash'] ?? '' ) !== $hash ) {
			$report['structure'] = $structure;
		}
		$response = Connector::signed( 'POST', '/hub/sync', $report, $connection );
		if ( is_wp_error( $response ) ) {
			return self::failed( $response );
		}
		$body = $response['body'];
		if ( 200 !== $response['code'] || ! is_array( $body ) ) {
			/* translators: %d: HTTP status code. */
			return self::failed( new WP_Error( 'campaignbandit_sync', sprintf( __( 'The hub answered HTTP %d.', 'campaign-bandit' ), $response['code'] ) ) );
		}

		if ( isset( $body['reidentify'] ) ) {
			// This site is a copy the hub split off (BR-34, decision 0016): pair as a site
			// of its own, then sync again under the new identity.
			$result = Connector::reidentify( $body['reidentify'], $connection );
			return is_wp_error( $result ) ? self::failed( $result ) : self::sync();
		}

		if ( isset( $body['package'] ) ) {
			$payload = SignedPackage::open( $body['package'], $connection['hub_public_key'], $connection['site_uid'], $connection['last_sequence'] );
			if ( null === $payload ) {
				return self::failed( new WP_Error( 'campaignbandit_package', __( 'The hub sent a package this site could not verify. It keeps the campaigns it has.', 'campaign-bandit' ) ) );
			}
			$failed = PackageApplier::apply( $payload );
			if ( null === Connection::get() ) {
				// The package disconnected this site.
				return true;
			}
			if ( $failed > 0 ) {
				return self::failed(
					new WP_Error(
						'campaignbandit_media',
						/* translators: %d: number of images. */
						sprintf( _n( '%d campaign image could not be copied from the hub. Its campaign keeps the version it had until it is.', '%d campaign images could not be copied from the hub. Their campaigns keep the version they had until they are.', $failed, 'campaign-bandit' ), $failed )
					)
				);
			}
		}
		$done = array(
			'last_sync_at' => time(),
			'last_error'   => '',
		);
		// The hub confirms it stored the totals; a hub without campaign metrics doesn't
		// answer this, so the site keeps them for a hub that does.
		if ( isset( $body['metrics'] ) ) {
			$done['metrics_through'] = $metrics['through'];
		}
		if ( isset( $report['structure'] ) && true === ( $body['structure'] ?? false ) ) {
			$done['structure_hash'] = $hash;
		}
		Connection::update( $done );
		return true;
	}

	/**
	 * Totals to report: hub campaigns, from the day before the last day the hub
	 * confirmed. None from a staging site, which counts in no metric (BR-15).
	 *
	 * @return array{rows: array<int, array<string, mixed>>, through: string}
	 */
	private static function metrics(): array {
		$today = wp_date( 'Y-m-d' );
		if ( Connection::is_staging() ) {
			return array(
				'rows'    => array(),
				'through' => $today,
			);
		}
		$stored  = (array) get_option( Connection::OPTION, array() );
		$through = is_string( $stored['metrics_through'] ?? null ) ? $stored['metrics_through'] : null;
		$from    = MetricsReport::from_day( $through, $today );
		$taken   = MetricsReport::take( Plugin::metrics()->report_rows( array_keys( PackageApplier::known() ), $from ) );
		return array(
			'rows'    => $taken['rows'],
			'through' => $taken['through'] ?? $today,
		);
	}

	/**
	 * Pause hub campaigns at once when the site's URL no longer matches the one it was
	 * paired with, and resume them when it matches again (BR-34). Runs on every request
	 * of a connected site; the comparison reads only the autoloaded connection.
	 */
	public static function check_url(): void {
		$stored = get_option( Connection::OPTION );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$changed = Connection::current_url() !== (string) ( $stored['paired_url'] ?? '' );
		if ( (bool) ( $stored['url_hold'] ?? false ) === $changed ) {
			return;
		}
		Connection::update( array( 'url_hold' => $changed ? 1 : 0 ) );
		PackageApplier::reconcile();
	}

	/**
	 * POST /connector/refresh: the hub's signal that something changed (BR-30).
	 */
	public static function routes(): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/connector/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => static function (): array {
					self::sync();
					return array( 'ok' => true );
				},
				'permission_callback' => array( self::class, 'from_hub' ),
			)
		);
	}

	/**
	 * Whether a request was signed by this site's hub, inside the window, with a new
	 * nonce. Anything else is a bare 401.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function from_hub( WP_REST_Request $request ) {
		$connection = Connection::get();
		$headers    = array(
			RequestSigner::HEADER_TIMESTAMP => (string) $request->get_header( 'x_campaign_bandit_timestamp' ),
			RequestSigner::HEADER_NONCE     => (string) $request->get_header( 'x_campaign_bandit_nonce' ),
			RequestSigner::HEADER_SIGNATURE => (string) $request->get_header( 'x_campaign_bandit_request_signature' ),
		);
		$ok         = null !== $connection
			&& (string) $request->get_header( 'x_campaign_bandit_site' ) === $connection['site_uid']
			&& RequestSigner::verify( $connection['hub_public_key'], $request->get_method(), $request->get_route(), $headers, (string) $request->get_body(), time() )
			&& NonceStore::use( 'hub', $headers[ RequestSigner::HEADER_NONCE ] );
		return $ok ? true : new WP_Error( 'campaignbandit_unauthorised', 'Unauthorised', array( 'status' => 401 ) );
	}

	/**
	 * Health the hub shows on its Sites screen (S2).
	 *
	 * @return array<string, mixed>
	 */
	private static function environment(): array {
		global $wp_version;
		return array(
			'php'             => PHP_VERSION,
			'wordpress'       => (string) $wp_version,
			'page_cache'      => defined( 'WP_CACHE' ) && WP_CACHE,
			// The cookie consent tool on this site, or '' (BR-48).
			'consent_tool'    => \CampaignBandit\Privacy\ConsentTools::detect(),
			'timezone'        => wp_timezone_string(),
			'local_campaigns' => count( array_filter( ( new \CampaignBandit\Publishing\DeploymentRepository() )->live( 0 ), static fn ( $d ): bool => ! PackageApplier::is_hub_campaign( $d->campaign_uid ) ) ),
			// Leads waiting to reach the hub, and leads that gave up after 7 days (BR-41).
			'held_leads'      => ( new \CampaignBandit\Leads\LeadRepository() )->count_forward( ForwardPolicy::PENDING ),
			'not_forwarded'   => ( new \CampaignBandit\Leads\LeadRepository() )->count_forward( ForwardPolicy::NOT_FORWARDED ),
			// Images that could not be copied from the hub (decision 0002).
			'media_failed'    => (int) ( ( (array) get_option( Connection::OPTION, array() ) )['media_failed'] ?? 0 ),
		);
	}

	/**
	 * Keep the error for the Connection section, and pass it on.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function failed( WP_Error $error ): WP_Error {
		Connection::update( array( 'last_error' => $error->get_error_message() ) );
		return $error;
	}
}
