<?php
/**
 * The leads list on the Leads screen (S6, decision 0012).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Leads\LeadCsv;
use CampaignBandit\Leads\LeadFilter;
use CampaignBandit\Leads\LeadManager;
use CampaignBandit\Security\Capabilities;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Leads as a WordPress list table: status views with counts, search, campaign and
 * date filters, pagination and bulk delete.
 */
final class LeadsListTable extends WP_List_Table {

	/**
	 * Counts per status, from the last query.
	 *
	 * @var array<string, int>
	 */
	private array $counts = array();

	/**
	 * Create the table.
	 *
	 * @param LeadManager $manager Lead records.
	 * @param LeadFilter  $filter  Current filter.
	 */
	public function __construct(
		private readonly LeadManager $manager,
		private readonly LeadFilter $filter,
	) {
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Load the current page of leads.
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'campaignbandit_leads_per_page', 20 );
		$result   = $this->manager->page( $this->filter, $this->get_pagenum(), $per_page );

		$this->items           = $result['rows'];
		$this->counts          = $result['counts'];
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		$columns = array(
			'received' => __( 'Received', 'campaign-bandit' ),
			'lead'     => __( 'Lead', 'campaign-bandit' ),
			'campaign' => __( 'Campaign', 'campaign-bandit' ),
			'status'   => __( 'Status', 'campaign-bandit' ),
		);
		return current_user_can( Capabilities::MANAGE_LEADS ) ? array( 'cb' => '<input type="checkbox" />' ) + $columns : $columns;
	}

	/**
	 * Status filter with counts (S6).
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$labels = array( '' => __( 'All', 'campaign-bandit' ) );
		if ( LeadsScreen::delivery_enabled() ) {
			$labels['delivered'] = __( 'Delivered', 'campaign-bandit' );
			$labels['retrying']  = __( 'Retrying', 'campaign-bandit' );
			$labels['failed']    = __( 'Failed', 'campaign-bandit' );
		}
		$labels['stored_only'] = __( 'Stored only', 'campaign-bandit' );
		$labels['test']        = __( 'Test (staging)', 'campaign-bandit' );
		$views                 = array();
		foreach ( $labels as $status => $label ) {
			// Test leads exist only on staging connections (BR-33); hide the view until there are some.
			if ( 'test' === $status && 0 === ( $this->counts['test'] ?? 0 ) ) {
				continue;
			}
			$views[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( LeadsScreen::url( $this->filter->with_status( $status )->to_query_args() ) ),
				$this->filter->status === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $this->counts[ $status ] ?? 0 ) )
			);
		}
		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		if ( ! current_user_can( Capabilities::MANAGE_LEADS ) ) {
			return array();
		}
		/**
		 * Filters the extra bulk actions on the Leads list. The add-on adds "Send again".
		 * Each is handled by the `campaignbandit_leads_bulk_{action}` filter.
		 *
		 * @param array<string, string> $actions Action => label.
		 */
		$extra = (array) apply_filters( 'campaignbandit_lead_bulk_actions', array() );
		return $extra + array( 'delete' => __( 'Delete permanently', 'campaign-bandit' ) );
	}

	/**
	 * Campaign and date filters, and the CSV export button.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$campaigns = $this->manager->campaigns_with_leads();
		$sites     = self::sites();
		?>
		<div class="alignleft actions cbandit-lead-filters">
			<?php if ( array() !== $sites ) : ?>
				<label class="screen-reader-text" for="cbandit-filter-site"><?php esc_html_e( 'Filter by site', 'campaign-bandit' ); ?></label>
				<select name="site" id="cbandit-filter-site">
					<option value=""><?php esc_html_e( 'All sites', 'campaign-bandit' ); ?></option>
					<?php foreach ( $sites as $id => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $this->filter->site, $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label class="screen-reader-text" for="cbandit-filter-campaign"><?php esc_html_e( 'Filter by campaign', 'campaign-bandit' ); ?></label>
			<select name="campaign" id="cbandit-filter-campaign">
				<option value=""><?php esc_html_e( 'All campaigns', 'campaign-bandit' ); ?></option>
				<?php foreach ( $campaigns as $uid => $name ) : ?>
					<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $this->filter->campaign, $uid ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="cbandit-filter-from"><?php esc_html_e( 'From', 'campaign-bandit' ); ?></label>
			<input type="date" name="from" id="cbandit-filter-from" value="<?php echo esc_attr( (string) $this->filter->from ); ?>" />
			<label for="cbandit-filter-to"><?php esc_html_e( 'To', 'campaign-bandit' ); ?></label>
			<input type="date" name="to" id="cbandit-filter-to" value="<?php echo esc_attr( (string) $this->filter->to ); ?>" />
			<?php submit_button( __( 'Filter', 'campaign-bandit' ), '', 'filter_action', false ); ?>
		</div>
		<?php if ( current_user_can( Capabilities::MANAGE_LEADS ) && $this->has_items() ) : ?>
			<div class="alignleft actions">
				<a class="button" href="<?php echo esc_url( LeadsScreen::export_url( $this->filter ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'campaign-bandit' ); ?>
				</a>
			</div>
			<?php
		endif;
	}

	/**
	 * Empty states (S6).
	 */
	public function no_items(): void {
		if ( $this->filter->is_filtered() ) {
			esc_html_e( 'No leads match these filters.', 'campaign-bandit' );
			return;
		}
		esc_html_e( 'No leads yet. They appear here when a visitor submits a campaign form.', 'campaign-bandit' );
	}

	/**
	 * Highlight the open lead.
	 *
	 * @param array<string, mixed> $item Lead row.
	 */
	public function single_row( $item ): void {
		$open = LeadsScreen::open_lead() === $item['uid'];
		echo '<tr' . ( $open ? ' class="cbandit-row-open" aria-current="true"' : '' ) . '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string, mixed> $item Lead row.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="cbandit-lead-%1$s">%2$s</label><input type="checkbox" id="cbandit-lead-%1$s" name="lead_ids[]" value="%1$s" />',
			esc_attr( (string) $item['uid'] ),
			esc_html__( 'Select lead', 'campaign-bandit' )
		);
	}

	/**
	 * Received time, in site time.
	 *
	 * @param array<string, mixed> $item Lead row.
	 */
	protected function column_received( $item ): string {
		$time = LeadCsv::site_time( (string) $item['submitted_at'], wp_timezone() );
		return esc_html( '' !== $time ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '' );
	}

	/**
	 * Name and email, linking to the detail panel.
	 *
	 * @param array<string, mixed> $item Lead row.
	 */
	protected function column_lead( $item ): string {
		$name  = (string) ( $item['name'] ?? '' );
		$email = (string) ( $item['email'] ?? '' );
		$title = '' !== $name ? $name : ( '' !== $email ? $email : __( '(no name or email)', 'campaign-bandit' ) );

		$out = sprintf(
			'<a class="row-title" href="%1$s">%2$s</a>',
			esc_url( LeadsScreen::url( $this->filter->to_query_args() + array( 'lead' => (string) $item['uid'] ) ) ),
			esc_html( $title )
		);
		if ( '' !== $name && '' !== $email ) {
			$out .= '<br /><span class="cbandit-muted">' . esc_html( $email ) . '</span>';
		}
		return $out;
	}

	/**
	 * Campaign name and version.
	 *
	 * @param array<string, mixed> $item Lead row.
	 */
	protected function column_campaign( $item ): string {
		$name  = $this->manager->campaign_name( (string) $item['campaign_uid'] );
		$sites = self::sites();
		$site  = $sites[ (int) $item['site_id'] ] ?? '';
		return sprintf(
			'%1$s <span class="cbandit-muted">· v%2$d</span>%3$s',
			esc_html( '' !== $name ? $name : __( '(deleted campaign)', 'campaign-bandit' ) ),
			(int) $item['version_number'],
			'' !== $site ? '<br /><span class="cbandit-muted">' . esc_html( $site ) . '</span>' : ''
		);
	}

	/**
	 * Sites leads can come from, by site ID, on a hub (S6); empty on a single site.
	 *
	 * @return array<int, string>
	 */
	public static function sites(): array {
		static $sites = null;
		if ( null === $sites ) {
			/**
			 * Filters the sites shown in the Leads site column and filter. The hub adds
			 * itself (0) and its connected sites.
			 *
			 * @param array<int, string> $sites Site ID => label.
			 */
			$sites = (array) apply_filters( 'campaignbandit_lead_sites', array() );
		}
		return $sites;
	}

	/**
	 * Delivery status as a tinted label with a dot.
	 *
	 * @param array<string, mixed> $item Lead row.
	 */
	protected function column_status( $item ): string {
		return LeadsScreen::status_label( $item );
	}
}
