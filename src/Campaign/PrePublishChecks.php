<?php
/**
 * Pre-publish and on-save checks (FR-32). Errors block publishing; warnings don't.
 *
 * WordPress-free: works on parsed blocks (the parse_blocks() shape) and settings. The
 * caller measures image files and passes the theme's colours, for the image weight
 * (BR-25) and contrast (05 §6) checks.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

use CampaignBandit\Forms\FormSchema;

/**
 * Runs the checks.
 */
final class PrePublishChecks {

	public const ERROR   = 'error';
	public const WARNING = 'warning';

	/**
	 * Run every check.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param CampaignSettings                 $settings Settings.
	 * @param callable|null                    $measure  fn( int $id, string $url ): ?array{name: string, bytes: int}, or null to skip image weight.
	 * @param array<string, string>            $palette  Theme colours by slug, for contrast.
	 * @return array<int, array{id: string, severity: string, message: string, args?: array<int, string|int|float>}>
	 */
	public static function run( array $blocks, CampaignSettings $settings, ?callable $measure = null, array $palette = array() ): array {
		$results = array();
		$flat    = self::flatten( $blocks );

		if ( array() === $flat ) {
			$results[] = self::result( 'empty', self::ERROR, 'Add some content.' );
		}

		if ( ! $settings->has_trigger() ) {
			$results[] = self::result( 'no_trigger', self::ERROR, 'Choose a trigger, such as on click, after a delay or on scroll.' );
		}

		$unsupported = array_values(
			array_unique(
				array_filter(
					array_map( static fn ( array $b ): string => (string) $b['blockName'], $flat ),
					static fn ( string $name ): bool => ! in_array( $name, BlockSet::allowed(), true )
				)
			)
		);
		if ( array() !== $unsupported ) {
			$results[] = self::result( 'unsupported_blocks', self::ERROR, 'Remove blocks campaigns can\'t use: ' . implode( ', ', $unsupported ) . '.' );
		}

		$form = FormSchema::from_blocks( $blocks );
		if ( 'form' === $settings->get( 'goal' ) && ! $form->has_form && array() !== $flat ) {
			$results[] = self::result( 'goal_needs_form', self::ERROR, 'The goal is "Form submitted", but the campaign has no form. Add a Form block or change the goal.' );
		}
		foreach ( $form->problems() as $problem ) {
			$results[] = self::result( $problem['id'], self::ERROR, $problem['message'] );
		}

		$missing_alt = 0;
		foreach ( $flat as $block ) {
			if ( 'core/image' === $block['blockName'] && ! self::has_alt( $block ) ) {
				++$missing_alt;
			}
		}
		if ( $missing_alt > 0 ) {
			$results[] = self::result( 'missing_alt', self::WARNING, sprintf( '%d image(s) have no alternative text.', $missing_alt ) );
		}

		// Campaign buttons (FR-23): Call and Text us need a number; a button goal needs a button.
		$buttons  = array_map( static fn ( array $b ): array => (array) ( $b['attrs'] ?? array() ), array_filter( $flat, static fn ( array $b ): bool => 'core/button' === $b['blockName'] ) );
		$messages = array(
			'button_no_phone' => 'A Call or Text us button has no phone number.',
			'goal_no_button'  => 'The goal is "Button clicked", but no button is marked "Counts as the goal".',
		);
		foreach ( ButtonActions::problems( array_values( $buttons ), (string) $settings->get( 'goal' ) ) as $problem ) {
			$results[] = self::result( $problem['id'], $problem['severity'], $messages[ $problem['id'] ] );
		}

		// Copy variables (FR-26): a token nothing defines is shown to visitors as typed.
		$undefined = CopyVariables::undefined( self::text( $blocks ), (array) $settings->get( 'variables' ) );
		if ( array() !== $undefined ) {
			$names     = implode( ', ', array_map( static fn ( string $n ): string => '{{' . $n . '}}', array_slice( $undefined, 0, 5 ) ) );
			$results[] = self::result( 'undefined_variables', self::WARNING, sprintf( 'The text uses %s, which this campaign doesn’t define. Visitors will see it as written.', $names ), array( $names ) );
		}

		// Image weight (BR-25): the heaviest few, then the total.
		if ( null !== $measure ) {
			$files = array();
			foreach ( ImageWeight::images( $flat ) as $image ) {
				$file = $measure( $image['id'], $image['url'] );
				if ( is_array( $file ) ) {
					$files[] = $file;
				}
			}
			$weight = ImageWeight::check( $files );
			foreach ( array_slice( $weight['heavy'], 0, 3 ) as $i => $file ) {
				$results[] = self::result( 'image_heavy-' . $i, self::WARNING, sprintf( 'Image %1$s is %2$d KB. Keep each image under 200 KB, so the page stays fast.', $file['name'], ImageWeight::kb( $file['bytes'] ) ), array( $file['name'], ImageWeight::kb( $file['bytes'] ) ) );
			}
			// With one image, its own warning already says it all.
			if ( $weight['over_total'] && count( $files ) > 1 ) {
				$results[] = self::result( 'image_total', self::WARNING, sprintf( 'The campaign’s images weigh %d KB together. Keep them under 500 KB.', ImageWeight::kb( $weight['total'] ) ), array( ImageWeight::kb( $weight['total'] ) ) );
			}
		}

		// Contrast (05 §6): the campaign's own colours, a few at most.
		$seen = array();
		foreach ( Contrast::problems( $blocks, $palette ) as $problem ) {
			$key = $problem['block'] . $problem['text'] . $problem['background'];
			if ( isset( $seen[ $key ] ) || count( $seen ) >= 3 ) {
				continue;
			}
			$label        = ucfirst( str_replace( '-', ' ', (string) substr( (string) strrchr( '/' . $problem['block'], '/' ), 1 ) ) );
			$results[]    = self::result( 'contrast-' . count( $seen ), self::WARNING, sprintf( 'Text in a %1$s block is hard to read: %2$s:1 against its background (needs %3$s:1).', $label, $problem['ratio'], $problem['needed'] ), array( $label, $problem['ratio'], $problem['needed'] ) );
			$seen[ $key ] = true;
		}

		return $results;
	}

