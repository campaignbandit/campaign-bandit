/**
 * "Explain this page" on Campaign detail (FR-34): renders the answer the server
 * prepared the data for. Plain DOM, no framework: an admin screen script.
 */
import { explainPage } from './explain.js';

/**
 * Put the results in their table.
 *
 * @param {Object}   data        What PHP printed.
 * @param {Document} [doc]       Document.
 * @param {Element}  [container] Where to render.
 */
export function render(
	data,
	doc = document,
	container = doc.getElementById( 'cbandit-explain-results' )
) {
	if ( ! container || ! data?.manifest ) {
		return;
	}
	const labels = data.labels || {};
	const rows = explainPage( data.manifest, data.visit );
	if ( rows.length === 0 ) {
		container.textContent = labels.none || '';
		return;
	}

	const table = doc.createElement( 'table' );
	table.className = 'wp-list-table widefat striped cbandit-explain-table';
	const head = doc.createElement( 'thead' );
	head.innerHTML =
		'<tr><th scope="col"></th><th scope="col"></th><th scope="col"></th></tr>';
	[ labels.campaign, labels.result, labels.why ].forEach( ( text, i ) => {
		head.querySelectorAll( 'th' )[ i ].textContent = text || '';
	} );
	const body = doc.createElement( 'tbody' );

	rows.forEach( ( row ) => {
		const tr = doc.createElement( 'tr' );
		if ( row.campaign === data.current ) {
			tr.className = 'is-current';
		}
		const name = doc.createElement( 'td' );
		const strong = doc.createElement( 'strong' );
		strong.textContent = row.name;
		name.append(
			strong,
			' ',
			labels.formats?.[ row.format ] || row.format
		);

		const result = doc.createElement( 'td' );
		const status = doc.createElement( 'span' );
		status.className =
			'cbandit-status cbandit-status--' +
			( row.outcome === 'shows' ? 'good' : 'neutral' );
		status.textContent = labels.outcomes?.[ row.outcome ] || row.outcome;
		result.append( status );

		const why = doc.createElement( 'td' );
		if ( row.outcome === 'shows' ) {
			why.textContent = row.opens
				.map( ( o ) =>
					( labels.opens?.[ o.type ] || o.type ).replace(
						'%s',
						String( o.value ?? '' )
					)
				)
				.join( ' · ' );
		} else if ( row.outcome === 'outranked' ) {
			why.textContent = ( labels.outrankedBy || '%s' ).replace(
				'%s',
				row.reason
			);
		} else if ( row.outcome === 'not_placed' ) {
			why.textContent = labels.notPlaced || '';
		} else {
			why.textContent = row.reason;
		}
		tr.append( name, result, why );
		body.append( tr );
	} );

	table.append( head, body );
	container.replaceChildren( table );
}

if ( typeof window !== 'undefined' && window.campaignBanditExplain ) {
	render( window.campaignBanditExplain );
}
