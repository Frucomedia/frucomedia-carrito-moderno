<?php
/**
 * Plugin Name:       Frucomedia Carrito Moderno
 * Plugin URI:        https://github.com/Frucomedia/frucomedia-carrito-moderno
 * Description:       Carrito lateral, barra de envío gratis, recomendaciones en el carrito y rediseño del carrito y el checkout clásicos de WooCommerce, pensado para móvil.
 * Version:           1.4.0
 * Author:            Frucomedia
 * Author URI:        https://www.frucomedia.com
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   10.3
 * Text Domain:       frucomedia-carrito-moderno
 * Domain Path:       /languages
 * Update URI:        https://github.com/Frucomedia/frucomedia-carrito-moderno
 * License:           GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'FCM_VERSION', '1.4.0' );
// Repositorio de GitHub del que se descargan las actualizaciones («usuario/repositorio»).
if ( ! defined( 'FCM_GITHUB_REPO' ) ) {
	define( 'FCM_GITHUB_REPO', 'Frucomedia/frucomedia-carrito-moderno' );
}
define( 'FCM_FILE', __FILE__ );
define( 'FCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCM_URL', plugin_dir_url( __FILE__ ) );

// Compatibilidad declarada con HPOS y con los bloques de carrito/checkout (el plugin no toca pedidos ni bloques).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FCM_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FCM_FILE, true );
		}
	}
);

/**
 * Traducciones del plugin incluidas (español y catalán), sin Loco Translate.
 * Si hay un archivo propio en wp-content/languages/plugins/ (p. ej. creado con Loco), se carga antes y manda.
 * Los textos estándar (Subtotal, Total, Ver carrito…) usan las traducciones de WooCommerce y salen en cualquier idioma.
 */
add_action(
	'init',
	static function () {
		$domain = 'frucomedia-carrito-moderno';
		$locale = determine_locale();
		$custom = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
		if ( is_readable( $custom ) ) {
			load_textdomain( $domain, $custom, $locale );
		}
		$lang       = strtolower( strtok( $locale, '_' ) );
		$candidates = array( $locale );
		if ( 'es' === $lang ) {
			$candidates[] = 'es_ES';
		} elseif ( 'ca' === $lang ) {
			$candidates[] = 'ca';
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			$file = FCM_DIR . 'languages/' . $domain . '-' . $candidate . '.mo';
			if ( is_readable( $file ) ) {
				load_textdomain( $domain, $file, $candidate );
				break;
			}
		}
	},
	5
);

// Actualizaciones desde GitHub (aviso en Plugins como cualquier otro plugin).
require_once FCM_DIR . 'includes/class-fcm-updater.php';
FCM_Updater::init();

/**
 * Este plugin sustituye a «Pataliebres Modern Cart». Al activarlo se desactiva el antiguo
 * (los ajustes se conservan: usan la misma opción).
 */
register_activation_hook(
	__FILE__,
	static function () {
		$old = 'pataliebres-modern-cart/pataliebres-modern-cart.php';
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( $old ) ) {
			deactivate_plugins( $old, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		// Si el plugin antiguo sigue activo, no arrancar los dos a la vez.
		if ( defined( 'PLMC_VERSION' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Deactivate “Pataliebres Modern Cart”: Frucomedia Carrito Moderno replaces it and keeps its settings.', 'frucomedia-carrito-moderno' ) . '</p></div>';
					}
				}
			);
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Frucomedia Carrito Moderno requires WooCommerce to be active.', 'frucomedia-carrito-moderno' ) . '</p></div>';
					}
				}
			);
			return;
		}

		require_once FCM_DIR . 'includes/class-fcm-settings.php';
		require_once FCM_DIR . 'includes/class-fcm-helpers.php';
		require_once FCM_DIR . 'includes/class-fcm-drawer.php';
		require_once FCM_DIR . 'includes/class-fcm-frontend.php';

		FCM_Settings::init();
		FCM_Drawer::init();
		FCM_Frontend::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=fcm-carrito-moderno' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woocommerce' ) . '</a>' );
		return $links;
	}
);
