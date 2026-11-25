/**
 * Campaign Bandit admin screens: ask before deleting leads in bulk (S6).
 */
( function () {
	const form = document.querySelector( '.cbandit-leads-list form' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'submit', function ( event ) {
		const submitter = event.submitter;
		if ( ! submitter || ! /^doaction2?$/.test( submitter.id ) ) {
			return;
		}
		const select = form.querySelector(
			submitter.id === 'doaction2'
				? 'select[name="action2"]'
				: 'select[name="action"]'
		);
		const chosen = form.querySelectorAll( 'input[name="lead_ids[]"]:checked' );
		if (
			select &&
			select.value === 'delete' &&
			chosen.length &&
			! window.confirm( form.dataset.confirmDelete )
		) {
			event.preventDefault();
		}
	} );
} )();
