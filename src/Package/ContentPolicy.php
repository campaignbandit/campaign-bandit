<?php
/**
 * What campaign content may contain when it is displayed (05 §8).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Package;

/**
 * Post content markup plus the form elements Campaign Bandit's form blocks render.
 * Scripts, iframes and event-handler attributes are never allowed.
 */
final class ContentPolicy {

	/**
	 * Allowed tags and attributes for wp_kses().
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$common = array(
			'class'              => true,
			'id'                 => true,
			'name'               => true,
			'required'           => true,
			'aria-describedby'   => true,
			'aria-invalid'       => true,
			'aria-label'         => true,
			'aria-live'          => true,
			'aria-required'      => true,
			'data-cbandit-goal'  => true,
			'data-field-key'     => true,
			'data-field-type'    => true,
			'data-required'      => true,
			'data-cbandit-after' => true,
			'hidden'             => true,
			'tabindex'           => true,
			'aria-hidden'        => true,
			'role'               => true,
		);

		return array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'form'     => $common + array(
					'action'            => true,
					'method'            => true,
					'novalidate'        => true,
					'data-cbandit-form' => true,
				),
				'input'    => $common + array(
					'type'            => true,
					'value'           => true,
					'placeholder'     => true,
					'checked'         => true,
					'autocomplete'    => true,
					'inputmode'       => true,
					'tabindex'        => true,
					// Date fields: the dates allowed (FR-23).
					'min'             => true,
					'max'             => true,
					'data-date-range' => true,
					'data-date-days'  => true,
					'data-date-from'  => true,
					'data-date-to'    => true,
				),
				'select'   => $common + array( 'multiple' => true ),
				'option'   => array(
					'value'    => true,
					'selected' => true,
					'disabled' => true,
				),
				'textarea' => $common + array(
					'placeholder' => true,
					'rows'        => true,
					'maxlength'   => true,
				),
				'button'   => $common + array(
					'type'  => true,
					// The form's button colours (filtered by safecss_filter_attr).
					'style' => true,
				),
				'label'    => $common + array( 'for' => true ),
				'fieldset' => $common,
				'div'      => array_merge( (array) ( wp_kses_allowed_html( 'post' )['div'] ?? array() ), $common, array( 'data-cbandit-success' => true ) ),
				'p'        => array_merge( (array) ( wp_kses_allowed_html( 'post' )['p'] ?? array() ), $common ),
				'span'     => array_merge( (array) ( wp_kses_allowed_html( 'post' )['span'] ?? array() ), $common ),
				'legend'   => $common,
			)
		);
	}
}
