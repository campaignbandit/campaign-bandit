<?php
/**
 * The plan this install runs on.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Licensing;

/**
 * Free, Pro, Agency or Agency Plus. The free plugin always reports Free;
 * the add-on supplies the licensed plan through the `campaignbandit_plan` filter.
 */
enum Plan: string {

	case Free       = 'free';
	case Pro        = 'pro';
	case Agency     = 'agency';
	case AgencyPlus = 'agency_plus';

	/**
	 * The plan for this install.
	 */
	public static function current(): Plan {
		/**
		 * Filters the plan this install runs on.
		 *
		 * @param string $plan Plan value. Default 'free'.
		 */
		$value = apply_filters( 'campaignbandit_plan', self::Free->value );
		return self::tryFrom( is_string( $value ) ? $value : '' ) ?? self::Free;
	}

	/**
	 * Whether this plan includes everything in another plan.
	 *
	 * @param Plan $other Plan to compare with.
	 */
	public function includes( Plan $other ): bool {
		return $this->rank() >= $other->rank();
	}

	/**
	 * Whether this plan runs a hub.
	 */
	public function is_hub_plan(): bool {
		return $this->includes( self::Agency );
	}

	/**
	 * Order of plans, lowest first.
	 */
	private function rank(): int {
		return match ( $this ) {
			self::Free       => 0,
			self::Pro        => 1,
			self::Agency     => 2,
			self::AgencyPlus => 3,
		};
	}
}
