<?php
/**
 * Actualizaciones desde las «releases» de GitHub.
 *
 * - Lee la última release de FCM_GITHUB_REPO (https://api.github.com, solo HTTPS).
 * - Si su versión es mayor que la instalada, WordPress muestra «Hay una nueva versión» en Plugins
 *   y se actualiza con el botón normal (también funciona con las actualizaciones automáticas).
 * - Descarga el ZIP adjunto a la release («frucomedia-carrito-moderno.zip»).
 * - Repositorio privado: añade en wp-config.php  define( 'FCM_GITHUB_TOKEN', 'token de solo lectura' );
 */

defined( 'ABSPATH' ) || exit;

class FCM_Updater {

	const SLUG      = 'frucomedia-carrito-moderno';
	const ASSET     = 'frucomedia-carrito-moderno.zip';
	const CACHE_KEY = 'fcm_github_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		if ( ! self::repo() ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_flush_on_force_check' ) );
	}

	/** «usuario/repositorio» validado; vacío si no está configurado. */
	private static function repo() {
		$repo = defined( 'FCM_GITHUB_REPO' ) ? (string) FCM_GITHUB_REPO : '';
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	private static function token() {
		return defined( 'FCM_GITHUB_TOKEN' ) ? trim( (string) FCM_GITHUB_TOKEN ) : '';
	}

	private static function basename() {
		return plugin_basename( FCM_FILE );
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . self::SLUG,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	public static function maybe_flush_on_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo decide si vaciar la caché local.
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			self::flush();
		}
	}

	/**
	 * Última release publicada (no borradores ni pre-releases). Se guarda 6 h en caché.
	 *
	 * @return array|null { version, package, url, notes, published }
	 */
	private static function release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => self::headers(),
			)
		);

		$data = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) && empty( $json['draft'] ) && empty( $json['prerelease'] ) ) {
				$version = ltrim( (string) $json['tag_name'], 'vV' );
				$package = '';
				foreach ( (array) ( $json['assets'] ?? array() ) as $asset ) {
					if ( isset( $asset['name'] ) && self::ASSET === $asset['name'] ) {
						// Con token (repo privado) se descarga por la API; sin token, por el enlace público.
						$package = self::token() ? (string) $asset['url'] : (string) $asset['browser_download_url'];
						break;
					}
				}
				if ( preg_match( '/^\d+(\.\d+){0,3}$/', $version ) && 0 === strpos( $package, 'https://' ) ) {
					$data = array(
						'version'   => $version,
						'package'   => $package,
						'url'       => esc_url_raw( (string) ( $json['html_url'] ?? '' ) ),
						'notes'     => (string) ( $json['body'] ?? '' ),
						'published' => (string) ( $json['published_at'] ?? '' ),
					);
				}
			}
		}

		// Si GitHub falla, se guarda un resultado vacío 1 h para no repetir la llamada en cada carga.
		set_site_transient( self::CACHE_KEY, $data ? $data : array(), $data ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $data;
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::release();
		$item    = (object) array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $release ? $release['version'] : FCM_VERSION,
			'url'          => $release ? $release['url'] : '',
			'package'      => $release ? $release['package'] : '',
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'tested'       => get_bloginfo( 'version' ),
		);

		if ( $release && version_compare( $release['version'], FCM_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$item->package = '';
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** Ventana «Ver detalles» de la pantalla de Plugins. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		$notes   = $release ? esc_html( $release['notes'] ) : '';

		return (object) array(
			'name'          => 'Frucomedia Carrito Moderno',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : FCM_VERSION,
			'author'        => 'Frucomedia',
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => esc_html__( 'Slide-out side cart, free shipping bar, in-cart recommendations and a mobile-first redesign of the classic WooCommerce cart and checkout.', 'frucomedia-carrito-moderno' ),
				'changelog'   => $notes ? '<pre style="white-space:pre-wrap">' . $notes . '</pre>' : '',
			),
		);
	}

	/** Repo privado: la descarga del ZIP por la API necesita el token y la cabecera octet-stream. */
	public static function auth_download( $args, $url ) {
		if ( self::token() && 0 === strpos( $url, 'https://api.github.com/repos/' . self::repo() . '/releases/assets/' ) ) {
			$args['headers'] = array_merge( (array) ( $args['headers'] ?? array() ), self::headers( 'application/octet-stream' ) );
		}
		return $args;
	}
}
