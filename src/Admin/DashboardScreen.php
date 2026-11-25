<?php
/**
 * The standalone dashboard (S10, FR-60): figures, live campaigns, the first-run
 * checklist and what the next plan adds. Free shows views and leads only.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Dashboard\DashboardService;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Security\Capabilities;

/**
 * Dashboard admin screen.
 */
final class DashboardScreen {

	public const SLUG = 'campaign-bandit-dashboard';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	/**
	 * Add Dashboard as the first item under Campaign Bandit.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . CampaignPostType::POST_TYPE,
			__( 'Dashboard', 'campaign-bandit' ),
			__( 'Dashboard', 'campaign-bandit' ),
			Capabilities::EDIT_CAMPAIGNS,
			self::SLUG,
			array( self::class, 'render' ),
			0
		);
		if ( false !== $hook ) {
			add_action(
				'load-' . $hook,
				static fn () => wp_enqueue_style( 'campaign-bandit-admin', plugins_url( 'assets/admin.css', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION )
			);
		}
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::EDIT_CAMPAIGNS ) ) {
			wp_die( esc_html__( 'You are not allowed to view the Campaign Bandit dashboard.', 'campaign-bandit' ), 403 );
		}

		/**
		 * Filters the screen shown in place of the single-site dashboard. A hub shows
		 * its Overview (S1).
		 *
		 * @param callable|null $screen Renderer, or null for the dashboard (S10).
		 */
		$screen = apply_filters( 'campaignbandit_dashboard_screen', null );
		if ( is_callable( $screen ) ) {
			call_user_func( $screen );
			return;
		}

		$service   = new DashboardService( Plugin::campaigns(), wp_timezone(), time() );
		$campaigns = $service->campaigns();
		$figures   = $service->figures( $campaigns );
		$checklist = $service->checklist();
		$leads     = current_user_can( Capabilities::VIEW_LEADS );
		?>
		<div class="wrap cbandit-dashboard">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Dashboard', 'campaign-bandit' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . CampaignPostType::POST_TYPE ) ); ?>"><?php esc_html_e( 'New campaign', 'campaign-bandit' ); ?></a>
			<hr class="wp-header-end" />

			<?php self::render_checklist( $checklist ); ?>

			<ul class="cbandit-figures" aria-label="<?php esc_attr_e( 'Last 7 days', 'campaign-bandit' ); ?>">
				<?php
				self::figure( __( 'Live campaigns', 'campaign-bandit' ), $figures['live'] );
				self::figure( __( 'Views, last 7 days', 'campaign-bandit' ), $figures['views'] );
				self::figure( __( 'Conversions, last 7 days', 'campaign-bandit' ), $figures['conversions'] );
				if ( $leads ) {
					self::figure( __( 'Leads, last 7 days', 'campaign-bandit' ), $figures['leads'], LeadsScreen::url() );
				}
				if ( $leads && LeadsScreen::delivery_enabled() ) {
					$reached = $service->reached();
					self::figure(
						__( 'Reached destination', 'campaign-bandit' ),
						$reached['delivered'],
						LeadsScreen::url( array( 'status' => 'delivered' ) ),
						sprintf(
							/* translators: 1: leads sent to destinations, 2: retrying, 3: failed. */
							__( 'of %1$s · %2$s retrying · %3$s failed', 'campaign-bandit' ),
							number_format_i18n( $reached['total'] ),
							number_format_i18n( $reached['retrying'] ),
							number_format_i18n( $reached['failed'] )
						)
					);
				}
				?>
			</ul>

			<h2><?php esc_html_e( 'Live campaigns', 'campaign-bandit' ); ?></h2>
			<?php self::render_table( $campaigns, $leads ); ?>

