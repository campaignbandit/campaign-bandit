<?php
/**
 * REST for the targeting library (BR-07, FR-36): list, create, edit and delete saved
 * conditions, and check where one is used. Pro and above, for people who can edit
 * campaigns.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Conditions;

use CampaignBandit\Licensing\Plan;
use CampaignBandit\Rest\RestController;
use CampaignBandit\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Saved condition routes.
 */
final class ConditionsRest {

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Whether the current person may use the library.
	 */
	public static function allowed(): bool {
		return Plan::current()->includes( Plan::Pro ) && current_user_can( Capabilities::EDIT_CAMPAIGNS );
	}

	/**
	 * Register the routes.
	 */
	public static function routes(): void {
		$uid  = '(?P<uid>[0-9A-HJKMNP-TV-Z]{26})';
		$args = array(
			'name'      => array(
				'type'     => 'string',
				'required' => true,
			),
			'category'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'condition' => array(
				'type'     => 'object',
				'required' => true,
			),
			'workspace' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/conditions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'list' ),
					'permission_callback' => array( self::class, 'allowed' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => static fn ( WP_REST_Request $r ) => self::respond( self::with_workspace( ( new SavedConditionStore() )->save( null, (string) $r['name'], (string) $r['category'], (array) $r['condition'] ), $r ), 201 ),
					'permission_callback' => array( self::class, 'allowed' ),
					'args'                => $args,
				),
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/conditions/' . $uid,
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => static fn ( WP_REST_Request $r ) => self::respond( self::with_workspace( ( new SavedConditionStore() )->save( (string) $r['uid'], (string) $r['name'], (string) $r['category'], (array) $r['condition'] ), $r ), 200 ),
					'permission_callback' => array( self::class, 'allowed' ),
					'args'                => $args,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static function ( WP_REST_Request $r ) {
						$result = ( new SavedConditionStore() )->delete( (string) $r['uid'] );
						return $result instanceof WP_Error ? $result : new WP_REST_Response( array( 'deleted' => true ) );
					},
					'permission_callback' => array( self::class, 'allowed' ),
				),
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/conditions/' . $uid . '/usage',
			array(
				'methods'             => 'GET',
				'callback'            => static fn ( WP_REST_Request $r ): WP_REST_Response => new WP_REST_Response( array( 'campaigns' => ( new SavedConditionStore() )->usage( (string) $r['uid'] ) ) ),
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
	}

	/**
	 * GET /conditions: the library; with ?campaign=, the ones that campaign can use (on
	 * a hub, those for every client and its own client's).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function list( WP_REST_Request $request ): WP_REST_Response {
		$all      = ( new SavedConditionStore() )->all();
		$campaign = (string) $request->get_param( 'campaign' );
		if ( '' !== $campaign ) {
			/**
			 * Filters the saved conditions a campaign can use. The hub keeps each client's
			 * to that client's campaigns (BR-07).
			 *
			 * @param array<int, array<string, mixed>> $all      Saved conditions.
			 * @param string                           $campaign Campaign ULID.
			 */
			$all = (array) apply_filters( 'campaignbandit_saved_conditions_for', $all, strtoupper( $campaign ) );
		}
		return new WP_REST_Response( array( 'conditions' => array_values( array_map( array( self::class, 'shape' ), $all ) ) ) );
	}

	/**
	 * Store the client a saved condition belongs to, when one was sent (hub).
	 *
	 * @param array<string, mixed>|WP_Error $saved   Saved condition.
	 * @param WP_REST_Request               $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function with_workspace( $saved, WP_REST_Request $request ) {
		if ( is_array( $saved ) && null !== $request->get_param( 'workspace' ) ) {
			$store = new SavedConditionStore();
			$store->set_workspace( (string) $saved['uid'], (int) $request->get_param( 'workspace' ) );
			$saved = $store->get( (string) $saved['uid'] ) ?? $saved;
		}
		return $saved;
	}

	/**
	 * A saved condition as the editor takes it.
	 *
	 * @param array<string, mixed> $saved Saved condition.
	 * @return array<string, mixed>
	 */
	public static function shape( array $saved ): array {
		return array(
			'id'        => $saved['uid'],
			'name'      => $saved['name'],
			'category'  => $saved['category'],
			'condition' => $saved['condition'],
			'workspace' => (int) ( $saved['workspace'] ?? 0 ),
		);
	}

	/**
	 * The saved condition, or the error.
	 *
	 * @param array<string, mixed>|WP_Error $result Result.
	 * @param int                           $status Status on success.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result, int $status ) {
		return $result instanceof WP_Error ? $result : new WP_REST_Response( self::shape( $result ), $status );
	}
}
