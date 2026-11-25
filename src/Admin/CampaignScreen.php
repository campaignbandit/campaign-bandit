<?php
/**
 * Campaign detail (S12): where one campaign runs, its versions and what happened to it.
 *
 * Editing stays in the editor. The screen has the header, figures and chart
 * (CampaignResults), and the Versions, Leads and Activity tabs; the hub adds a Sites
 * tab through the `campaignbandit_campaign_tabs` filter.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\Campaign;
use CampaignBandit\Campaign\ArchiveRule;
use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Package\ContentPolicy;
use CampaignBandit\Package\PackageWriter;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Metrics\ConversionRate;
use CampaignBandit\Metrics\MetricTotals;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\VersionRepository;
use CampaignBandit\Security\Capabilities;

/**
 * The Campaign detail screen.
 */
final class CampaignScreen {

	public const SLUG = 'campaign-bandit-campaign';

	private const ACTION = 'campaignbandit_campaign_action';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 30 );
		add_filter( 'post_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		// A hidden page: keep the Campaigns menu item highlighted.
		add_filter(
			'submenu_file',
			static function ( $file ) {
				global $plugin_page;
				return self::SLUG === $plugin_page ? 'edit.php?post_type=' . CampaignPostType::POST_TYPE : $file;
			}
		);
	}

	/**
	 * Register the page without a menu item.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'',
			__( 'Campaign', 'campaign-bandit' ),
			'',
			Capabilities::EDIT_CAMPAIGNS,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( false !== $hook ) {
			add_action(
				'load-' . $hook,
				static function (): void {
					// Hidden pages have no menu title; give the browser tab one.
					$GLOBALS['title'] = __( 'Campaign', 'campaign-bandit' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					wp_enqueue_style( 'campaign-bandit-admin', plugins_url( 'assets/admin.css', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION );
					wp_enqueue_script( 'campaign-bandit-detail', plugins_url( 'assets/campaign-detail.js', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION, true );

					/**
					 * Fires when Campaign detail loads, for added tabs' scripts.
					 */
					do_action( 'campaignbandit_campaign_screen_load' );
				}
			);
		}
	}

	/**
	 * The screen URL for a campaign.
	 *
	 * @param string                $uid  Campaign ULID.
	 * @param array<string, string> $args More query values.
	 */
	public static function url( string $uid, array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => CampaignPostType::POST_TYPE,
					'page'      => self::SLUG,
					'campaign'  => $uid,
				),
				array_map( 'rawurlencode', $args )
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * "Details" in the campaign list.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public static function row_action( array $actions, $post ): array {
		if ( CampaignPostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		$uid = (string) get_post_meta( $post->ID, CampaignPostType::META_UID, true );
		if ( '' !== $uid ) {
			$actions = array( 'cbandit_details' => sprintf( '<a href="%s">%s</a>', esc_url( self::url( $uid ) ), esc_html__( 'Details', 'campaign-bandit' ) ) ) + $actions;
		}
		return $actions;
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		$uid      = isset( $_GET['campaign'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['campaign'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$campaign = '' === $uid ? null : Plugin::campaigns()->get( $uid );
		if ( null === $campaign ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Campaign', 'campaign-bandit' ) . '</h1><p>' . esc_html__( 'No campaign has that ID.', 'campaign-bandit' ) . '</p></div>';
			return;
		}

		$versions = ( new VersionRepository() )->for_campaign( $uid );
		$changes  = Plugin::publishing()->draft_changes( $uid );
		$tabs     = self::tabs( $uid, $versions );
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$tab      = isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
		$edit_url = (string) get_edit_post_link( $campaign->post_id, 'url' );
		$next     = ( array() === $versions ? 0 : $versions[0]['version']->number ) + 1;

		/**
		 * Filters where "Publish" goes from Campaign detail. The hub sends it to its
		 * Publish screen (S5); a single site publishes from the editor.
		 *
		 * @param string $url Default: the editor.
		 * @param string $uid Campaign ULID.
		 */
		$publish_url = (string) apply_filters( 'campaignbandit_publish_url', $edit_url, $uid );
		$ahead       = null === $changes || array() !== $changes;
		$settings    = CampaignSettings::from_json( (string) get_post_meta( $campaign->post_id, CampaignPostType::META_SETTINGS, true ) );
		?>
		<div class="wrap cbandit-campaign">
			<p class="cbandit-breadcrumb"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . CampaignPostType::POST_TYPE ) ); ?>"><?php esc_html_e( 'Campaigns', 'campaign-bandit' ); ?></a> › <?php echo esc_html( $campaign->name ); ?></p>
			<div class="cbandit-campaign__head">
				<div>
					<h1><?php echo esc_html( '' !== $campaign->name ? $campaign->name : __( '(no name)', 'campaign-bandit' ) ); ?></h1>
					<p class="cbandit-campaign__about"><?php echo esc_html( self::about( $settings ) ); ?></p>
					<div class="cbandit-campaign__meta">
						<?php echo self::status( $uid, $versions, $campaign->archived ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status(). ?>
						<span class="cbandit-muted">
							<?php
							if ( array() === $versions ) {
								/* translators: %d: version number. */
								echo esc_html( sprintf( __( 'Draft v%d · not published', 'campaign-bandit' ), 1 ) );
							} elseif ( $ahead ) {
								/* translators: %d: version number. */
								echo esc_html( sprintf( __( 'Draft v%d not published', 'campaign-bandit' ), $next ) );
							} else {
								/* translators: 1: time span such as "2 hours", 2: name. */
								echo esc_html( sprintf( __( 'Edited %1$s ago', 'campaign-bandit' ), human_time_diff( (int) strtotime( $campaign->modified_at ) ) ) );
							}
							?>
						</span>
						<?php
						/**
						 * Fires at the end of the Campaign detail header line. The hub shows the client.
						 *
						 * @param Campaign $campaign Campaign.
						 */
						do_action( 'campaignbandit_campaign_header_meta', $campaign );
						?>
					</div>
				</div>
				<div class="cbandit-campaign__actions">
					<?php if ( ! $campaign->archived && $ahead && current_user_can( Capabilities::PUBLISH_CAMPAIGNS ) ) : ?>
						<?php /* translators: %d: version number. */ ?>
						<a class="button" href="<?php echo esc_url( $publish_url ); ?>"><?php echo esc_html( sprintf( __( 'Publish v%d', 'campaign-bandit' ), array() === $versions ? 1 : $next ) ); ?></a>
					<?php endif; ?>
					<?php if ( ! $campaign->archived ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit campaign', 'campaign-bandit' ); ?></a>
					<?php endif; ?>
					<?php if ( array() !== $versions && ExplainPanel::available() ) : ?>
						<a class="button" href="<?php echo esc_url( ExplainPanel::url( $uid ) ); ?>"><?php esc_html_e( 'Explain a page', 'campaign-bandit' ); ?></a>
					<?php endif; ?>
					<?php self::render_more( $campaign ); ?>
				</div>
			</div>
			<?php self::render_notice(); ?>
			<?php if ( $campaign->archived ) : ?>
				<div class="cbandit-card">
					<p><?php esc_html_e( 'Archived: this campaign is on no site and can’t be edited. Restore it from More to edit and publish it again.', 'campaign-bandit' ); ?></p>
				</div>
			<?php endif; ?>
			<?php
			/**
			 * Fires under the Campaign detail header, for notices and results.
			 *
			 * @param string $uid Campaign ULID.
			 */
			do_action( 'campaignbandit_campaign_notices', $uid );
			?>

			<div class="cbandit-campaign__body">
			<div class="cbandit-campaign__main">
			<?php if ( array() === $versions ) : ?>
				<div class="cbandit-card">
					<p><?php esc_html_e( 'Not published yet. Publish to start collecting views and leads. Results, sites and versions appear here once it’s live.', 'campaign-bandit' ); ?></p>
				</div>
			</div>
				<?php SetupCard::render( $campaign, $settings ); ?>
			</div>
		</div>
				<?php
				return;
			endif;
			?>

			<?php CampaignResults::render( $campaign, $versions ); ?>

			<nav class="nav-tab-wrapper cbandit-tabs" aria-label="<?php esc_attr_e( 'Campaign sections', 'campaign-bandit' ); ?>">
				<?php
				$range = CampaignResults::range();
				foreach ( $tabs as $key => $label ) :
					$href = self::url(
						$uid,
						array_filter(
							array(
								'tab'   => $key,
								'range' => '7' === $range ? '' : $range,
							)
						)
					);
					?>
					<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $href ); ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="cbandit-tab-panel">
				<?php
				$compare = isset( $_GET['compare'] ) ? absint( $_GET['compare'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
				if ( 'versions' === $tab && $compare > 0 ) {
					self::render_compare( $campaign, $compare );
				} elseif ( 'versions' === $tab ) {
					// An archived campaign can't be edited or published: no draft row, no rollback.
					self::render_versions( $uid, $versions, ! $campaign->archived && $ahead ? $next : null, $publish_url, $edit_url, ! $campaign->archived );
				} elseif ( 'activity' === $tab ) {
					self::render_activity( $uid );
				} elseif ( 'leads' === $tab ) {
					CampaignResults::render_leads( $campaign );
				} else {
					/**
					 * Renders an added Campaign detail tab.
					 *
					 * @param Campaign $campaign Campaign.
					 */
					do_action( 'campaignbandit_campaign_tab_' . $tab, $campaign );
				}
				?>
			</div>
			</div>
			<?php SetupCard::render( $campaign, $settings ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Format, goal and offer, for the header: "Modal · Goal: Form submitted · Offer: Free quote".
	 *
	 * @param CampaignSettings $settings Settings.
	 */
	private static function about( CampaignSettings $settings ): string {
		$s     = $settings->to_array();
		$parts = array(
			array(
				'modal'    => __( 'Modal', 'campaign-bandit' ),
				'slide_in' => __( 'Slide-in', 'campaign-bandit' ),
				'bar'      => __( 'Sticky bar', 'campaign-bandit' ),
				'inline'   => __( 'Inline', 'campaign-bandit' ),
			)[ $s['format'] ] ?? '',
			'button' === $s['goal'] ? __( 'Goal: Button clicked', 'campaign-bandit' ) : __( 'Goal: Form submitted', 'campaign-bandit' ),
		);
		if ( '' !== $s['offer'] ) {
			/* translators: %s: offer, such as "Free quote". */
			$parts[] = sprintf( __( 'Offer: %s', 'campaign-bandit' ), $s['offer'] );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * The More menu: switch off or pause everywhere, duplicate, archive or restore.
	 *
	 * @param Campaign $campaign Campaign.
	 */
	private static function render_more( Campaign $campaign ): void {
		$items = array();
		if ( ! $campaign->archived ) {
			$here = ( new DeploymentRepository() )->find( $campaign->uid, 0 );
			if ( null !== $here && Deployment::LIVE === $here->state ) {
				$items['switch_off'] = array( 'label' => __( 'Switch off', 'campaign-bandit' ) );
			} elseif ( null !== $here && Deployment::PAUSED === $here->state ) {
				$items['switch_on'] = array( 'label' => __( 'Switch on', 'campaign-bandit' ) );
			}
			if ( current_user_can( Capabilities::EDIT_CAMPAIGNS ) ) {
				$items['duplicate'] = array( 'label' => __( 'Duplicate', 'campaign-bandit' ) );
			}
			$refusal          = Plugin::campaign_actions()->archive_refusal( $campaign->uid );
			$items['archive'] = array(
				'label'   => __( 'Archive', 'campaign-bandit' ),
				'reason'  => null === $refusal ? '' : ( ArchiveRule::LIVE === $refusal ? __( 'Live somewhere: pause it first.', 'campaign-bandit' ) : __( 'A publish is scheduled: cancel it first.', 'campaign-bandit' ) ),
				'confirm' => __( 'Archive this campaign? It leaves any site where it is paused, and can be restored later.', 'campaign-bandit' ),
			);
		} else {
			$items['restore'] = array( 'label' => __( 'Restore', 'campaign-bandit' ) );
		}
		if ( ! current_user_can( Capabilities::PUBLISH_CAMPAIGNS ) ) {
			unset( $items['switch_off'], $items['switch_on'], $items['archive'], $items['restore'] );
		}

		/**
		 * Filters the More menu on Campaign detail (S12). The hub replaces "Switch off"
		 * with "Pause on all sites".
		 *
		 * @param array<string, array{label: string, reason?: string, confirm?: string, choices?: array<string, string>, chosen?: string}> $items    Op => item; "choices" adds a select sent as "into".
		 * @param Campaign                                                               $campaign Campaign.
		 */
		$items = (array) apply_filters( 'campaignbandit_campaign_more_actions', $items, $campaign );
		if ( array() === $items ) {
			return;
		}
		?>
		<details class="cbandit-more">
			<summary class="button"><?php esc_html_e( 'More', 'campaign-bandit' ); ?></summary>
			<ul class="cbandit-more__menu">
				<?php foreach ( $items as $op => $item ) : ?>
					<li>
						<?php if ( '' !== ( $item['reason'] ?? '' ) ) : ?>
							<span class="cbandit-more__item is-disabled" aria-disabled="true"><?php echo esc_html( $item['label'] ); ?> <span class="cbandit-muted"><?php echo esc_html( (string) $item['reason'] ); ?></span></span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo '' !== ( $item['confirm'] ?? '' ) ? ' onsubmit="return window.confirm( ' . esc_attr( (string) wp_json_encode( $item['confirm'] ) ) . ' );"' : ''; ?>>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
								<input type="hidden" name="op" value="<?php echo esc_attr( (string) $op ); ?>" />
								<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign->uid ); ?>" />
								<?php wp_nonce_field( self::ACTION . '_' . $op . '_' . $campaign->uid ); ?>
								<?php if ( ! empty( $item['choices'] ) ) : ?>
									<div class="cbandit-more__choice">
										<label for="cbandit-more-<?php echo esc_attr( (string) $op ); ?>"><?php echo esc_html( $item['label'] ); ?></label>
										<select name="into" id="cbandit-more-<?php echo esc_attr( (string) $op ); ?>">
											<?php foreach ( (array) $item['choices'] as $value => $choice ) : ?>
												<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $value, (string) ( $item['chosen'] ?? '' ) ); ?>><?php echo esc_html( (string) $choice ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Go', 'campaign-bandit' ); ?></button>
									</div>
								<?php else : ?>
									<button type="submit" class="cbandit-more__item"><?php echo esc_html( $item['label'] ); ?></button>
								<?php endif; ?>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Handle a More menu action, then return to the campaign with what happened.
	 */
	public static function handle(): void {
		$op  = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$uid = isset( $_POST['campaign'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) ) : '';
		check_admin_referer( self::ACTION . '_' . $op . '_' . $uid );
		if ( ! current_user_can( Capabilities::EDIT_CAMPAIGNS ) ) {
			wp_die( esc_html__( 'You are not allowed to change campaigns.', 'campaign-bandit' ), 403 );
		}
		$actions = Plugin::campaign_actions();
		$target  = $uid;
		switch ( $op ) {
			case 'duplicate':
				$result = $actions->duplicate( $uid, isset( $_POST['into'] ) ? sanitize_text_field( wp_unslash( $_POST['into'] ) ) : '' );
				if ( $result instanceof Campaign ) {
					$target = $result->uid;
					$result = __( 'Copy created as a new draft. It has no versions and is on no site until you publish it.', 'campaign-bandit' );
				}
				break;
			case 'archive':
				$result = $actions->archive( $uid );
				$result = true === $result ? __( 'Campaign archived.', 'campaign-bandit' ) : $result;
				break;
			case 'restore':
				$result = $actions->restore( $uid );
				$result = true === $result ? __( 'Campaign restored as a draft. Publish it to put it back on sites.', 'campaign-bandit' ) : $result;
				break;
			case 'switch_off':
			case 'switch_on':
				$result = 'switch_off' === $op ? Plugin::publishing()->switch_off( $uid ) : Plugin::publishing()->switch_on( $uid );
				if ( ! is_wp_error( $result ) ) {
					$result = 'switch_off' === $op ? __( 'Campaign switched off.', 'campaign-bandit' ) : __( 'Campaign switched on.', 'campaign-bandit' );
				}
				break;
			default:
				/**
				 * Handles an added More action. Return the notice to show, or a WP_Error.
				 *
				 * @param string|\WP_Error|null $result Null when nothing handled it.
				 * @param string                $uid    Campaign ULID.
				 */
				$result = apply_filters( 'campaignbandit_campaign_action_' . $op, null, $uid );
		}//end switch
		if ( is_string( $result ) || is_wp_error( $result ) ) {
			set_transient(
				'campaignbandit_campaign_notice_' . get_current_user_id(),
				array(
					'type'    => is_wp_error( $result ) ? 'error' : 'success',
					'message' => is_wp_error( $result ) ? $result->get_error_message() : $result,
				),
				MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( self::url( $target ) );
		exit;
	}

	/**
	 * The notice from the last More action, once.
	 */
	private static function render_notice(): void {
		$key    = 'campaignbandit_campaign_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success',
			esc_html( (string) ( $notice['message'] ?? '' ) )
		);
	}

	/**
	 * Tabs with counts.
	 *
	 * @param string                                                                                    $uid      Campaign ULID.
	 * @param array<int, array{version: \CampaignBandit\Publishing\Version, published_by_name: string}> $versions Versions.
	 * @return array<string, string> Key => label.
	 */
	private static function tabs( string $uid, array $versions ): array {
		/**
		 * Filters the Campaign detail tabs. The hub adds "sites" first.
		 *
		 * @param array<string, string> $tabs Key => label.
		 * @param string                $uid  Campaign ULID.
		 */
		return (array) apply_filters(
			'campaignbandit_campaign_tabs',
			array(
				/* translators: %d: number of versions. */
				'versions' => sprintf( __( 'Versions (%d)', 'campaign-bandit' ), count( $versions ) ),
				/* translators: %d: number of leads in the chosen range. */
				'leads'    => sprintf( __( 'Leads (%d)', 'campaign-bandit' ), CampaignResults::lead_count( $uid ) ),
				'activity' => __( 'Activity', 'campaign-bandit' ),
			),
			$uid
		);
	}

	/**
	 * The status label: "Live on this site", "Live on 5 sites · paused on 1", "Switched off".
	 *
	 * @param string                                                                                    $uid      Campaign ULID.
	 * @param array<int, array{version: \CampaignBandit\Publishing\Version, published_by_name: string}> $versions Versions.
	 * @param bool                                                                                      $archived Archived.
	 */
	private static function status( string $uid, array $versions, bool $archived = false ): string {
		if ( $archived ) {
			return '<span class="cbandit-status cbandit-status--neutral">' . esc_html_x( 'Archived', 'campaign status', 'campaign-bandit' ) . '</span>';
		}
		if ( array() === $versions ) {
			return '<span class="cbandit-status cbandit-status--neutral">' . esc_html__( 'Draft', 'campaign-bandit' ) . '</span>';
		}
		$live   = 0;
		$paused = 0;
		foreach ( ( new DeploymentRepository() )->for_campaign( $uid ) as $deployment ) {
			$live   += Deployment::LIVE === $deployment->state ? 1 : 0;
			$paused += Deployment::PAUSED === $deployment->state ? 1 : 0;
		}
		$hub = Plan::current()->is_hub_plan();
		if ( 0 === $live ) {
			$label = 0 === $paused ? __( 'Not live', 'campaign-bandit' ) : ( $hub ? __( 'Paused', 'campaign-bandit' ) : __( 'Switched off', 'campaign-bandit' ) );
			return '<span class="cbandit-status cbandit-status--neutral">' . esc_html( $label ) . '</span>';
		}
		if ( ! $hub ) {
			$label = __( 'Live on this site', 'campaign-bandit' );
		} else {
			/* translators: %d: number of sites. */
			$label = sprintf( _n( 'Live on %d site', 'Live on %d sites', $live, 'campaign-bandit' ), $live );
			if ( $paused > 0 ) {
				/* translators: %d: number of sites. */
				$label .= ' · ' . sprintf( __( 'paused on %d', 'campaign-bandit' ), $paused );
			}
		}
		return '<span class="cbandit-status cbandit-status--good">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Versions, newest first (FR-40). Free shows only the live version (BR-20).
	 *
	 * @param string                                                                                    $uid         Campaign ULID.
	 * @param array<int, array{version: \CampaignBandit\Publishing\Version, published_by_name: string}> $versions    Versions.
	 * @param int|null                                                                                  $draft       Next version number when the draft is ahead.
	 * @param string                                                                                    $publish_url Where Publish goes.
	 * @param string                                                                                    $edit_url The editor.
	 * @param bool                                                                                      $can_roll_back Whether rollback links show (not for an archived campaign).
	 */
	private static function render_versions( string $uid, array $versions, ?int $draft, string $publish_url, string $edit_url, bool $can_roll_back = true ): void {
		$counts = array();
		foreach ( ( new DeploymentRepository() )->for_campaign( $uid ) as $deployment ) {
			if ( Deployment::LIVE === $deployment->state ) {
				$counts[ $deployment->version_number ] = ( $counts[ $deployment->version_number ] ?? 0 ) + 1;
			}
		}
		$history = Plan::current()->includes( Plan::Pro );
		if ( ! $history ) {
			$versions = array_slice( $versions, 0, 1 );
		}
		// Views, conversions and rate since each version went live, across every site
		// that ran it (FR-61; Pro and above).
		$figures = $history ? MetricTotals::by_version( $uid, Plan::current()->is_hub_plan() ) : array();
		/**
		 * Filters the link for "Roll back to this version" on a version row, or '' for none.
		 *
		 * @param string $url    Default ''.
		 * @param string $uid    Campaign ULID.
		 * @param int    $number Version number.
		 */
		$rollback = static fn ( int $number ): string => $can_roll_back ? (string) apply_filters( 'campaignbandit_rollback_url', '', $uid, $number ) : '';
		?>
		<table class="wp-list-table widefat striped cbandit-versions-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Version', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Published', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'campaign-bandit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What changed', 'campaign-bandit' ); ?></th>
					<?php if ( $history ) : ?>
						<th scope="col" class="num"><?php esc_html_e( 'Views', 'campaign-bandit' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Conversions', 'campaign-bandit' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Rate', 'campaign-bandit' ); ?></th>
					<?php endif; ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'campaign-bandit' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( null !== $draft && $history ) : ?>
					<tr>
						<td><strong><?php echo esc_html( 'v' . $draft ); ?></strong></td>
						<td class="cbandit-muted">—</td>
						<td><span class="cbandit-status cbandit-status--neutral"><?php esc_html_e( 'Draft', 'campaign-bandit' ); ?></span></td>
						<td class="cbandit-muted"><?php esc_html_e( 'Not published yet', 'campaign-bandit' ); ?></td>
						<td class="num cbandit-muted">—</td>
						<td class="num cbandit-muted">—</td>
						<td class="num cbandit-muted">—</td>
						<td class="cbandit-row-links">
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Open draft', 'campaign-bandit' ); ?></a>
							<?php if ( current_user_can( Capabilities::PUBLISH_CAMPAIGNS ) ) : ?>
								<a href="<?php echo esc_url( $publish_url ); ?>"><?php esc_html_e( 'Publish', 'campaign-bandit' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $versions as $row ) : ?>
					<?php
					$version = $row['version'];
					$live_on = $counts[ $version->number ] ?? 0;
					$url     = $version->number < $versions[0]['version']->number ? $rollback( $version->number ) : '';
					?>
					<tr>
						<td><strong><?php echo esc_html( 'v' . $version->number ); ?></strong></td>
						<td>
							<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $version->published_at . ' UTC' ) ) ); ?>
							<?php if ( '' !== $row['published_by_name'] ) : ?>
								<br /><span class="cbandit-muted"><?php echo esc_html( $row['published_by_name'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $live_on > 0 ) : ?>
								<span class="cbandit-status cbandit-status--good">
									<?php
									echo esc_html(
										Plan::current()->is_hub_plan()
											/* translators: %d: number of sites. */
											? sprintf( _n( 'Live on %d site', 'Live on %d sites', $live_on, 'campaign-bandit' ), $live_on )
											: __( 'Live', 'campaign-bandit' )
									);
									?>
								</span>
							<?php else : ?>
								<span class="cbandit-status cbandit-status--neutral"><?php esc_html_e( 'Not live', 'campaign-bandit' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( '' !== $version->change_summary ? $version->change_summary : '—' ); ?></td>
						<?php if ( $history ) : ?>
							<?php
							$views = $figures[ $version->number ]['views'] ?? 0;
							$convs = $figures[ $version->number ]['conversions'] ?? 0;
							$rate  = ConversionRate::percent( $convs, $views );
							?>
							<td class="num"><?php echo esc_html( number_format_i18n( $views ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $convs ) ); ?></td>
							<td class="num"><?php echo esc_html( null === $rate ? '—' : number_format_i18n( $rate, 1 ) . '%' ); ?></td>
						<?php endif; ?>
						<td class="cbandit-row-links">
							<?php if ( null !== $draft ) : ?>
								<?php
								$compare_url = self::url(
									$uid,
									array(
										'tab'     => 'versions',
										'compare' => (string) $version->number,
									)
								);
								?>
								<a href="<?php echo esc_url( $compare_url ); ?>"><?php esc_html_e( 'Compare with draft', 'campaign-bandit' ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Roll back', 'campaign-bandit' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $history ) : ?>
			<p class="cbandit-muted"><?php esc_html_e( 'Pro keeps every version, with rollback to any of them.', 'campaign-bandit' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * A version side by side with the draft: content as it renders, then the settings
	 * that differ (S12 "Compare with draft").
	 *
	 * @param Campaign $campaign Campaign.
	 * @param int      $number   Version number.
	 */
	private static function render_compare( Campaign $campaign, int $number ): void {
		$version = ( new VersionRepository() )->find_number( $campaign->uid, $number );
		$post    = get_post( $campaign->post_id );
		if ( null === $version || null === $post ) {
			echo '<p class="cbandit-muted">' . esc_html__( 'That version does not exist.', 'campaign-bandit' ) . '</p>';
			return;
		}
		$a       = self::flatten( $version->settings->to_array() );
		$b       = self::flatten( CampaignSettings::from_json( (string) get_post_meta( $post->ID, CampaignPostType::META_SETTINGS, true ) )->to_array() );
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
			if ( ( $a[ $key ] ?? '' ) !== ( $b[ $key ] ?? '' ) ) {
				$changed[ $key ] = array( $a[ $key ] ?? '—', $b[ $key ] ?? '—' );
			}
		}
		?>
		<p><a href="<?php echo esc_url( self::url( $campaign->uid, array( 'tab' => 'versions' ) ) ); ?>">&larr; <?php esc_html_e( 'Versions', 'campaign-bandit' ); ?></a></p>
		<div class="cbandit-compare">
			<div class="cbandit-compare__side">
				<h3><?php echo esc_html( 'v' . $version->number ); ?></h3>
				<div class="cbandit-compare__content"><?php echo PackageWriter::content_html( $version ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered by the content policy. ?></div>
			</div>
			<div class="cbandit-compare__side">
				<h3><?php esc_html_e( 'Draft', 'campaign-bandit' ); ?></h3>
				<div class="cbandit-compare__content"><?php echo wp_kses( do_blocks( $post->post_content ), ContentPolicy::allowed_html() ); ?></div>
			</div>
		</div>
		<h3><?php esc_html_e( 'Settings that differ', 'campaign-bandit' ); ?></h3>
		<?php if ( array() === $changed ) : ?>
			<p class="cbandit-muted"><?php esc_html_e( 'None: only the content changed.', 'campaign-bandit' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Setting', 'campaign-bandit' ); ?></th>
						<th scope="col"><?php echo esc_html( 'v' . $version->number ); ?></th>
						<th scope="col"><?php esc_html_e( 'Draft', 'campaign-bandit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $changed as $key => $values ) : ?>
						<tr>
							<td><?php echo esc_html( str_replace( '_', ' ', (string) $key ) ); ?></td>
							<td><code><?php echo esc_html( $values[0] ); ?></code></td>
							<td><code><?php echo esc_html( $values[1] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Settings as "path › to › key" => readable value, for the comparison.
	 *
	 * @param array<string, mixed> $values Settings.
	 * @param string               $prefix Path so far.
	 * @return array<string, string>
	 */
	private static function flatten( array $values, string $prefix = '' ): array {
		$out = array();
		foreach ( $values as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . ' › ' . $key;
			if ( is_array( $value ) && array() !== $value && ! array_is_list( $value ) ) {
				$out += self::flatten( $value, $path );
			} elseif ( is_bool( $value ) ) {
				$out[ $path ] = $value ? 'yes' : 'no';
			} elseif ( null === $value || '' === $value || array() === $value ) {
				$out[ $path ] = '—';
			} else {
				$out[ $path ] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
			}
		}
		return $out;
	}

	/**
	 * This campaign's audit events (BR-71).
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function render_activity( string $uid ): void {
		$events = ( new AuditLog() )->for_object( 'campaign', $uid );
		if ( array() === $events ) {
			echo '<p class="cbandit-muted">' . esc_html__( 'Nothing recorded yet.', 'campaign-bandit' ) . '</p>';
			return;
		}
		?>
		<ul class="cbandit-timeline">
			<?php foreach ( $events as $event ) : ?>
				<li class="cbandit-timeline__item">
					<span class="cbandit-timeline__time"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $event['occurred_at'] . ' UTC' ) ) ); ?></span>
					<?php echo esc_html( self::describe( $event ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) : ?>
			<?php
			$history = add_query_arg(
				array(
					'tab'  => 'audit',
					'area' => 'campaign',
					'days' => '365',
				),
				SettingsScreen::url()
			);
			?>
			<p><a href="<?php echo esc_url( $history ); ?>"><?php esc_html_e( 'Full history in Settings › Audit log', 'campaign-bandit' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * One event as a sentence. Also used by the hub's Recent activity (S1).
	 *
	 * @param array<string, mixed> $event   Event: user_name, action, details.
	 * @param string               $subject What it was about, such as a campaign name, or ''.
	 */
	public static function describe( array $event, string $subject = '' ): string {
		$d       = (array) $event['details'];
		$about   = '' !== $subject ? ' · ' . $subject : '';
		$site    = isset( $d['url'] ) ? ' ' . (string) $d['url'] : '';
		$who     = '' !== $event['user_name'] ? $event['user_name'] : __( 'Campaign Bandit', 'campaign-bandit' );
		$version = isset( $d['version'] ) ? 'v' . (int) $d['version'] : '';
		$sites   = isset( $d['sites'] ) && is_array( $d['sites'] ) ? implode( ', ', array_map( 'strval', $d['sites'] ) ) : '';
		$on      = '' !== $sites ? ' ' . sprintf( /* translators: %s: list of sites. */ __( 'on %s', 'campaign-bandit' ), $sites ) : '';
		$name    = isset( $d['name'] ) && '' !== $d['name'] ? ' ' . (string) $d['name'] : '';
		$what    = match ( $event['action'] ) {
			/* translators: %s: version, such as v3. */
			'campaign.published'          => sprintf( __( 'published %s', 'campaign-bandit' ), $version ) . $on,
			/* translators: %s: version, such as v3. */
			'campaign.rolled_back'        => sprintf( __( 'rolled back to %s', 'campaign-bandit' ), $version ) . $on,
			/* translators: 1: version, 2: date and time. */
			'campaign.scheduled'          => sprintf( __( 'scheduled %1$s for %2$s', 'campaign-bandit' ), $version, isset( $d['at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $d['at'] . ' UTC' ) ) : '' ) . $on,
			'campaign.schedule_cancelled' => __( 'cancelled the scheduled publish', 'campaign-bandit' ),
			'campaign.paused'             => __( 'paused the campaign', 'campaign-bandit' ) . $on,
			'campaign.resumed'            => __( 'resumed the campaign', 'campaign-bandit' ) . $on,
			'campaign.deleted'            => __( 'deleted the campaign', 'campaign-bandit' ),
			'campaign.archived'           => __( 'archived the campaign', 'campaign-bandit' ),
			'campaign.restored'           => __( 'restored the campaign', 'campaign-bandit' ),
			'campaign.duplicated'         => __( 'created the campaign as a copy', 'campaign-bandit' ),
			'campaign.client_changed'     => isset( $d['to'] ) ? sprintf( /* translators: %s: client name. */ __( 'moved the campaign to %s', 'campaign-bandit' ), (string) $d['to'] ) : __( 'moved the campaign to another client', 'campaign-bandit' ),
			'site.pairing_key_created'    => __( 'created a pairing key', 'campaign-bandit' ),
			'site.paired'                 => __( 'connected a site', 'campaign-bandit' ) . $site,
			'site.disconnected'           => __( 'disconnected a site', 'campaign-bandit' ) . $site,
			'site.disconnected_by_site'   => __( 'a site disconnected itself', 'campaign-bandit' ),
			'site.url_changed'            => __( 'noticed a site URL change', 'campaign-bandit' ),
			'site.move_confirmed'         => __( 'confirmed a site move', 'campaign-bandit' ),
			'site.promoted'               => __( 'promoted a staging site to live', 'campaign-bandit' ),
			'site.moved'                  => __( 'moved a site to another client', 'campaign-bandit' ),
			'site.marked_staging'         => __( 'marked a copied site as staging', 'campaign-bandit' ),
			'site.paired_as_new'          => __( 'paired a copied site as a new site', 'campaign-bandit' ),
			'site.split_cancelled'        => __( 'stopped waiting for a copied site', 'campaign-bandit' ),
			'site.reidentified'           => __( 'joined the hub as a site of its own', 'campaign-bandit' ),
			'site.default_destinations_changed' => __( 'changed where leads from a site\'s own campaigns go', 'campaign-bandit' ) . $site,
			'site.lead_emails_changed'    => __( 'changed who gets a site\'s new-lead emails', 'campaign-bandit' ) . $site,
			'campaign.site_settings_changed' => __( 'changed site settings', 'campaign-bandit' ) . $on,
			'destination.created'         => __( 'added a destination', 'campaign-bandit' ) . $name,
			'destination.changed'         => __( 'changed a destination', 'campaign-bandit' ) . $name,
			'destination.secret_regenerated' => __( 'regenerated a destination\'s signing secret', 'campaign-bandit' ) . $name,
			'destination.disabled'        => __( 'disabled a destination', 'campaign-bandit' ) . $name,
			'destination.enabled'         => __( 'enabled a destination', 'campaign-bandit' ) . $name,
			'destination.deleted'         => __( 'deleted a destination', 'campaign-bandit' ) . $name,
			'lead.exported'               => __( 'exported leads', 'campaign-bandit' ),
			'lead.deleted'                => __( 'deleted a lead', 'campaign-bandit' ),
			'lead.retention_deleted'      => __( 'deleted leads past the retention period', 'campaign-bandit' ),
			'settings.retention_changed'  => isset( $d['to'] ) ? sprintf( /* translators: %s: number of days. */ __( 'set lead retention to %s days', 'campaign-bandit' ), (string) $d['to'] ) : __( 'changed lead retention', 'campaign-bandit' ),
			default                       => str_replace( array( '.', '_' ), ' ', (string) $event['action'] ),
		};//end match
		return $who . ' ' . $what . $about;
	}
}