			<?php UpgradePanel::render(); ?>
		</div>
		<?php
	}

	/**
	 * One figure.
	 *
	 * @param string $label  Label.
	 * @param int    $value  Value.
	 * @param string $link   Optional link.
	 * @param string $detail Optional line under the label.
	 */
	private static function figure( string $label, int $value, string $link = '', string $detail = '' ): void {
		$number = number_format_i18n( $value );
		echo '<li class="cbandit-figure"><span class="cbandit-figure__value">';
		echo '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $number ) . '</a>' : esc_html( $number );
		echo '</span><span class="cbandit-figure__label">' . esc_html( $label ) . '</span>';
		if ( '' !== $detail ) {
			echo '<span class="cbandit-figure__label">' . esc_html( $detail ) . '</span>';
		}
		echo '</li>';
	}

	/**
	 * The first-run checklist, until all three steps are done (S10).
	 *
	 * @param array{created: bool, published: bool, lead: bool} $done Steps done.
	 */
	private static function render_checklist( array $done ): void {
		if ( $done['created'] && $done['published'] && $done['lead'] ) {
			return;
		}
		$steps = array(
			array(
				'done'  => $done['created'],
				'label' => __( 'Create a campaign', 'campaign-bandit' ),
				'help'  => __( 'Start blank or from a starter pattern.', 'campaign-bandit' ),
				'link'  => admin_url( 'post-new.php?post_type=' . CampaignPostType::POST_TYPE ),
			),
			array(
				'done'  => $done['published'],
				'label' => __( 'Publish it', 'campaign-bandit' ),
				'help'  => __( 'Choose a trigger, then press Publish in the campaign sidebar.', 'campaign-bandit' ),
				'link'  => admin_url( 'edit.php?post_type=' . CampaignPostType::POST_TYPE ),
			),
			array(
				'done'  => $done['lead'],
				'label' => __( 'See your first lead', 'campaign-bandit' ),
				'help'  => __( 'Leads arrive when a visitor submits a campaign form.', 'campaign-bandit' ),
				'link'  => current_user_can( Capabilities::VIEW_LEADS ) ? LeadsScreen::url() : '',
			),
		);
		?>
		<section class="cbandit-card cbandit-checklist-card" aria-labelledby="cbandit-getting-started">
			<h2 id="cbandit-getting-started"><?php esc_html_e( 'Get started', 'campaign-bandit' ); ?></h2>
			<ol class="cbandit-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="cbandit-step<?php echo $step['done'] ? ' is-done' : ''; ?>">
						<span class="cbandit-step__mark" aria-hidden="true"></span>
						<span class="cbandit-step__text">
							<?php if ( '' !== $step['link'] && ! $step['done'] ) : ?>
								<a href="<?php echo esc_url( $step['link'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $step['label'] ); ?>
							<?php endif; ?>
							<span class="screen-reader-text"><?php echo $step['done'] ? esc_html__( '(done)', 'campaign-bandit' ) : esc_html__( '(to do)', 'campaign-bandit' ); ?></span>
							<span class="cbandit-muted"><?php echo esc_html( $step['help'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	/**
	 * The live campaigns table (as S1, for this site).
	 *
	 * @param array<int, array<string, mixed>> $campaigns Rows.
	 * @param bool                             $leads     Whether the user may see lead counts.
	 */
	private static function render_table( array $campaigns, bool $leads ): void {
		if ( array() === $campaigns ) {
			echo '<p class="cbandit-muted">' . esc_html__( 'Nothing is live yet. Published campaigns appear here with their views and leads.', 'campaign-bandit' ) . '</p>';
			return;
		}
		$formats = array(
			'modal'    => __( 'Modal', 'campaign-bandit' ),
			'inline'   => __( 'Inline', 'campaign-bandit' ),
			'slide_in' => __( 'Slide-in', 'campaign-bandit' ),
			'bar'      => __( 'Sticky bar', 'campaign-bandit' ),
		);
		?>
		<table class="wp-list-table widefat striped cbandit-campaigns-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Campaign', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Format', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version', 'campaign-bandit' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Views (7d)', 'campaign-bandit' ); ?></th>
					<?php if ( $leads ) : ?>
						<th scope="col" class="num"><?php esc_html_e( 'Leads (7d)', 'campaign-bandit' ); ?></th>
					<?php endif; ?>
					<th scope="col" class="num"><?php esc_html_e( 'Conversion rate', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'campaign-bandit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $campaigns as $row ) : ?>
					<tr>
						<td>
							<?php if ( $row['post_id'] > 0 ) : ?>
								<a class="row-title" href="<?php echo esc_url( CampaignScreen::url( (string) $row['uid'] ) ); ?>"><?php echo esc_html( '' !== $row['name'] ? (string) $row['name'] : __( '(no title)', 'campaign-bandit' ) ); ?></a>
							<?php else : ?>
								<?php esc_html_e( '(deleted campaign)', 'campaign-bandit' ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $formats[ $row['format'] ] ?? (string) $row['format'] ); ?></td>
						<td>
							<?php echo esc_html( 'v' . (int) $row['version'] ); ?>
							<?php if ( $row['draft_changes'] ) : ?>
								<span class="cbandit-muted"><?php esc_html_e( '· draft changes', 'campaign-bandit' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['views'] ) ); ?></td>
						<?php if ( $leads ) : ?>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['leads'] ) ); ?></td>
						<?php endif; ?>
						<td class="num">
							<?php
							echo esc_html(
								null === $row['rate']
									? '—'
									/* translators: %s: conversion rate, such as 3.5. */
									: sprintf( __( '%s%%', 'campaign-bandit' ), number_format_i18n( (float) $row['rate'], 1 ) )
							);
							?>
						</td>
						<td>
							<?php
							printf(
								'<span class="cbandit-status cbandit-status--%1$s">%2$s</span>',
								esc_attr( Deployment::LIVE === $row['state'] ? 'good' : 'attention' ),
								esc_html( Deployment::LIVE === $row['state'] ? __( 'Live', 'campaign-bandit' ) : __( 'Switched off', 'campaign-bandit' ) )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
