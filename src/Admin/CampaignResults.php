<?php
/**
 * Campaign detail results (S12): the range, the four figures with the change on the
 * previous period, the daily line chart switched by the figures, and the Leads tab.
 * Figures are this site's (BR-15); on a hub, every site's together: client sites report
 * their totals with each sync (FR-61, decision 0020).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Campaign\Campaign;
use CampaignBandit\Database\Schema;
use CampaignBandit\Leads\LeadFilter;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Metrics\Figures;
use CampaignBandit\Metrics\LineChart;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\DeploymentRepository;

/**
 * Figures, chart and leads for one campaign.
 */
final class CampaignResults {

	public const RANGES = array( '7', '30', 'version' );

	/**
	 * The chosen range: 7 or 30 days, or since the live version went live (Pro and above).
	 */
	public static function range(): string {
		$range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '7'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		if ( 'version' === $range && ! Plan::current()->includes( Plan::Pro ) ) {
			return '7';
		}
		return in_array( $range, self::RANGES, true ) ? $range : '7';
	}

	/**
	 * Days of a range, in the site's time zone: first day, number of days, and the
	 * previous period of the same length (null for "This version").
	 *
	 * @param string $uid   Campaign ULID.
	 * @param string $range Range.
	 * @return array{first: string, days: int, previous: ?string}
	 */
	public static function period( string $uid, string $range ): array {
		$today = (int) strtotime( wp_date( 'Y-m-d' ) . ' 12:00:00 UTC' );
		if ( 'version' === $range ) {
			$published = self::live_since( $uid );
			$since     = null === $published ? $today : (int) strtotime( wp_date( 'Y-m-d', (int) strtotime( $published . ' UTC' ) ) . ' 12:00:00 UTC' );
			$days      = max( 1, min( 365, (int) round( ( $today - $since ) / DAY_IN_SECONDS ) + 1 ) );
			return array(
				'first'    => gmdate( 'Y-m-d', $today - ( $days - 1 ) * DAY_IN_SECONDS ),
				'days'     => $days,
				'previous' => null,
			);
		}
		$days = (int) $range;
		return array(
			'first'    => gmdate( 'Y-m-d', $today - ( $days - 1 ) * DAY_IN_SECONDS ),
			'days'     => $days,
			'previous' => gmdate( 'Y-m-d', $today - ( 2 * $days - 1 ) * DAY_IN_SECONDS ),
		);
	}

