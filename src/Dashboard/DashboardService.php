<?php
/**
 * Figures for the standalone dashboard (S10, FR-60): live campaigns, views and
 * leads in the last 7 days, the live campaigns table and the first-run checklist.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Dashboard;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignService;
use CampaignBandit\Database\Schema;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Metrics\ConversionRate;
use CampaignBandit\Publishing\Deployment;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads dashboard figures for this site (site ID 0).
 */
final class DashboardService {

	public const DAYS = 7;

	/**
	 * Create the service.
	 *
	 * @param CampaignService $campaigns     Campaigns, for names and posts.
	 * @param DateTimeZone    $site_timezone Site timezone: the 7 days are site days.
	 * @param int             $now           Current Unix time.
	 */
	public function __construct(
		private readonly CampaignService $campaigns,
		private readonly DateTimeZone $site_timezone,
		private readonly int $now,
	) {}

	/**
	 * The first site day in the range, "Y-m-d" (today counts as one of the 7).
	 */
	public function first_day(): string {
		return ( new DateTimeImmutable( '@' . $this->now ) )
			->setTimezone( $this->site_timezone )
			->modify( '-' . ( self::DAYS - 1 ) . ' days' )
			->format( 'Y-m-d' );
	}

	/**
	 * Midnight at the start of the range, as UTC "Y-m-d H:i:s" for submitted_at.
	 */
	private function first_moment_utc(): string {
		return ( new DateTimeImmutable( $this->first_day() . ' 00:00:00', $this->site_timezone ) )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Campaigns that are live or switched off on this site, with their 7-day figures,
	 * live ones first, then by views.
	 *
	 * @return array<int, array{uid: string, name: string, post_id: int, format: string, version: int, state: string, draft_changes: bool, views: int, conversions: int, rate: float|null, leads: int}>
	 */
	public function campaigns(): array {
		global $wpdb;
		$deployments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT d.campaign_uid, d.state, d.version_number, d.published_at, v.settings FROM %i d JOIN %i v ON v.id = d.version_id WHERE d.site_id = 0 AND d.state IN (%s, %s)',
				$wpdb->prefix . Schema::DEPLOYMENTS,
				$wpdb->prefix . Schema::VERSIONS,
				Deployment::LIVE,
				Deployment::PAUSED
			),
			ARRAY_A
		);

		$metrics = self::by_campaign(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT campaign_uid, SUM(views) AS views, SUM(conversions) AS conversions FROM %i WHERE site_id = 0 AND day >= %s GROUP BY campaign_uid',
					$wpdb->prefix . Schema::METRICS,
					$this->first_day()
				),
				ARRAY_A
			)
		);
		$leads   = self::by_campaign(
			$wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT campaign_uid, COUNT(*) AS leads FROM %i WHERE site_id = 0 AND is_test = 0 AND submitted_at >= %s GROUP BY campaign_uid',
					LeadRepository::table(),
					$this->first_moment_utc()
				),
				ARRAY_A
			)
		);

		$rows = array();
		foreach ( is_array( $deployments ) ? $deployments : array() as $d ) {
			$uid      = (string) $d['campaign_uid'];
			$campaign = $this->campaigns->get( $uid );
			$settings = json_decode( (string) $d['settings'], true );
			$views    = (int) ( $metrics[ $uid ]['views'] ?? 0 );
			$convs    = (int) ( $metrics[ $uid ]['conversions'] ?? 0 );
			$modified = null === $campaign ? '' : (string) get_post_field( 'post_modified_gmt', $campaign->post_id );

			$rows[] = array(
				'uid'           => $uid,
				'name'          => null === $campaign ? '' : $campaign->name,
				'post_id'       => null === $campaign ? 0 : $campaign->post_id,
				'format'        => is_array( $settings ) ? (string) ( $settings['format'] ?? 'modal' ) : 'modal',
				'version'       => (int) $d['version_number'],
				'state'         => (string) $d['state'],
				// The draft was edited after this version was published.
				'draft_changes' => '' !== $modified && null !== $d['published_at'] && $modified > (string) $d['published_at'],
				'views'         => $views,
				'conversions'   => $convs,
				'rate'          => ConversionRate::percent( $convs, $views ),
				'leads'         => (int) ( $leads[ $uid ]['leads'] ?? 0 ),
			);
		}//end foreach

		usort(
			$rows,
			static fn ( array $a, array $b ): int => array( Deployment::LIVE !== $a['state'], -$a['views'] ) <=> array( Deployment::LIVE !== $b['state'], -$b['views'] )
		);
		return $rows;
	}

	/**
	 * The four figures (S10). Pro's "reached destination" joins with webhook delivery (M2).
	 *
	 * @param array<int, array<string, mixed>> $campaigns Rows from campaigns().
	 * @return array{live: int, views: int, conversions: int, leads: int}
	 */
	public function figures( array $campaigns ): array {
		global $wpdb;
		$leads = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE site_id = 0 AND is_test = 0 AND submitted_at >= %s',
				LeadRepository::table(),
				$this->first_moment_utc()
			)
		);
		return array(
			'live'        => count( array_filter( $campaigns, static fn ( array $c ): bool => Deployment::LIVE === $c['state'] ) ),
			'views'       => array_sum( array_column( $campaigns, 'views' ) ),
			'conversions' => array_sum( array_column( $campaigns, 'conversions' ) ),
			'leads'       => $leads,
		);
	}

	/**
	 * "Reached destination" for the last 7 days (S10, Pro): of the leads that had
	 * destinations, how many were delivered, and how many are retrying or failed.
	 *
	 * @return array{delivered: int, total: int, retrying: int, failed: int}
	 */
	public function reached(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM %i WHERE site_id = 0 AND is_test = 0 AND status <> 'stored_only' AND submitted_at >= %s GROUP BY status",
				LeadRepository::table(),
				$this->first_moment_utc()
			),
			ARRAY_A
		);
		$by   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by[ (string) $row['status'] ] = (int) $row['n'];
		}
		return array(
			'delivered' => $by['delivered'] ?? 0,
			'total'     => array_sum( $by ),
			// Sending counts under Retrying (S6).
			'retrying'  => ( $by['retrying'] ?? 0 ) + ( $by['sending'] ?? 0 ),
			'failed'    => $by['failed'] ?? 0,
		);
	}

	/**
	 * The first-run checklist (S10): create a campaign, publish it, see the first lead.
	 *
	 * @return array{created: bool, published: bool, lead: bool}
	 */
	public function checklist(): array {
		global $wpdb;
		$created   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash')",
				$wpdb->posts,
				CampaignPostType::POST_TYPE
			)
		);
		$published = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE site_id = 0', $wpdb->prefix . Schema::DEPLOYMENTS )
		);
		$lead      = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_test = 0', LeadRepository::table() )
		);
		return array(
			'created'   => $created > 0,
			'published' => $published > 0,
			'lead'      => $lead > 0,
		);
	}

	/**
	 * Rows keyed by campaign ULID.
	 *
	 * @param mixed $rows Query results.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_campaign( $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['campaign_uid'] ] = $row;
		}
		return $out;
	}
}
