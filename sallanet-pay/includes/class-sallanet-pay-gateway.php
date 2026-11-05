<?php
/**
 * WooCommerce payment gateway for sallanet Pay.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sallanet_Pay_Gateway extends WC_Payment_Gateway {

	const ID = 'sallanet_pay';

	// Statuses that mean the buyer has paid (escrow = paid, waiting for settlement).
	const PAID_STATUSES = array( 'escrow', 'confirmed', 'completed' );

	const META_TX       = '_sallanet_tx_id';
	const META_AMOUNT   = '_sallanet_coin_amount';
	const META_CURRENCY = '_sallanet_coin_currency';
	const META_REFUNDED = '_sallanet_coin_refunded';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'sallanet Pay', 'sallanet-pay' );
		$this->method_description = __( 'Customers pay in Pi or ST on the secure sallanet Pay page, then return to your store. Payment is held in escrow until the buyer confirms or the hold period ends.', 'sallanet-pay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = SALLANET_PAY_URL . 'assets/logo.png';

		$this->init_form_fields();
		$this->init_settings();
		// Show the translated default in the settings form until the merchant writes their own.
		foreach ( array( 'title', 'description' ) as $key ) {
			$this->settings[ $key ] = $this->localized_option( $key );
		}

		$this->title       = $this->localized_option( 'title' );
		$this->description = $this->localized_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'verify_on_return' ), 5 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_release_on_complete' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'section_connect'  => array(
				'title'       => __( 'Connect your account', 'sallanet-pay' ),
				'type'        => 'title',
				'class'       => 'sallanet-step',
				'description' => __( 'Get these from your sallanet Pay developer dashboard.', 'sallanet-pay' ),
			),
			'api_key'          => array(
				'title'       => __( 'API key', 'sallanet-pay' ),
				'type'        => 'password',
				'description' => __( 'From your sallanet Pay developer dashboard. It is shown only once when created.', 'sallanet-pay' ),
				'default'     => '',
			),
			'app_id'           => array(
				'title'       => __( 'App ID (optional)', 'sallanet-pay' ),
				'type'        => 'text',
				'description' => __( 'Needed only if your key has more than one linked app.', 'sallanet-pay' ),
				'default'     => '',
			),
			'test_connection'  => array(
				'title' => __( 'Check connection', 'sallanet-pay' ),
				'type'  => 'sallanet_info',
			),
			'section_webhook'  => array(
				'title'       => __( 'Link the webhook', 'sallanet-pay' ),
				'type'        => 'title',
				'class'       => 'sallanet-step',
				'description' => __( 'sallanet Pay tells your store when a payment arrives or is refunded.', 'sallanet-pay' ),
			),
			'webhook_url'      => array(
				'title' => __( 'Webhook URL', 'sallanet-pay' ),
				'type'  => 'sallanet_info',
			),
			'webhook_secret'   => array(
				'title'       => __( 'Webhook secret', 'sallanet-pay' ),
				'type'        => 'password',
				'description' => __( 'Paste the webhook secret from your sallanet Pay dashboard.', 'sallanet-pay' ),
				'default'     => '',
			),
			'section_pricing'  => array(
				'title'       => __( 'Set pricing', 'sallanet-pay' ),
				'type'        => 'title',
				'class'       => 'sallanet-step',
				'description' => __( 'How the order total is turned into coins.', 'sallanet-pay' ),
			),
			'coin'             => array(
				'title'   => __( 'Charge in', 'sallanet-pay' ),
				'type'    => 'select',
				'options' => array(
					'pi' => 'Pi',
					'st' => 'ST',
				),
				'default' => 'pi',
			),
			'pricing'          => array(
				'title'       => __( 'Price conversion', 'sallanet-pay' ),
				'type'        => 'select',
				'options'     => array(
					'direct'   => __( 'Prices are already in the coin (store currency PI or ST)', 'sallanet-pay' ),
					'usd_live' => __( 'USD prices, convert with the live Pi price', 'sallanet-pay' ),
					'fixed'    => __( 'Fixed rates only (set below)', 'sallanet-pay' ),
				),
				'default'     => 'direct',
				'description' => __( 'How the order total is turned into coins. An order already in PI or ST is never converted. Any currency listed in Fixed rates uses that rate.', 'sallanet-pay' ),
			),
			'fixed_rate'       => array(
				'title'       => __( 'Fixed rates', 'sallanet-pay' ),
				'type'        => 'textarea',
				'css'         => 'direction:ltr;text-align:left;',
				'description' => __( 'How many units of a currency equal 1 coin. One per line, like EGP=50 and USD=1.5. A number alone applies to the store currency. Add every currency your currency switcher plugin offers.', 'sallanet-pay' ),
				'default'     => '',
			),
			'rate_preview'     => array(
				'title' => __( 'Current rates', 'sallanet-pay' ),
				'type'  => 'sallanet_info',
			),
			'section_display'  => array(
				'title'       => __( 'Show it to buyers', 'sallanet-pay' ),
				'type'        => 'title',
				'class'       => 'sallanet-step',
				'description' => __( 'What buyers see at checkout.', 'sallanet-pay' ),
			),
			'enabled'          => array(
				'title'   => __( 'Enable', 'sallanet-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable sallanet Pay', 'sallanet-pay' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'   => __( 'Title', 'sallanet-pay' ),
				'type'    => 'text',
				'default' => __( 'Pay with Pi (sallanet Pay)', 'sallanet-pay' ),
			),
			'description'      => array(
				'title'   => __( 'Description', 'sallanet-pay' ),
				'type'    => 'textarea',
				'default' => __( 'You will be taken to sallanet Pay to complete the payment securely.', 'sallanet-pay' ),
			),
			'section_advanced' => array(
				'title'       => __( 'Advanced', 'sallanet-pay' ),
				'type'        => 'title',
				'class'       => 'sallanet-advanced-toggle',
				'description' => __( 'Most stores do not need to change these.', 'sallanet-pay' ),
			),
			'release_on_complete' => array(
				'title'       => __( 'Instant settlement', 'sallanet-pay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Release escrow when the order is marked Completed', 'sallanet-pay' ),
				'description' => __( 'Only for verified digital stores that have instant settlement enabled by sallanet.', 'sallanet-pay' ),
				'default'     => 'no',
			),
			'api_base'         => array(
				'title'       => __( 'API base URL', 'sallanet-pay' ),
				'type'        => 'text',
				'description' => __( 'Leave empty unless told otherwise.', 'sallanet-pay' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Setup steps for the progress card: [ key, label, hint, done ].
	 */
	public function setup_steps() {
		$key      = trim( (string) $this->get_option( 'api_key' ) );
		$verified = '' !== $key && hash_equals( (string) get_option( 'sallanet_pay_connection_ok', '' ), wp_hash( $key ) );
		return array(
			array( 'section_connect', __( 'Save the API key', 'sallanet-pay' ), __( 'Paste the API key from your sallanet Pay developer dashboard and save.', 'sallanet-pay' ), '' !== $key ),
			array( 'section_webhook', __( 'Link the webhook', 'sallanet-pay' ), __( 'Copy the webhook URL to your dashboard and paste the webhook secret here.', 'sallanet-pay' ), '' !== trim( (string) $this->get_option( 'webhook_secret' ) ) ),
			array( 'section_connect', __( 'Check the connection', 'sallanet-pay' ), __( 'Press Check connection after saving.', 'sallanet-pay' ), $verified ),
			array( 'section_pricing', __( 'Prepare pricing', 'sallanet-pay' ), __( 'Add a rate for your store currency or use Pi as the store currency.', 'sallanet-pay' ), '' !== $this->coin_preview( 100, get_woocommerce_currency() ) ),
			array( 'section_display', __( 'Turn it on for buyers', 'sallanet-pay' ), __( 'Tick Enable and save.', 'sallanet-pay' ), 'yes' === $this->get_option( 'enabled' ) ),
		);
	}

	private function progress_card_html() {
		$steps = $this->setup_steps();
		$done  = count( array_filter( wp_list_pluck( $steps, 3 ) ) );
		$total = count( $steps );
		$html  = '<div class="sallanet-progress" id="sallanet-progress">';
		$html .= '<div class="sallanet-progress-head"><strong>' . esc_html__( 'Setup progress', 'sallanet-pay' ) . '</strong><span class="sallanet-count">'
			/* translators: 1: steps done, 2: all steps */
			. esc_html( sprintf( __( '%1$d of %2$d steps done', 'sallanet-pay' ), $done, $total ) ) . '</span></div>';
		$html .= '<div class="sallanet-bar"><span style="width:' . esc_attr( (string) round( 100 * $done / $total ) ) . '%"></span></div><ol>';
		foreach ( $steps as $i => $step ) {
			list( $section, $label, $hint, $ok ) = $step;
			$html .= '<li class="' . ( $ok ? 'is-done' : '' ) . '" data-step="' . ( $i + 1 ) . '"><span class="sallanet-dot">' . ( $ok ? '&#10003;' : (int) ( $i + 1 ) ) . '</span><div>'
				. '<a href="#' . esc_attr( $this->get_field_key( $section ) ) . '">' . esc_html( $label ) . '</a>'
				. ( $ok ? '' : '<small>' . esc_html( $hint ) . '</small>' ) . '</div></li>';
		}
		$html .= '</ol>';
		if ( $done === $total ) {
			$html .= '<p class="sallanet-ready">' . esc_html__( 'All set. Buyers can pay with sallanet Pay.', 'sallanet-pay' ) . '</p>';
		}
		return $html . '</div>';
	}

	public function api() {
		return new Sallanet_Pay_API( $this->get_option( 'api_key' ), $this->get_option( 'api_base' ) );
	}

	public function coin() {
		return 'st' === $this->get_option( 'coin' ) ? 'st' : 'pi';
	}

	public function is_available() {
		if ( ! parent::is_available() || '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Fixed rates as CODE => rate. A bare number is keyed to the store base currency.
	 */
	public function fixed_rates() {
		$rates = array();
		$parts = preg_split( '/[\r\n;]+/', (string) $this->get_option( 'fixed_rate' ) );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^([A-Za-z]{2,5})\s*[=:]\s*([0-9]+(?:[.,][0-9]+)?)$/', $part, $m ) ) {
				$code = strtoupper( $m[1] );
				$rate = (float) str_replace( ',', '.', $m[2] );
			} elseif ( preg_match( '/^[0-9]+(?:[.,][0-9]+)?$/', $part ) ) {
				$code = strtoupper( get_option( 'woocommerce_currency' ) );
				$rate = (float) str_replace( ',', '.', $part );
			} else {
				continue;
			}
			if ( $rate > 0 ) {
				$rates[ $code ] = $rate;
			}
		}
		return $rates;
	}

	/**
	 * Converts an amount in the order currency to coins. Returns 0 when it cannot.
	 * Uses the order currency, so currency switcher plugins work when each of
	 * their currencies has a fixed rate (or is USD with live pricing).
	 */
	public function to_coin( $amount, $currency ) {
		$amount   = (float) $amount;
		$currency = strtoupper( (string) $currency );
		$coin     = $this->coin();
		$rates    = $this->fixed_rates();
		$coins    = 0.0;
		if ( strtoupper( $coin ) === $currency ) {
			$coins = $amount;
		} elseif ( isset( $rates[ $currency ] ) ) {
			$coins = $amount / $rates[ $currency ];
		} elseif ( 'usd_live' === $this->get_option( 'pricing' ) && 'pi' === $coin && 'USD' === $currency ) {
			$price = $this->api()->pi_usd_price();
			if ( $price > 0 ) {
				$coins = $amount / $price;
			}
		}
		// The API accepts at most 7 decimals; round up so the store never gets less.
		return $coins > 0 ? ceil( $coins * 1e7 - 1e-6 ) / 1e7 : 0.0;
	}

	/**
	 * Keys only administrators may see or change (shop managers cannot).
	 */
	const ADMIN_ONLY = array( 'api_key', 'webhook_secret', 'app_id', 'api_base', 'webhook_url', 'test_connection' );

	public function get_form_fields() {
		$fields = parent::get_form_fields();
		if ( is_admin() && did_action( 'init' ) && ! current_user_can( 'manage_options' ) ) {
			foreach ( self::ADMIN_ONLY as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					// 'title' fields are display only and are never saved, so the stored value stays.
					$fields[ $key ] = array(
						'title'       => $fields[ $key ]['title'],
						'type'        => 'title',
						'description' => __( 'Only site administrators can see or change this.', 'sallanet-pay' ),
					);
				}
			}
		}
		return $fields;
	}

	/**
	 * Saved title or description, translated when it is still the English default.
	 */
	public function localized_option( $key ) {
		$value    = (string) $this->get_option( $key );
		$defaults = array(
			'title'       => 'Pay with Pi (sallanet Pay)',
			'description' => 'You will be taken to sallanet Pay to complete the payment securely.',
		);
		if ( isset( $defaults[ $key ] ) && ( '' === $value || $value === $defaults[ $key ] ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			return __( $defaults[ $key ], 'sallanet-pay' );
		}
		return $value;
	}

	public function get_icon() {
		$icon = '<img src="' . esc_url( $this->icon ) . '" alt="sallanet Pay" class="sallanet-pay-icon" style="width:36px;height:36px;max-height:36px;border-radius:9px;vertical-align:middle;margin-inline-start:8px;float:none;" />';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * "About 41.48 Pi" for a total, or '' when it cannot be priced.
	 */
	public function coin_preview( $total, $currency ) {
		$coins = $this->to_coin( $total, $currency );
		if ( $coins <= 0 ) {
			return '';
		}
		/* translators: %s: amount with coin, like 41.48 Pi */
		return sprintf( __( 'About %s', 'sallanet-pay' ), $this->coin_amount_text( $coins ) );
	}

	public function coin_amount_text( $coins ) {
		return wc_format_decimal( $coins, 4, true ) . ' ' . ( 'st' === $this->coin() ? 'ST' : 'Pi' );
	}

	/**
	 * Same as coin_preview but HTML, with the amount kept left to right inside Arabic text.
	 */
	public function coin_preview_html( $total, $currency ) {
		$coins = $this->to_coin( $total, $currency );
		if ( $coins <= 0 ) {
			return '';
		}
		/* translators: %s: amount with coin, like 41.48 Pi */
		$parts = explode( '%s', __( 'About %s', 'sallanet-pay' ), 2 );
		return esc_html( $parts[0] ) . '<bdi dir="ltr">' . esc_html( $this->coin_amount_text( $coins ) ) . '</bdi>' . esc_html( $parts[1] ?? '' );
	}

	public function escrow_note() {
		return __( 'Your payment is protected in escrow until you receive your order.', 'sallanet-pay' );
	}

	/**
	 * Conversion data for the block checkout, so the amount updates with the cart.
	 */
	public function conversion_data() {
		$usd = 0.0;
		if ( 'usd_live' === $this->get_option( 'pricing' ) && 'pi' === $this->coin() ) {
			$usd = $this->api()->pi_usd_price();
		}
		return array(
			'coin'     => $this->coin(),
			'rates'    => (object) $this->fixed_rates(),
			'usdPrice' => $usd,
		);
	}

	public function payment_fields() {
		echo '<div class="sallanet-pay-box" style="display:flex;flex-direction:column;gap:6px;">';
		if ( $this->description ) {
			echo '<div>' . wp_kses_post( wpautop( wptexturize( $this->description ) ) ) . '</div>';
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$preview = $this->coin_preview_html( WC()->cart->get_total( 'edit' ), get_woocommerce_currency() );
			if ( $preview ) {
				echo '<strong class="sallanet-pay-amount" style="font-size:1.1em;">' . $preview . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in coin_preview_html.
			}
		}
		echo '<small class="sallanet-pay-escrow" style="opacity:.75;">' . esc_html( $this->escrow_note() ) . '</small>';
		echo '</div>';
	}

	public function validate_sallanet_info_field( $key, $value ) {
		return '';
	}

	/**
	 * Read-only rows: webhook URL with copy button, connection check, rate preview.
	 */
	public function generate_sallanet_info_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$inner     = '';
		if ( 'webhook_url' === $key ) {
			$url   = rest_url( 'sallanet-pay/v1/webhook' );
			$inner = '<code id="sallanet-webhook-url" style="direction:ltr;display:inline-block;padding:6px 8px;word-break:break-all;">' . esc_html( $url ) . '</code> '
				. '<button type="button" class="button" data-sallanet-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'sallanet-pay' ) . '</button>'
				. '<p class="description">' . esc_html__( 'Put this URL in your sallanet Pay developer dashboard.', 'sallanet-pay' ) . '</p>';
		} elseif ( 'test_connection' === $key ) {
			$inner = '<button type="button" class="button" id="sallanet-test-connection">' . esc_html__( 'Check connection', 'sallanet-pay' ) . '</button> '
				. '<span id="sallanet-test-result" style="margin-inline-start:8px;"></span>'
				. '<p class="description">' . esc_html__( 'Checks the saved API key with sallanet Pay. Save your changes first.', 'sallanet-pay' ) . '</p>';
		} elseif ( 'rate_preview' === $key ) {
			$lines = array();
			$coin  = 'st' === $this->coin() ? 'ST' : 'Pi';
			$bdi   = function ( $text ) {
				return '<bdi dir="ltr">' . esc_html( $text ) . '</bdi>';
			};
			foreach ( $this->fixed_rates() as $code => $rate ) {
				$lines[] = $bdi( '1 ' . $coin . ' = ' . wc_format_decimal( $rate, 4, true ) . ' ' . $code );
			}
			if ( 'usd_live' === $this->get_option( 'pricing' ) && 'Pi' === $coin ) {
				$price   = $this->api()->pi_usd_price();
				$lines[] = $price > 0
					/* translators: %s: live price, like 1 Pi = 0.5 USD */
					? sprintf( esc_html__( 'Live price %s', 'sallanet-pay' ), $bdi( '1 Pi = ' . wc_format_decimal( $price, 4, true ) . ' USD' ) )
					: esc_html__( 'Live Pi price is not available right now.', 'sallanet-pay' );
			}
			$store = get_woocommerce_currency();
			$check = $this->coin_preview_html( 100, $store );
			$lines[] = $check
				/* translators: 1: amount in store currency, like 100 USD, 2: amount in coins */
				? sprintf( esc_html__( '%1$s in your store currency is %2$s.', 'sallanet-pay' ), $bdi( '100 ' . $store ), $check )
				/* translators: %s: store currency */
				: sprintf( esc_html__( 'Your store currency %s has no rate yet. Buyers cannot pay with sallanet Pay until you add one.', 'sallanet-pay' ), $bdi( $store ) );
			$inner = '<div style="line-height:1.9;">' . implode( '<br>', $lines ) . '</div>';
		}
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label></th>
			<td class="forminp"><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function admin_options() {
		?>
		<style>
			#mainform .sallanet-progress, #mainform .sallanet-card { background:#fff; border-radius:14px; padding:16px 18px; margin:16px 0; max-width:820px; box-sizing:border-box; }
			#mainform .sallanet-progress-head { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; font-size:14px; }
			#mainform .sallanet-progress-head span { color:#50575e; }
			#mainform .sallanet-bar { height:8px; background:#e8eef7; border-radius:8px; margin:12px 0; overflow:hidden; }
			#mainform .sallanet-bar span { display:block; height:100%; background:#0c3a7a; border-radius:8px; }
			#mainform .sallanet-progress ol { list-style:none; margin:0; padding:0; display:grid; gap:10px; }
			#mainform .sallanet-progress li { display:flex; gap:10px; align-items:flex-start; margin:0; }
			#mainform .sallanet-progress li a { font-weight:600; text-decoration:none; color:#1d2327; }
			#mainform .sallanet-progress li small { display:block; color:#646970; margin-top:2px; line-height:1.6; }
			#mainform .sallanet-dot { flex:0 0 26px; width:26px; height:26px; border-radius:50%; background:#e8eef7; color:#0c3a7a; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; }
			#mainform .sallanet-progress li.is-done .sallanet-dot { background:#1a7f37; color:#fff; }
			#mainform .sallanet-progress li.is-done a { color:#50575e; }
			#mainform .sallanet-ready { margin:12px 0 0; color:#1a7f37; font-weight:600; }
			#mainform { counter-reset: sallanetstep; }
			#mainform .sallanet-card h3 { display:flex; align-items:center; gap:10px; margin:0 0 6px; font-size:16px; }
			#mainform .sallanet-card h3.sallanet-step::before { counter-increment: sallanetstep; content: counter(sallanetstep); flex:0 0 28px; width:28px; height:28px; border-radius:50%; background:#0c3a7a; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:14px; }
			#mainform .sallanet-card > p { color:#646970; margin:0 0 4px; }
			#mainform .sallanet-card .form-table { margin:0; }
			#mainform .sallanet-card.is-advanced { padding:12px 18px; }
			#mainform .sallanet-card.is-advanced h3 { font-size:14px; margin:0; }
		</style>
		<?php
		parent::admin_options();
		$nonce = wp_create_nonce( 'sallanet_pay_test' );
		$texts = array(
			'copied'   => __( 'Copied', 'sallanet-pay' ),
			'checking' => __( 'Checking', 'sallanet-pay' ),
			'failed'   => __( 'Could not reach the site. Try again.', 'sallanet-pay' ),
			/* translators: 1: steps done, 2: all steps */
			'count'    => __( '%1$d of %2$d steps done', 'sallanet-pay' ),
		);
		?>
		<script>
		( function () {
			var t = <?php echo wp_json_encode( $texts ); ?>;
			document.querySelectorAll( '[data-sallanet-copy]' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					var v = b.getAttribute( 'data-sallanet-copy' );
					( navigator.clipboard ? navigator.clipboard.writeText( v ) : Promise.reject() ).then( function () { b.textContent = t.copied; } ).catch( function () { window.prompt( '', v ); } );
				} );
			} );
			var btn = document.getElementById( 'sallanet-test-connection' );
			var out = document.getElementById( 'sallanet-test-result' );
			if ( btn && out ) {
				btn.addEventListener( 'click', function () {
					out.textContent = t.checking;
					var body = new FormData();
					body.append( 'action', 'sallanet_pay_test_connection' );
					body.append( '_wpnonce', <?php echo wp_json_encode( $nonce ); ?> );
					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( r ) {
							out.textContent = r && r.data ? r.data.message : t.failed;
							out.style.color = r && r.success ? '#1a7f37' : '#b42318';
							var step = document.querySelector( '#sallanet-progress li[data-step="3"]' );
							if ( r && r.success && step && ! step.classList.contains( 'is-done' ) ) {
								step.classList.add( 'is-done' );
								step.querySelector( '.sallanet-dot' ).innerHTML = '&#10003;';
								var hint = step.querySelector( 'small' );
								if ( hint ) { hint.remove(); }
								var all = document.querySelectorAll( '#sallanet-progress li' ).length;
								var done = document.querySelectorAll( '#sallanet-progress li.is-done' ).length;
								var count = document.querySelector( '#sallanet-progress .sallanet-count' );
								if ( count ) { count.textContent = t.count.replace( '%1$d', done ).replace( '%2$d', all ); }
								var bar = document.querySelector( '#sallanet-progress .sallanet-bar span' );
								if ( bar ) { bar.style.width = Math.round( 100 * done / all ) + '%'; }
							}
						} )
						.catch( function () { out.textContent = t.failed; out.style.color = '#b42318'; } );
				} );
			}
			// Each section (title, text, table) becomes a card; the progress card goes first.
			var form = document.getElementById( 'mainform' );
			var heads = form ? form.querySelectorAll( 'h3.sallanet-step, h3.sallanet-advanced-toggle' ) : [];
			heads.forEach( function ( h ) {
				var card = document.createElement( 'div' );
				card.className = 'sallanet-card' + ( h.classList.contains( 'sallanet-advanced-toggle' ) ? ' is-advanced' : '' );
				h.parentNode.insertBefore( card, h );
				var node = h;
				while ( node ) {
					var next = node.nextElementSibling;
					card.appendChild( node );
					if ( node.tagName === 'TABLE' ) { break; }
					node = next;
				}
			} );
			var progress = <?php echo wp_json_encode( $this->progress_card_html() ); ?>;
			if ( heads.length ) {
				heads[0].parentNode.insertAdjacentHTML( 'beforebegin', progress );
			}
			// Advanced section starts folded.
			var adv = document.querySelector( 'h3.sallanet-advanced-toggle, h2.sallanet-advanced-toggle, .sallanet-advanced-toggle' );
			if ( adv ) {
				var table = adv.nextElementSibling;
				while ( table && table.tagName !== 'TABLE' ) { table = table.nextElementSibling; }
				if ( table ) {
					table.style.display = 'none';
					adv.style.cursor = 'pointer';
					adv.insertAdjacentHTML( 'beforeend', ' <span aria-hidden="true">&#9662;</span>' );
					adv.addEventListener( 'click', function () { table.style.display = table.style.display === 'none' ? '' : 'none'; } );
				}
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Admin "Check connection": a status lookup for a random id answers 404 when the key works.
	 */
	public static function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sallanet_pay_test', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'sallanet-pay' ) ), 403 );
		}
		$gateway = new self();
		if ( '' === trim( (string) $gateway->get_option( 'api_key' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Add and save the API key first.', 'sallanet-pay' ) ) );
		}
		$result = $gateway->api()->transaction_status( wp_generate_uuid4() );
		if ( 404 === $result['status'] ) {
			update_option( 'sallanet_pay_connection_ok', wp_hash( trim( (string) $gateway->get_option( 'api_key' ) ) ), false );
			wp_send_json_success( array( 'message' => __( 'Connected. The API key works.', 'sallanet-pay' ) ) );
		}
		if ( 401 === $result['status'] ) {
			wp_send_json_error( array( 'message' => __( 'The API key is wrong or expired.', 'sallanet-pay' ) ) );
		}
		if ( 429 === $result['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Wait a minute and try again.', 'sallanet-pay' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Could not reach sallanet Pay. Try again later.', 'sallanet-pay' ) ) );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$coins = $this->to_coin( $order->get_total(), $order->get_currency() );
		if ( $coins <= 0 ) {
			wc_add_notice( __( 'This payment method cannot price this order right now. Please try again or choose another method.', 'sallanet-pay' ), 'error' );
			$order->add_order_note( __( 'sallanet Pay: could not convert the order total. Check the price conversion settings.', 'sallanet-pay' ) );
			return array( 'result' => 'failure' );
		}

		$result = $this->api()->create_checkout(
			$coins,
			$this->coin(),
			sprintf(
				/* translators: 1: order number, 2: site name */
				__( 'Order #%1$s at %2$s', 'sallanet-pay' ),
				$order->get_order_number(),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$this->get_return_url( $order ),
			$order->get_cancel_order_url_raw(),
			$this->get_option( 'app_id' )
		);

		if ( ! $result['ok'] || empty( $result['data']['checkout_url'] ) || empty( $result['data']['transaction_id'] ) ) {
			wc_add_notice( __( 'Could not start the payment. Please try again in a moment.', 'sallanet-pay' ), 'error' );
			$order->add_order_note( 'sallanet Pay: checkout creation failed (' . sanitize_text_field( $result['error'] ) . ')' );
			return array( 'result' => 'failure' );
		}

		$tx_id = sanitize_text_field( $result['data']['transaction_id'] );
		$order->update_meta_data( self::META_TX, $tx_id );
		$order->update_meta_data( self::META_AMOUNT, wc_format_decimal( $coins, 7 ) );
		$order->update_meta_data( self::META_CURRENCY, $this->coin() );
		$order->update_meta_data( self::META_REFUNDED, '0' );
		$order->set_transaction_id( $tx_id );
		$order->update_status(
			'pending',
			sprintf(
				/* translators: 1: amount, 2: coin, 3: transaction id */
				__( 'sallanet Pay checkout created for %1$s %2$s (transaction %3$s). Waiting for payment.', 'sallanet-pay' ),
				wc_format_decimal( $coins, 7 ),
				strtoupper( $this->coin() ),
				$tx_id
			)
		);
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $result['data']['checkout_url'] ),
		);
	}

	/**
	 * Marks the order paid if the transaction is paid and matches the order.
	 * Safe to call many times (webhook, return page).
	 */
	public static function settle_order( $order, $tx_id, $status, $amount, $currency, $source ) {
		if ( ! $order || $order->get_payment_method() !== self::ID ) {
			return false;
		}
		if ( (string) $order->get_meta( self::META_TX ) !== (string) $tx_id ) {
			return false;
		}
		if ( ! in_array( (string) $status, self::PAID_STATUSES, true ) ) {
			return false;
		}
		$expected = (float) $order->get_meta( self::META_AMOUNT );
		if ( abs( (float) $amount - $expected ) > 1e-6 || strtolower( (string) $currency ) !== (string) $order->get_meta( self::META_CURRENCY ) ) {
			$order->add_order_note( 'sallanet Pay: paid amount does not match the order. Not marked as paid (' . esc_html( $source ) . ').' );
			return false;
		}
		if ( $order->is_paid() ) {
			return true;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: coin, 3: source */
				__( 'sallanet Pay: payment of %1$s %2$s received and held in escrow (%3$s).', 'sallanet-pay' ),
				wc_format_decimal( $amount, 7 ),
				strtoupper( (string) $currency ),
				$source
			)
		);
		$order->payment_complete( $tx_id );
		return true;
	}

	/**
	 * Return page check, so the order is updated even before the webhook arrives.
	 */
	public function verify_on_return( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$tx_id = (string) $order->get_meta( self::META_TX );
		if ( '' === $tx_id ) {
			return;
		}
		$result = $this->api()->transaction_status( $tx_id );
		if ( ! $result['ok'] || empty( $result['data']['transaction'] ) ) {
			return;
		}
		$tx = $result['data']['transaction'];
		self::settle_order( $order, $tx_id, $tx['status'] ?? '', $tx['amount'] ?? 0, $tx['currency'] ?? '', 'return page' );
	}

	public function maybe_release_on_complete( $order_id ) {
		if ( 'yes' !== $this->get_option( 'release_on_complete' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== self::ID || $order->get_meta( '_sallanet_released' ) ) {
			return;
		}
		$tx_id = (string) $order->get_meta( self::META_TX );
		if ( '' === $tx_id ) {
			return;
		}
		$result = $this->api()->confirm( $tx_id );
		if ( $result['ok'] ) {
			$order->update_meta_data( '_sallanet_released', '1' );
			$order->add_order_note( __( 'sallanet Pay: escrow released to your balance.', 'sallanet-pay' ) );
		} else {
			$order->add_order_note( 'sallanet Pay: release failed (' . sanitize_text_field( $result['error'] ) . ')' );
		}
		$order->save();
	}

	public function can_refund_order( $order ) {
		return $order && '' !== (string) $order->get_meta( self::META_TX ) && $order->is_paid() && parent::can_refund_order( $order );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'sallanet_refund', __( 'Order not found.', 'sallanet-pay' ) );
		}
		$tx_id = (string) $order->get_meta( self::META_TX );
		$total = (float) $order->get_total();
		$coins = (float) $order->get_meta( self::META_AMOUNT );
		if ( '' === $tx_id || $total <= 0 || $coins <= 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'This order has no sallanet Pay transaction.', 'sallanet-pay' ) );
		}
		$amount = null === $amount ? $total : (float) $amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'Refund amount must be more than zero.', 'sallanet-pay' ) );
		}

		// sallanet Pay accepts one refund per transaction (full or partial).
		if ( (float) $order->get_meta( self::META_REFUNDED ) > 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'sallanet Pay allows one refund per order and this order was already refunded.', 'sallanet-pay' ) );
		}

		// Refund in coins at the rate the buyer paid.
		$is_full    = abs( $amount - $total ) < 0.005;
		$coin_value = $is_full ? $coins : min( $coins, floor( $coins * ( $amount / $total ) * 1e7 + 1e-6 ) / 1e7 );
		if ( $coin_value <= 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'Refund amount is too small.', 'sallanet-pay' ) );
		}

		$send   = abs( $coin_value - $coins ) < 1e-7 ? null : $coin_value;
		$result = $this->api()->refund( $tx_id, $send );
		if ( ! $result['ok'] ) {
			return new WP_Error( 'sallanet_refund', 'sallanet Pay: ' . sanitize_text_field( $result['error'] ) );
		}

		$order->update_meta_data( self::META_REFUNDED, wc_format_decimal( $coin_value, 7 ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: coin, 3: reason */
				__( 'sallanet Pay: refunded %1$s %2$s to the buyer. %3$s', 'sallanet-pay' ),
				wc_format_decimal( $coin_value, 7 ),
				strtoupper( (string) $order->get_meta( self::META_CURRENCY ) ),
				sanitize_text_field( $reason )
			)
		);
		$order->save();
		return true;
	}
}
