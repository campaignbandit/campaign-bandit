<?php
/**
 * "Explain this page" on Campaign detail (FR-34, decision 0023): choose a page, a
 * device, a country and URL parameters, and see each campaign and whether it would
 * show there for a first-time visitor, and why. Pro and above.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Explain\PageExplainer;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Security\Capabilities;
use WP_Error;

/**
 * The panel.
 */
final class ExplainPanel {

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'campaignbandit_campaign_notices', array( self::class, 'render' ) );
	}

	/**
	 * Whether this person can use it.
	 */
	public static function available(): bool {
		return Plan::current()->includes( Plan::Pro ) && current_user_can( Capabilities::EDIT_CAMPAIGNS );
	}

	/**
	 * The link that opens the panel.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public static function url( string $uid ): string {
		return CampaignScreen::url( $uid, array( 'explain' => '1' ) ) . '#cbandit-explain';
	}

	/**
	 * The panel, when asked for.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public static function render( string $uid ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only view.
		if ( ! isset( $_GET['explain'] ) || ! self::available() ) {
			return;
		}
		$typed   = isset( $_GET['page_url'] ) ? sanitize_text_field( wp_unslash( $_GET['page_url'] ) ) : '/';
		$device  = isset( $_GET['device'] ) ? sanitize_key( $_GET['device'] ) : 'desktop';
		$device  = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'desktop';
		$country = isset( $_GET['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['country'] ) ) ) : '';
		$country = 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
		$query   = isset( $_GET['params'] ) ? sanitize_text_field( wp_unslash( $_GET['params'] ) ) : '';
		$site    = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0;
		$asked   = isset( $_GET['page_url'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/**
		 * Filters the sites a page can be explained on. The hub adds the campaign's
		 * client sites.
		 *
		 * @param array<int, array{id: int, label: string}> $sites Sites.
		 * @param string                                    $uid   Campaign ULID.
		 */
		$sites = (array) apply_filters(
			'campaignbandit_explain_sites',
			array(
				array(
					'id'    => 0,
					'label' => __( 'This site', 'campaign-bandit' ),
				),
			),
			$uid
		);

		$data  = null;
		$error = '';
		if ( $asked ) {
			// A query string typed with the URL counts as URL parameters too.
			$from_url = (string) ( wp_parse_url( $typed, PHP_URL_QUERY ) ?? '' );
			$path     = PageExplainer::path( $typed );
			if ( 0 === $site ) {
				$data = PageExplainer::local( $path, $from_url );
			} else {
				/**
				 * Filters what's needed to explain a page on another site. The hub asks the
				 * client site for the page's facts and builds that site's campaigns.
				 *
				 * @param array<string, mixed>|WP_Error|null $data Data.
				 * @param int                                $site Site.
				 * @param string                             $path  Page path.
				 * @param string                             $query The URL's query string.
				 */
				$data = apply_filters( 'campaignbandit_explain_data', null, $site, $path, $from_url );
			}
			if ( $data instanceof WP_Error ) {
				$error = $data->get_error_message();
				$data  = null;
			} elseif ( ! is_array( $data ) ) {
				$error = __( 'That site couldn’t be asked about its page. Check it is connected and online.', 'campaign-bandit' );
				$data  = null;
			}
			if ( null !== $data ) {
				self::enqueue( $uid, $data, $device, '' === $country ? 'unknown' : $country, trim( $from_url . '&' . ltrim( $query, '?' ), '&' ) );
			}
		}//end if
		?>
		<div class="cbandit-panel-card cbandit-explain" id="cbandit-explain">
			<div class="cbandit-panel-card__head">
				<h2><?php esc_html_e( 'Explain a page', 'campaign-bandit' ); ?></h2>
				<a class="cbandit-close" href="<?php echo esc_url( CampaignScreen::url( $uid ) ); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Close', 'campaign-bandit' ); ?></span><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
			</div>
			<p class="cbandit-muted"><?php esc_html_e( 'Which campaigns would show on a page, and why, for a first-time visitor. No real visitor’s history is used.', 'campaign-bandit' ); ?></p>
			<form method="get" class="cbandit-explain__form">
				<input type="hidden" name="page" value="<?php echo esc_attr( CampaignScreen::SLUG ); ?>" />
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view. ?>
				<input type="hidden" name="post_type" value="<?php echo esc_attr( isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '' ); ?>" />
				<input type="hidden" name="campaign" value="<?php echo esc_attr( $uid ); ?>" />
				<input type="hidden" name="explain" value="1" />
				<?php if ( count( $sites ) > 1 ) : ?>
					<p>
						<label for="cbandit-explain-site"><?php esc_html_e( 'Site', 'campaign-bandit' ); ?></label>
						<select name="site" id="cbandit-explain-site">
							<?php foreach ( $sites as $choice ) : ?>
								<option value="<?php echo esc_attr( (string) $choice['id'] ); ?>" <?php selected( (int) $choice['id'], $site ); ?>><?php echo esc_html( (string) $choice['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<p>
					<label for="cbandit-explain-url"><?php esc_html_e( 'Page', 'campaign-bandit' ); ?></label>
					<input type="text" name="page_url" id="cbandit-explain-url" class="regular-text code" value="<?php echo esc_attr( $typed ); ?>" aria-describedby="cbandit-explain-url-help" />
					<span class="description" id="cbandit-explain-url-help"><?php esc_html_e( 'A path such as /pricing, or the page’s full address.', 'campaign-bandit' ); ?></span>
				</p>
				<p>
					<label for="cbandit-explain-device"><?php esc_html_e( 'Device', 'campaign-bandit' ); ?></label>
					<select name="device" id="cbandit-explain-device">
						<option value="desktop" <?php selected( $device, 'desktop' ); ?>><?php esc_html_e( 'Desktop', 'campaign-bandit' ); ?></option>
						<option value="tablet" <?php selected( $device, 'tablet' ); ?>><?php esc_html_e( 'Tablet', 'campaign-bandit' ); ?></option>
						<option value="mobile" <?php selected( $device, 'mobile' ); ?>><?php esc_html_e( 'Mobile', 'campaign-bandit' ); ?></option>
					</select>
				</p>
				<p>
					<label for="cbandit-explain-country"><?php esc_html_e( 'Country', 'campaign-bandit' ); ?></label>
					<input type="text" name="country" id="cbandit-explain-country" class="small-text" maxlength="2" value="<?php echo esc_attr( $country ); ?>" placeholder="US" aria-describedby="cbandit-explain-country-help" />
					<span class="description" id="cbandit-explain-country-help"><?php esc_html_e( 'Two letters. Empty: a visitor whose country can’t be found.', 'campaign-bandit' ); ?></span>
				</p>
				<p>
					<label for="cbandit-explain-params"><?php esc_html_e( 'URL parameters', 'campaign-bandit' ); ?></label>
					<input type="text" name="params" id="cbandit-explain-params" class="regular-text code" value="<?php echo esc_attr( $query ); ?>" placeholder="utm_source=google" />
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Explain', 'campaign-bandit' ); ?></button></p>
			</form>
			<?php if ( '' !== $error ) : ?>
				<p class="cbandit-explain__error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<div id="cbandit-explain-results" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * The script that judges the page in the browser, with its data.
	 *
	 * @param string               $uid     Campaign ULID (shown first).
	 * @param array<string, mixed> $data    Manifest, facts and path.
	 * @param string               $device  Device.
	 * @param string               $country Country or 'unknown'.
	 * @param string               $query   URL parameters.
	 */
	private static function enqueue( string $uid, array $data, string $device, string $country, string $query ): void {
		$file = CAMPAIGNBANDIT_DIR . '/build/explain.js';
		if ( ! file_exists( $file ) ) {
			return;
		}
		parse_str( $query, $params );
		$payload = array(
			'current'  => $uid,
			'manifest' => $data['manifest'],
			'visit'    => array(
				'path'    => (string) $data['path'],
				'params'  => array_map( 'strval', array_filter( (array) $params, 'is_scalar' ) ),
				'device'  => $device,
				'country' => $country,
				'page'    => $data['facts'],
				'now'     => (int) floor( microtime( true ) * 1000 ),
			),
			'labels'   => array(
				'campaign'    => __( 'Campaign', 'campaign-bandit' ),
				'result'      => __( 'On this page', 'campaign-bandit' ),
				'why'         => __( 'Why', 'campaign-bandit' ),
				'none'        => __( 'No campaign is live on this site.', 'campaign-bandit' ),
				'outcomes'    => array(
					'shows'      => __( 'Would show', 'campaign-bandit' ),
					'outranked'  => __( 'Another shows instead', 'campaign-bandit' ),
					'not_placed' => __( 'Qualifies, if placed here', 'campaign-bandit' ),
					'no'         => __( 'Would not show', 'campaign-bandit' ),
				),
				/* translators: %s: campaign name. */
				'outrankedBy' => __( 'Only one popup shows at a time: “%s” comes first (priority, then the newest).', 'campaign-bandit' ),
				'notPlaced'   => __( 'Inline campaigns show where they are placed in the page.', 'campaign-bandit' ),
				'opens'       => array(
					/* translators: %s: number of seconds. */
					'time'         => __( 'After %s seconds', 'campaign-bandit' ),
					/* translators: %s: percent. */
					'scroll'       => __( 'At %s%% scrolled', 'campaign-bandit' ),
					'exit_intent'  => __( 'On exit intent', 'campaign-bandit' ),
					/* translators: %s: event name. */
					'custom_event' => __( 'On the event %s', 'campaign-bandit' ),
					'click'        => __( 'On click', 'campaign-bandit' ),
					'hover'        => __( 'On hover', 'campaign-bandit' ),
					'inline'       => __( 'Where it is placed', 'campaign-bandit' ),
				),
				'formats'     => array(
					'modal'    => __( 'Modal', 'campaign-bandit' ),
					'slide_in' => __( 'Slide-in', 'campaign-bandit' ),
					'bar'      => __( 'Sticky bar', 'campaign-bandit' ),
					'inline'   => __( 'Inline', 'campaign-bandit' ),
				),
			),
		);
		wp_enqueue_script( 'campaign-bandit-explain', plugins_url( 'build/explain.js', CAMPAIGNBANDIT_FILE ), array(), (string) filemtime( $file ), true );
		wp_add_inline_script( 'campaign-bandit-explain', 'window.campaignBanditExplain=' . wp_json_encode( $payload ) . ';', 'before' );
	}
}
