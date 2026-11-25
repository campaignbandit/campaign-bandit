/**
 * Campaign forms in the browser (FR-23, 05 §6): validate, show errors next to each
 * field and announce them, send to this site's own endpoint (FR-50), then show the
 * success state, or keep the visitor's entries when sending fails.
 */

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/**
 * Today in the visitor's timezone, `YYYY-MM-DD`.
 *
 * @param {Date} [now] Now.
 * @return {string} Date.
 */
export function today( now = new Date() ) {
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad(
		now.getDate()
	) }`;
}

/**
 * The earliest and latest dates a Date field allows (DateRange on the server).
 *
 * @param {{type: string, days: number, from: string, to: string}} range Range.
 * @param {string}                                                 day   Today, `YYYY-MM-DD`.
 * @return {{min: string, max: string}} Limits; '' for none.
 */
export function dateLimits( range, day ) {
	if ( range.type === 'not_past' ) {
		return { min: day, max: '' };
	}
	if ( range.type === 'within' ) {
		const end = new Date( day + 'T12:00:00Z' );
		end.setUTCDate( end.getUTCDate() + ( Number( range.days ) || 0 ) );
		return { min: day, max: end.toISOString().slice( 0, 10 ) };
	}
	if ( range.type === 'between' ) {
		return { min: range.from || '', max: range.to || '' };
	}
	return { min: '', max: '' };
}

/**
 * A Date field's range, from its data attributes.
 *
 * @param {Element} el Input.
 * @return {{type: string, days: number, from: string, to: string}} Range.
 */
function rangeOf( el ) {
	return {
		type: el.getAttribute( 'data-date-range' ) || 'any',
		days: Number( el.getAttribute( 'data-date-days' ) ) || 0,
		from: el.getAttribute( 'data-date-from' ) || '',
		to: el.getAttribute( 'data-date-to' ) || '',
	};
}

/**
 * Validate one value the way the server does (SubmissionValidator).
 *
 * @param {string}                  type     Field type.
 * @param {string|string[]|boolean} value    Value.
 * @param {boolean}                 required Whether the field is required.
 * @param {Object}                  [limits] Date fields: {min, max}.
 * @return {string|null} Error code, or null.
 */
export function fieldError( type, value, required, limits = null ) {
	const empty =
		value === false ||
		( Array.isArray( value )
			? value.length === 0
			: String( value ).trim() === '' );
	if ( empty ) {
		return required ? 'required' : null;
	}
	if ( type === 'email' && ! EMAIL.test( String( value ).trim() ) ) {
		return 'email';
	}
	if ( type === 'date' ) {
		const text = String( value ).trim();
		const parsed = new Date( text + 'T12:00:00Z' );
		if (
			! /^\d{4}-\d{2}-\d{2}$/.test( text ) ||
			isNaN( parsed ) ||
			parsed.toISOString().slice( 0, 10 ) !== text
		) {
			return 'date';
		}
		// YYYY-MM-DD dates compare correctly as strings.
		if (
			limits &&
			( ( limits.min && text < limits.min ) ||
				( limits.max && text > limits.max ) )
		) {
			return 'date_range';
		}
	}
	if ( type === 'phone' ) {
		const text = String( value ).trim();
		const digits = ( text.match( /\d/g ) || [] ).length;
		if ( ! /^[0-9+\-().\s]+$/.test( text ) || digits < 6 || digits > 20 ) {
			return 'phone';
		}
	}
	return null;
}

/**
 * Read every field in a form: key → { type, value, required, element, error }.
 *
 * @param {HTMLFormElement} form Form.
 * @return {Object<string, Object>} Fields.
 */
export function readFields( form ) {
	const fields = {};
	form.querySelectorAll( '[data-field-type]' ).forEach( ( el ) => {
		const key = el.getAttribute( 'data-field-key' );
		const type = el.getAttribute( 'data-field-type' );
		const errorId = el.getAttribute( 'aria-describedby' );
		const error = errorId ? form.querySelector( `#${ errorId }` ) : null;
		let value;
		let required;
		if ( type === 'choice' && el.tagName === 'SELECT' ) {
			value = el.value;
			required = el.required;
		} else if ( type === 'choice' ) {
			const picked = [ ...el.querySelectorAll( 'input:checked' ) ].map(
				( i ) => i.value
			);
			const multiple = !! el.querySelector( 'input[type="checkbox"]' );
			value = multiple ? picked : picked[ 0 ] || '';
			required = el.hasAttribute( 'data-required' );
		} else if ( type === 'consent' ) {
			value = el.checked;
			required = el.required;
		} else {
			value = el.value;
			required = el.required;
		}
		fields[ key ] = { type, value, required, element: el, error };
	} );
	return fields;
}

/**
 * The control that takes focus and aria-invalid: the first option of a group of
 * radio buttons or checkboxes, otherwise the field itself.
 *
 * @param {Object} field Field from readFields().
 * @return {Element|null} Control.
 */
function focusable( field ) {
	return field.type === 'choice' && field.element.tagName !== 'SELECT'
		? field.element.querySelector( 'input' )
		: field.element;
}

/**
 * Show or clear a field's error (linked by aria-describedby, announced by the status line).
 *
 * @param {Object}      field   Field from readFields().
 * @param {string|null} message Message, or null to clear.
 */
function setError( field, message ) {
	const target = focusable( field );
	if ( message ) {
		target?.setAttribute( 'aria-invalid', 'true' );
		if ( field.error ) {
			field.error.textContent = message;
			field.error.hidden = false;
		}
	} else {
		target?.removeAttribute( 'aria-invalid' );
		if ( field.error ) {
			field.error.textContent = '';
			field.error.hidden = true;
		}
	}
}