	/**
	 * When the live version went live (UTC), or null. On a hub: the newest version live on
	 * any site, from the first site that got it.
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function live_since( string $uid ): ?string {
		if ( ! Plan::current()->is_hub_plan() ) {
			$deployment = ( new DeploymentRepository() )->find( $uid, 0 );
			return $deployment?->published_at;
		}
		$version = 0;
		$since   = null;
		foreach ( ( new DeploymentRepository() )->for_campaign( $uid ) as $deployment ) {
			if ( Deployment::LIVE !== $deployment->state || null === $deployment->published_at ) {
				continue;
			}
			if ( $deployment->version_number > $version ) {
				$version = $deployment->version_number;
				$since   = $deployment->published_at;
			} elseif ( $deployment->version_number === $version && $deployment->published_at < (string) $since ) {
				$since = $deployment->published_at;
			}
		}
		return $since;
	}

	/**
	 * Views, conversions and leads by day: this site's, or on a hub every site's (FR-61).
	 * Test leads are left out (BR-15).
	 *
	 * @param string $uid   Campaign ULID.
	 * @param string $first First day, Y-m-d.
	 * @param int    $days  Days.
	 * @return array<string, array{views: int, conversions: int, leads: int}>
	 */
	public static function counts( string $uid, string $first, int $days ): array {
		global $wpdb;
		$hub  = Plan::current()->is_hub_plan();
		$last = gmdate( 'Y-m-d', (int) strtotime( $first . ' 12:00:00 UTC' ) + ( $days - 1 ) * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT day, SUM(views) AS views, SUM(conversions) AS conversions FROM %i WHERE campaign_uid = %s AND ( site_id = 0 OR %d = 1 ) AND day BETWEEN %s AND %s GROUP BY day',
				$wpdb->prefix . Schema::METRICS,
				$uid,
				$hub ? 1 : 0,
				$first,
				$last
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['day'] ] = array(
				'views'       => (int) $row['views'],
				'conversions' => (int) $row['conversions'],
				'leads'       => 0,
			);
		}
		$filter = new LeadFilter( campaign: $uid, from: $first, to: $last, site: $hub ? null : 0 );
		foreach ( ( new LeadRepository() )->each( $filter, wp_timezone() ) as $lead ) {
			if ( ! empty( $lead['is_test'] ) ) {
				continue;
			}
			$day = wp_date( 'Y-m-d', (int) strtotime( $lead['submitted_at'] . ' UTC' ) );
			if ( ! isset( $out[ $day ] ) ) {
				$out[ $day ] = array(
					'views'       => 0,
					'conversions' => 0,
					'leads'       => 0,
				);
			}
			++$out[ $day ]['leads'];
		}
		return $out;
	}

	/**
	 * Render the range, figures and chart.
	 *
	 * @param Campaign                                                                                  $campaign Campaign.
	 * @param array<int, array{version: \CampaignBandit\Publishing\Version, published_by_name: string}> $versions Versions.
	 */
	public static function render( Campaign $campaign, array $versions ): void {
		$range   = self::range();
		$period  = self::period( $campaign->uid, $range );
		$series  = Figures::series( self::counts( $campaign->uid, $period['first'], $period['days'] ), $period['first'], $period['days'] );
		$totals  = Figures::totals( $series );
		$before  = null;
		$change  = array();
		$compare = '';
		if ( null !== $period['previous'] ) {
			$before = Figures::series( self::counts( $campaign->uid, $period['previous'], $period['days'] ), $period['previous'], $period['days'] );
			$change = Figures::change( $totals, Figures::totals( $before ) );
			/* translators: %d: number of days. */
			$compare = sprintf( _n( 'on previous %d day', 'on previous %d days', $period['days'], 'campaign-bandit' ), $period['days'] );
		}
		$last = end( $series );
		$hub  = Plan::current()->is_hub_plan();
		if ( LeadsScreen::delivery_enabled() ) {
			self::count_split( $campaign->uid, $period['first'], (string) $last['day'] );
		}
		$ranges = array(
			'7'  => __( 'Last 7 days', 'campaign-bandit' ),
			'30' => __( 'Last 30 days', 'campaign-bandit' ),
		);
		if ( Plan::current()->includes( Plan::Pro ) ) {
			$ranges['version'] = __( 'This version', 'campaign-bandit' );
		}
		$format = static fn ( string $day ): string => wp_date( 'M j', (int) strtotime( $day . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
		?>
		<div class="cbandit-results">
			<div class="cbandit-results__head">
				<div class="cbandit-range" role="group" aria-label="<?php esc_attr_e( 'Date range', 'campaign-bandit' ); ?>">
					<?php
					$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
					foreach ( $ranges as $value => $label ) :
						$current = (string) $value === $range;
						$href    = CampaignScreen::url(
							$campaign->uid,
							array_filter(
								array(
									'range' => '7' === (string) $value ? '' : (string) $value,
									'tab'   => $tab,
								)
							)
						);
						?>
						<a class="cbandit-range__item<?php echo $current ? ' is-current' : ''; ?>" href="<?php echo esc_url( $href ); ?>"<?php echo $current ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
				<span class="cbandit-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: first day, 2: last day, 3: "this site" or "all sites". */
							__( '%1$s – %2$s · %3$s', 'campaign-bandit' ),
							$format( $period['first'] ),
							$format( (string) $last['day'] ),
							$hub ? __( 'all sites', 'campaign-bandit' ) : __( 'this site', 'campaign-bandit' )
						)
					);
					?>
				</span>
			</div>

			<div class="cbandit-figures">
				<?php
				$cards = array(
					'views'       => __( 'Views', 'campaign-bandit' ),
					'conversions' => __( 'Conversions', 'campaign-bandit' ),
					'rate'        => __( 'Conversion rate', 'campaign-bandit' ),
					'leads'       => __( 'Leads', 'campaign-bandit' ),
				);
				foreach ( $cards as $measure => $label ) :
					$value = $totals[ $measure ];
					$shown = 'rate' === $measure
						? ( null === $value ? '—' : number_format_i18n( $value * 100, 1 ) . '%' )
						: number_format_i18n( (int) $value );
					?>
					<button type="button" class="cbandit-figure" data-measure="<?php echo esc_attr( $measure ); ?>" aria-pressed="<?php echo 'views' === $measure ? 'true' : 'false'; ?>">
						<span class="cbandit-figure__label"><?php echo esc_html( $label ); ?></span>
						<span class="cbandit-figure__value"><?php echo esc_html( $shown ); ?></span>
						<?php $delta = (float) ( $change[ $measure ] ?? 0 ); ?>
						<span class="cbandit-figure__note<?php echo 'leads' !== $measure && $delta > 0 ? ' is-up' : ( 'leads' !== $measure && $delta < 0 ? ' is-down' : '' ); ?>"><?php echo esc_html( self::note( $measure, $totals, $change, $compare ) ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="cbandit-card cbandit-chart">
				<?php if ( 0 === $totals['views'] ) : ?>
					<p class="cbandit-muted"><?php esc_html_e( 'No views in this range yet. If it just went live, give it a little time; otherwise check where it is set to show.', 'campaign-bandit' ); ?></p>
				<?php else : ?>
					<?php self::render_chart( $campaign, $series, $before, $versions, $totals, $period['days'], $compare, $hub ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Delivered, retrying and failed leads in the range (Pro and above, S12).
	 *
	 * @var string
	 */
	private static string $split = '';

	/**
	 * "212 delivered · 1 retrying · 1 failed" for the leads in the range.
	 */
	private static function delivery_split(): string {
		return self::$split;
	}

	/**
	 * Count the delivery split once per render.
	 *
	 * @param string $uid   Campaign ULID.
	 * @param string $first First day.
	 * @param string $last  Last day.
	 */
	private static function count_split( string $uid, string $first, string $last ): void {
		$repo   = new LeadRepository();
		$parts  = array();
		$labels = array(
			/* translators: %s: number of leads. */
			'delivered'   => __( '%s delivered', 'campaign-bandit' ),
			/* translators: %s: number of leads. */
			'retrying'    => __( '%s retrying', 'campaign-bandit' ),
			/* translators: %s: number of leads. */
			'failed'      => __( '%s failed', 'campaign-bandit' ),
			/* translators: %s: number of leads. */
			'stored_only' => __( '%s stored only', 'campaign-bandit' ),
		);
		foreach ( $labels as $status => $label ) {
			$count = $repo->count( new LeadFilter( status: $status, campaign: $uid, from: $first, to: $last ), wp_timezone() );
			if ( $count > 0 ) {
				$parts[] = sprintf( $label, number_format_i18n( $count ) );
			}
		}
		self::$split = implode( ' · ', $parts );
	}

	/**
	 * The line under a figure: the change on the previous period, or what it means.
	 *
	 * @param string                    $measure Measure.
	 * @param array<string, mixed>      $totals  Totals.
	 * @param array<string, float|null> $change  Changes.
	 * @param string                    $compare "on previous 7 days", or '' without one.
	 */
	private static function note( string $measure, array $totals, array $change, string $compare ): string {
		if ( 'leads' === $measure ) {
			return LeadsScreen::delivery_enabled() ? self::delivery_split() : __( 'Stored on this site', 'campaign-bandit' );
		}
		if ( 'views' === $measure && 0 === $totals['views'] ) {
			return __( 'No views yet', 'campaign-bandit' );
		}
		if ( '' === $compare || null === ( $change[ $measure ] ?? null ) ) {
			return 'rate' === $measure && null === $totals['rate'] ? __( 'Conversions ÷ views', 'campaign-bandit' ) : '';
		}
		$value = (float) $change[ $measure ];
		$sign  = $value > 0 ? '↑ ' : ( $value < 0 ? '↓ ' : '' );
		return 'rate' === $measure
			/* translators: 1: change in percentage points such as "+0.1", 2: "on previous 7 days". */
			? sprintf( __( '%1$s pts %2$s', 'campaign-bandit' ), $sign . number_format_i18n( abs( $value ), 1 ), $compare )
			/* translators: 1: change in percent such as "+9%", 2: "on previous 7 days". */
			: sprintf( __( '%1$s %2$s', 'campaign-bandit' ), $sign . number_format_i18n( abs( $value ) ) . '%', $compare );
	}

	/**
	 * The chart: one SVG per measure (the figures switch between them), the previous
	 * period dashed behind, version markers, and the same numbers as a table.
	 *
	 * @param Campaign                                                                                  $campaign Campaign.
	 * @param array<int, array<string, mixed>>                                                          $series   This period.
	 * @param array<int, array<string, mixed>>|null                                                     $before   Previous period.
	 * @param array<int, array{version: \CampaignBandit\Publishing\Version, published_by_name: string}> $versions Versions.
	 * @param array<string, mixed>                                                                      $totals   Totals.
	 * @param int                                                                                       $days     Days.
	 * @param string                                                                                    $compare  "on previous 7 days".
	 * @param bool                                                                                      $hub      On a hub: say whose figures these are.
	 */
	private static function render_chart( Campaign $campaign, array $series, ?array $before, array $versions, array $totals, int $days, string $compare, bool $hub ): void {
		$names   = array(
			'views'       => __( 'Views', 'campaign-bandit' ),
			'conversions' => __( 'Conversions', 'campaign-bandit' ),
			'rate'        => __( 'Conversion rate', 'campaign-bandit' ),
			'leads'       => __( 'Leads', 'campaign-bandit' ),
		);
		$labels  = LineChart::label_days( count( $series ) );
		$day_of  = array_flip( array_column( $series, 'day' ) );
		$markers = array();
		foreach ( $versions as $row ) {
			$day = wp_date( 'Y-m-d', (int) strtotime( $row['version']->published_at . ' UTC' ) );
			// Versions come newest first: a day keeps the newest that went live on it.
			if ( isset( $day_of[ $day ] ) && ! isset( $markers[ $day_of[ $day ] ] ) ) {
				$markers[ $day_of[ $day ] ] = 'v' . $row['version']->number;
			}
		}
		$short = static fn ( string $day ): string => wp_date( count( $series ) <= 8 ? 'D j' : 'M j', (int) strtotime( $day . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
		$value = static fn ( string $measure, $v ): string => null === $v ? '—' : ( 'rate' === $measure ? number_format_i18n( (float) $v * 100, 1 ) . '%' : number_format_i18n( (int) $v ) );

		$url = CampaignScreen::url( $campaign->uid, array( 'tab' => 'versions' ) );
		foreach ( Figures::MEASURES as $measure ) {
			$now  = array_column( $series, $measure );
			$prev = null === $before ? array() : array_column( $before, $measure );
			$max  = LineChart::nice_max( (float) max( array_merge( array( 0 ), array_filter( $now, 'is_numeric' ), array_filter( $prev, 'is_numeric' ) ) ) );
			// The numbers to draw; the page script draws them at the chart's real width.
			$data = array(
				'values'   => array_map( static fn ( $v ) => null === $v ? null : (float) $v, $now ),
				'previous' => array() === $prev ? null : array_map( static fn ( $v ) => null === $v ? null : (float) $v, $prev ),
				'max'      => $max,
				'ticks'    => array_map(
					static fn ( int $i ): string => 'rate' === $measure ? number_format_i18n( $max * 100 * $i / 4, $max * 100 < 4 ? 1 : 0 ) . '%' : number_format_i18n( $max * $i / 4 ),
					range( 0, 4 )
				),
				'labels'   => array_map(
					static fn ( int $i ): array => array(
						'i'    => $i,
						'text' => $short( (string) $series[ $i ]['day'] ),
					),
					LineChart::label_days( count( $series ) )
				),
				'markers'  => array_map(
					static fn ( int $i, string $label ): array => array(
						'i'     => $i,
						'label' => $label,
						'url'   => $url,
					),
					array_keys( $markers ),
					array_values( $markers )
				),
				'tips'     => array_map(
					static fn ( int $i ): string => $short( (string) $series[ $i ]['day'] ) . ' · ' . $value( $measure, $now[ $i ] ),
					array_keys( $now )
				),
			);
			?>
			<figure class="cbandit-chart__measure" data-measure="<?php echo esc_attr( $measure ); ?>"<?php echo 'views' !== $measure ? ' hidden' : ''; ?>>
				<figcaption class="cbandit-chart__caption">
					<strong><?php echo esc_html( sprintf( /* translators: %s: measure such as "Views". */ __( '%s per day', 'campaign-bandit' ), $names[ $measure ] ) ); ?></strong>
					<?php if ( null !== $before ) : ?>
						<span class="cbandit-chart__key"><span class="cbandit-chart__swatch"></span><?php echo esc_html( $names[ $measure ] ); ?> <span class="cbandit-chart__swatch is-previous"></span><?php echo esc_html( ucfirst( str_replace( 'on ', '', $compare ) ) ); ?></span>
					<?php endif; ?>
				</figcaption>
				<div class="cbandit-chart__plot" data-chart="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: measure. */ __( '%s per day. The same numbers are in the table below.', 'campaign-bandit' ), $names[ $measure ] ) ); ?>"></div>
				<?php if ( 'rate' === $measure && Figures::rate_is_noisy( $totals, $days ) ) : ?>
					<p class="cbandit-chart__note"><?php esc_html_e( 'Fewer than 5 conversions a day on average: daily rates swing a lot with numbers this small.', 'campaign-bandit' ); ?></p>
				<?php endif; ?>
			</figure>
			<?php
		}//end foreach
		?>
		<div class="cbandit-chart__footer">
		<details class="cbandit-chart__table">
			<summary><?php esc_html_e( 'Show as table', 'campaign-bandit' ); ?></summary>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Day', 'campaign-bandit' ); ?></th>
				<?php foreach ( $names as $name ) : ?>
					<th scope="col"><?php echo esc_html( $name ); ?></th>
				<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $series as $row ) : ?>
					<tr><th scope="row"><?php echo esc_html( $short( (string) $row['day'] ) ); ?></th>
					<?php foreach ( array_keys( $names ) as $measure ) : ?>
						<td><?php echo esc_html( $value( $measure, $row[ $measure ] ) ); ?></td>
					<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php if ( $hub ) : ?>
			<span><?php esc_html_e( 'All sites together. Client sites report their figures when they sync, every 5 minutes; per-site figures are in the Sites tab.', 'campaign-bandit' ); ?></span>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Leads tab: the newest 10 in the range, with a link to all of them in Leads (S6).
	 *
	 * @param Campaign $campaign Campaign.
	 */
	public static function render_leads( Campaign $campaign ): void {
		$period = self::period( $campaign->uid, self::range() );
		$last   = gmdate( 'Y-m-d', (int) strtotime( $period['first'] . ' 12:00:00 UTC' ) + ( $period['days'] - 1 ) * DAY_IN_SECONDS );
		$filter = new LeadFilter( campaign: $campaign->uid, from: $period['first'], to: $last );
		$leads  = ( new LeadRepository() )->search( $filter, wp_timezone(), 10 );
		if ( array() === $leads ) {
			echo '<p class="cbandit-muted">' . esc_html__( 'No leads in this range yet.', 'campaign-bandit' ) . '</p>';
			return;
		}
		/** This filter is documented in src/Admin/LeadsListTable.php */
		$sites = (array) apply_filters( 'campaignbandit_lead_sites', array() );
		?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Received', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Lead', 'campaign-bandit' ); ?></th>
					<?php if ( array() !== $sites ) : ?>
						<th scope="col"><?php esc_html_e( 'Site', 'campaign-bandit' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Version', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'campaign-bandit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $leads as $lead ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( LeadsScreen::url( array( 'lead' => (string) $lead['uid'] ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $lead['submitted_at'] . ' UTC' ) ) ); ?></a></td>
						<td><?php echo esc_html( trim( (string) ( $lead['name'] ?? '' ) . ' ' . (string) ( $lead['email'] ?? '' ) ) ); ?></td>
						<?php if ( array() !== $sites ) : ?>
							<td><?php echo esc_html( (string) ( $sites[ (int) $lead['site_id'] ] ?? __( 'This site', 'campaign-bandit' ) ) ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( 'v' . (int) $lead['version_number'] ); ?></td>
						<td><?php echo LeadsScreen::status_label( $lead ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_label(). ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( LeadsScreen::url( array( 'campaign' => $campaign->uid ) ) ); ?>"><?php esc_html_e( 'Open in Leads', 'campaign-bandit' ); ?></a></p>
		<?php
	}

	/**
	 * Leads in the range, for the tab label.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public static function lead_count( string $uid ): int {
		$period = self::period( $uid, self::range() );
		$last   = gmdate( 'Y-m-d', (int) strtotime( $period['first'] . ' 12:00:00 UTC' ) + ( $period['days'] - 1 ) * DAY_IN_SECONDS );
		return ( new LeadRepository() )->count( new LeadFilter( campaign: $uid, from: $period['first'], to: $last ), wp_timezone() );
	}
}
