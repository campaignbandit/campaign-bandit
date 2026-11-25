<?php
/**
 * The Leads screen (S6, FR-54): list, detail panel, CSV export and delete.
 *
 * Standalone and Free sites show the list and detail; the delivery log comes with
 * webhook delivery (M2). The display explanation shows on Pro and above (FR-50).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Leads\Consents;
use CampaignBandit\Leads\LeadCsv;
use CampaignBandit\Leads\LeadFilter;
use CampaignBandit\Leads\LeadManager;
use CampaignBandit\Leads\LeadSource;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Plugin;
use CampaignBandit\Security\Capabilities;
use CampaignBandit\Settings\DataSettings;
use CampaignBandit\Support\Ulid;

/**
 * Leads admin screen.
 */
final class LeadsScreen {

	public const SLUG = 'campaign-bandit-leads';

	private const EXPORT_ACTION = 'campaignbandit_export_leads';
	private const DELETE_ACTION = 'campaignbandit_delete_lead';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'handle_export' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
	}

	/**
	 * Add Leads under the Campaign Bandit menu.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . CampaignPostType::POST_TYPE,
			__( 'Leads', 'campaign-bandit' ),
			__( 'Leads', 'campaign-bandit' ),
			Capabilities::VIEW_LEADS,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( false === $hook ) {
			return;
		}
		add_action( 'load-' . $hook, array( self::class, 'load' ) );
	}

	/**
	 * Before output: screen options, bulk delete, styles.
	 */
	public static function load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Leads per page', 'campaign-bandit' ),
				'default' => 20,
				'option'  => 'campaignbandit_leads_per_page',
			)
		);
		add_filter( 'set_screen_option_campaignbandit_leads_per_page', static fn ( $keep, $option, $value ) => min( 200, max( 1, (int) $value ) ), 10, 3 );

		self::handle_bulk();

		wp_enqueue_style( 'campaign-bandit-admin', plugins_url( 'assets/admin.css', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION );
		wp_enqueue_script( 'campaign-bandit-admin', plugins_url( 'assets/admin.js', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * The screen URL with query values.
	 *
	 * @param array<string, string> $args Query values.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => CampaignPostType::POST_TYPE,
					'page'      => self::SLUG,
				),
				array_map( 'rawurlencode', $args )
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * A nonce-protected CSV export URL for a filter.
	 *
	 * @param LeadFilter $filter Filter (with a lead ID: one lead).
	 */
	public static function export_url( LeadFilter $filter ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge( array( 'action' => self::EXPORT_ACTION ), array_map( 'rawurlencode', $filter->to_query_args() ) ),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
	}

	/**
	 * Whether webhook delivery is available here, which adds the Delivered, Retrying
	 * and Failed filters (the add-on turns it on for Pro and above).
	 */
	public static function delivery_enabled(): bool {
		/**
		 * Filters whether lead delivery statuses apply on this site.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'campaignbandit_delivery_enabled', false );
	}

	/**
	 * The lead open in the detail panel, or ''.
	 */
	public static function open_lead(): string {
		$uid = isset( $_GET['lead'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['lead'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		return Ulid::is_valid( $uid ) ? $uid : '';
	}

	/**
	 * Narrow the list to one client's sites (S6, hub). Nothing on a single site.
	 *
	 * @param LeadFilter $filter The current filter.
	 */
	private static function client_filter( LeadFilter $filter ): void {
		$clients = self::clients();
		if ( array() === $clients ) {
			return;
		}
		?>
		<div class="cbandit-leads-client">
			<label class="screen-reader-text" for="cbandit-lead-client"><?php esc_html_e( 'Client', 'campaign-bandit' ); ?></label>
			<select name="client" id="cbandit-lead-client">
				<option value=""><?php esc_html_e( 'All clients', 'campaign-bandit' ); ?></option>
				<?php foreach ( $clients as $id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filter->client, (int) $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'campaign-bandit' ); ?></button>
		</div>
		<?php
	}

	/**
	 * The list filter from the request. The open lead is not a list filter.
	 */
	private static function list_filter(): LeadFilter {
		$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		unset( $query['lead'] );
		$filter = LeadFilter::from_request( is_array( $query ) ? $query : array() );
		if ( null === $filter->client ) {
			return $filter;
		}

		/**
		 * Filters the sites a client's leads can come from (S6, hub). The hub answers
		 * with that client's site IDs; an empty list narrows to nothing.
		 *
		 * @param int[] $sites  Site IDs.
		 * @param int   $client Client (workspace) ID.
		 */
		return $filter->within_sites( (array) apply_filters( 'campaignbandit_lead_client_sites', array(), $filter->client ) );
	}

	/**
	 * The clients leads can be filtered by, on a hub (S6); none on a single site.
	 *
	 * @return array<int, string> Client ID => name.
	 */
	public static function clients(): array {
		/**
		 * Filters the clients shown in the Leads client filter. The hub adds its own.
		 *
		 * @param array<int, string> $clients Client ID => name.
		 */
		return (array) apply_filters( 'campaignbandit_lead_clients', array() );
	}

	/**
	 * A delivery status as a tinted label with a dot (look rules: never colour alone).
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	public static function status_label( array $lead ): string {
		[ $label, $tone ] = ! empty( $lead['is_test'] )
			? array( __( 'Test (staging)', 'campaign-bandit' ), 'neutral' )
			: match ( (string) $lead['status'] ) {
				'delivered' => array( __( 'Delivered', 'campaign-bandit' ), 'good' ),
				'sending'   => array( __( 'Sending', 'campaign-bandit' ), 'neutral' ),
				'retrying'  => array( __( 'Retrying', 'campaign-bandit' ), 'attention' ),
				'failed'    => array( __( 'Failed', 'campaign-bandit' ), 'bad' ),
				default     => array( __( 'Stored only', 'campaign-bandit' ), 'neutral' ),
			};
		if ( ! empty( $lead['delivered_by_site'] ) && empty( $lead['is_test'] ) ) {
			/* translators: %s: delivery status the site reported, such as "Delivered". */
			$label = sprintf( __( 'Delivered by site · %s', 'campaign-bandit' ), $label );
		}
		return sprintf( '<span class="cbandit-status cbandit-status--%1$s">%2$s</span>', esc_attr( $tone ), esc_html( $label ) );
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_LEADS ) ) {
			wp_die( esc_html__( 'You are not allowed to view leads.', 'campaign-bandit' ), 403 );
		}

		$manager = Plugin::lead_manager();
		$filter  = self::list_filter();
		$table   = new LeadsListTable( $manager, $filter );
		$table->prepare_items();

		$open = self::open_lead();
		$lead = '' !== $open ? $manager->get( $open ) : null;
		?>
		<div class="wrap cbandit-leads">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'campaign-bandit' ); ?></h1>
			<?php
			if ( self::delivery_enabled() && 'retrying' === $filter->status ) {
				/**
				 * Fires beside the Leads title on the Retrying view. The add-on adds
				 * "Retry all retrying now" (S6).
				 */
				do_action( 'campaignbandit_leads_toolbar' );
			}
			?>
			<hr class="wp-header-end" />
			<?php self::notices(); ?>
			<div class="cbandit-leads-layout">
				<div class="cbandit-leads-list">
					<?php $table->views(); ?>
					<form method="get" data-confirm-delete="<?php echo esc_attr( __( 'Permanently delete the selected leads? This can’t be undone.', 'campaign-bandit' ) ); ?>">
						<input type="hidden" name="post_type" value="<?php echo esc_attr( CampaignPostType::POST_TYPE ); ?>" />
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
						<?php if ( '' !== $filter->status ) : ?>
							<input type="hidden" name="status" value="<?php echo esc_attr( $filter->status ); ?>" />
						<?php endif; ?>
						<?php self::client_filter( $filter ); ?>
						<?php
						$table->search_box( __( 'Search name or email', 'campaign-bandit' ), 'cbandit-lead-search' );
						$table->display();
						?>
					</form>
					<?php self::retention_notice(); ?>
				</div>
				<?php
				// The detail column is always there, so the list keeps its width when a lead opens.
				if ( null !== $lead ) {
					self::render_detail( $manager, $lead, $filter );
				} else {
					$message = '' !== $open
						? __( 'This lead no longer exists.', 'campaign-bandit' )
						: __( 'Select a lead to see its fields, consent and where it came from.', 'campaign-bandit' );
					echo '<div class="cbandit-lead-detail cbandit-lead-detail--empty"><p>' . esc_html( $message ) . '</p></div>';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Consent by purpose, each with its exact text (FR-23, BR-44).
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	private static function render_consents( array $lead ): void {
		$consents = Consents::for_lead( $lead );
		if ( array() === $consents ) {
			return;
		}
		$names = array(
			'contact' => __( 'Contact about this request', 'campaign-bandit' ),
			'email'   => __( 'Email marketing', 'campaign-bandit' ),
			'sms'     => __( 'Text messages', 'campaign-bandit' ),
		);
		?>
		<h3><?php esc_html_e( 'Consent', 'campaign-bandit' ); ?></h3>
		<ul class="cbandit-consents">
			<?php foreach ( $consents as $purpose => $consent ) : ?>
				<li>
					<span class="cbandit-consents__name"><?php echo esc_html( $names[ $purpose ] ?? $purpose ); ?></span>
					<?php if ( $consent['given'] ) : ?>
						<span class="cbandit-status cbandit-status--good"><?php esc_html_e( 'Given', 'campaign-bandit' ); ?></span>
					<?php else : ?>
						<span class="cbandit-status cbandit-status--neutral"><?php esc_html_e( 'Not given', 'campaign-bandit' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $consent['text'] ) : ?>
						<p class="cbandit-consent"><?php echo esc_html( $consent['text'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Where the lead came from (BR-47): first visit, this visit, click IDs, visits, pages.
	 *
	 * @param array<string, mixed> $source Cleaned source.
	 */
	private static function render_source( array $source ): void {
		$same   = $source['first'] === $source['last'];
		$visits = array( 'last' => __( 'This visit', 'campaign-bandit' ) );
		if ( ! $same ) {
			$visits = array( 'first' => __( 'First visit', 'campaign-bandit' ) ) + $visits;
		}
		$parts = array(
			'source'   => __( 'Source', 'campaign-bandit' ),
			'medium'   => __( 'Medium', 'campaign-bandit' ),
			'campaign' => __( 'Campaign', 'campaign-bandit' ),
			'term'     => __( 'Term', 'campaign-bandit' ),
			'content'  => __( 'Content', 'campaign-bandit' ),
		);
		?>
		<h3><?php esc_html_e( 'Source', 'campaign-bandit' ); ?></h3>
		<div class="cbandit-source">
			<?php foreach ( $visits as $key => $title ) : ?>
				<?php $visit = $source[ $key ]; ?>
				<div class="cbandit-source__visit">
					<h4>
						<?php echo esc_html( $title ); ?>
						<?php if ( '' !== $visit['at'] ) : ?>
							<span class="cbandit-muted">· <?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $visit['at'] ) ) ); ?></span>
						<?php endif; ?>
					</h4>
					<dl class="cbandit-dl">
						<?php if ( array() === $visit['utm'] && '' === $visit['referrer'] ) : ?>
							<dt><?php esc_html_e( 'Source', 'campaign-bandit' ); ?></dt>
							<dd><?php esc_html_e( '(direct)', 'campaign-bandit' ); ?></dd>
						<?php endif; ?>
						<?php foreach ( $visit['utm'] as $part => $value ) : ?>
							<dt><?php echo esc_html( $parts[ $part ] ?? $part ); ?></dt>
							<dd><?php echo esc_html( $value ); ?></dd>
						<?php endforeach; ?>
						<?php if ( '' !== $visit['referrer'] ) : ?>
							<dt><?php esc_html_e( 'Referrer', 'campaign-bandit' ); ?></dt>
							<dd><?php echo esc_html( $visit['referrer'] ); ?></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'Landing page', 'campaign-bandit' ); ?></dt>
						<dd><?php echo esc_html( $visit['landing_page'] ); ?></dd>
					</dl>
				</div>
			<?php endforeach; ?>
		</div>
		<dl class="cbandit-dl">
			<dt><?php esc_html_e( 'Click IDs', 'campaign-bandit' ); ?></dt>
			<dd>
				<?php if ( array() === $source['click_ids'] ) : ?>
					<?php esc_html_e( 'None found', 'campaign-bandit' ); ?>
				<?php else : ?>
					<?php foreach ( $source['click_ids'] as $name => $value ) : ?>
						<span class="cbandit-source__id"><?php echo esc_html( $name ); ?> <code><?php echo esc_html( $value ); ?></code></span>
					<?php endforeach; ?>
				<?php endif; ?>
			</dd>
			<dt><?php esc_html_e( 'Visits', 'campaign-bandit' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $source['visits'] ) ); ?></dd>
			<?php if ( array() !== $source['pages'] ) : ?>
				<dt><?php esc_html_e( 'Pages viewed', 'campaign-bandit' ); ?></dt>
				<dd>
					<ol class="cbandit-source__pages">
						<?php foreach ( $source['pages'] as $page ) : ?>
							<li><?php echo esc_html( $page ); ?></li>
						<?php endforeach; ?>
					</ol>
				</dd>
			<?php endif; ?>
		</dl>
		<p class="cbandit-muted cbandit-source__note"><?php esc_html_e( 'Recorded in the visitor\'s browser on this site only. Click IDs can be missing when browsers remove them.', 'campaign-bandit' ); ?></p>
		<?php
	}

	/**
	 * The detail panel for one lead (S6).
	 *
	 * @param LeadManager          $manager Lead records.
	 * @param array<string, mixed> $lead    Lead row.
	 * @param LeadFilter           $filter  Current list filter, to return to.
	 */
	private static function render_detail( LeadManager $manager, array $lead, LeadFilter $filter ): void {
		$uid      = (string) $lead['uid'];
		$fields   = json_decode( (string) $lead['fields'], true );
		$utm      = array_filter( (array) json_decode( (string) ( $lead['utm'] ?? '' ), true ) );
		$labels   = $manager->field_labels( $lead );
		$campaign = $manager->campaign_name( (string) $lead['campaign_uid'] );
		$received = LeadCsv::site_time( (string) $lead['submitted_at'], wp_timezone() );
		$close    = self::url( $filter->to_query_args() );
		?>
		<aside class="cbandit-lead-detail" aria-labelledby="cbandit-lead-title">
			<div class="cbandit-lead-detail__head">
				<h2 id="cbandit-lead-title"><?php echo esc_html( '' !== (string) $lead['name'] ? (string) $lead['name'] : ( (string) $lead['email'] ) ); ?></h2>
				<a class="cbandit-close" href="<?php echo esc_url( $close ); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Close lead', 'campaign-bandit' ); ?></span><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
			</div>
			<?php echo self::status_label( $lead ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_label(). ?>

			<dl class="cbandit-dl">
				<dt><?php esc_html_e( 'Received', 'campaign-bandit' ); ?></dt>
				<dd><?php echo esc_html( '' !== $received ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $received ) : '' ); ?></dd>
				<dt><?php esc_html_e( 'Campaign', 'campaign-bandit' ); ?></dt>
				<dd><?php echo esc_html( sprintf( '%s · v%d', '' !== $campaign ? $campaign : __( '(deleted campaign)', 'campaign-bandit' ), (int) $lead['version_number'] ) ); ?></dd>
				<?php if ( '' !== (string) $lead['page_url'] ) : ?>
					<dt><?php esc_html_e( 'Page', 'campaign-bandit' ); ?></dt>
					<dd><a href="<?php echo esc_url( (string) $lead['page_url'] ); ?>"><?php echo esc_html( (string) wp_parse_url( (string) $lead['page_url'], PHP_URL_PATH ) ); ?></a></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Lead ID', 'campaign-bandit' ); ?></dt>
				<dd><code><?php echo esc_html( $uid ); ?></code></dd>
				<?php if ( ! empty( $lead['notified'] ) ) : ?>
					<dt><?php esc_html_e( 'Email', 'campaign-bandit' ); ?></dt>
					<dd>
						<?php if ( 'sent' === $lead['notified'] ) : ?>
							<?php esc_html_e( 'Sent', 'campaign-bandit' ); ?>
						<?php else : ?>
							<span class="cbandit-status cbandit-status--bad"><?php esc_html_e( 'Email failed', 'campaign-bandit' ); ?></span>
						<?php endif; ?>
					</dd>
				<?php endif; ?>
			</dl>

			<h3><?php esc_html_e( 'Fields', 'campaign-bandit' ); ?></h3>
			<dl class="cbandit-dl">
				<?php foreach ( is_array( $fields ) ? $fields : array() as $key => $value ) : ?>
					<dt><?php echo esc_html( $labels[ $key ] ?? (string) $key ); ?></dt>
					<dd><?php echo esc_html( '' !== LeadCsv::value( $value ) ? LeadCsv::value( $value ) : '—' ); ?></dd>
				<?php endforeach; ?>
			</dl>

			<?php self::render_consents( $lead ); ?>

			<?php $source = LeadSource::from_json( $lead['source'] ?? null ); ?>
			<?php if ( array() !== $source ) : ?>
				<?php self::render_source( $source ); ?>
			<?php elseif ( array() !== $utm ) : ?>
				<h3><?php esc_html_e( 'Campaign tracking (UTM)', 'campaign-bandit' ); ?></h3>
				<dl class="cbandit-dl">
					<?php foreach ( $utm as $part => $value ) : ?>
						<dt><?php echo esc_html( 'utm_' . $part ); ?></dt>
						<dd><?php echo esc_html( LeadCsv::value( $value ) ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>

			<?php self::render_explanation( $lead ); ?>

			<?php
			/**
			 * Fires in the lead detail panel after "Why it showed". The add-on adds the
			 * delivery log and replay actions here (S6).
			 *
			 * @param array<string, mixed> $lead Lead row.
			 */
			do_action( 'campaignbandit_lead_detail', $lead );
			?>

			<?php if ( current_user_can( Capabilities::MANAGE_LEADS ) ) : ?>
				<div class="cbandit-lead-actions">
					<a class="button" href="<?php echo esc_url( self::export_url( new LeadFilter( lead: $uid ) ) ); ?>"><?php esc_html_e( 'Export lead', 'campaign-bandit' ); ?></a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm( <?php echo esc_attr( (string) wp_json_encode( __( 'Permanently delete this lead? This can’t be undone.', 'campaign-bandit' ) ) ); ?> );">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
						<input type="hidden" name="lead" value="<?php echo esc_attr( $uid ); ?>" />
						<input type="hidden" name="return" value="<?php echo esc_attr( $close ); ?>" />
						<?php wp_nonce_field( self::DELETE_ACTION . '_' . $uid ); ?>
						<button type="submit" class="button cbandit-button-delete"><?php esc_html_e( 'Delete lead', 'campaign-bandit' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</aside>
		<?php
	}

	/**
	 * Why the campaign showed (BR-14), on Pro and above.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	private static function render_explanation( array $lead ): void {
		if ( ! Plan::current()->includes( Plan::Pro ) ) {
			return;
		}
		$explanation = json_decode( (string) ( $lead['explanation'] ?? '' ), true );
		?>
		<h3><?php esc_html_e( 'Why it showed', 'campaign-bandit' ); ?></h3>
		<?php if ( ! is_array( $explanation ) ) : ?>
			<p class="cbandit-muted"><?php esc_html_e( 'Explanation not available.', 'campaign-bandit' ); ?></p>
			<?php
			return;
		endif;
		?>
		<ul class="cbandit-explanation">
			<?php foreach ( (array) ( $explanation['checks'] ?? array() ) as $check ) : ?>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html( sprintf( '%s: %s', (string) ( $check['check'] ?? '' ), (string) ( $check['detail'] ?? '' ) ) ); ?></li>
			<?php endforeach; ?>
			<?php if ( ! empty( $explanation['trigger']['label'] ) ) : ?>
				<li><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php echo esc_html( sprintf( /* translators: %s: trigger, such as "scroll". */ __( 'Trigger: %s', 'campaign-bandit' ), (string) $explanation['trigger']['label'] ) ); ?></li>
			<?php endif; ?>
			<?php foreach ( (array) ( $explanation['outranked'] ?? array() ) as $other ) : ?>
				<li><span class="dashicons dashicons-arrow-down-alt" aria-hidden="true"></span><?php echo esc_html( sprintf( /* translators: %s: campaign name. */ __( 'Outranked %s', 'campaign-bandit' ), (string) ( $other['name'] ?? '' ) ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The retention notice at the foot of the list (S6, BR-50).
	 */
	private static function retention_notice(): void {
		$days = DataSettings::retention_days();
		$text = 0 === $days
			? __( 'Leads are kept until you delete them.', 'campaign-bandit' )
			/* translators: %d: number of days. */
			: sprintf( _n( 'Leads are deleted automatically %d day after they arrive.', 'Leads are deleted automatically %d days after they arrive.', $days, 'campaign-bandit' ), $days );
		echo '<p class="cbandit-retention">' . esc_html( $text );
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo ' <a href="' . esc_url( SettingsScreen::url() ) . '">' . esc_html__( 'Change in Settings', 'campaign-bandit' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Result notices after an action.
	 */
	private static function notices(): void {
		$name    = 'cbandit_leads_notice_' . get_current_user_id();
		$message = isset( $_GET['done'] ) ? get_transient( $name ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( is_string( $message ) ) {
			delete_transient( $name );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		$deleted = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( null === $deleted ) {
			return;
		}
		$message = sprintf(
			/* translators: %s: number of leads. */
			_n( '%s lead deleted.', '%s leads deleted.', $deleted, 'campaign-bandit' ),
			number_format_i18n( $deleted )
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Stream a CSV of the filtered list, or one lead.
	 */
	public static function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_LEADS ) ) {
			wp_die( esc_html__( 'You are not allowed to export leads.', 'campaign-bandit' ), 403 );
		}

		$filter = LeadFilter::from_request( wp_unslash( $_GET ) );
		$name   = '' !== $filter->lead ? 'lead-' . strtolower( $filter->lead ) : 'leads-' . wp_date( 'Y-m-d' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download.
		if ( false !== $out ) {
			Plugin::lead_manager()->export( $filter, $out, AuditLog::CHANNEL_SCREEN );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * Delete one lead from the detail panel.
	 */
	public static function handle_delete(): void {
		$uid = isset( $_POST['lead'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['lead'] ) ) ) : '';
		check_admin_referer( self::DELETE_ACTION . '_' . $uid );
		if ( ! current_user_can( Capabilities::MANAGE_LEADS ) ) {
			wp_die( esc_html__( 'You are not allowed to delete leads.', 'campaign-bandit' ), 403 );
		}

		$deleted = Ulid::is_valid( $uid ) && Plugin::lead_manager()->delete( $uid, AuditLog::CHANNEL_SCREEN ) ? 1 : 0;
		$return  = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : self::url();
		wp_safe_redirect( add_query_arg( 'deleted', $deleted, wp_validate_redirect( $return, self::url() ) ) );
		exit;
	}

	/**
	 * Bulk actions from the list: delete, and the add-on's (runs before output, on the screen's load hook).
	 */
	private static function handle_bulk(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_GET['action2'] ) ? sanitize_key( $_GET['action2'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		}
		if ( '' === $action || '-1' === $action || empty( $_GET['lead_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
			return;
		}
		check_admin_referer( 'bulk-leads' );
		if ( ! current_user_can( Capabilities::MANAGE_LEADS ) ) {
			wp_die( esc_html__( 'You are not allowed to change leads.', 'campaign-bandit' ), 403 );
		}
		if ( 'delete' !== $action ) {
			$ids  = array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['lead_ids'] ) );
			$uids = array_values( array_filter( array_map( 'strtoupper', $ids ), array( Ulid::class, 'is_valid' ) ) );
			/**
			 * Handles an extra bulk action (see `campaignbandit_lead_bulk_actions`).
			 *
			 * @param string|null $message Notice to show afterwards, or null when not handled.
			 * @param string[]    $uids    Chosen lead ULIDs.
			 */
			$message = apply_filters( 'campaignbandit_leads_bulk_' . $action, null, $uids );
			if ( is_string( $message ) ) {
				set_transient( 'cbandit_leads_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
				wp_safe_redirect( add_query_arg( 'done', '1', self::url( self::list_filter()->to_query_args() ) ) );
				exit;
			}
			return;
		}

		$manager = Plugin::lead_manager();
		$deleted = 0;
		$ids     = array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['lead_ids'] ) );
		foreach ( $ids as $raw ) {
			$uid = strtoupper( (string) $raw );
			if ( Ulid::is_valid( $uid ) && $manager->delete( $uid, AuditLog::CHANNEL_SCREEN ) ) {
				++$deleted;
			}
		}
		wp_safe_redirect( add_query_arg( 'deleted', $deleted, self::url( self::list_filter()->to_query_args() ) ) );
		exit;
	}
}
