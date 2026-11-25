/**
 * Display conditions rule builder (BR-06, S4): groups of sentence-style rules.
 * Each row reads Type · Operator · Value. Paid rule types and group logic show
 * locked with the plan that unlocks them (FR-25).
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	ToggleControl,
	Flex,
	FlexBlock,
	FlexItem,
	ComboboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { HelpTip, RemoveButton } from './ui';
import { dependsOnSite, pagePath } from './site-rules';
import { ruleIsIncomplete } from './condition-rules';
import { SavedConditionRule, SaveAsSaved } from './saved-conditions';
import { useEntityRecords } from '@wordpress/core-data';
import { createContext, useContext, useState } from '@wordpress/element';

const config = window.campaignBanditEditor || { locked: {} };

/**
 * One site's own content, when rules are built for that site (a saved condition's site
 * definition on a hub, requirements/08 P4): its post types, categories and pages
 * instead of this site's. Null: this site's.
 */
export const SiteContent = createContext( null );

const isLocked = ( control ) => Boolean( config.locked?.[ control ] );
const pro = ( label, control ) =>
	isLocked( control )
		? `${ label } (${ __( 'Pro', 'campaign-bandit' ) })`
		: label;

export const RULE_TYPES = () => [
	{ value: 'page', label: __( 'Page', 'campaign-bandit' ) },
	{ value: 'path', label: __( 'URL path', 'campaign-bandit' ) },
	{ value: 'device', label: __( 'Device', 'campaign-bandit' ) },
	{
		value: 'param',
		label: pro(
			__( 'URL parameter', 'campaign-bandit' ),
			'url_parameters'
		),
		disabled: isLocked( 'url_parameters' ),
	},
	{
		value: 'country',
		label: pro( __( 'Country', 'campaign-bandit' ), 'country' ),
		disabled: isLocked( 'country' ),
	},
];

export const OPERATORS = () => ( {
	page: [
		[ 'is', __( 'is', 'campaign-bandit' ) ],
		[ 'is_not', __( 'is not', 'campaign-bandit' ) ],
	],
	path: [
		[ 'is', __( 'is', 'campaign-bandit' ) ],
		[ 'is_not', __( 'is not', 'campaign-bandit' ) ],
		[ 'starts_with', __( 'starts with', 'campaign-bandit' ) ],
		[ 'contains', __( 'contains', 'campaign-bandit' ) ],
		[ 'not_contains', __( 'does not contain', 'campaign-bandit' ) ],
		[ 'matches', __( 'matches (use * as a wildcard)', 'campaign-bandit' ) ],
	],
	param: [
		[ 'equals', __( 'equals', 'campaign-bandit' ) ],
		[ 'not_equals', __( 'does not equal', 'campaign-bandit' ) ],
		[ 'contains', __( 'contains', 'campaign-bandit' ) ],
		[ 'not_contains', __( 'does not contain', 'campaign-bandit' ) ],
		[ 'starts_with', __( 'starts with', 'campaign-bandit' ) ],
		[ 'ends_with', __( 'ends with', 'campaign-bandit' ) ],
		[ 'exists', __( 'is present', 'campaign-bandit' ) ],
		[ 'not_exists', __( 'is not present', 'campaign-bandit' ) ],
	],
	device: [
		[ 'is', __( 'is', 'campaign-bandit' ) ],
		[ 'is_not', __( 'is not', 'campaign-bandit' ) ],
	],
	country: [
		[ 'is', __( 'is', 'campaign-bandit' ) ],
		[ 'is_not', __( 'is not', 'campaign-bandit' ) ],
	],
} );

/**
 * A new rule of a type, with sensible starting values.
 *
 * @param {string} type Rule type.
 * @return {Object} Rule.
 */
export function newRule( type ) {
	switch ( type ) {
		case '':
			return { type: '' };
		case 'page':
			return { type, op: 'is', value: [ 'front' ] };
		case 'path':
			return { type, op: 'starts_with', value: '' };
		case 'device':
			return { type, op: 'is', value: [ 'mobile' ] };
		case 'param':
			return { type, name: 'utm_source', op: 'equals', value: '' };
		case 'country':
			return { type, op: 'is', value: [] };
		case 'condition':
			return { type, id: '', name: '', condition: { groups: [] } };
		default:
			return { type: 'page', op: 'is', value: [ 'front' ] };
	}
}

