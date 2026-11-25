<?php
/**
 * The new-lead email (FR-56): subject, HTML and plain text.
 *
 * Plain and quiet: one column, system fonts, no images, no tracking. Built from data the
 * caller gathers and translates, so it is WordPress-free and unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Notifications;

/**
 * Builds the email.
 */
final class LeadEmail {

	/**
	 * Words used in the email; the caller passes translated ones.
	 */
	private const STRINGS = array(
		'subject'   => 'New lead: %1$s · %2$s',
		'intro'     => 'New lead from %s',
		'consent'   => 'Consent',
		'given'     => 'Yes',
		'not_given' => 'No',
		'page'      => 'Page',
		'submitted' => 'Submitted',
		'first'     => 'First visit',
		'this'      => 'This visit',
		'open'      => 'Open lead',
		'reply'     => 'Reply to this email to answer %s directly.',
		'no_email'  => 'This lead left no email address.',
		'sent_by'   => 'Sent by Campaign Bandit on %s.',
		'change'    => 'Change who gets these emails in Campaign Bandit › Settings.',
	);

	/**
	 * Build the email.
	 *
	 * @param array<string, mixed>  $lead {
	 *      The lead, ready to show.
	 *
	 *     @type string                                            $campaign  Campaign name.
	 *     @type string                                            $site      Site name.
	 *     @type string                                            $host      Site address without scheme.
	 *     @type string                                            $client    Client name on a hub, or ''.
	 *     @type string                                            $name      The lead's name, or ''.
	 *     @type array<int, array{0: string, 1: string}>           $fields    Label and value pairs.
	 *     @type array<int, array{0: string, 1: bool, 2: string}>  $consents  Purpose name, given, text.
	 *     @type string                                            $page      Page path.
	 *     @type string                                            $submitted Time in the site's timezone.
	 *     @type string                                            $first     First visit's source, or ''.
	 *     @type string                                            $last      This visit's source, or ''.
	 *     @type string                                            $url       Link to the lead in the admin.
	 *     @type string                                            $reply_to  The lead's email address, or ''.
	 * }
	 * @param array<string, string> $strings Translated words, by the keys in STRINGS.
	 * @return array{subject: string, html: string, text: string, reply_to: string}
	 */
	public static function build( array $lead, array $strings = array() ): array {
		$t        = $strings + self::STRINGS;
		$campaign = (string) ( $lead['campaign'] ?? '' );
		$site     = (string) ( $lead['site'] ?? '' );
		$who      = '' !== (string) ( $lead['name'] ?? '' ) ? (string) $lead['name'] : (string) ( $lead['reply_to'] ?? '' );
		$intro    = sprintf( $t['intro'], $campaign ) . ( '' !== (string) ( $lead['client'] ?? '' ) ? ' · ' . $lead['client'] : '' );
		$reply    = (string) ( $lead['reply_to'] ?? '' );
		$footer   = array(
			sprintf( $t['sent_by'], (string) ( $lead['host'] ?? '' ) ),
			'' !== $reply ? sprintf( $t['reply'], '' !== $who ? $who : $reply ) : $t['no_email'],
			$t['change'],
		);
		$meta     = array_filter(
			array(
				array( $t['page'], (string) ( $lead['page'] ?? '' ) ),
				array( $t['submitted'], (string) ( $lead['submitted'] ?? '' ) ),
				array( $t['first'], (string) ( $lead['first'] ?? '' ) ),
				array( $t['this'], (string) ( $lead['last'] ?? '' ) ),
			),
			static fn ( array $row ): bool => '' !== $row[1]
		);
		$fields   = array_values( (array) ( $lead['fields'] ?? array() ) );
		$consents = array_values( (array) ( $lead['consents'] ?? array() ) );

		// Plain text.
		$text = $site . "\n" . $intro . "\n\n";
		foreach ( $fields as $field ) {
			$text .= $field[0] . ': ' . $field[1] . "\n";
		}
		if ( array() !== $consents ) {
			$text .= "\n" . $t['consent'] . "\n";
			foreach ( $consents as $consent ) {
				$text .= $consent[0] . ': ' . ( $consent[1] ? $t['given'] : $t['not_given'] ) . ( '' !== $consent[2] ? ' — "' . $consent[2] . '"' : '' ) . "\n";
			}
		}
		$text .= "\n";
		foreach ( $meta as $row ) {
			$text .= $row[0] . ': ' . $row[1] . "\n";
		}
		$text .= "\n" . $t['open'] . ': ' . (string) ( $lead['url'] ?? '' ) . "\n\n" . implode( "\n", $footer ) . "\n";

		// HTML: tables and inline styles, for mail clients.
		$e     = static fn ( string $value ): string => htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$row   = static fn ( string $label, string $value ): string => '<tr><td style="padding:6px 12px 6px 0;color:#50575e;vertical-align:top;white-space:nowrap">' . $label . '</td><td style="padding:6px 0;color:#1d2327;vertical-align:top;word-break:break-word">' . $value . '</td></tr>';
		$value = static function ( string $label, string $raw ) use ( $e ): string {
			if ( false !== filter_var( $raw, FILTER_VALIDATE_EMAIL ) ) {
				return '<a href="mailto:' . $e( $raw ) . '" style="color:#1d2327">' . $e( $raw ) . '</a>';
			}
			if ( 1 === preg_match( '/^\+?[0-9 ().\-]{7,}$/', $raw ) ) {
				return '<a href="tel:' . $e( (string) preg_replace( '/[^0-9+]/', '', $raw ) ) . '" style="color:#1d2327;display:inline-block;padding:6px 0">' . $e( $raw ) . '</a>';
			}
			return nl2br( $e( $raw ) );
		};

		$html  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="color-scheme" content="light dark"><title>' . $e( sprintf( $t['subject'], $campaign, $site ) ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f6f7f7">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f7"><tr><td align="center" style="padding:24px 12px">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #dcdcde;border-radius:2px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327">';
		$html .= '<tr><td style="padding:20px 24px 4px"><div style="font-size:18px;font-weight:600">' . $e( $site ) . '</div><div style="color:#50575e">' . $e( $intro ) . '</div></td></tr>';
		$html .= '<tr><td style="padding:12px 24px"><table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">';
		foreach ( $fields as $field ) {
			$html .= $row( $e( $field[0] ), $value( $field[0], (string) $field[1] ) );
		}
		$html .= '</table></td></tr>';
		if ( array() !== $consents ) {
			$html .= '<tr><td style="padding:4px 24px 12px"><div style="border:1px solid #dcdcde;border-radius:2px;padding:10px 12px;font-size:14px"><div style="font-weight:600;margin-bottom:4px">' . $e( $t['consent'] ) . '</div>';
			foreach ( $consents as $consent ) {
				$html .= '<div style="margin:2px 0">' . $e( $consent[0] ) . ': <strong>' . $e( $consent[1] ? $t['given'] : $t['not_given'] ) . '</strong>' . ( '' !== $consent[2] ? ' <span style="color:#50575e">— “' . $e( $consent[2] ) . '”</span>' : '' ) . '</div>';
			}
			$html .= '</div></td></tr>';
		}
		if ( array() !== $meta ) {
			$html .= '<tr><td style="padding:0 24px 12px;font-size:13px;color:#50575e"><table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse">';
			foreach ( $meta as $item ) {
				$html .= $row( $e( $item[0] ), $e( $item[1] ) );
			}
			$html .= '</table></td></tr>';
		}
		$html .= '<tr><td style="padding:8px 24px 20px"><a href="' . $e( (string) ( $lead['url'] ?? '' ) ) . '" style="display:inline-block;padding:12px 20px;background:#2271b1;color:#ffffff;text-decoration:none;border-radius:2px;font-weight:600">' . $e( $t['open'] ) . '</a></td></tr>';
		$html .= '</table>';
		$html .= '<p style="max-width:600px;margin:12px auto 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#646970">' . implode( '<br>', array_map( $e, $footer ) ) . '</p>';
		$html .= '</td></tr></table></body></html>';

		return array(
			'subject'  => sprintf( $t['subject'], $campaign, $site ),
			'html'     => $html,
			'text'     => $text,
			'reply_to' => false !== filter_var( $reply, FILTER_VALIDATE_EMAIL ) ? $reply : '',
		);
	}
}
