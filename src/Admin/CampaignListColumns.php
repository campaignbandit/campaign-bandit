<?php
/**
 * Campaigns list (S3): extra columns and filters on WordPress's own campaign list.
 *
 * Views count every site: client sites report theirs when they sync (FR-61).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Database\Schema;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\DeploymentRepository;
use WP_Query;

/**
 * Columns: format, version, status across sites, leads (7 days), last edited.
 * Filters: status and format.
 */
final class CampaignListColumns {

	private const STATUSES = array( 'live', 'paused', 'scheduled', 'unpublished' );

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		$type = CampaignPostType::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'cell' ), 10, 2 );
		// The name column carries a second line (format, client) and the row actions.
		add_filter( 'list_table_primary_column', static fn ( string $column, string $screen ): string => 'edit-' . $type === $screen ? 'cbandit_name' : $column, 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", static fn ( array $columns ): array => array( 'cbandit_name' => 'title' ) + $columns );
		add_action( 'restrict_manage_posts', array( self::class, 'filters' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_filters' ) );
		// Campaign posts are always drafts (BR-20): core's draft labels, Drafts view and
		// month filter say nothing useful here.
		add_filter(
			'display_post_states',
			static fn ( array $states, $post ): array => CampaignPostType::POST_TYPE === $post->post_type ? array_diff_key( $states, array( 'draft' => true ) ) : $states,
			10,
			2
		);
		add_filter( "views_edit-{$type}", static fn ( array $views ): array => array_diff_key( $views, array( 'draft' => true ) ) );
		// Quick Edit and Bulk edit could publish the post itself, which must stay a draft.
		add_filter(
			'post_row_actions',
			static fn ( array $actions, $post ): array => CampaignPostType::POST_TYPE === $post->post_type ? array_diff_key( $actions, array( 'inline hide-if-no-js' => true ) ) : $actions,
			10,
			2
		);
		add_filter( "bulk_actions-edit-{$type}", static fn ( array $actions ): array => array_diff_key( $actions, array( 'edit' => true ) ) );
		add_filter( 'disable_months_dropdown', static fn ( bool $disable, string $post_type ): bool => CampaignPostType::POST_TYPE === $post_type ? true : $disable, 10, 2 );
		add_action(
			'admin_enqueue_scripts',
			static function (): void {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( null !== $screen && 'edit-' . CampaignPostType::POST_TYPE === $screen->id ) {
					wp_enqueue_style( 'campaign-bandit-admin', plugins_url( 'assets/admin.css', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION );
				}
			}
		);
	}

	/**
	 * Column set (S3): each fact in its own column, so people can choose what they see
	 * in Screen Options. Core's date column means little here (campaigns stay drafts).
	 * On a hub the add-on adds Client before Title.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		unset( $columns['date'] );
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ 'title' === $key ? 'cbandit_name' : $key ] = $label;
		}
		return $out + array(
			'cbandit_format'  => __( 'Format', 'campaign-bandit' ),
			'cbandit_version' => __( 'Version', 'campaign-bandit' ),
			'cbandit_status'  => __( 'Status', 'campaign-bandit' ),
			'cbandit_views'   => __( 'Views (7 days)', 'campaign-bandit' ),
			'cbandit_leads'   => __( 'Leads (7 days)', 'campaign-bandit' ),
			'cbandit_edited'  => __( 'Last edited', 'campaign-bandit' ),
		);
	}

	/**
	 * One cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Campaign post ID.
	 */
	public static function cell( string $column, int $post_id ): void {
		if ( 'cbandit_name' === $column ) {
			self::name( $post_id );
			return;
		}
		$uid = (string) get_post_meta( $post_id, CampaignPostType::META_UID, true );
		switch ( $column ) {
			case 'cbandit_format':
				echo esc_html( self::format_label( (string) self::settings( $post_id )->get( 'format' ) ) );
				break;

			case 'cbandit_views':
				echo esc_html( '' === $uid ? '—' : number_format_i18n( self::views( $uid ) ) );
				break;

			case 'cbandit_version':
				$live = self::live_version( $uid );
				echo esc_html( null === $live ? '—' : 'v' . $live );
				$changes = '' === $uid ? null : Plugin::publishing()->draft_changes( $uid );
				if ( null !== $live && is_array( $changes ) && array() !== $changes ) {
					echo ' <span class="cbandit-muted">' . esc_html__( '· draft changes', 'campaign-bandit' ) . '</span>';
				}
				break;

			case 'cbandit_status':
				if ( CampaignPostType::ARCHIVED === get_post_status( $post_id ) ) {
					echo '<span class="cbandit-status cbandit-status--neutral">' . esc_html_x( 'Archived', 'campaign status', 'campaign-bandit' ) . '</span>';
					break;
				}
				echo self::status( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status().
				break;

			case 'cbandit_leads':
				echo esc_html( '' === $uid ? '—' : number_format_i18n( self::leads( $uid ) ) );
				break;

			case 'cbandit_edited':
				$post = get_post( $post_id );
				if ( null === $post ) {
					break;
				}
				$editor = get_userdata( (int) get_post_meta( $post_id, '_edit_last', true ) );
				/* translators: %s: time span, such as "2 hours". */
				echo esc_html( sprintf( __( '%s ago', 'campaign-bandit' ), human_time_diff( (int) get_post_timestamp( $post, 'modified' ) ) ) );
				if ( false !== $editor ) {
					echo '<br /><span class="cbandit-muted">' . esc_html( $editor->display_name ) . '</span>';
				}
				break;
		}//end switch
	}

	/**
	 * The name, linking to Campaign detail, and its states.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	private static function name( int $post_id ): void {
		$post = get_post( $post_id );
		if ( null === $post ) {
			return;
		}
		$uid   = (string) get_post_meta( $post_id, CampaignPostType::META_UID, true );
		$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'campaign-bandit' );
		$url   = '' !== $uid ? CampaignScreen::url( $uid ) : (string) get_edit_post_link( $post_id );
		?>
		<strong><a class="row-title" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a><?php _post_states( $post ); ?></strong>
		<?php
	}

	/**
	 * Status and format filters above the list.
	 *
	 * @param string $post_type Post type of the list.
	 */
	public static function filters( string $post_type ): void {
		if ( CampaignPostType::POST_TYPE !== $post_type ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['cbandit_status'] ) ? sanitize_key( $_GET['cbandit_status'] ) : '';
		$format = isset( $_GET['cbandit_format'] ) ? sanitize_key( $_GET['cbandit_format'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$statuses = array(
			'live'        => __( 'Live', 'campaign-bandit' ),
			'paused'      => Plan::current()->is_hub_plan() ? __( 'Paused', 'campaign-bandit' ) : __( 'Switched off', 'campaign-bandit' ),
			'scheduled'   => __( 'Scheduled', 'campaign-bandit' ),
			'unpublished' => __( 'Not published', 'campaign-bandit' ),
		);
		?>
		<label class="screen-reader-text" for="cbandit-status-filter"><?php esc_html_e( 'Filter by status', 'campaign-bandit' ); ?></label>
		<select name="cbandit_status" id="cbandit-status-filter">
			<option value=""><?php esc_html_e( 'All statuses', 'campaign-bandit' ); ?></option>
			<?php foreach ( $statuses as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label class="screen-reader-text" for="cbandit-format-filter"><?php esc_html_e( 'Filter by format', 'campaign-bandit' ); ?></label>
		<select name="cbandit_format" id="cbandit-format-filter">
			<option value=""><?php esc_html_e( 'All formats', 'campaign-bandit' ); ?></option>
			<?php foreach ( CampaignSettings::FORMATS as $value ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $format, $value ); ?>><?php echo esc_html( self::format_label( $value ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Narrow the list to the chosen status and format.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function apply_filters( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || CampaignPostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['cbandit_status'] ) ? sanitize_key( $_GET['cbandit_status'] ) : '';
		$format = isset( $_GET['cbandit_format'] ) ? sanitize_key( $_GET['cbandit_format'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, self::STATUSES, true ) && ! in_array( $format, CampaignSettings::FORMATS, true ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		$ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $status, $format ): bool {
					if ( in_array( $format, CampaignSettings::FORMATS, true ) && self::settings( $id )->get( 'format' ) !== $format ) {
						return false;
					}
					return ! in_array( $status, self::STATUSES, true ) || self::status_key( (string) get_post_meta( $id, CampaignPostType::META_UID, true ) ) === $status;
				}
			)
		);
		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
	}

	/**
	 * The campaign's draft settings.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function settings( int $post_id ): CampaignSettings {
		return CampaignSettings::from_json( (string) get_post_meta( $post_id, CampaignPostType::META_SETTINGS, true ) );
	}

	/**
	 * A format's label.
	 *
	 * @param string $format Format.
	 */
	private static function format_label( string $format ): string {
		return array(
			'modal'    => __( 'Modal', 'campaign-bandit' ),
			'inline'   => __( 'Inline', 'campaign-bandit' ),
			'slide_in' => __( 'Slide-in', 'campaign-bandit' ),
			'bar'      => __( 'Sticky bar', 'campaign-bandit' ),
		)[ $format ] ?? $format;
	}

	/**
	 * Counts of the campaign's deployments by state.
	 *
	 * @param string $uid Campaign ULID.
	 * @return array{live: int, paused: int, scheduled: int, version: ?int}
	 */
	private static function counts( string $uid ): array {
		$counts = array(
			'live'      => 0,
			'paused'    => 0,
			'scheduled' => 0,
			'version'   => null,
		);
		if ( '' === $uid ) {
			return $counts;
		}
		foreach ( ( new DeploymentRepository() )->for_campaign( $uid ) as $deployment ) {
			if ( Deployment::LIVE === $deployment->state ) {
				++$counts['live'];
				$counts['version'] = max( (int) $counts['version'], $deployment->version_number );
			} elseif ( Deployment::PAUSED === $deployment->state ) {
				++$counts['paused'];
			} elseif ( Deployment::SCHEDULED === $deployment->state ) {
				++$counts['scheduled'];
			}
		}
		return $counts;
	}

	/**
	 * The highest live version, or null.
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function live_version( string $uid ): ?int {
		return self::counts( $uid )['version'];
	}

	/**
	 * One of live, paused, scheduled or unpublished: "Live" if any site has it live (S3).
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function status_key( string $uid ): string {
		$c = self::counts( $uid );
		if ( $c['live'] > 0 ) {
			return 'live';
		}
		if ( $c['paused'] > 0 ) {
			return 'paused';
		}
		return $c['scheduled'] > 0 ? 'scheduled' : 'unpublished';
	}

	/**
	 * The status label: "Live on 3 · paused on 2" on a hub, "Live" on a single site.
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function status( string $uid ): string {
		$c   = self::counts( $uid );
		$hub = Plan::current()->is_hub_plan();
		switch ( self::status_key( $uid ) ) {
			case 'live':
				/* translators: %d: number of sites. */
				$label = $hub ? sprintf( _n( 'Live on %d site', 'Live on %d sites', $c['live'], 'campaign-bandit' ), $c['live'] ) : __( 'Live', 'campaign-bandit' );
				if ( $hub && $c['paused'] > 0 ) {
					/* translators: %d: number of sites. */
					$label .= ' · ' . sprintf( __( 'paused on %d', 'campaign-bandit' ), $c['paused'] );
				}
				$tone = 'good';
				break;
			case 'paused':
				$label = $hub ? __( 'Paused', 'campaign-bandit' ) : __( 'Switched off', 'campaign-bandit' );
				$tone  = 'attention';
				break;
			case 'scheduled':
				$label = __( 'Scheduled', 'campaign-bandit' );
				$tone  = 'neutral';
				break;
			default:
				$label = __( 'Not published', 'campaign-bandit' );
				$tone  = 'neutral';
		}//end switch
		return sprintf( '<span class="cbandit-status cbandit-status--%1$s">%2$s</span>', esc_attr( $tone ), esc_html( $label ) );
	}

	/**
	 * Views in the last 7 days, on every site (client sites report theirs, FR-61).
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function views( string $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COALESCE(SUM(views), 0) FROM %i WHERE campaign_uid = %s AND day >= %s',
				$wpdb->prefix . Schema::METRICS,
				$uid,
				wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Leads in the last 7 days, from every site (test leads excluded).
	 *
	 * @param string $uid Campaign ULID.
	 */
	private static function leads( string $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE campaign_uid = %s AND is_test = 0 AND submitted_at >= %s',
				LeadRepository::table(),
				$uid,
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			)
		);
	}
}
