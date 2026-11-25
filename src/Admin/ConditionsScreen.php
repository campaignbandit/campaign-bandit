<?php
/**
 * Conditions (S11): the targeting library. Saved conditions with a one-line summary,
 * where they are used, and an editor with the same rule builder as campaigns (BR-07,
 * FR-36). Pro and above.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Conditions\ConditionsRest;
use CampaignBandit\Conditions\Republisher;
use CampaignBandit\Conditions\SavedConditionStore;
use CampaignBandit\Editor\Editor;

/**
 * The Conditions screen.
 */
final class ConditionsScreen {

	public const SLUG = 'campaign-bandit-conditions';

	private const ACTION = 'campaignbandit_conditions';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		// Before Leads, so the menu reads Campaigns · Conditions · Leads (S11).
		add_action( 'admin_menu', array( self::class, 'menu' ), 9 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The screen URL.
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
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Add Conditions under the Campaign Bandit menu, on Pro and above.
	 */
	public static function menu(): void {
		if ( ! ConditionsRest::allowed() ) {
			return;
		}
		$hook = add_submenu_page(
			'edit.php?post_type=' . CampaignPostType::POST_TYPE,
			__( 'Conditions', 'campaign-bandit' ),
			__( 'Conditions', 'campaign-bandit' ),
			\CampaignBandit\Security\Capabilities::EDIT_CAMPAIGNS,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/**
	 * Styles, and on the edit view the rule builder.
	 */
	public static function load(): void {
		wp_enqueue_style( 'campaign-bandit-admin', plugins_url( 'assets/admin.css', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view.
		if ( ! isset( $_GET['edit'] ) ) {
			return;
		}
		$asset = CAMPAIGNBANDIT_DIR . '/build/conditions/index.asset.php';
		if ( ! file_exists( $asset ) ) {
			return;
		}
		$deps = require $asset;
		wp_enqueue_script( 'campaign-bandit-conditions', plugins_url( 'build/conditions/index.js', CAMPAIGNBANDIT_FILE ), $deps['dependencies'], $deps['version'], true );
		wp_enqueue_style( 'campaign-bandit-conditions', plugins_url( 'build/conditions/index.css', CAMPAIGNBANDIT_FILE ), array( 'wp-components' ), $deps['version'] );
		wp_enqueue_style( 'campaign-bandit-conditions-screen', plugins_url( 'build/conditions/style-index.css', CAMPAIGNBANDIT_FILE ), array( 'campaign-bandit-conditions' ), $deps['version'] );
		wp_set_script_translations( 'campaign-bandit-conditions', 'campaign-bandit' );
		$store = new SavedConditionStore();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view.
		$uid   = sanitize_text_field( wp_unslash( $_GET['edit'] ) );
		$saved = 'new' === $uid ? null : $store->get( strtoupper( $uid ) );
		// The rule builder reads the same settings as the campaign editor.
		wp_add_inline_script(
			'campaign-bandit-conditions',
			'window.campaignBanditEditor=' . wp_json_encode( Editor::config() ) . ';window.campaignBanditCondition=' . wp_json_encode(
				/**
				 * Filters what the saved condition editor gets. The hub adds its clients
				 * (`workspaces`) and the condition on each client site (`sites`).
				 *
				 * @param array<string, mixed>      $data  Data.
				 * @param array<string, mixed>|null $saved The saved condition, or null for a new one.
				 */
				apply_filters(
					'campaignbandit_condition_screen_data',
					array(
						'saved'      => null === $saved ? null : ConditionsRest::shape( $saved ),
						'categories' => $store->categories(),
						'listUrl'    => self::url(),
					),
					$saved
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The list, or the editor.
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view.
		if ( isset( $_GET['edit'] ) ) {
			echo '<div class="wrap cbandit-conditions"><p class="cbandit-breadcrumb"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Conditions', 'campaign-bandit' ) . '</a></p><div id="cbandit-condition-editor"></div></div>';
			return;
		}
		$store = new SavedConditionStore();
		$all   = $store->all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		// On a hub, a saved condition belongs to one client or to every client (BR-07).
		$client = isset( $_GET['client'] ) ? absint( $_GET['client'] ) : 0;
		$open   = isset( $_GET['usage'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['usage'] ) ) ) : '';
		$done   = isset( $_GET['done'] ) ? sanitize_text_field( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$shown = array_filter(
			$all,
			static fn ( array $s ): bool => ( '' === $search || false !== mb_stripos( $s['name'], $search ) )
				&& ( '' === $category || $category === $s['category'] )
				// A client's own, and the ones every client shares.
				&& ( 0 === $client || 0 === (int) $s['workspace'] || $client === (int) $s['workspace'] )
		);
		?>
		<div class="wrap cbandit-conditions">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Conditions', 'campaign-bandit' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add saved condition', 'campaign-bandit' ); ?></a>
			<hr class="wp-header-end" />
			<?php if ( '' !== $done ) : ?>
				<div class="notice notice-<?php echo 'deleted' === $done || 'duplicated' === $done || str_starts_with( $done, __( 'Republished', 'campaign-bandit' ) ) ? 'success' : 'error'; ?> is-dismissible"><p>
					<?php
					echo esc_html(
						match ( $done ) {
							'deleted'    => __( 'Saved condition deleted.', 'campaign-bandit' ),
							'duplicated' => __( 'Saved condition duplicated.', 'campaign-bandit' ),
							default      => $done,
						}
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="cbandit-split cbandit-conditions__layout">
			<div class="cbandit-split__main">
			<?php if ( array() === $all ) : ?>
				<div class="cbandit-card cbandit-conditions__empty">
					<p><?php esc_html_e( 'No saved conditions yet. Add one here, or build rules in a campaign and use “Save as a saved condition”.', 'campaign-bandit' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add saved condition', 'campaign-bandit' ); ?></a></p>
				</div>
			</div>
			<div class="cbandit-split__aside"><?php self::render_help(); ?></div>
			</div>
			</div>
				<?php
				return;
			endif;
			?>

			<form method="get" class="cbandit-filters cbandit-conditions__filters">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( CampaignPostType::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label class="screen-reader-text" for="cbandit-conditions-search"><?php esc_html_e( 'Search saved conditions', 'campaign-bandit' ); ?></label>
				<input type="search" id="cbandit-conditions-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search', 'campaign-bandit' ); ?>" />
				<?php $categories = $store->categories(); ?>
				<?php if ( array() !== $categories ) : ?>
					<label class="screen-reader-text" for="cbandit-conditions-category"><?php esc_html_e( 'Category', 'campaign-bandit' ); ?></label>
					<select id="cbandit-conditions-category" name="category">
						<option value=""><?php esc_html_e( 'All categories', 'campaign-bandit' ); ?></option>
						<?php foreach ( $categories as $choice ) : ?>
							<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $choice, $category ); ?>><?php echo esc_html( $choice ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<?php
				/**
				 * Filters the client picker on the saved conditions list (S11, hub).
				 *
				 * @param array<int, string> $clients Client ID => name.
				 */
				$clients = (array) apply_filters( 'campaignbandit_saved_condition_clients', array() );
				?>
				<?php if ( array() !== $clients ) : ?>
					<label class="screen-reader-text" for="cbandit-conditions-client"><?php esc_html_e( 'Client', 'campaign-bandit' ); ?></label>
					<select id="cbandit-conditions-client" name="client">
						<option value=""><?php esc_html_e( 'All clients', 'campaign-bandit' ); ?></option>
						<?php foreach ( $clients as $id => $name ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $client, (int) $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'campaign-bandit' ); ?></button>
			</form>

			<table class="wp-list-table widefat striped cbandit-conditions-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'campaign-bandit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'campaign-bandit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rules', 'campaign-bandit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Used by', 'campaign-bandit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last edited', 'campaign-bandit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $saved ) : ?>
						<?php
						$usage    = $store->usage( $saved['uid'] );
						$live     = count( array_filter( $usage, static fn ( array $u ): bool => $u['live'] ) );
						$outdated = count( array_filter( $usage, static fn ( array $u ): bool => $u['outdated'] ) );
						?>
						<tr>
							<td>
								<a class="row-title" href="<?php echo esc_url( self::url( array( 'edit' => $saved['uid'] ) ) ); ?>"><?php echo esc_html( $saved['name'] ); ?></a>
								<div class="cbandit-row-links">
									<a href="<?php echo esc_url( self::url( array( 'edit' => $saved['uid'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'campaign-bandit' ); ?></a>
									<a href="<?php echo esc_url( self::url( array( 'usage' => $saved['uid'] ) ) ); ?>"><?php esc_html_e( 'Check usage', 'campaign-bandit' ); ?></a>
									<?php self::action_link( 'duplicate', $saved['uid'], __( 'Duplicate', 'campaign-bandit' ) ); ?>
									<?php if ( array() === $usage ) : ?>
										<?php self::action_link( 'delete', $saved['uid'], __( 'Delete', 'campaign-bandit' ), __( 'Delete this saved condition? This can’t be undone.', 'campaign-bandit' ) ); ?>
									<?php else : ?>
										<span class="cbandit-muted" title="<?php esc_attr_e( 'Campaigns use it: take it out of them first.', 'campaign-bandit' ); ?>"><?php esc_html_e( 'Delete (in use)', 'campaign-bandit' ); ?></span>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<?php echo esc_html( '' !== $saved['category'] ? $saved['category'] : '—' ); ?>
								<?php
								/**
								 * Filters the client a saved condition belongs to, for the list (hub).
								 *
								 * @param string $name      Client name, or '' for every client.
								 * @param int    $workspace Workspace ID.
								 */
								$client = (string) apply_filters( 'campaignbandit_saved_condition_client', '', (int) $saved['workspace'] );
								?>
								<?php if ( '' !== $client ) : ?>
									<br /><span class="cbandit-muted"><?php echo esc_html( $client ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::summary( $saved['condition'] ) ); ?></td>
							<td>
								<?php
								echo esc_html(
									array() === $usage
										? __( 'Not used yet', 'campaign-bandit' )
										/* translators: 1: number of campaigns, 2: number live. */
										: sprintf( _n( '%1$d campaign (%2$d live)', '%1$d campaigns (%2$d live)', count( $usage ), 'campaign-bandit' ), count( $usage ), $live )
								);
								?>
								<?php if ( $outdated > 0 ) : ?>
									<?php /* translators: %d: number of campaigns. */ ?>
									<br /><span class="cbandit-attention"><?php echo esc_html( sprintf( _n( 'Edited since: %d live campaign runs the older rules until it is republished.', 'Edited since: %d live campaigns run the older rules until they are republished.', $outdated, 'campaign-bandit' ), $outdated ) ); ?></span>
									<?php if ( current_user_can( \CampaignBandit\Security\Capabilities::PUBLISH_CAMPAIGNS ) ) : ?>
										<?php
										/* translators: %d: number of campaigns. */
										$republish = sprintf( _n( 'Republish %d campaign', 'Republish %d campaigns', $outdated, 'campaign-bandit' ), $outdated );
										self::action_link( 'republish', $saved['uid'], $republish, __( 'Publish a new version of each, with these rules, to the sites where it is live? Paused sites keep what they have.', 'campaign-bandit' ), 'button button-small' );
										?>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( human_time_diff( (int) strtotime( $saved['modified_at'] . ' UTC' ) ) ); ?>
								<?php if ( '' !== $saved['modified_by'] ) : ?>
									<br /><span class="cbandit-muted"><?php echo esc_html( $saved['modified_by'] ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $open === $saved['uid'] ) : ?>
							<tr class="cbandit-usage-row">
								<td colspan="5"><?php self::render_usage( $usage ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<div class="cbandit-split__aside"><?php self::render_help(); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * How saved conditions work, beside the list (S11).
	 */
	private static function render_help(): void {
		?>
		<aside class="cbandit-card cbandit-conditions__help" aria-labelledby="cbandit-conditions-help-title">
			<h2 id="cbandit-conditions-help-title"><?php esc_html_e( 'How saved conditions work', 'campaign-bandit' ); ?></h2>
			<p><?php esc_html_e( 'A saved condition is a set of rules with a name, such as “UK mobile visitors” or “Service pages”. Build it once and use it in any campaign.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Use one in a campaign', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'In the campaign editor, open Display conditions and choose “Saved condition…” as a rule. It combines with the campaign’s other rules like any rule.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Make one from a campaign', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'Build rules in a campaign, then choose “Save as a saved condition”. The campaign then uses it.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Change one', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'Edit it here or from the editor. Live campaigns keep the rules they were published with until you publish them again, so nothing changes on a site by surprise. “Used by” says when a live campaign runs older rules; Check usage links to each one.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Delete one', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'Only when no campaign uses it. Check usage shows which do.', 'campaign-bandit' ); ?></p>
		</aside>
		<?php
	}

	/**
	 * Check usage (S11): every campaign that uses it, live or in a draft, with a way to
	 * publish the ones running older rules.
	 *
	 * @param array<int, array<string, mixed>> $usage Usage.
	 */
	private static function render_usage( array $usage ): void {
		if ( array() === $usage ) {
			echo '<p class="cbandit-muted">' . esc_html__( 'No campaign uses this saved condition.', 'campaign-bandit' ) . '</p>';
			return;
		}
		echo '<ul class="cbandit-usage">';
		foreach ( $usage as $use ) {
			$where = array();
			if ( $use['live'] ) {
				/* translators: %d: number of sites. */
				$where[] = Editor::is_hub() ? sprintf( _n( 'live or paused on %d site', 'live or paused on %d sites', count( $use['sites'] ), 'campaign-bandit' ), count( $use['sites'] ) ) : __( 'live', 'campaign-bandit' );
			}
			if ( $use['draft'] ) {
				$where[] = __( 'in the draft', 'campaign-bandit' );
			}
			echo '<li><a href="' . esc_url( CampaignScreen::url( (string) $use['campaign'] ) ) . '">' . esc_html( (string) $use['name'] ) . '</a> <span class="cbandit-muted">' . esc_html( implode( ' · ', $where ) ) . '</span>';
			if ( $use['outdated'] ) {
				/**
				 * Filters where "Publish" goes from Campaign detail (see CampaignScreen).
				 *
				 * @param string $url Default: the editor.
				 * @param string $uid Campaign ULID.
				 */
				$publish = (string) apply_filters( 'campaignbandit_publish_url', (string) get_edit_post_link( (int) $use['post_id'], 'url' ), (string) $use['campaign'] );
				echo ' · <span class="cbandit-attention">' . esc_html__( 'runs the older rules', 'campaign-bandit' ) . '</span> <a href="' . esc_url( $publish ) . '">' . esc_html__( 'Publish to apply', 'campaign-bandit' ) . '</a>';
			}
			echo '</li>';
		}//end foreach
		echo '</ul>';
	}

	/**
	 * A small POST action as a link-like button.
	 *
	 * @param string $op      Operation.
	 * @param string $uid     Saved condition ID.
	 * @param string $label   Label.
	 * @param string $confirm Question to confirm, or ''.
	 * @param string $button  Button class.
	 */
	private static function action_link( string $op, string $uid, string $label, string $confirm = '', string $button = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cbandit-inline-form"<?php echo '' !== $confirm ? ' onsubmit="return window.confirm( ' . esc_attr( (string) wp_json_encode( $confirm ) ) . ' );"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>" />
			<input type="hidden" name="condition" value="<?php echo esc_attr( $uid ); ?>" />
			<?php wp_nonce_field( self::ACTION . '_' . $op . '_' . $uid ); ?>
			<button type="submit" class="<?php echo esc_attr( '' !== $button ? $button : 'button-link' . ( 'delete' === $op ? ' button-link-delete' : '' ) ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Duplicate and delete.
	 */
	public static function handle(): void {
		$op  = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$uid = isset( $_POST['condition'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['condition'] ) ) ) : '';
		check_admin_referer( self::ACTION . '_' . $op . '_' . $uid );
		if ( ! ConditionsRest::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to change saved conditions.', 'campaign-bandit' ), 403 );
		}
		$store = new SavedConditionStore();
		$done  = '';
		if ( 'delete' === $op ) {
			$result = $store->delete( $uid );
			$done   = is_wp_error( $result ) ? $result->get_error_message() : 'deleted';
		} elseif ( 'republish' === $op ) {
			if ( ! current_user_can( \CampaignBandit\Security\Capabilities::PUBLISH_CAMPAIGNS ) ) {
				wp_die( esc_html__( 'You are not allowed to publish campaigns.', 'campaign-bandit' ), 403 );
			}
			$result = Republisher::run( $uid );
			$done   = array() === $result['failed']
				/* translators: %d: number of campaigns. */
				? sprintf( _n( 'Republished %d campaign.', 'Republished %d campaigns.', count( $result['done'] ), 'campaign-bandit' ), count( $result['done'] ) )
				/* translators: 1: campaign names, 2: reason. */
				: sprintf( __( 'Not republished: %1$s (%2$s).', 'campaign-bandit' ), implode( ', ', array_keys( $result['failed'] ) ), implode( '; ', array_unique( $result['failed'] ) ) );
		} elseif ( 'duplicate' === $op ) {
			$saved = $store->get( $uid );
			/* translators: %s: saved condition name. */
			$result = null === $saved ? null : $store->save( null, sprintf( __( '%s (copy)', 'campaign-bandit' ), $saved['name'] ), $saved['category'], $saved['condition'] );
			$done   = is_array( $result ) ? 'duplicated' : __( 'The saved condition could not be duplicated.', 'campaign-bandit' );
		}
		wp_safe_redirect( self::url( array( 'done' => rawurlencode( $done ) ) ) );
		exit;
	}

	/**
	 * A one-line summary of the rules: "2 rule sets: Page is Front page; Device is mobile…".
	 *
	 * @param array<string, mixed> $condition Rules.
	 */
	public static function summary( array $condition ): string {
		$sets  = (array) ( $condition['groups'] ?? array() );
		$parts = array();
		foreach ( $sets as $group ) {
			$rules = array();
			foreach ( (array) $group['rules'] as $rule ) {
				$rules[] = self::sentence( $rule );
			}
			$parts[] = implode( ' ' . __( 'and', 'campaign-bandit' ) . ' ', $rules );
		}
		$text = implode( ' · ' . __( 'or', 'campaign-bandit' ) . ' · ', $parts );
		if ( ! empty( $condition['invert'] ) ) {
			/* translators: %s: rules. */
			$text = sprintf( __( 'Not when: %s', 'campaign-bandit' ), $text );
		}
		return mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 157 ) . '…' : $text;
	}

	/**
	 * One rule in words: "Page is Front page", "Device is mobile", "URL path starts with /blog".
	 *
	 * @param array<string, mixed> $rule Rule.
	 */
	public static function sentence( array $rule ): string {
		$ops  = array(
			'is'           => __( 'is', 'campaign-bandit' ),
			'is_not'       => __( 'is not', 'campaign-bandit' ),
			'starts_with'  => __( 'starts with', 'campaign-bandit' ),
			'ends_with'    => __( 'ends with', 'campaign-bandit' ),
			'contains'     => __( 'contains', 'campaign-bandit' ),
			'not_contains' => __( 'does not contain', 'campaign-bandit' ),
			'matches'      => __( 'matches', 'campaign-bandit' ),
			'equals'       => __( 'equals', 'campaign-bandit' ),
			'not_equals'   => __( 'does not equal', 'campaign-bandit' ),
			'exists'       => __( 'is present', 'campaign-bandit' ),
			'not_exists'   => __( 'is not present', 'campaign-bandit' ),
		);
		$op   = $ops[ (string) ( $rule['op'] ?? '' ) ] ?? (string) ( $rule['op'] ?? '' );
		$list = static fn ( $value ): string => implode( ', ', array_map( 'strval', (array) $value ) );
		switch ( $rule['type'] ?? '' ) {
			case 'page':
				$pages = array_map( array( self::class, 'page_value' ), (array) ( $rule['value'] ?? array() ) );
				/* translators: 1: "is" or "is not", 2: pages. */
				return sprintf( __( 'Page %1$s %2$s', 'campaign-bandit' ), $op, implode( __( ' or ', 'campaign-bandit' ), $pages ) );
			case 'path':
				/* translators: 1: operator, 2: path. */
				return sprintf( __( 'URL path %1$s %2$s', 'campaign-bandit' ), $op, (string) ( $rule['value'] ?? '' ) );
			case 'device':
				/* translators: 1: "is" or "is not", 2: devices. */
				return sprintf( __( 'Device %1$s %2$s', 'campaign-bandit' ), $op, $list( $rule['value'] ?? array() ) );
			case 'country':
				/* translators: 1: "is" or "is not", 2: country codes. */
				return sprintf( __( 'Country %1$s %2$s', 'campaign-bandit' ), $op, $list( $rule['value'] ?? array() ) );
			case 'param':
				/* translators: 1: parameter name, 2: operator, 3: value. */
				return trim( sprintf( __( 'URL parameter %1$s %2$s %3$s', 'campaign-bandit' ), (string) ( $rule['name'] ?? '' ), $op, in_array( $rule['op'] ?? '', array( 'exists', 'not_exists' ), true ) ? '' : (string) ( $rule['value'] ?? '' ) ) );
			case 'condition':
				return (string) ( $rule['name'] ?? '' );
		}
		return '';
	}

	/**
	 * A page rule value in words.
	 *
	 * @param mixed $value Value such as "front", "singular:post", "term:category:news".
	 */
	private static function page_value( $value ): string {
		$parts = explode( ':', (string) $value, 3 );
		switch ( $parts[0] ) {
			case 'front':
				return __( 'Front page', 'campaign-bandit' );
			case 'posts_page':
				return __( 'Posts page', 'campaign-bandit' );
			case 'archive':
				return __( 'Any archive', 'campaign-bandit' );
			case 'search':
				return __( 'Search results', 'campaign-bandit' );
			case '404':
				return __( 'Page not found', 'campaign-bandit' );
			case 'singular':
				/* translators: %s: post type. */
				return isset( $parts[1] ) ? sprintf( __( 'a single %s', 'campaign-bandit' ), $parts[1] ) : __( 'any single item', 'campaign-bandit' );
			case 'term':
				/* translators: 1: taxonomy, 2: term slug. */
				return sprintf( __( 'in %1$s %2$s', 'campaign-bandit' ), $parts[1] ?? '', $parts[2] ?? '' );
			case 'child_of':
				/* translators: %s: parent page path or ID. */
				return sprintf( __( 'a child of %s', 'campaign-bandit' ), substr( (string) $value, 9 ) );
		}//end switch
		return (string) $value;
	}
}
