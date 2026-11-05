<?php
/**
 * Carrito lateral: HTML, fragmentos y endpoint AJAX.
 */

defined( 'ABSPATH' ) || exit;

class FCM_Drawer {

	const NONCE = 'plmc-cart';

	public static function init() {
		if ( ! FCM_Settings::get( 'drawer' ) ) {
			return;
		}
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'fragments' ) );
		add_action( 'wc_ajax_plmc_cart', array( __CLASS__, 'ajax' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'flag_open' ), 20 );

		if ( FCM_Settings::get( 'stay_on_page' ) ) {
			add_filter( 'pre_option_woocommerce_cart_redirect_after_add', array( __CLASS__, 'no_redirect' ) );
		}
		if ( FCM_Settings::get( 'hide_added_notice' ) ) {
			add_filter( 'wc_add_to_cart_message_html', array( __CLASS__, 'hide_added_message' ), 99 );
		}
	}

	public static function no_redirect() {
		return 'no';
	}

	/** Con el carrito lateral el aviso «se ha añadido a tu carrito» sobra. WooCommerce no guarda avisos vacíos. */
	public static function hide_added_message( $message ) {
		return is_admin() && ! wp_doing_ajax() ? $message : '';
	}

	/** El carrito lateral no se usa en carrito, checkout ni cuenta. */
	public static function enabled_here() {
		if ( is_admin() || ! function_exists( 'is_cart' ) ) {
			return false;
		}
		return ! ( is_cart() || is_checkout() || is_account_page() );
	}

	/**
	 * Tras añadir con el formulario normal (sin AJAX), deja una cookie de un solo uso
	 * para que el JS abra el carrito al cargar la página. Funciona aunque la página venga de caché.
	 */
	public static function flag_open() {
		if ( wp_doing_ajax() || ! FCM_Settings::get( 'drawer_auto_open' ) || headers_sent() || 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
			return;
		}
		wc_setcookie( 'plmc_open', '1', 0, is_ssl(), false );
	}

	public static function count_html() {
		$count = WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
		return '<span class="plmc-count" data-count="' . esc_attr( $count ) . '">' . esc_html( $count ) . '</span>';
	}

	public static function footer() {
		if ( ! self::enabled_here() ) {
			return;
		}
		$icon_bag   = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>';
		$icon_close = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
		?>
		<div class="plmc-drawer" id="plmc-drawer" hidden>
			<div class="plmc-drawer__overlay" data-plmc-close></div>
			<div class="plmc-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="plmc-drawer-title" tabindex="-1">
				<div class="plmc-drawer__head">
					<p class="plmc-drawer__title" id="plmc-drawer-title"><?php esc_html_e( 'Your cart', 'frucomedia-carrito-moderno' ); ?> <?php echo self::count_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en count_html(). ?></p>
					<button type="button" class="plmc-drawer__close" data-plmc-close aria-label="<?php esc_attr_e( 'Close cart', 'frucomedia-carrito-moderno' ); ?>"><?php echo $icon_close; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG estático. ?></button>
				</div>
				<div class="plmc-drawer__content" data-loaded="0">
					<div class="plmc-drawer__loading"><span class="plmc-spinner" aria-hidden="true"></span></div>
				</div>
				<div class="plmc-drawer__status screen-reader-text" aria-live="polite"></div>
			</div>
		</div>
		<?php if ( FCM_Settings::get( 'floating_button' ) ) : ?>
			<button type="button" class="plmc-fab" data-plmc-open aria-label="<?php esc_attr_e( 'Open cart', 'frucomedia-carrito-moderno' ); ?>">
				<?php echo $icon_bag; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG estático. ?>
				<?php echo self::count_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
		<?php endif; ?>
		<?php
	}

	public static function fragments( $fragments ) {
		$fragments['div.plmc-drawer__content'] = self::content_html();
		$fragments['span.plmc-count']          = self::count_html();
		return $fragments;
	}

	/**
	 * Contenido del panel. Siempre se genera en una petición AJAX o sin caché.
	 */
	public static function content_html() {
		$cart  = WC()->cart;
		$nonce = wp_create_nonce( self::NONCE );
		ob_start();

		echo '<div class="plmc-drawer__content" data-loaded="1" data-nonce="' . esc_attr( $nonce ) . '">';

		if ( ! $cart || $cart->is_empty() ) {
			echo '<div class="plmc-empty">';
			echo '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>';
			echo '<p>' . esc_html__( 'Your cart is currently empty.', 'woocommerce' ) . '</p>';
			echo '<a class="plmc-btn plmc-btn--primary" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Return to shop', 'woocommerce' ) . '</a>';
			echo '</div></div>';
			return ob_get_clean();
		}

		echo FCM_Helpers::shipping_bar_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en el helper.

		echo '<div class="plmc-drawer__scroll">';
		echo '<ul class="plmc-items">';
		foreach ( $cart->get_cart() as $key => $item ) {
			self::item_html( $key, $item );
		}
		echo '</ul>';
		self::recommendations_html();
		echo '</div>';

		// Pie: subtotal y botones.
		echo '<div class="plmc-drawer__foot">';
		echo '<div class="plmc-line"><span>' . esc_html__( 'Subtotal', 'woocommerce' ) . '</span><strong>' . wp_kses_post( $cart->get_cart_subtotal() ) . '</strong></div>';
		$discount = (float) $cart->get_discount_total();
		if ( $discount > 0 ) {
			$shown = $cart->display_prices_including_tax() ? $discount + (float) $cart->get_discount_tax() : $discount;
			echo '<div class="plmc-line plmc-line--discount"><span>' . esc_html__( 'Discount', 'frucomedia-carrito-moderno' ) . '</span><strong>−' . wp_kses_post( wc_price( $shown ) ) . '</strong></div>';
		}
		echo '<p class="plmc-note">' . esc_html__( 'Shipping is calculated at checkout.', 'frucomedia-carrito-moderno' ) . '</p>';
		echo '<a class="plmc-btn plmc-btn--primary plmc-btn--block" href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Proceed to checkout', 'woocommerce' ) . '</a>';
		echo '<div class="plmc-foot-links">';
		echo '<a href="' . esc_url( wc_get_cart_url() ) . '">' . esc_html__( 'View cart', 'woocommerce' ) . '</a>';
		echo '<button type="button" class="plmc-link" data-plmc-close>' . esc_html__( 'Continue shopping', 'woocommerce' ) . '</button>';
		echo '</div>';
		echo '</div>';

		echo '</div>';
		return ob_get_clean();
	}

	private static function item_html( $key, $item ) {
		$product = apply_filters( 'woocommerce_cart_item_product', $item['data'], $item, $key );
		if ( ! $product || ! $product->exists() || $item['quantity'] <= 0 || ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $item, $key ) ) {
			return;
		}
		$qty       = (int) $item['quantity'];
		$permalink = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $item ) : '', $item, $key );
		$thumb     = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image( 'woocommerce_thumbnail' ), $item, $key );
		$name      = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $item, $key );
		$subtotal  = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $qty ), $item, $key );
		$max       = $product->is_sold_individually() ? 1 : (int) $product->get_max_purchase_quantity();
		$plain     = wp_strip_all_tags( $product->get_name() );

		echo '<li class="plmc-item" data-key="' . esc_attr( $key ) . '">';

		echo '<div class="plmc-item__img">';
		if ( $permalink ) {
			echo '<a href="' . esc_url( $permalink ) . '" tabindex="-1" aria-hidden="true">' . wp_kses_post( $thumb ) . '</a>';
		} else {
			echo wp_kses_post( $thumb );
		}
		echo '</div>';

		echo '<div class="plmc-item__body">';
		echo '<div class="plmc-item__top">';
		echo '<div class="plmc-item__name">';
		if ( $permalink && false === strpos( $name, '<a' ) ) {
			echo '<a href="' . esc_url( $permalink ) . '">' . wp_kses_post( $name ) . '</a>';
		} else {
			echo wp_kses_post( $name );
		}
		echo '</div>';
		/* translators: %s: nombre del producto */
		echo '<button type="button" class="plmc-item__remove" data-plmc-remove aria-label="' . esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), $plain ) ) . '"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg></button>';
		echo '</div>';

		$meta = wc_get_formatted_cart_item_data( $item );
		if ( $meta ) {
			echo '<div class="plmc-item__meta">' . wp_kses_post( $meta ) . '</div>';
		}

		echo '<div class="plmc-item__bottom">';
		if ( 1 === $max ) {
			echo '<span class="plmc-item__qtytext">× ' . esc_html( $qty ) . '</span>';
		} else {
			$at_max = ( $max > 0 && $qty >= $max );
			echo '<div class="plmc-qty" data-qty="' . esc_attr( $qty ) . '">';
			echo '<button type="button" data-plmc-qty="-1" aria-label="' . esc_attr__( 'Remove one', 'frucomedia-carrito-moderno' ) . '">−</button>';
			echo '<span class="plmc-qty__val" aria-label="' . esc_attr__( 'Quantity', 'woocommerce' ) . '">' . esc_html( $qty ) . '</span>';
			echo '<button type="button" data-plmc-qty="1" aria-label="' . esc_attr__( 'Add one', 'frucomedia-carrito-moderno' ) . '"' . ( $at_max ? ' disabled' : '' ) . '>+</button>';
			echo '</div>';
		}
		echo '<span class="plmc-item__price">' . wp_kses_post( $subtotal ) . '</span>';
		echo '</div>';

		echo '</div></li>';
	}

	private static function recommendations_html() {
		$products = FCM_Helpers::recommendations();
		if ( ! $products ) {
			return;
		}
		echo '<div class="plmc-recs">';
		echo '<p class="plmc-recs__title">' . esc_html__( 'Pairs well with', 'frucomedia-carrito-moderno' ) . '</p>';
		echo '<ul class="plmc-recs__list">';
		foreach ( $products as $p ) {
			$link = $p->get_permalink();
			echo '<li class="plmc-rec">';
			echo '<a class="plmc-rec__img" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . wp_kses_post( $p->get_image( 'woocommerce_thumbnail' ) ) . '</a>';
			echo '<div class="plmc-rec__body">';
			echo '<a class="plmc-rec__name" href="' . esc_url( $link ) . '">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</a>';
			echo '<span class="plmc-rec__price">' . wp_kses_post( $p->get_price_html() ) . '</span>';
			if ( $p->is_type( 'simple' ) && ! $p->is_sold_individually() ) {
				echo '<button type="button" class="plmc-btn plmc-btn--small" data-plmc-add="' . esc_attr( $p->get_id() ) . '">' . esc_html__( 'Add', 'frucomedia-carrito-moderno' ) . '</button>';
			} else {
				echo '<a class="plmc-btn plmc-btn--small plmc-btn--ghost" href="' . esc_url( $link ) . '">' . esc_html__( 'See options', 'frucomedia-carrito-moderno' ) . '</a>';
			}
			echo '</div></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Endpoint ?wc-ajax=plmc_cart. Operaciones: refresh, qty, remove, add.
	 * Nunca se lee un precio del navegador: solo clave de línea, cantidad o ID de producto.
	 */
	public static function ajax() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- se verifica abajo para las operaciones que modifican.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( ! WC()->cart ) {
			wp_send_json_error( array( 'code' => 'nocart' ), 400 );
		}

		if ( 'refresh' !== $op ) {
			if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
				wp_send_json_error( array( 'code' => 'nonce' ), 403 );
			}

			$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
			$key = preg_match( '/^[a-f0-9]{32}$/', $key ) ? $key : '';

			switch ( $op ) {
				case 'qty':
					$qty  = isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 0;
					$item = $key ? WC()->cart->get_cart_item( $key ) : array();
					if ( empty( $item ) ) {
						break;
					}
					if ( 0 === $qty ) {
						WC()->cart->remove_cart_item( $key );
						break;
					}
					$product = $item['data'];
					if ( $product->is_sold_individually() ) {
						$qty = 1;
					}
					$max = (int) $product->get_max_purchase_quantity();
					if ( $max > 0 && $qty > $max ) {
						$qty = $max;
					}
					$qty = min( $qty, 999 );
					if ( apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
						WC()->cart->set_quantity( $key, $qty, true );
					}
					break;

				case 'remove':
					if ( $key && WC()->cart->get_cart_item( $key ) ) {
						WC()->cart->remove_cart_item( $key );
					}
					break;

				case 'add':
					$pid     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
					$product = $pid ? wc_get_product( $pid ) : null;
					if ( ! $product || ! $product->is_type( 'simple' ) || 'publish' !== $product->get_status() || ! $product->is_visible() || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
						wc_add_notice( __( 'This product can\'t be added to the cart right now.', 'frucomedia-carrito-moderno' ), 'error' );
						break;
					}
					if ( apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ) ) {
						WC()->cart->add_to_cart( $pid, 1 );
					}
					break;

				default:
					wp_send_json_error( array( 'code' => 'op' ), 400 );
			}
			WC()->cart->calculate_totals();
		}
		// phpcs:enable

		$errors = array();
		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : $notice;
			$errors[] = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		}
		wc_clear_notices();

		ob_start();
		woocommerce_mini_cart();
		$mini = ob_get_clean();

		wp_send_json_success(
			array(
				'fragments' => apply_filters(
					'woocommerce_add_to_cart_fragments',
					array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' )
				),
				'cart_hash' => WC()->cart->get_cart_hash(),
				'errors'    => $errors,
			)
		);
	}
}
