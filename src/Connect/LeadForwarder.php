<?php
/**
 * Forwards leads from a connected site to its hub (BR-41, FR-51, decision 0014).
 *
 * Every lead is stored on the site first (BR-40). It is then sent to the hub in signed
 * batches, at once and on a 5-minute schedule, with ForwardPolicy's gaps for up to
 * 7 days. While the site's URL differs from its paired URL, leads are held (BR-34).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Site\ModeStore;
use CampaignBandit\Site\SiteMode;

/**
 * Queues and sends leads to the hub.
 */
final class LeadForwarder {

	public const HOOK = 'campaignbandit_forward_leads';

	private const LOCK = 'campaignbandit_forward_lock';

	/**
	 * Whether a run is booked for the end of this request.
	 *
	 * @var bool
	 */
	private static bool $booked = false;

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action(
			self::HOOK,
			static function (): void {
				self::run();
			}
		);
		add_action(
			'campaignbandit_disconnected',
			static function (): void {
				wp_clear_scheduled_hook( self::HOOK );
			}
		);
		if ( SiteMode::Connected !== ModeStore::current() ) {
			return;
		}
		add_action( 'campaignbandit_lead_stored', array( self::class, 'queue' ) );
		add_action( 'campaignbandit_lead_status_changed', array( self::class, 'status_changed' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_event( time() + SyncClient::INTERVAL, SyncClient::SCHEDULE, self::HOOK );
				}
			}
		);
	}

	/**
	 * Mark a new lead for forwarding and send it once the visitor has their answer.
	 *
	 * @param string $uid Lead ULID.
	 */
	public static function queue( string $uid ): void {
		$connection = Connection::get();
		if ( null === $connection ) {
			return;
		}
		( new LeadRepository() )->queue_forward( $uid, 'staging' === $connection['connection'] );
		if ( ! self::$booked ) {
			self::$booked = true;
			add_action(
				'shutdown',
				static function (): void {
					if ( function_exists( 'fastcgi_finish_request' ) ) {
						fastcgi_finish_request();
					}
					self::run();
				},
				0
			);
		}
	}

	/**
	 * A lead from one of this site's own campaigns changed delivery status after it was
	 * forwarded: send it again, so the hub shows the status the site has (BR-61).
	 *
	 * @param string $uid Lead ULID.
	 */
	public static function status_changed( string $uid ): void {
		$repo = new LeadRepository();
		$lead = $repo->find( $uid );
		if ( null === $lead || ForwardPolicy::FORWARDED !== $lead['forward_status'] || PackageApplier::is_hub_campaign( (string) $lead['campaign_uid'] ) ) {
			return;
		}
		self::queue( $uid );
	}

	/**
	 * Send due leads in batches. One run at a time.
	 *
	 * @return int Leads the hub accepted.
	 */
	public static function run(): int {
		$connection = Connection::get();
		if ( null === $connection ) {
			return 0;
		}
		// Held or unsendable for 7 days: "Not forwarded", kept on the site (BR-41, BR-34).
		( new LeadRepository() )->expire_forwards( gmdate( 'Y-m-d H:i:s', time() - ForwardPolicy::LIMIT ) );
		// While the site's URL differs from its paired URL, leads are held (BR-34).
		if ( Connection::current_url() !== $connection['paired_url'] ) {
			return 0;
		}
		// Expired locks (a run that died) are taken over after 2 minutes.
		$lock = get_option( self::LOCK );
		if ( false !== $lock && (int) $lock > time() - 120 ) {
			return 0;
		}
		if ( false === $lock ? ! add_option( self::LOCK, (string) time(), '', false ) : ! update_option( self::LOCK, (string) time(), false ) ) {
			return 0;
		}

		$accepted = 0;
		try {
			$repo = new LeadRepository();
			for ( $round = 0; $round < 10; $round++ ) {
				$due = $repo->due_forwards( gmdate( 'Y-m-d H:i:s' ), ForwardPolicy::BATCH );
				if ( array() === $due ) {
					break;
				}
				$sent      = self::send( $repo, $due, $connection );
				$accepted += $sent;
				if ( 0 === $sent ) {
					break;
					// The hub is unreachable or refusing: try again later.
				}
			}
		} finally {
			delete_option( self::LOCK );
		}
		return $accepted;
	}

	/**
	 * Send one batch and record each lead's result.
	 *
	 * @param LeadRepository                   $repo       Leads.
	 * @param array<int, array<string, mixed>> $due        Lead rows.
	 * @param array<string, mixed>             $connection Connection.
	 * @return int Leads accepted.
	 */
	private static function send( LeadRepository $repo, array $due, array $connection ): int {
		$response = Connector::signed( 'POST', '/hub/leads', array( 'leads' => array_map( array( self::class, 'payload' ), $due ) ), $connection );
		$accepted = array();
		if ( ! is_wp_error( $response ) && 200 === $response['code'] && is_array( $response['body'] ) && is_array( $response['body']['accepted'] ?? null ) ) {
			$accepted = array_flip( array_filter( $response['body']['accepted'], 'is_string' ) );
		}

		// The hub removed this site more than 7 days ago and takes no more leads (BR-41).
		$gone = ! is_wp_error( $response ) && 410 === $response['code'];

		$now = time();
		foreach ( $due as $lead ) {
			$uid      = (string) $lead['uid'];
			$attempts = (int) $lead['forward_attempts'] + 1;
			if ( $gone ) {
				$repo->set_forward( $uid, ForwardPolicy::NOT_FORWARDED, $attempts, null );
				continue;
			}
			if ( isset( $accepted[ $uid ] ) ) {
				$repo->set_forward( $uid, ForwardPolicy::FORWARDED, $attempts, null );
				continue;
			}
			$next = ForwardPolicy::next( $attempts, (int) strtotime( $lead['submitted_at'] . ' UTC' ), $now );
			if ( null === $next ) {
				$repo->set_forward( $uid, ForwardPolicy::NOT_FORWARDED, $attempts, null );
			} else {
				$repo->set_forward( $uid, ForwardPolicy::PENDING, $attempts, gmdate( 'Y-m-d H:i:s', $next ) );
			}
		}
		return count( $accepted );
	}

	/**
	 * A lead as the hub receives it.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, mixed>
	 */
	public static function payload( array $lead ): array {
		$decode = static function ( $json ) {
			$value = json_decode( (string) $json, true );
			return is_array( $value ) ? $value : null;
		};
		return array(
			'uid'             => (string) $lead['uid'],
			'campaign'        => (string) $lead['campaign_uid'],
			'version'         => (int) $lead['version_number'],
			'submitted_at'    => (string) $lead['submitted_at'],
			'email'           => $lead['email'],
			'name'            => $lead['name'],
			'fields'          => $decode( $lead['fields'] ) ?? array(),
			'consent_text'    => $lead['consent_text'],
			'consent'         => $decode( $lead['consent'] ?? null ),
			'source'          => $decode( $lead['source'] ?? null ),
			'page_url'        => (string) $lead['page_url'],
			'utm'             => $decode( $lead['utm'] ),
			'explanation'     => $decode( $lead['explanation'] ),
			'status'          => (string) $lead['status'],
			// Which forwarding attempt this is, for the hub's delivery log (S6).
			'forward_attempt' => (int) ( $lead['forward_attempts'] ?? 0 ) + 1,
			'is_test'         => (bool) $lead['is_test'],
			// Local campaigns (BR-61): the hub stores these and never delivers them again.
			'local'           => ! PackageApplier::is_hub_campaign( (string) $lead['campaign_uid'] ),
			// Whether this site delivers its own campaigns' leads (it has Pro, BR-61).
			'site_delivery'   => (bool) apply_filters( 'campaignbandit_delivery_enabled', false ),
		);
	}
}
