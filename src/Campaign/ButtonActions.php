<?php
/**
 * Campaign buttons (FR-23): a Button's action (go to its link, close the campaign,
 * call or text a number) and whether it counts as the goal (BR-15). The editor stores
 * them as block attributes; this turns them into the rendered link.
 *
 * The rules (action, phone, href) are WordPress-free and unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

/**
 * Button actions.
 */
final class ButtonActions {

	public const LINK  = 'link';
	public const CLOSE = 'close';
	public const CALL  = 'call';
	public const TEXT  = 'text';

	public const ACTIONS = array( self::LINK, self::CLOSE, self::CALL, self::TEXT );

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_filter( 'render_block_core/button', array( self::class, 'render' ), 10, 2 );
	}

	/**
	 * A button's action. The "Closes the campaign" style always closes.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	public static function action( array $attrs ): string {
		if ( 1 === preg_match( '/\bis-style-cbandit-dismiss\b/', (string) ( $attrs['className'] ?? '' ) ) ) {
			return self::CLOSE;
		}
		return in_array( $attrs['cbanditAction'] ?? null, self::ACTIONS, true ) ? $attrs['cbanditAction'] : self::LINK;
	}

	/**
	 * A number to dial: a leading + and digits only; '' when too short to dial.
	 *
	 * @param mixed $phone Number as typed.
	 */
	public static function phone( $phone ): string {
		$phone  = is_string( $phone ) ? trim( $phone ) : '';
		$digits = (string) preg_replace( '/\D/', '', $phone );
		if ( strlen( $digits ) < 3 ) {
			return '';
		}
		return ( str_starts_with( $phone, '+' ) ? '+' : '' ) . substr( $digits, 0, 20 );
	}

	/**
	 * The link for a Call or Text us button, or null for other actions or no number.
	 * `sms:NUMBER?&body=` opens the message on both iOS and Android.
	 *
	 * @param string $action  Action.
	 * @param string $phone   Number as typed.
	 * @param string $message Text us message.
	 */
	public static function href( string $action, string $phone, string $message = '' ): ?string {
		$number = self::phone( $phone );
		if ( '' === $number ) {
			return null;
		}
		if ( self::CALL === $action ) {
			return 'tel:' . $number;
		}
		if ( self::TEXT === $action ) {
			$message = trim( $message );
			return 'sms:' . $number . ( '' !== $message ? '?&body=' . rawurlencode( mb_substr( $message, 0, 300 ) ) : '' );
		}
		return null;
	}

	/**
	 * Problems for pre-publish checks (FR-32): a Call or Text us button without a
	 * number (error); goal "Button clicked" with no button counting as the goal (warning).
	 *
	 * @param array<int, array<string, mixed>> $buttons Attributes of every Button.
	 * @param string                           $goal    The campaign's goal.
	 * @return array<int, array{id: string, severity: string}>
	 */
	public static function problems( array $buttons, string $goal ): array {
		$problems = array();
		$counts   = false;
		foreach ( $buttons as $attrs ) {
			$action = self::action( $attrs );
			if ( in_array( $action, array( self::CALL, self::TEXT ), true ) && '' === self::phone( $attrs['cbanditPhone'] ?? '' ) ) {
				$problems['button_no_phone'] = array(
					'id'       => 'button_no_phone',
					'severity' => 'error',
				);
			}
			$counts = $counts || true === ( $attrs['cbanditGoal'] ?? false ) || 1 === preg_match( '/\bcbandit-goal\b/', (string) ( $attrs['className'] ?? '' ) );
		}
		if ( 'button' === $goal && ! $counts ) {
			$problems['goal_no_button'] = array(
				'id'       => 'goal_no_button',
				'severity' => 'warning',
			);
		}
		return array_values( $problems );
	}

	/**
	 * Write the action into a rendered Button: the link for Call and Text us, and data
	 * attributes the runtime reads (Close, the goal, and which links carry a number a
	 * site can change).
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function render( string $html, array $block ): string {
		$attrs  = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$action = self::action( $attrs );
		$goal   = true === ( $attrs['cbanditGoal'] ?? false );
		if ( ( self::LINK === $action && ! $goal ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'class_name' => 'wp-block-button__link' ) ) ) {
			return $html;
		}
		if ( self::LINK !== $action ) {
			$tags->set_attribute( 'data-cbandit-action', $action );
		}
		if ( $goal ) {
			$tags->set_attribute( 'data-cbandit-goal', 'true' );
		}
		$href = self::href( $action, (string) ( $attrs['cbanditPhone'] ?? '' ), (string) ( $attrs['cbanditMessage'] ?? '' ) );
		if ( null !== $href && 'A' === $tags->get_tag() ) {
			$tags->set_attribute( 'href', $href );
			$tags->remove_attribute( 'target' );
		} elseif ( self::CLOSE === $action && 'A' === $tags->get_tag() ) {
			$tags->set_attribute( 'href', '#' );
			$tags->set_attribute( 'role', 'button' );
		}
		return $tags->get_updated_html();
	}
}
