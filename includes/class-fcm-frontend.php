<?php
/**
 * Recursos, clases del body y mejoras de las páginas de carrito y checkout clásicas.
 */

defined( 'ABSPATH' ) || exit;

class FCM_Frontend {

	/** @var bool Estamos pintando la tabla del resumen del pedido. */
	private static $in_review = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_exclusions' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'rocket_exclusions' ) );
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'rocket_exclusions' ) );

		// Carrito.
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'cart_steps' ), 5 );
		add_action( 'woocommerce_before_cart_table', array( __CLASS__, 'cart_shipping_bar' ), 5 );
		add_action( 'woocommerce_after_cart_totals', array( __CLASS__, 'cart_sticky_cta' ) );

		// Checkout.
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'checkout_steps' ), 4 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'checkout_summary' ), 6 );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'checkout_fragments' ) );
		add_action( 'woocommerce_review_order_before_cart_contents', array( __CLASS__, 'review_start' ) );
		add_action( 'woocommerce_review_order_after_cart_contents', array( __CLASS__, 'review_end' ) );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'review_thumbnail' ), 20, 3 );
		add_action( 'woocommerce_review_order_after_submit', array( __CLASS__, 'secure_note' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'checkout_coupon_box' ) );

		// Envoltorio propio para poder dar estilo solo al carrito/checkout y no a otros bloques «.woocommerce» (buscador, etc.).
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'wrap_shortcode' ), 10, 2 );

		// Textos del tema sin traducir en carrito/checkout → traducción de WooCommerce del mismo texto.
		if ( FCM_Settings::get( 'theme_strings' ) ) {
			add_action( 'wp', array( __CLASS__, 'maybe_enable_theme_strings' ) );
			add_filter( 'gettext', array( __CLASS__, 'theme_string_fallback' ), 20, 3 );
		}

		// Apple Pay / Google Pay de Stripe: aceptar los packs de Pataliebres Lotes en carrito y checkout.
		add_filter( 'wc_stripe_payment_request_supported_types', array( __CLASS__, 'express_supported_types' ) );
		add_filter( 'wc_stripe_express_checkout_supported_types', array( __CLASS__, 'express_supported_types' ) );
	}

	/**
	 * Stripe oculta los botones exprés si el carrito tiene un tipo de producto que no conoce (el pack es «lote»).
	 * En la ficha del pack no se añaden: allí el cliente tiene que elegir tallas antes de comprar.
	 */
	public static function express_supported_types( $types ) {
		$types = is_array( $types ) ? $types : array();
		if ( function_exists( 'is_product' ) && is_product() ) {
			return $types;
		}
		if ( ! in_array( 'lote', $types, true ) ) {
			$types[] = 'lote';
		}
		return $types;
	}

	/** @var bool */
	private static $theme_strings = false;

	public static function maybe_enable_theme_strings() {
		if ( 0 === strpos( determine_locale(), 'en' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se lee el nombre del endpoint.
		$wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
		if ( is_cart() || is_checkout() || in_array( $wc_ajax, array( 'update_order_review', 'checkout', 'apply_coupon', 'remove_coupon', 'update_shipping_method' ), true ) ) {
			self::$theme_strings = true;
		}
	}

	/**
	 * Si un tema (p. ej. Bridge) escribe «Cart totals» o «First name» con su propio dominio y no tiene
	 * traducción a este idioma, se usa la traducción que WooCommerce ya tiene para ese mismo texto.
	 */
	public static function theme_string_fallback( $translation, $text, $domain ) {
		static $busy = false;
		if ( ! self::$theme_strings || $busy || $translation !== $text || in_array( $domain, array( 'woocommerce', 'default', 'frucomedia-carrito-moderno' ), true ) ) {
			return $translation;
		}
		$busy   = true;
		$result = translate( $text, 'woocommerce' );
		if ( $result === $text ) {
			// Los temas a veces usan «Cart Totals» o «Apply Coupon»: probar con la forma de WooCommerce («Cart totals»).
			$sentence = ucfirst( strtolower( $text ) );
			if ( $sentence !== $text ) {
				$alt    = translate( $sentence, 'woocommerce' );
				$result = ( $alt !== $sentence ) ? $alt : $text;
			}
		}
		$busy = false;
		return $result;
	}

	public static function wrap_shortcode( $output, $tag ) {
		if ( 'woocommerce_cart' === $tag && self::is_styled_cart() ) {
			return '<div class="plmc-cart-wrap">' . $output . '</div>';
		}
		if ( 'woocommerce_checkout' === $tag && self::is_styled_checkout() ) {
			return '<div class="plmc-checkout-wrap">' . $output . '</div>';
		}
		return $output;
	}

	/**
	 * Cupón dentro de la caja «Tu pedido», entre el resumen y las formas de pago.
	 * No es un <form> (no se pueden anidar formularios): el JS lo envía por el AJAX de cupones de WooCommerce.
	 * Este hook solo se ejecuta al cargar la página, así que la caja no se borra al recalcular el pedido.
	 */
	public static function checkout_coupon_box() {
		if ( ! self::is_styled_checkout() || ! wc_coupons_enabled() ) {
			return;
		}
		echo '<div class="plmc-coupon">';
		echo '<button type="button" class="plmc-coupon__toggle" aria-expanded="false" aria-controls="plmc-coupon-form">' . esc_html__( 'Have a coupon?', 'woocommerce' ) . ' <span>' . esc_html__( 'Add it here', 'frucomedia-carrito-moderno' ) . '</span></button>';
		echo '<div class="plmc-coupon__form" id="plmc-coupon-form" hidden>';
		echo '<label class="screen-reader-text" for="plmc-coupon-code">' . esc_html__( 'Coupon code', 'woocommerce' ) . '</label>';
		echo '<input type="text" id="plmc-coupon-code" class="input-text" placeholder="' . esc_attr__( 'Coupon code', 'woocommerce' ) . '" autocomplete="off" autocapitalize="characters">';
		echo '<button type="button" class="plmc-btn plmc-btn--primary plmc-coupon__apply">' . esc_html__( 'Apply', 'frucomedia-carrito-moderno' ) . '</button>';
		echo '</div>';
		echo '<div class="plmc-coupon__msg" aria-live="polite"></div>';
		echo '</div>';
	}

	public static function secure_note() {
		if ( FCM_Settings::get( 'checkout_style' ) ) {
			echo '<p class="plmc-secure"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>' . esc_html__( '100% secure, encrypted payment', 'frucomedia-carrito-moderno' ) . '</p>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Detección de páginas                                               */
	/* ------------------------------------------------------------------ */

	private static function uses_blocks( $page ) {
		$id = wc_get_page_id( $page );
		return $id > 0 && function_exists( 'has_block' ) && has_block( 'woocommerce/' . $page, $id );
	}

	/**
	 * Página de carrito/checkout hecha con los widgets «Carrito» / «Finalizar compra» de Elementor Pro:
	 * tienen su propio diseño (se edita en Elementor), así que no se les aplica el rediseño.
	 */
	private static function uses_elementor_widget( $page ) {
		$id = wc_get_page_id( $page );
		if ( $id <= 0 || ! defined( 'ELEMENTOR_VERSION' ) ) {
			return false;
		}
		$data   = get_post_meta( $id, '_elementor_data', true );
		$widget = 'cart' === $page ? 'woocommerce-cart' : 'woocommerce-checkout-page';
		return is_string( $data ) && false !== strpos( $data, '"widgetType":"' . $widget . '"' );
	}

	public static function is_styled_cart() {
		return FCM_Settings::get( 'cart_style' ) && is_cart() && ! self::uses_blocks( 'cart' ) && ! self::uses_elementor_widget( 'cart' );
	}

	public static function is_styled_checkout() {
		return FCM_Settings::get( 'checkout_style' ) && is_checkout() && ! is_wc_endpoint_url() && ! self::uses_blocks( 'checkout' ) && ! self::uses_elementor_widget( 'checkout' );
	}

	/* ------------------------------------------------------------------ */
	/* Recursos                                                           */
	/* ------------------------------------------------------------------ */

	public static function assets() {
		$drawer   = FCM_Settings::get( 'drawer' ) && FCM_Drawer::enabled_here();
		$cart     = self::is_styled_cart();
		$checkout = self::is_styled_checkout();

		if ( ! $drawer && ! $cart && ! $checkout ) {
			return;
		}

		wp_enqueue_style( 'plmc', FCM_URL . 'assets/plmc.css', array(), FCM_VERSION );
		$accent = FCM_Settings::get( 'accent' );
		$accent = sanitize_hex_color( $accent ) ? $accent : '#111111';
		wp_add_inline_style( 'plmc', ':root{--plmc-accent:' . $accent . ';}' );

		$deps = array( 'jquery' );
		if ( $drawer ) {
			$deps[] = 'wc-cart-fragments';
			wp_enqueue_script( 'wc-cart-fragments' );
		}
		wp_enqueue_script( 'plmc', FCM_URL . 'assets/plmc.js', $deps, FCM_VERSION, true );
		wp_localize_script(
			'plmc',
			'plmcConfig',
			array(
				'ajaxUrl'  => WC_AJAX::get_endpoint( 'plmc_cart' ),
				'drawer'   => $drawer ? 1 : 0,
				'autoOpen' => FCM_Settings::get( 'drawer_auto_open' ) ? 1 : 0,
				'selector' => (string) FCM_Settings::get( 'cart_selector' ),
				'cartPage' => $cart ? 1 : 0,
				'checkoutPage' => $checkout ? 1 : 0,
				'elementor'    => (string) FCM_Settings::get( 'elementor_cart' ),
				'cartUrl'      => wc_get_cart_url(),
				'i18n'     => array(
					'error'   => __( 'The cart couldn\'t be updated. Please try again.', 'frucomedia-carrito-moderno' ),
					'updated' => __( 'Cart updated.', 'woocommerce' ),
					'minus'   => __( 'Remove one', 'frucomedia-carrito-moderno' ),
					'plus'    => __( 'Add one', 'frucomedia-carrito-moderno' ),
					'login'   => __( 'Returning customer?', 'woocommerce' ),
					'gift'    => __( 'Have a gift card?', 'frucomedia-carrito-moderno' ),
					'couponEmpty' => __( 'Please enter a coupon code.', 'woocommerce' ),
				),
			)
		);
	}

	public static function body_class( $classes ) {
		if ( self::is_styled_cart() ) {
			$classes[] = 'plmc-cart';
		}
		if ( self::is_styled_checkout() ) {
			$classes[] = 'plmc-checkout';
		}
		if ( FCM_Settings::get( 'drawer' ) ) {
			$classes[] = 'plmc-has-drawer';
			$el        = FCM_Settings::get( 'elementor_cart' );
			if ( in_array( $el, array( 'all', 'side' ), true ) ) {
				$classes[] = 'plmc-elementor-' . $el;
			}
		}
		return $classes;
	}

	/** Evita que optimizadores retrasen o combinen el script del carrito. */
	public static function script_tag( $tag, $handle ) {
		if ( 'plmc' !== $handle ) {
			return $tag;
		}
		return str_replace( ' src=', ' data-no-optimize="1" data-no-defer="1" data-cfasync="false" src=', $tag );
	}

	public static function rocket_exclusions( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = '/frucomedia-carrito-moderno/assets/plmc.js';
		$list[] = 'plmcConfig';
		return $list;
	}

	/* ------------------------------------------------------------------ */
	/* Pasos                                                              */
	/* ------------------------------------------------------------------ */

	private static function steps_html( $current ) {
		$steps = array(
			1 => __( 'Cart', 'woocommerce' ),
			2 => __( 'Shipping & payment', 'frucomedia-carrito-moderno' ),
			3 => __( 'Confirmation', 'frucomedia-carrito-moderno' ),
		);
		$html = '<ol class="plmc-steps">';
		foreach ( $steps as $n => $label ) {
			$state = $n < $current ? 'is-done' : ( $n === $current ? 'is-current' : '' );
			$inner = '<span class="plmc-steps__n">' . esc_html( $n ) . '</span><span class="plmc-steps__label">' . esc_html( $label ) . '</span>';
			if ( 1 === $n && $current > 1 ) {
				$inner = '<a href="' . esc_url( wc_get_cart_url() ) . '">' . $inner . '</a>';
			}
			$html .= '<li class="' . esc_attr( $state ) . '"' . ( $n === $current ? ' aria-current="step"' : '' ) . '>' . $inner . '</li>';
		}
		return $html . '</ol>';
	}

	public static function cart_steps() {
		if ( self::is_styled_cart() && FCM_Settings::get( 'steps' ) ) {
			echo self::steps_html( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado al construirlo.
		}
	}

	public static function checkout_steps() {
		if ( self::is_styled_checkout() && FCM_Settings::get( 'steps' ) ) {
			echo self::steps_html( 2 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ------------------------------------------------------------------ */
	/* Carrito                                                            */
	/* ------------------------------------------------------------------ */

	public static function cart_shipping_bar() {
		// Se pinta dentro del formulario: WooCommerce lo refresca al cambiar cantidades.
		if ( self::is_styled_cart() ) {
			echo FCM_Helpers::shipping_bar_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** Barra fija inferior en móvil con el total y el botón de pagar. */
	public static function cart_sticky_cta() {
		if ( ! self::is_styled_cart() || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		echo '<div class="plmc-sticky-cta" aria-hidden="true">';
		echo '<div class="plmc-sticky-cta__total"><span>' . esc_html__( 'Total', 'woocommerce' ) . '</span><strong>' . wp_kses_post( WC()->cart->get_total() ) . '</strong></div>';
		echo '<a class="plmc-btn plmc-btn--primary" tabindex="-1" href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Proceed to checkout', 'woocommerce' ) . '</a>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Checkout                                                           */
	/* ------------------------------------------------------------------ */

	public static function checkout_summary() {
		if ( ! self::is_styled_checkout() || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		echo '<details class="plmc-summary">';
		echo '<summary><span class="plmc-summary__label"><span class="plmc-summary__show">' . esc_html__( 'Show order summary', 'frucomedia-carrito-moderno' ) . '</span><span class="plmc-summary__hide">' . esc_html__( 'Hide order summary', 'frucomedia-carrito-moderno' ) . '</span></span>';
		echo self::summary_total_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</summary>';
		echo self::summary_inner_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</details>';
	}

	private static function summary_total_html() {
		return '<span class="plmc-summary__total">' . wp_kses_post( WC()->cart->get_total() ) . '</span>';
	}

	private static function summary_inner_html() {
		$cart = WC()->cart;
		$html = '<div class="plmc-summary__inner"><ul class="plmc-sitems">';
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = apply_filters( 'woocommerce_cart_item_product', $item['data'], $item, $key );
			if ( ! $product || ! $product->exists() || $item['quantity'] <= 0 || ! apply_filters( 'woocommerce_checkout_cart_item_visible', true, $item, $key ) ) {
				continue;
			}
			$meta  = wc_get_formatted_cart_item_data( $item );
			$html .= '<li class="plmc-sitem">'
				. '<span class="plmc-sitem__img">' . wp_kses_post( $product->get_image( 'woocommerce_gallery_thumbnail' ) ) . '<span class="plmc-sitem__qty">' . esc_html( (int) $item['quantity'] ) . '</span></span>'
				. '<span class="plmc-sitem__body"><span class="plmc-sitem__name">' . esc_html( wp_strip_all_tags( $product->get_name() ) ) . '</span>'
				. ( $meta ? '<span class="plmc-sitem__meta">' . wp_kses_post( $meta ) . '</span>' : '' )
				. '</span>'
				. '<span class="plmc-sitem__price">' . wp_kses_post( $cart->get_product_subtotal( $product, $item['quantity'] ) ) . '</span>'
				. '</li>';
		}
		$html .= '</ul><div class="plmc-stotals">';
		$html .= '<div class="plmc-line"><span>' . esc_html__( 'Subtotal', 'woocommerce' ) . '</span><span>' . wp_kses_post( $cart->get_cart_subtotal() ) . '</span></div>';
		foreach ( $cart->get_coupons() as $code => $coupon ) {
			$amount = $cart->get_coupon_discount_amount( $code, ! $cart->display_prices_including_tax() );
			$html  .= '<div class="plmc-line plmc-line--discount"><span>' . esc_html( wc_cart_totals_coupon_label( $coupon, false ) ) . '</span><span>−' . wp_kses_post( wc_price( $amount ) ) . '</span></div>';
		}
		if ( $cart->needs_shipping() && $cart->show_shipping() ) {
			$html .= '<div class="plmc-line"><span>' . esc_html__( 'Shipping', 'woocommerce' ) . '</span><span>' . wp_kses_post( $cart->get_cart_shipping_total() ) . '</span></div>';
		}
		$html .= '<div class="plmc-line plmc-line--total"><span>' . esc_html__( 'Total', 'woocommerce' ) . '</span><span>' . wp_kses_post( $cart->get_total() ) . '</span></div>';
		$html .= '</div></div>';
		return $html;
	}

	public static function checkout_fragments( $fragments ) {
		if ( FCM_Settings::get( 'checkout_style' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$fragments['.plmc-summary__total'] = self::summary_total_html();
			$fragments['.plmc-summary__inner'] = self::summary_inner_html();
		}
		return $fragments;
	}

	public static function review_start() {
		self::$in_review = (bool) FCM_Settings::get( 'checkout_style' );
	}

	public static function review_end() {
		self::$in_review = false;
	}

	/** Miniatura en la tabla «Tu pedido» del checkout. */
	public static function review_thumbnail( $name, $item, $key ) {
		if ( ! self::$in_review || empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
			return $name;
		}
		$img = $item['data']->get_image( 'woocommerce_gallery_thumbnail' );
		return '<span class="plmc-ro-thumb">' . wp_kses_post( $img ) . '</span><span class="plmc-ro-name">' . $name . '</span>';
	}
}
