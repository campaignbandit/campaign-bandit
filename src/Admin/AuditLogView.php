<?php
/**
 * Settings › Audit log (S8, BR-71): every recorded event, filterable by area, person
 * and period, kept for 365 days.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Plugin;

/**
 * The audit log tab.
 */
final class AuditLogView {

	private const PER_PAGE = 50;

	/**
	 * Render the filters, the events and the pages.
	 */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$area = isset( $_GET['area'] ) ? sanitize_key( $_GET['area'] ) : '';
		$user = isset( $_GET['person'] ) ? absint( $_GET['person'] ) : 0;
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$days    = in_array( $days, array( 0, 7, 30, 365 ), true ) ? $days : 30;
		$log     = new AuditLog();
		$result  = $log->query(
			array(
				'area' => $area,
				'user' => $user,
				'days' => $days,
			),
			$page,
			self::PER_PAGE
		);
		$areas   = array(
			'campaign'    => __( 'Campaigns', 'campaign-bandit' ),
			'site'        => __( 'Sites', 'campaign-bandit' ),
			'pairing_key' => __( 'Pairing keys', 'campaign-bandit' ),
			'destination' => __( 'Destinations', 'campaign-bandit' ),
			'lead'        => __( 'Leads', 'campaign-bandit' ),
			'setting'     => __( 'Settings', 'campaign-bandit' ),
			'hub'         => __( 'Hub connection', 'campaign-bandit' ),
		);
		$periods = array(
			7   => __( 'Last 7 days', 'campaign-bandit' ),
			30  => __( 'Last 30 days', 'campaign-bandit' ),
			365 => __( 'Last 365 days', 'campaign-bandit' ),
		);
		?>
		<p class="cbandit-muted"><?php esc_html_e( 'Who did what, and when: publishing, pausing, rollbacks, site and destination changes, lead exports and deletions, and settings. Events are kept for 365 days.', 'campaign-bandit' ); ?></p>
		<form method="get" class="cbandit-filters">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( \CampaignBandit\Campaign\CampaignPostType::POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( SettingsScreen::SLUG ); ?>" />
			<input type="hidden" name="tab" value="audit" />
			<label class="screen-reader-text" for="cbandit-audit-area"><?php esc_html_e( 'Area', 'campaign-bandit' ); ?></label>
			<select name="area" id="cbandit-audit-area">
				<option value=""><?php esc_html_e( 'Everything', 'campaign-bandit' ); ?></option>
				<?php foreach ( $areas as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $area, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="cbandit-audit-person"><?php esc_html_e( 'Person', 'campaign-bandit' ); ?></label>
			<select name="person" id="cbandit-audit-person">
				<option value="0"><?php esc_html_e( 'Everyone', 'campaign-bandit' ); ?></option>
				<?php foreach ( $log->people() as $id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $user, $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="cbandit-audit-days"><?php esc_html_e( 'Period', 'campaign-bandit' ); ?></label>
			<select name="days" id="cbandit-audit-days">
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $days, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'campaign-bandit' ); ?></button>
		</form>

		<?php if ( array() === $result['events'] ) : ?>
			<p class="cbandit-muted"><?php esc_html_e( 'Nothing recorded for this filter.', 'campaign-bandit' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="wp-list-table widefat striped cbandit-audit">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'How', 'campaign-bandit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['events'] as $event ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $event['occurred_at'] . ' UTC' ) ) ); ?></td>
						<td>
							<?php echo esc_html( CampaignScreen::describe( $event, '' ) ); ?>
							<?php $link = self::subject( $event ); ?>
							<?php if ( null !== $link ) : ?>
								<br />
								<?php if ( '' !== $link['url'] ) : ?>
									<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
								<?php else : ?>
									<span class="cbandit-muted"><?php echo esc_html( $link['label'] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="cbandit-muted"><?php echo esc_html( self::channel( $event['channel'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
	}

	/**
	 * What an event was about, with a link when there is a screen for it.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array{label: string, url: string}|null
	 */
	private static function subject( array $event ): ?array {
		$details = (array) $event['details'];
		switch ( $event['object_type'] ) {
			case 'campaign':
				$campaign = Plugin::campaigns()->get( (string) $event['object_id'] );
				return null === $campaign
					? array(
						'label' => __( 'A campaign that no longer exists', 'campaign-bandit' ),
						'url'   => '',
					)
					: array(
						'label' => '' !== $campaign->name ? $campaign->name : __( '(no name)', 'campaign-bandit' ),
						'url'   => CampaignScreen::url( $campaign->uid ),
					);
			case 'lead':
				return '' === (string) $event['object_id'] ? null : array(
					/* translators: %s: lead ID. */
					'label' => sprintf( __( 'Lead %s', 'campaign-bandit' ), (string) $event['object_id'] ),
					'url'   => '',
				);
			default:
				return isset( $details['url'] ) && is_string( $details['url'] ) ? array(
					'label' => $details['url'],
					'url'   => '',
				) : null;
		}//end switch
	}

	/**
	 * How the change was made.
	 *
	 * @param string $channel Channel.
	 */
	private static function channel( string $channel ): string {
		return match ( $channel ) {
			AuditLog::CHANNEL_REST   => __( 'REST API', 'campaign-bandit' ),
			AuditLog::CHANNEL_MCP    => __( 'via MCP', 'campaign-bandit' ),
			AuditLog::CHANNEL_SYSTEM => __( 'Automatic', 'campaign-bandit' ),
			default                  => __( 'Admin screen', 'campaign-bandit' ),
		};
	}
}
