<?php
/**
 * What the next plan adds (S8 sidebar, S10): Free sees what Pro adds, Pro sees the
 * Agency hub. Not shown on a hub, nor on a client site connected to one, where the
 * agency runs Campaign Bandit. Information only: nothing is locked by it, and it can't
 * be dismissed because it never interrupts (WordPress.org guidelines, 05 §9).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Connect\Connection;
use CampaignBandit\Licensing\Plan;

/**
 * The upgrade panel.
 */
final class UpgradePanel {

	/**
	 * Whether there is a panel to show here.
	 */
	public static function shown(): bool {
		return ! Plan::current()->is_hub_plan() && null === Connection::get();
	}

	/**
	 * Show the panel, when there is a next plan to show.
	 */
	public static function render(): void {
		if ( ! self::shown() ) {
			return;
		}
		$plan = Plan::current();
		if ( Plan::Free === $plan ) {
			$title = __( 'Campaign Bandit Pro adds', 'campaign-bandit' );
			$items = array(
				__( 'Webhook destinations with retries and a delivery log: send leads to your CRM, Zapier or Make', 'campaign-bandit' ),
				__( 'Slide-ins and sticky bars', 'campaign-bandit' ),
				__( 'Scroll, exit intent, hover and custom event triggers', 'campaign-bandit' ),
				__( 'Schedules, URL parameters, country and rule sets', 'campaign-bandit' ),
				__( 'Frequency, priority and suppression settings', 'campaign-bandit' ),
				__( 'Versions and rollback, and why each campaign showed for every lead', 'campaign-bandit' ),
			);
		} else {
			$title = __( 'Campaign Bandit Agency adds', 'campaign-bandit' );
			$items = array(
				__( 'One hub that runs campaigns on all your client sites', 'campaign-bandit' ),
				__( 'Publish to chosen sites, with per-site settings and rollback', 'campaign-bandit' ),
				__( 'Every lead from every site in one place, with proof of delivery', 'campaign-bandit' ),
				__( 'A workspace for each client', 'campaign-bandit' ),
			);
		}

		/**
		 * Filters the "See plans" link. Empty hides the link.
		 *
		 * @param string $url  Plans page URL.
		 * @param string $plan Current plan.
		 */
		$url = (string) apply_filters( 'campaignbandit_upgrade_url', '', $plan->value );
		?>
		<section class="cbandit-card cbandit-upgrade" aria-labelledby="cbandit-upgrade-title">
			<h2 id="cbandit-upgrade-title"><?php echo esc_html( $title ); ?></h2>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( '' !== $url ) : ?>
				<p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'See plans', 'campaign-bandit' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