/**
 * Choose a page by typing its name (S4). A site can have thousands of pages, so this
 * searches rather than listing them: the old control loaded the first hundred, which
 * left the rest unselectable on a large site.
 *
 * On a hub building a saved condition's definition for one client site, the pages come
 * from what that site reported, so the list is filtered in place instead.
 *
 * @param {Object}                   props          Props.
 * @param {string}                   props.label    Field label.
 * @param {string}                   props.value    The chosen page's path.
 * @param {Array|null}               props.pages    A client site's pages, or null for this site's.
 * @param {(page: Object) => string} props.pathOf   Reads a page's path.
 * @param {(path: string) => void}   props.onChange Called with the chosen path.
 * @return {Element} The control.
 */
function PagePicker( { label, value, pages, pathOf, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	// This site's pages, narrowed by what has been typed. A client site's arrived whole.
	const { records: found } = useEntityRecords( 'postType', 'page', {
		per_page: 20,
		search: search || undefined,
		orderby: search ? 'relevance' : 'title',
		order: search ? undefined : 'asc',
		_fields: 'id,title,link',
		context: 'view',
	} );
	const source = pages
		? pages.filter( ( p ) =>
				`${ p.title?.rendered || '' } ${ pathOf( p ) }`
					.toLowerCase()
					.includes( search.toLowerCase() )
			)
		: found;

	const options = ( source || [] )
		.slice( 0, 20 )
		.map( ( p ) => ( {
			value: pathOf( p ),
			label: `${ p.title?.rendered || `#${ p.id }` } (${ pathOf( p ) })`,
		} ) )
		.filter( ( o ) => o.value );

	// Keep the chosen page visible even when it is outside the current results.
	if ( value && ! options.some( ( o ) => o.value === value ) ) {
		options.unshift( { value, label: value } );
	}

	return (
		<ComboboxControl
			label={ label }
			value={ value }
			options={ options }
			onChange={ ( path ) => onChange( path || '' ) }
			onFilterValueChange={ setSearch }
			help={ __( 'Start typing a page’s name.', 'campaign-bandit' ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * Value control for a page rule: a kind, plus a post type, term or parent page.
 *
 * @param {Object}                 props          Props.
 * @param {Object}                 props.rule     Rule.
 * @param {(rule: Object) => void} props.onChange Called with the new rule.
 */
function PageValue( { rule, onChange } ) {
	const value = ( rule.value || [] )[ 0 ] || 'front';
	const [ kind, ...rest ] = value.split( ':' );
	const arg = rest.join( ':' );
	const site = useContext( SiteContent );
	const { records: ownPages } = useEntityRecords( 'postType', 'page', {
		per_page: 100,
		_fields: 'id,title,link',
		context: 'view',
	} );
	const set = ( v ) => onChange( { ...rule, value: [ v ] } );
	// "Child of" stores the parent's path, so the rule carries across sites (decision 0021).
	const pathOf = ( page ) =>
		page?.path ?? pagePath( page?.link, config.homePath || '' );
	const pages = site
		? site.pages.map( ( p ) => ( {
				id: p.path,
				path: p.path,
				title: { rendered: p.title },
			} ) )
		: ownPages;
	const parent = /^\d+$/.test( arg )
		? pathOf( ( pages || [] ).find( ( p ) => String( p.id ) === arg ) )
		: arg;
	const postTypes = site ? site.postTypes : config.postTypes || [];
	const taxonomies = site ? site.taxonomies : config.taxonomies || [];
	const terms =
		site?.taxonomies.find( ( t ) => t.slug === arg.split( ':' )[ 0 ] )
			?.terms || null;

	return (
		<>
			<SelectControl
				label={ __( 'Page', 'campaign-bandit' ) }
				hideLabelFromVision
				value={ kind }
				options={ [
					{
						value: 'front',
						label: __( 'Front page', 'campaign-bandit' ),
					},
					{
						value: 'posts_page',
						label: __( 'Posts page', 'campaign-bandit' ),
					},
					{
						value: 'singular',
						label: __( 'Any single…', 'campaign-bandit' ),
					},
					{
						value: 'term',
						label: __( 'In a category or tag…', 'campaign-bandit' ),
					},
					{
						value: 'child_of',
						label: __( 'A child of the page…', 'campaign-bandit' ),
					},
					{
						value: 'archive',
						label: __( 'Any archive', 'campaign-bandit' ),
					},
					{
						value: 'search',
						label: __( 'Search results', 'campaign-bandit' ),
					},
					{
						value: '404',
						label: __( 'Page not found (404)', 'campaign-bandit' ),
					},
				] }
				onChange={ ( k ) => {
					if ( k === 'singular' ) {
						set( `singular:${ postTypes[ 0 ]?.slug || 'post' }` );
					} else if ( k === 'term' ) {
						set( `term:${ taxonomies[ 0 ]?.slug || 'category' }:` );
					} else if ( k === 'child_of' ) {
						set( `child_of:${ pathOf( pages?.[ 0 ] ) || '/' }` );
					} else {
						set( k );
					}
				} }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ kind === 'singular' && (
				<SelectControl
					label={ __( 'Post type', 'campaign-bandit' ) }
					help={ __(
						'Any single item of this type. To target one particular page, use a URL path rule instead.',
						'campaign-bandit'
					) }
					value={ arg }
					options={ postTypes.map( ( t ) => ( {
						value: t.slug,
						label: t.label,
					} ) ) }
					onChange={ ( t ) => set( `singular:${ t }` ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }
			{ kind === 'term' && (
				<Flex>
					<FlexItem>
						<SelectControl
							label={ __( 'Taxonomy', 'campaign-bandit' ) }
							value={ arg.split( ':' )[ 0 ] }
							options={ taxonomies.map( ( t ) => ( {
								value: t.slug,
								label: t.label,
							} ) ) }
							onChange={ ( t ) =>
								set(
									`term:${ t }:${ arg.split( ':' )[ 1 ] || '' }`
								)
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</FlexItem>
					<FlexBlock>
						{ terms ? (
							// The site's own terms to pick from.
							<SelectControl
								label={ __( 'Term', 'campaign-bandit' ) }
								value={ arg.split( ':' )[ 1 ] || '' }
								options={ [
									{
										value: '',
										label: __(
											'Choose…',
											'campaign-bandit'
										),
										disabled: true,
									},
									...terms.map( ( t ) => ( {
										value: t.slug,
										label: `${ t.name } (${ t.slug })`,
									} ) ),
									// The current value, when this site doesn't have it.
									...( arg.split( ':' )[ 1 ] &&
									! terms.some(
										( t ) =>
											t.slug === arg.split( ':' )[ 1 ]
									)
										? [
												{
													value: arg.split(
														':'
													)[ 1 ],
													label: sprintf(
														/* translators: %s: term slug. */
														__(
															'%s (not on this site)',
															'campaign-bandit'
														),
														arg.split( ':' )[ 1 ]
													),
												},
											]
										: [] ),
								] }
								onChange={ ( slug ) =>
									set(
										`term:${ arg.split( ':' )[ 0 ] }:${ slug }`
									)
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) : (
							<TextControl
								label={ __( 'Slug', 'campaign-bandit' ) }
								value={ arg.split( ':' )[ 1 ] || '' }
								onChange={ ( slug ) =>
									set(
										`term:${ arg.split( ':' )[ 0 ] }:${ slug.trim().toLowerCase() }`
									)
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
					</FlexBlock>
				</Flex>
			) }
			{ kind === 'child_of' && (
				<PagePicker
					label={ __( 'Parent page', 'campaign-bandit' ) }
					value={ parent }
					pages={ pages }
					pathOf={ pathOf }
					onChange={ ( path ) => set( `child_of:${ path }` ) }
				/>
			) }
		</>
	);
}

/**
 * The rule type picker, starting at "Select rule…".
 *
 * @param {Object}                 props              Props.
 * @param {string}                 props.value        Rule type, or '' when not chosen yet.
 * @param {(type: string) => void} props.onChange     Called with the chosen type.
 * @param {boolean}                [props.allowSaved] Offer saved conditions (not inside one).
 */
function RuleTypeSelect( { value, onChange, allowSaved = true } ) {
	return (
		<SelectControl
			label={ __( 'Rule', 'campaign-bandit' ) }
			hideLabelFromVision
			value={ value }
			options={ [
				{
					value: '',
					label: __( 'Select rule…', 'campaign-bandit' ),
					disabled: true,
				},
				...RULE_TYPES(),
				// A saved condition (BR-07); not inside another saved condition.
				...( allowSaved || value === 'condition'
					? [
							{
								value: 'condition',
								label: pro(
									__( 'Saved condition…', 'campaign-bandit' ),
									'saved_conditions'
								),
								disabled: isLocked( 'saved_conditions' ),
							},
						]
					: [] ),
			] }
			onChange={ onChange }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * One rule: the type, then the operator and value once a type is chosen.
 *
 * @param {Object}                 props              Props.
 * @param {Object}                 props.rule         Rule.
 * @param {(rule: Object) => void} props.onChange     Called with the new rule.
 * @param {boolean}                [props.allowSaved] Offer saved conditions (not inside one).
 */
export function RuleRow( { rule, onChange, allowSaved = true } ) {
	const operators = OPERATORS()[ rule.type ] || [];
	const list = Array.isArray( rule.value ) ? rule.value : [];
	const typeSelect = (
		<RuleTypeSelect
			value={ rule.type || '' }
			allowSaved={ allowSaved }
			onChange={ ( type ) => onChange( newRule( type ) ) }
		/>
	);
	if ( ! rule.type ) {
		return <div className="cbandit-rule">{ typeSelect }</div>;
	}
	if ( rule.type === 'condition' ) {
		return (
			<div className="cbandit-rule">
				{ typeSelect }
				<SavedConditionRule rule={ rule } onChange={ onChange } />
			</div>
		);
	}

	return (
		<div className="cbandit-rule">
			{ typeSelect }
			<SelectControl
				label={ __( 'Operator', 'campaign-bandit' ) }
				hideLabelFromVision
				value={ rule.op }
				options={ operators.map( ( [ value, label ] ) => ( {
					value,
					label,
				} ) ) }
				onChange={ ( op ) => onChange( { ...rule, op } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ rule.type === 'param' && (
				<div className="cbandit-sentence">
					<input
						className="cbandit-inline-text"
						aria-label={ __(
							'Parameter name (case-sensitive)',
							'campaign-bandit'
						) }
						placeholder="utm_source"
						value={ rule.name || '' }
						onChange={ ( e ) =>
							onChange( { ...rule, name: e.target.value.trim() } )
						}
					/>
					{ ! [ 'exists', 'not_exists' ].includes( rule.op ) && (
						<input
							className="cbandit-inline-text"
							aria-label={ __(
								'Value (any case)',
								'campaign-bandit'
							) }
							placeholder="google"
							value={ rule.value || '' }
							onChange={ ( e ) =>
								onChange( { ...rule, value: e.target.value } )
							}
						/>
					) }
					<HelpTip
						text={ __(
							'Parameter names are case-sensitive (UTM names are usually lowercase). Values match in any case.',
							'campaign-bandit'
						) }
					/>
				</div>
			) }
			{ rule.type === 'page' && (
				<PageValue rule={ rule } onChange={ onChange } />
			) }
			{ rule.type === 'path' && (
				<div className="cbandit-sentence">
					<input
						className="cbandit-inline-text"
						aria-label={ __( 'Path', 'campaign-bandit' ) }
						placeholder="/blog"
						value={ rule.value || '' }
						onChange={ ( e ) =>
							onChange( { ...rule, value: e.target.value } )
						}
					/>
					{ rule.op === 'matches' && (
						<HelpTip
							text={ __(
								'Use * for any characters, for example /blog/* or /shop/*/reviews.',
								'campaign-bandit'
							) }
						/>
					) }
				</div>
			) }
			{ rule.type === 'device' && (
				<div className="cbandit-sentence">
					{ [
						[ 'mobile', __( 'Mobile', 'campaign-bandit' ) ],
						[ 'tablet', __( 'Tablet', 'campaign-bandit' ) ],
						[ 'desktop', __( 'Desktop', 'campaign-bandit' ) ],
					].map( ( [ device, label ] ) => (
						<CheckboxControl
							key={ device }
							label={ label }
							checked={ list.includes( device ) }
							onChange={ ( on ) =>
								onChange( {
									...rule,
									value: on
										? [ ...list, device ]
										: list.filter( ( d ) => d !== device ),
								} )
							}
							__nextHasNoMarginBottom
						/>
					) ) }
				</div>
			) }
			{ rule.type === 'country' && (
				<div className="cbandit-sentence">
					<input
						className="cbandit-inline-text"
						aria-label={ __(
							'Country codes, comma separated',
							'campaign-bandit'
						) }
						placeholder="US, CA"
						value={ list.join( ', ' ) }
						onChange={ ( e ) =>
							onChange( {
								...rule,
								value: e.target.value
									.split( ',' )
									.map( ( c ) => c.trim().toUpperCase() )
									.filter( Boolean ),
							} )
						}
					/>
					<HelpTip
						text={ __(
							'Two-letter codes. Visitors whose country can’t be found never match “is”, and always match “is not”.',
							'campaign-bandit'
						) }
					/>
				</div>
			) }
			{ config.hub && dependsOnSite( rule ) && (
				<p className="cbandit-rule-note">
					{ __(
						'Depends on each site’s content. Checked when you publish.',
						'campaign-bandit'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * The divider between two rule sets: "or" (BR-06). A stored condition that
 * combines its sets with "all" reads "and".
 *
 * @param {Object} props       Props.
 * @param {string} props.logic 'any' or 'all'.
 */
function SetDivider( { logic } ) {
	return (
		<div className="cbandit-connector" aria-hidden="true">
			<span>
				{ logic === 'all'
					? __( 'and', 'campaign-bandit' )
					: __( 'or', 'campaign-bandit' ) }
			</span>
		</div>
	);
}

/**
 * The label above a rule: "Show the campaign if" for the first, then "And if"
 * (rules in a set all apply, BR-06), with a remove button. A stored set that
 * combines its rules with "any" reads "Or if".
 *
 * @param {Object}     props          Props.
 * @param {boolean}    props.first    First rule in its set.
 * @param {string}     props.logic    The set's logic: 'all' or 'any'.
 * @param {() => void} props.onRemove Called to remove the rule.
 */
function RuleLabel( { first, logic, onRemove } ) {
	let text = __( 'And if', 'campaign-bandit' );
	if ( first ) {
		text = __( 'Show the campaign if', 'campaign-bandit' );
	} else if ( logic === 'any' ) {
		text = __( 'Or if', 'campaign-bandit' );
	}
	return (
		<div className="cbandit-rule-label">
			<span>{ text }</span>
			<RemoveButton
				label={ __( 'Remove rule', 'campaign-bandit' ) }
				onClick={ onRemove }
			/>
		</div>
	);
}

/**
 * Display condition rules (BR-06), laid out like Block Visibility's rule sets: an
 * OR of ANDs. Each set lists rules that must all apply ("Show the campaign if",
 * "And if"); one set applying is enough; "Hide when rules apply" inverts the whole.
 *
 * @param {Object}                      props              Props.
 * @param {Object}                      props.condition    Condition (BR-06).
 * @param {(condition: Object) => void} props.onChange     Called with the new condition.
 * @param {boolean}                     [props.allowSaved] Offer saved conditions (not inside one).
 */
export function ConditionBuilder( { condition, onChange, allowSaved = true } ) {
	const c = {
		logic: 'any',
		invert: false,
		groups: [],
		...( condition || {} ),
	};
	const logicLocked = isLocked( 'condition_logic' );
	// New conditions are always saved as an OR of ANDs (BR-06).
	const setGroups = ( groups ) =>
		onChange( {
			...c,
			// A stored "all" between sets is kept; anything simpler becomes "any".
			logic: c.groups.length > 1 ? c.logic : 'any',
			groups,
		} );
	const setGroup = ( index, patch ) =>
		setGroups(
			c.groups.map( ( g, i ) => ( i === index ? { ...g, ...patch } : g ) )
		);
	// Rules that will be thrown away on save, and what that would leave behind.
	const ignored = c.groups.reduce(
		( total, g ) =>
			total + ( g.rules || [] ).filter( ruleIsIncomplete ).length,
		0
	);
	const kept = c.groups.reduce(
		( total, g ) =>
			total +
			( g.rules || [] ).filter( ( r ) => ! ruleIsIncomplete( r ) ).length,
		0
	);
	const removeRule = ( gi, ri ) => {
		const rules = c.groups[ gi ].rules.filter( ( _, i ) => i !== ri );
		setGroups(
			rules.length
				? c.groups.map( ( g, i ) => ( i === gi ? { ...g, rules } : g ) )
				: c.groups.filter( ( _, i ) => i !== gi )
		);
	};

	if ( c.groups.length === 0 ) {
		return (
			<>
				<p className="cbandit-everyone">
					{ __(
						'Everyone, on every page. Pick a rule to narrow it.',
						'campaign-bandit'
					) }
				</p>
				<RuleTypeSelect
					value=""
					allowSaved={ allowSaved }
					onChange={ ( type ) =>
						setGroups( [
							{ logic: 'all', rules: [ newRule( type ) ] },
						] )
					}
				/>
			</>
		);
	}

	const several = c.groups.length > 1;

	return (
		<>
			{ ignored > 0 && (
				<p className="cbandit-note cbandit-note--warning">
					{ kept === 0
						? __(
								'A rule has no value yet, so it will be ignored — and with nothing left, this campaign would show on every page. Fill it in or remove it.',
								'campaign-bandit'
							)
						: __(
								'A rule has no value yet, so it will be ignored. The campaign will show more widely than these rules say.',
								'campaign-bandit'
							) }
				</p>
			) }
			{ c.groups.map( ( group, gi ) => (
				<div key={ gi }>
					{ gi > 0 && <SetDivider logic={ c.logic } /> }
					<fieldset className="cbandit-group">
						{ several ? (
							<legend className="cbandit-set-title">
								<span>
									{ sprintf(
										/* translators: %d: rule set number. */
										__( 'Rule set %d', 'campaign-bandit' ),
										gi + 1
									) }
								</span>
								<RemoveButton
									label={ __(
										'Remove rule set',
										'campaign-bandit'
									) }
									onClick={ () =>
										setGroups(
											c.groups.filter(
												( _, i ) => i !== gi
											)
										)
									}
								/>
							</legend>
						) : (
							<legend className="screen-reader-text">
								{ __( 'Rule set', 'campaign-bandit' ) }
							</legend>
						) }
						{ group.rules.map( ( rule, ri ) => (
							<div key={ ri } className="cbandit-rule-wrap">
								<RuleLabel
									first={ ri === 0 }
									logic={ group.logic || 'all' }
									onRemove={ () => removeRule( gi, ri ) }
								/>
								<RuleRow
									rule={ rule }
									allowSaved={ allowSaved }
									onChange={ ( next ) =>
										setGroup( gi, {
											rules: group.rules.map( ( r, i ) =>
												i === ri ? next : r
											),
										} )
									}
								/>
							</div>
						) ) }
						<div className="cbandit-actions">
							<Button
								variant="link"
								onClick={ () =>
									setGroup( gi, {
										rules: [
											...group.rules,
											newRule( '' ),
										],
									} )
								}
							>
								{ __( 'Add rule', 'campaign-bandit' ) }
							</Button>
						</div>
					</fieldset>
				</div>
			) ) }
			<div className="cbandit-actions">
				<Button
					variant="link"
					disabled={ logicLocked }
					onClick={ () =>
						setGroups( [
							...c.groups,
							{ logic: 'all', rules: [ newRule( '' ) ] },
						] )
					}
				>
					{ pro(
						__( 'Add rule set', 'campaign-bandit' ),
						'condition_logic'
					) }
				</Button>
				<HelpTip
					text={ __(
						'All rules in a set must apply. Add another set for an alternative: the campaign shows when any one set applies.',
						'campaign-bandit'
					) }
				/>
				{ allowSaved && (
					<SaveAsSaved condition={ c } onChange={ onChange } />
				) }
			</div>
			<ToggleControl
				label={ pro(
					__( 'Hide when rules apply', 'campaign-bandit' ),
					'condition_logic'
				) }
				disabled={ logicLocked }
				checked={ Boolean( c.invert ) }
				onChange={ ( invert ) => onChange( { ...c, invert } ) }
				__nextHasNoMarginBottom
			/>
		</>
	);
}
