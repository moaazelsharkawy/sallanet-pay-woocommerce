( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var el = window.wp && window.wp.element && window.wp.element.createElement;
	var decode = window.wp && window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	if ( ! registry || ! settingsApi || ! el ) {
		return;
	}
	var settings = settingsApi.getSetting( 'sallanet_pay_data', {} );
	var title = decode( settings.title || 'sallanet Pay' );
	var Content = function () {
		return el( 'div', null, decode( settings.description || '' ) );
	};
	var Label = function () {
		return el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
			settings.icon ? el( 'img', { src: settings.icon, alt: '', width: 24, height: 24 } ) : null,
			title
		);
	};
	registry.registerPaymentMethod( {
		name: 'sallanet_pay',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] }
	} );
} )();
