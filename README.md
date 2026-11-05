# Frucomedia Carrito Moderno

Plugin de WordPress/WooCommerce hecho por Frucomedia: carrito lateral, barra de envío gratis, recomendaciones en el carrito y rediseño del carrito y el checkout **clásicos** (shortcode), pensado para móvil.

Requisitos: WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+.

## Instalar en una web nueva
1. Descarga `frucomedia-carrito-moderno.zip` de la última versión en **Releases**.
2. WordPress → Plugins → Añadir nuevo → Subir plugin → activar.
3. Ajustes en **WooCommerce → Carrito moderno**. Vacía la caché (WP Rocket, Cloudflare…).

Si la web tenía «Pataliebres Modern Cart», se desactiva solo al activar este y conserva los ajustes. Después puedes borrarlo.

## Actualizaciones
Las webs consultan este repositorio cada 6 horas. Cuando hay una versión nueva aparece «Hay una nueva versión» en Plugins y se actualiza con el botón de siempre (o con las actualizaciones automáticas de WordPress si las activas para este plugin).

Repositorio privado: añade en el `wp-config.php` de cada web
`define( 'FCM_GITHUB_TOKEN', 'github_pat_…' );` con un token de solo lectura de este repositorio.

## Publicar una versión
1. Cambia la versión en `frucomedia-carrito-moderno.php` (cabecera `Version` y `FCM_VERSION`) y añade los cambios en `CHANGELOG.md` (`## X.Y.Z`). Sube los archivos.
2. En GitHub: **Releases → Draft a new release** → *Choose a tag* → escribe `vX.Y.Z` → *Create new tag* → título `vX.Y.Z` → pega los cambios → **Publish release**.
   (Con git también vale: `git tag vX.Y.Z && git push --follow-tags`.)
3. GitHub Actions comprueba que la versión coincide y la sintaxis PHP, genera `frucomedia-carrito-moderno.zip` y lo adjunta a la release (pestaña **Actions** para ver el resultado; tarda ~1 minuto).
4. En unas horas (o con «Comprobar de nuevo» en Escritorio → Actualizaciones) las webs muestran la actualización.

Antes de una actualización grande (PHP, WooCommerce, tema), prueba en una copia de la web.

## Idiomas
Textos en inglés en el código; traducciones incluidas para español (`es_ES`, sirve para cualquier `es_*`) y catalán (`ca`) en `languages/`. Los textos estándar usan las traducciones de WooCommerce. Un `.mo` propio en `wp-content/languages/plugins/` (p. ej. de Loco Translate) también se carga.

## Seguridad
- Endpoint AJAX `?wc-ajax=plmc_cart` con nonce; solo recibe clave de línea, cantidad o ID de producto. Los precios se calculan siempre en el servidor.
- Sin consultas SQL propias; toda la salida escapada; ajustes con permiso `manage_woocommerce`.
- El actualizador solo descarga por HTTPS desde este repositorio.
