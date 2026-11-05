<?php
/**
 * Ajustes del plugin (WooCommerce → Carrito moderno).
 */

defined( 'ABSPATH' ) || exit;

class FCM_Settings {

	const OPTION = 'plmc_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			'drawer'            => 1,
			'drawer_auto_open'  => 1,
			'stay_on_page'      => 1,
			'hide_added_notice' => 1,
			'cart_selector'     => 'a.header_cart, a.cart-contents, .site-header a[href$="/carrito/"], header a[href$="/carrito/"]',
			'floating_button'   => 0,
			'shipping_bar'      => 1,
			'shipping_min'      => '',
			'upsells'           => 1,
			'upsells_max'       => 3,
			'upsells_related'   => 1,
			'cart_style'        => 1,
			'checkout_style'    => 1,
			'steps'             => 1,
			'theme_strings'     => 1,
			'elementor_cart'    => 'all',
			'accent'            => '#111111',
		);
	}

	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Modern Cart', 'frucomedia-carrito-moderno' ),
			__( 'Modern Cart', 'frucomedia-carrito-moderno' ),
			'manage_woocommerce',
			'fcm-carrito-moderno',
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'plmc_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Limpia todo lo que llega del formulario. Solo se guardan claves conocidas.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		$bools = array( 'drawer', 'drawer_auto_open', 'stay_on_page', 'hide_added_notice', 'floating_button', 'shipping_bar', 'upsells', 'upsells_related', 'cart_style', 'checkout_style', 'steps', 'theme_strings' );

		foreach ( $bools as $b ) {
			$out[ $b ] = empty( $input[ $b ] ) ? 0 : 1;
		}

		// Selector CSS: solo caracteres válidos en selectores habituales.
		$selector             = isset( $input['cart_selector'] ) ? wp_unslash( (string) $input['cart_selector'] ) : '';
		$selector             = preg_replace( '/[^a-zA-Z0-9\s\-_.#,:>\[\]="\'$^*~()\/+]/', '', $selector );
		$out['cart_selector'] = substr( trim( $selector ), 0, 500 );

		$min = isset( $input['shipping_min'] ) ? trim( (string) $input['shipping_min'] ) : '';
		$min = str_replace( ',', '.', $min );
		$out['shipping_min'] = ( '' === $min || ! is_numeric( $min ) || (float) $min <= 0 ) ? '' : wc_format_decimal( $min, 2 );

		$max                = isset( $input['upsells_max'] ) ? absint( $input['upsells_max'] ) : 3;
		$out['upsells_max'] = max( 1, min( 8, $max ) );

		$accent        = isset( $input['accent'] ) ? sanitize_hex_color( $input['accent'] ) : '';
		$out['accent'] = $accent ? $accent : '#111111';

		$el                    = isset( $input['elementor_cart'] ) ? sanitize_key( $input['elementor_cart'] ) : 'all';
		$out['elementor_cart'] = in_array( $el, array( 'all', 'side', 'off' ), true ) ? $el : 'all';

		self::$cache = null;
		return $out;
	}

	private static function checkbox( $key, $label, $help = '' ) {
		$v = self::get( $key );
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>%5$s',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( 1, (int) $v, false ),
			esc_html( $label ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Modern Cart', 'frucomedia-carrito-moderno' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'plmc_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Side cart', 'frucomedia-carrito-moderno' ); ?></th>
						<td>
							<?php self::checkbox( 'drawer', __( 'Enable the slide-out side cart', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'drawer_auto_open', __( 'Open it automatically when a product is added', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'stay_on_page', __( 'Stay on the product page after adding (instead of going to the cart page)', 'frucomedia-carrito-moderno' ), __( 'Overrides the WooCommerce setting “Redirect to the cart page after successful addition”.', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'hide_added_notice', __( 'Hide the “… has been added to your cart” notice', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'floating_button', __( 'Show a floating cart button (bottom right)', 'frucomedia-carrito-moderno' ), __( 'Useful if your header cart icon doesn\'t open the panel.', 'frucomedia-carrito-moderno' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plmc-selector"><?php esc_html_e( 'Header cart icon', 'frucomedia-carrito-moderno' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="plmc-selector" name="<?php echo esc_attr( $o ); ?>[cart_selector]" value="<?php echo esc_attr( self::get( 'cart_selector' ) ); ?>">
							<p class="description"><?php esc_html_e( 'CSS selector of the links that open the side cart instead of going to the cart page. Separate several with commas.', 'frucomedia-carrito-moderno' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Free shipping', 'woocommerce' ); ?></th>
						<td>
							<?php self::checkbox( 'shipping_bar', __( 'Show the free shipping progress bar', 'frucomedia-carrito-moderno' ) ); ?>
							<p style="margin-top:10px">
								<label for="plmc-min"><?php esc_html_e( 'Minimum amount (optional):', 'frucomedia-carrito-moderno' ); ?></label>
								<input type="text" inputmode="decimal" id="plmc-min" class="small-text" name="<?php echo esc_attr( $o ); ?>[shipping_min]" value="<?php echo esc_attr( self::get( 'shipping_min' ) ); ?>"> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'Leave it empty to use the minimum amount of the “Free shipping” method in your WooCommerce shipping zones.', 'frucomedia-carrito-moderno' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recommendations', 'frucomedia-carrito-moderno' ); ?></th>
						<td>
							<?php self::checkbox( 'upsells', __( 'Show recommended products inside the side cart', 'frucomedia-carrito-moderno' ), __( 'Uses each product\'s “Cross-sells” (Product data → Linked products).', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'upsells_related', __( 'If there are no cross-sells, show related products', 'frucomedia-carrito-moderno' ) ); ?>
							<p style="margin-top:10px">
								<label for="plmc-max"><?php esc_html_e( 'Maximum products:', 'frucomedia-carrito-moderno' ); ?></label>
								<input type="number" min="1" max="8" id="plmc-max" class="small-text" name="<?php echo esc_attr( $o ); ?>[upsells_max]" value="<?php echo esc_attr( self::get( 'upsells_max' ) ); ?>">
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Design', 'frucomedia-carrito-moderno' ); ?></th>
						<td>
							<?php self::checkbox( 'cart_style', __( 'Redesign the cart page', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'checkout_style', __( 'Redesign the checkout page', 'frucomedia-carrito-moderno' ) ); ?><br>
							<?php self::checkbox( 'steps', __( 'Show the “Cart → Details → Payment” steps', 'frucomedia-carrito-moderno' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fcm-elementor"><?php esc_html_e( 'Elementor “Menu Cart” widget', 'frucomedia-carrito-moderno' ); ?></label></th>
						<td>
							<?php $el = self::get( 'elementor_cart' ); ?>
							<select id="fcm-elementor" name="<?php echo esc_attr( self::OPTION ); ?>[elementor_cart]">
								<option value="all" <?php selected( $el, 'all' ); ?>><?php esc_html_e( 'Open this side cart instead of Elementor’s (side cart and dropdown)', 'frucomedia-carrito-moderno' ); ?></option>
								<option value="side" <?php selected( $el, 'side' ); ?>><?php esc_html_e( 'Replace only Elementor’s “Side cart” type', 'frucomedia-carrito-moderno' ); ?></option>
								<option value="off" <?php selected( $el, 'off' ); ?>><?php esc_html_e( 'Don’t touch Elementor’s cart', 'frucomedia-carrito-moderno' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Translations', 'frucomedia-carrito-moderno' ); ?></th>
						<td>
							<?php self::checkbox( 'theme_strings', __( 'Use WooCommerce translations for untranslated theme texts on the cart and checkout (e.g. “Cart totals”, “Coupon code”, form placeholders)', 'frucomedia-carrito-moderno' ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plmc-accent"><?php esc_html_e( 'Main color', 'frucomedia-carrito-moderno' ); ?></label></th>
						<td>
							<input type="color" id="plmc-accent" name="<?php echo esc_attr( $o ); ?>[accent]" value="<?php echo esc_attr( self::get( 'accent' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Buttons, progress bar and details.', 'frucomedia-carrito-moderno' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p class="description"><?php esc_html_e( 'If you use WP Rocket or another cache, clear it after saving.', 'frucomedia-carrito-moderno' ); ?></p>
		</div>
		<?php
	}
}
