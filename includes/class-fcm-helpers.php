<?php
/**
 * Cálculos compartidos: envío gratis y recomendaciones.
 */

defined( 'ABSPATH' ) || exit;

class FCM_Helpers {

	/**
	 * Devuelve los datos de la barra de envío gratis o null si no aplica.
	 *
	 * @return array|null { threshold, amount, remaining, percent }
	 */
	public static function free_shipping_progress() {
		if ( ! FCM_Settings::get( 'shipping_bar' ) || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}
		if ( ! WC()->cart->needs_shipping() ) {
			return null;
		}

		$threshold        = 0.0;
		$ignore_discounts = false;
		$manual           = FCM_Settings::get( 'shipping_min' );

		if ( '' !== $manual && (float) $manual > 0 ) {
			$threshold = (float) $manual;
		} else {
			$found = self::zone_free_shipping();
			if ( ! $found ) {
				return null;
			}
			$threshold        = $found['min'];
			$ignore_discounts = $found['ignore_discounts'];
		}

		// Mismo cálculo que hace WooCommerce en el método «Envío gratuito».
		$amount = (float) WC()->cart->get_displayed_subtotal();
		if ( ! $ignore_discounts ) {
			$amount -= (float) WC()->cart->get_discount_total();
			if ( WC()->cart->display_prices_including_tax() ) {
				$amount -= (float) WC()->cart->get_discount_tax();
			}
		}
		$amount    = round( max( 0, $amount ), wc_get_price_decimals() );
		$remaining = max( 0, $threshold - $amount );

		return array(
			'threshold' => $threshold,
			'amount'    => $amount,
			'remaining' => $remaining,
			'percent'   => $threshold > 0 ? min( 100, (int) floor( $amount / $threshold * 100 ) ) : 100,
		);
	}

	/**
	 * Busca el método «Envío gratuito» con importe mínimo en la zona del cliente.
	 */
	private static function zone_free_shipping() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return null;
		}
		$packages = WC()->cart->get_shipping_packages();
		$package  = is_array( $packages ) ? reset( $packages ) : null;
		if ( ! $package ) {
			return null;
		}
		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		if ( ! $zone ) {
			return null;
		}

		$best = null;
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( 'free_shipping' !== $method->id ) {
				continue;
			}
			$requires = $method->get_option( 'requires' );
			if ( ! in_array( $requires, array( 'min_amount', 'either' ), true ) ) {
				continue;
			}
			$min = (float) $method->get_option( 'min_amount' );
			if ( $min <= 0 ) {
				continue;
			}
			if ( null === $best || $min < $best['min'] ) {
				$best = array(
					'min'              => $min,
					'ignore_discounts' => 'yes' === $method->get_option( 'ignore_discounts' ),
				);
			}
		}
		return $best;
	}

	/**
	 * HTML de la barra de envío gratis.
	 */
	public static function shipping_bar_html() {
		$p = self::free_shipping_progress();
		if ( ! $p ) {
			return '';
		}
		if ( $p['remaining'] <= 0 ) {
			$msg = '<strong>' . esc_html__( 'You\'ve got free shipping!', 'frucomedia-carrito-moderno' ) . '</strong>';
		} else {
			/* translators: %s: importe que falta */
			$msg = sprintf( esc_html__( 'You\'re %s away from free shipping', 'frucomedia-carrito-moderno' ), '<strong>' . wp_kses_post( wc_price( $p['remaining'] ) ) . '</strong>' );
		}
		$done = $p['remaining'] <= 0 ? ' is-done' : '';

		return '<div class="plmc-ship' . esc_attr( $done ) . '">'
			. '<p class="plmc-ship__msg">' . $msg . '</p>'
			. '<div class="plmc-ship__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $p['percent'] ) . '" aria-label="' . esc_attr__( 'Progress towards free shipping', 'frucomedia-carrito-moderno' ) . '">'
			. '<span class="plmc-ship__fill" style="width:' . esc_attr( $p['percent'] ) . '%"></span>'
			. '</div></div>';
	}

	/**
	 * Productos recomendados para el carrito actual.
	 *
	 * @return WC_Product[]
	 */
	public static function recommendations() {
		if ( ! FCM_Settings::get( 'upsells' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}
		$max     = (int) FCM_Settings::get( 'upsells_max' );
		$in_cart = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$in_cart[] = (int) $item['product_id'];
			if ( ! empty( $item['variation_id'] ) ) {
				$in_cart[] = (int) $item['variation_id'];
			}
		}

		$ids = array_map( 'absint', (array) WC()->cart->get_cross_sells() );

		if ( empty( $ids ) && FCM_Settings::get( 'upsells_related' ) ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids = array_merge( $ids, wc_get_related_products( (int) $item['product_id'], $max * 2, $in_cart ) );
				if ( count( $ids ) >= $max * 2 ) {
					break;
				}
			}
		}

		$ids      = array_diff( array_unique( $ids ), $in_cart );
		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			$products[] = $product;
			if ( count( $products ) >= $max ) {
				break;
			}
		}
		return $products;
	}
}