	/**
	 * Every piece of text in the blocks: their markup and their string attributes, such
	 * as a button's URL or a field's placeholder. Used to find copy variable tokens.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private static function text( array $blocks ): string {
		$text = '';
		foreach ( self::flatten( $blocks ) as $block ) {
			$text .= (string) ( $block['innerHTML'] ?? '' );
			foreach ( (array) ( $block['attrs'] ?? array() ) as $value ) {
				if ( is_string( $value ) ) {
					$text .= ' ' . $value;
				}
			}
		}
		return $text;
	}

	/**
	 * Whether any result blocks publishing.
	 *
	 * @param array<int, array{id: string, severity: string, message: string}> $results Results.
	 */
	public static function has_errors( array $results ): bool {
		foreach ( $results as $result ) {
			if ( self::ERROR === $result['severity'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every block with a name, including inner blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$out[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten( $block['innerBlocks'] ) );
			}
		}
		return $out;
	}

	/**
	 * Whether an image block has alternative text (attribute or markup).
	 *
	 * @param array<string, mixed> $block Image block.
	 */
	private static function has_alt( array $block ): bool {
		if ( '' !== trim( (string) ( $block['attrs']['alt'] ?? '' ) ) ) {
			return true;
		}
		return 1 === preg_match( '/<img[^>]+alt="[^"]+"/', (string) ( $block['innerHTML'] ?? '' ) );
	}

	/**
	 * One result.
	 *
	 * @param string                       $id       Check ID.
	 * @param string                       $severity error or warning.
	 * @param string                       $message  Message for the editor.
	 * @param array<int, string|int|float> $args     Details for the translated message.
	 * @return array{id: string, severity: string, message: string, args?: array<int, string|int|float>}
	 */
	private static function result( string $id, string $severity, string $message, array $args = array() ): array {
		$result = array(
			'id'       => $id,
			'severity' => $severity,
			'message'  => $message,
		);
		if ( array() !== $args ) {
			$result['args'] = $args;
		}
		return $result;
	}
}