/**
 * Handle submits for every campaign form on the page.
 *
 * @param {Object}               options           Options.
 * @param {Document}             options.doc       Document.
 * @param {Window}               options.win       Window.
 * @param {Object}               options.config    Runtime configuration (rest, messages).
 * @param {() => Object}         options.context   Returns { params, device, pageGroup, explanation( id ) }.
 * @param {(id: string) => void} options.onSuccess Called with the campaign ID after a lead is stored.
 */
/**
 * The Success screen for a form: the nearest one beside the form or one of its
 * containers, within the campaign.
 *
 * @param {Element} form Form.
 * @return {Element|null} Success screen.
 */
export function successScreen( form ) {
	const root = form.closest( '[data-cbandit-campaign]' );
	for ( let el = form; el && el !== root; el = el.parentElement ) {
		const parent = el.parentElement;
		const found = parent
			? [ ...parent.children ].find(
					( child ) =>
						child !== el && child.matches( '[data-cbandit-after]' )
				)
			: null;
		if ( found ) {
			return found;
		}
	}
	return null;
}

export function attachForms( { doc, win, config, context, onSuccess } ) {
	const messages = config.messages || {};
	const text = ( code ) =>
		messages[ code ] ||
		{
			required: 'This field is required.',
			email: 'Enter a valid email address.',
			phone: 'Enter a valid phone number.',
			date: 'Enter a valid date.',
			date_range: 'Choose a date in the range allowed.',
			check: 'Please check the highlighted fields.',
			failed: 'Your details could not be sent. Please try again.',
			sending: 'Sending…',
		}[ code ];

	// The date picker offers only the dates allowed: min and max for today, set when
	// the field is used (the page may have been cached on an earlier day).
	doc.addEventListener( 'focusin', ( event ) => {
		const el = event.target;
		if ( el?.matches?.( 'input[type="date"][data-date-range]' ) ) {
			const limits = dateLimits( rangeOf( el ), today() );
			[ 'min', 'max' ].forEach( ( name ) =>
				limits[ name ]
					? el.setAttribute( name, limits[ name ] )
					: el.removeAttribute( name )
			);
		}
	} );

	doc.addEventListener( 'submit', async ( event ) => {
		const form = event.target;
		if ( ! form?.matches?.( 'form[data-cbandit-form]' ) ) {
			return;
		}
		event.preventDefault();

		const status = form.querySelector( '.cbandit-form-status' );
		const fields = readFields( form );

		let firstInvalid = null;
		Object.values( fields ).forEach( ( field ) => {
			const code = fieldError(
				field.type,
				field.value,
				field.required,
				field.type === 'date'
					? dateLimits( rangeOf( field.element ), today() )
					: null
			);
			setError( field, code ? text( code ) : null );
			if ( code && ! firstInvalid ) {
				firstInvalid = field;
			}
		} );
		if ( firstInvalid ) {
			if ( status ) {
				status.textContent = text( 'check' );
			}
			focusable( firstInvalid )?.focus();
			return;
		}

		const host = form.closest( '[data-cbandit-campaign]' );
		const campaign = host?.getAttribute( 'data-cbandit-campaign' );
		const version = Number( host?.getAttribute( 'data-cbandit-version' ) );
		const ctx = context();
		const values = {};
		Object.entries( fields ).forEach( ( [ key, field ] ) => {
			values[ key ] = field.value;
		} );
		const shownAt =
			Number( host?.getAttribute( 'data-cbandit-shown' ) ) || 0;
		const button = form.querySelector( 'button[type="submit"]' );

		if ( button ) {
			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
		}
		if ( status ) {
			status.textContent = text( 'sending' );
		}

		let response;
		let body = {};
		if ( config.previewMode ) {
			// A preview sends nothing: it shows the thank-you a real visitor would see.
			response = { ok: true, status: 201 };
		} else {
			try {
				response = await win.fetch( config.rest + 'leads', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						campaign,
						version,
						fields: values,
						hp:
							form.querySelector( '[name="cbandit_hp"]' )
								?.value || '',
						elapsed: shownAt ? Date.now() - shownAt : 0,
						page_url: win.location.href,
						utm: ctx.params,
						source: ctx.source?.() || {},
						device: ctx.device,
						pageGroup: ctx.pageGroup,
						explanation: ctx.explanation( campaign ),
					} ),
				} );
				body = await response.json().catch( () => ( {} ) );
			} catch {
				response = null;
			}
		}

		if ( button ) {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
		}

		if ( response && response.ok ) {
			// Stored on the site. A Success screen beside the form (or further up)
			// replaces the blocks around it; otherwise the form's message replaces it.
			const after = successScreen( form );
			if ( after ) {
				[ ...after.parentElement.children ].forEach( ( el ) => {
					el.hidden = el !== after;
				} );
				after.focus?.( { preventScroll: true } );
			} else {
				const success = form.parentElement?.querySelector(
					'[data-cbandit-success]'
				);
				form.hidden = true;
				if ( success ) {
					success.hidden = false;
				}
			}
			onSuccess( campaign );
			return;
		}

		// Not stored: keep the visitor's entries (FR-23, BR-40).
		const fieldErrors = body?.data?.fields || {};
		Object.entries( fieldErrors ).forEach( ( [ key, code ] ) => {
			if ( fields[ key ] ) {
				setError( fields[ key ], text( code ) );
			}
		} );
		if ( status ) {
			status.textContent = body?.message || text( 'failed' );
		}
	} );
}
