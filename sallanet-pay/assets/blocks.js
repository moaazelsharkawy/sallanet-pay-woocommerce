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

	// Same order as the server: same coin, fixed rate, then live USD price.
	function toCoin( amount, code ) {
		var c = settings.conversion;
		if ( ! c ) { return 0; }
		code = String( code || '' ).toUpperCase();
		var coins = 0;
		if ( code === String( c.coin ).toUpperCase() ) { coins = amount; }
		else if ( c.rates && c.rates[ code ] > 0 ) { coins = amount / c.rates[ code ]; }
		else if ( code === 'USD' && c.coin === 'pi' && c.usdPrice > 0 ) { coins = amount / c.usdPrice; }
		return coins > 0 ? Math.ceil( coins * 1e4 - 1e-9 ) / 1e4 : 0;
	}

	var Content = function ( props ) {
		var billing = props && props.billing;
		var amountLine = null;
		if ( billing && billing.cartTotal && billing.currency ) {
			var total = Number( billing.cartTotal.value ) / Math.pow( 10, billing.currency.minorUnit || 0 );
			var coins = toCoin( total, billing.currency.code );
			if ( coins > 0 ) {
				var label = coins + ' ' + ( settings.conversion.coin === 'st' ? 'ST' : 'Pi' );
				var parts = String( settings.aboutText || '%s' ).split( '%s' );
				// The amount stays left to right inside Arabic text.
				amountLine = el( 'strong', { style: { fontSize: '1.1em' } }, parts[ 0 ], el( 'bdi', { dir: 'ltr' }, label ), parts[ 1 ] || '' );
			}
		}
		return el(
			'div',
			{ style: { display: 'flex', flexDirection: 'column', gap: '6px' } },
			settings.description ? el( 'div', null, decode( settings.description ) ) : null,
			amountLine,
			settings.escrowText ? el( 'small', { style: { opacity: 0.75 } }, decode( settings.escrowText ) ) : null
		);
	};
	var Label = function () {
		return el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '10px', width: '100%' } },
			settings.icon ? el( 'img', { src: settings.icon, alt: '', width: 36, height: 36, style: { width: '36px', height: '36px', borderRadius: '9px' } } ) : null,
			el( 'span', { style: { fontWeight: 600 } }, title )
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
