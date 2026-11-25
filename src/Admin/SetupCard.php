<?php
/**
 * Campaign settings on Campaign detail (S12, the spec's Setup card): triggers, display
 * conditions, date and time, frequency and destinations as plain sentences. Changes are
 * made in the editor (the Edit campaign button); rows the plan doesn't include show the
 * plan that unlocks them (FR-25). The sentences follow the
 * editor's summary (js/editor/summary.js).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Campaign\Campaign;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Editor\Editor;
use CampaignBandit\Licensing\Plan;

/**
 * Setup card.
 */
final class SetupCard {

	/**
	 * Render the card.
	 *
	 * @param Campaign         $campaign Campaign.
	 * @param CampaignSettings $settings The draft's settings.
	 */
	public static function render( Campaign $campaign, CampaignSettings $settings ): void {
		$s      = $settings->to_array();
		$locked = Editor::locked_controls();
		$rows   = array(
			array( __( 'Triggers', 'campaign-bandit' ), self::triggers( $s ), '' ),
			array( __( 'Display conditions', 'campaign-bandit' ), self::conditions( $s ), '' ),
			array( __( 'Date & time', 'campaign-bandit' ), self::schedule( $s ), isset( $locked['schedule'] ) ? 'schedule' : '' ),
			array( __( 'Frequency', 'campaign-bandit' ), self::frequency( $s ), isset( $locked['frequency'] ) ? 'frequency' : '' ),
			array( __( 'Destinations', 'campaign-bandit' ), self::destinations( $s, isset( $locked['destinations'] ) ), isset( $locked['destinations'] ) ? 'destinations' : '' ),
		);
		?>
		<aside class="cbandit-card cbandit-setup" aria-labelledby="cbandit-setup-title">
			<h2 id="cbandit-setup-title"><?php esc_html_e( 'Campaign settings', 'campaign-bandit' ); ?></h2>
			<?php foreach ( $rows as [ $title, $text, $lock ] ) : ?>
				<div class="cbandit-setup__row">
					<div class="cbandit-setup__head">
						<span class="cbandit-setup__title"><?php echo esc_html( $title ); ?></span>
						<?php if ( '' !== $lock ) : ?>
							<span class="cbandit-badge"><?php echo esc_html( strtoupper( (string) $locked[ $lock ] ) ); ?></span>
						<?php endif; ?>
					</div>
					<p><?php echo esc_html( $text ); ?></p>
				</div>
			<?php endforeach; ?>
			<?php if ( Plan::current()->is_hub_plan() ) : ?>
				<p class="cbandit-muted cbandit-setup__note"><?php esc_html_e( 'Each site can override trigger timing, page and URL rules, priority and destinations in its site settings.', 'campaign-bandit' ); ?></p>
			<?php endif; ?>
		</aside>
		<?php
	}

	/**
	 * How it opens.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	public static function triggers( array $s ): string {
		if ( 'inline' === $s['format'] ) {
			return __( 'Shows where it is placed, with the block or shortcode.', 'campaign-bandit' );
		}
		$t     = $s['triggers'];
		$parts = array();
		if ( null !== $t['delay_seconds'] ) {
			/* translators: %d: seconds. */
			$parts[] = sprintf( _n( 'after %d second', 'after %d seconds', (int) $t['delay_seconds'], 'campaign-bandit' ), (int) $t['delay_seconds'] );
		}
		if ( $t['click'] ) {
			$parts[] = __( 'on click', 'campaign-bandit' );
		}
		if ( null !== $t['scroll_percent'] ) {
			/* translators: %d: percent of the page. */
			$parts[] = sprintf( __( 'at %d%% scrolled', 'campaign-bandit' ), (int) $t['scroll_percent'] );
		}
		if ( $t['exit_intent'] ) {
			$parts[] = __( 'on exit intent', 'campaign-bandit' );
		}
		if ( $t['hover'] ) {
			$parts[] = __( 'on hover', 'campaign-bandit' );
		}
		if ( '' !== $t['custom_event'] ) {
			/* translators: %s: event name. */
			$parts[] = sprintf( __( 'on the “%s” event', 'campaign-bandit' ), $t['custom_event'] );
		}
		return array() === $parts
			? __( 'No trigger yet: choose one in the editor.', 'campaign-bandit' )
			: ucfirst( implode( ' ' . __( 'or', 'campaign-bandit' ) . ' ', $parts ) );
	}

	/**
	 * Who sees it, where.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	public static function conditions( array $s ): string {
		$count = 0;
		foreach ( (array) ( $s['condition']['groups'] ?? array() ) as $group ) {
			$count += count( array_filter( (array) ( $group['rules'] ?? array() ), static fn ( $rule ): bool => ! empty( $rule['type'] ) ) );
		}
		if ( 0 === $count ) {
			return __( 'Everyone, on every page.', 'campaign-bandit' );
		}
		/* translators: %d: number of rules. */
		$phrase = sprintf( _n( 'Visitors matching %d rule.', 'Visitors matching %d rules.', $count, 'campaign-bandit' ), $count );
		/* translators: %s: "visitors matching 2 rules". */
		return ! empty( $s['condition']['invert'] ) ? sprintf( __( 'Everyone except %s', 'campaign-bandit' ), lcfirst( $phrase ) ) : $phrase;
	}

	/**
	 * When.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	public static function schedule( array $s ): string {
		$count = count( (array) $s['rules']['schedules'] );
		if ( 0 === $count ) {
			return __( 'Any time.', 'campaign-bandit' );
		}
		return $s['rules']['hide_during_schedules']
			/* translators: %d: number of schedules. */
			? sprintf( _n( 'Any time except during %d schedule.', 'Any time except during %d schedules.', $count, 'campaign-bandit' ), $count )
			/* translators: %d: number of schedules. */
			: sprintf( _n( 'During %d schedule.', 'During %d schedules.', $count, 'campaign-bandit' ), $count );
	}

	/**
	 * How often.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	public static function frequency( array $s ): string {
		$f = $s['frequency'];
		if ( 'every_view' === $f['type'] ) {
			return __( 'On every page view.', 'campaign-bandit' );
		}
		if ( 'once_per_visit' === $f['type'] ) {
			return __( 'At most once per visit.', 'campaign-bandit' );
		}
		$days = (int) $f['days'];
		return 1 === $days
			? __( 'At most once a day.', 'campaign-bandit' )
			/* translators: %d: number of days. */
			: sprintf( _n( 'At most once every %d day.', 'At most once every %d days.', $days, 'campaign-bandit' ), $days );
	}

	/**
	 * Where leads go.
	 *
	 * @param array<string, mixed> $s      Settings.
	 * @param bool                 $locked Destinations aren't in this plan.
	 */
	public static function destinations( array $s, bool $locked ): string {
		if ( $locked ) {
			return __( 'Stored on this site only. Pro also sends leads to webhooks.', 'campaign-bandit' );
		}
		/**
		 * Filters the destination names for a campaign's destinations (the add-on knows them).
		 *
		 * @param string[] $names Names.
		 * @param string[] $uids  Destination ULIDs.
		 */
		$names = (array) apply_filters( 'campaignbandit_destination_names', array(), (array) $s['destinations'] );
		return array() === $names
			? __( 'Stored on this site only: no destinations chosen.', 'campaign-bandit' )
			/* translators: %s: destination names. */
			: sprintf( __( 'Stored on this site, then sent to %s.', 'campaign-bandit' ), implode( ', ', $names ) );
	}
}
