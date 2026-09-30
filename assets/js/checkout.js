/**
 * MobilePay in WooCommerce's checkout block. Plain JS, no build step.
 * The payment itself happens on the server (SMPW_Payments::start) and in the MobilePay app.
 */
( function () {
	'use strict';
	const registry = window.wc && window.wc.wcBlocksRegistry;
	const settings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settings || ! window.wp || ! window.wp.element ) {
		return;
	}
	const el = window.wp.element.createElement;
	const decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : ( text ) => text;
	const data = settings.getSetting( 'smpw_mobilepay_data', {} );
	const title = decode( data.title || 'MobilePay' );
	const countries = Array.isArray( data.countries ) ? data.countries : [ 'DK', 'FI' ];

	// The logo when the shop has added it (assets/img/mobilepay.svg), else the title as text.
	const Label = () =>
		el(
			'span',
			{ className: 'smpw-label' },
			data.logo ? el( 'img', { className: 'smpw-logo', src: data.logo, alt: title, height: 20 } ) : title
		);
	const Content = () => el( 'p', { className: 'smpw-description' }, decode( data.description || '' ) );

	registry.registerPaymentMethod( {
		name: 'smpw_mobilepay',
		label: el( Label ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Content ),
		placeOrderButtonLabel: data.button || 'Buy now with MobilePay',
		canMakePayment: ( args ) => {
			const address = ( args && ( args.billingAddress || args.billingData ) ) || {};
			return ! address.country || countries.indexOf( address.country ) !== -1;
		},
		supports: { features: Array.isArray( data.supports ) ? data.supports : [ 'products' ] },
	} );
} )();
