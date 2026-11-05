/* Frucomedia Carrito Moderno */
(function () {
	'use strict';

	var cfg = window.plmcConfig || {};
	// wp_localize_script convierte los números en texto ("0" sería verdadero): normalizar.
	['drawer', 'autoOpen', 'cartPage', 'checkoutPage'].forEach(function (k) { cfg[k] = parseInt(cfg[k], 10) === 1; });
	var $ = window.jQuery;
	var doc = document;
	doc.documentElement.classList.add('plmc-js');

	function onReady(fn) {
		if (doc.readyState !== 'loading') { fn(); } else { doc.addEventListener('DOMContentLoaded', fn); }
	}

	function getCookie(name) {
		var m = doc.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : null;
	}

	function deleteCookie(name) {
		doc.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
		if (location.hostname) {
			doc.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + location.hostname;
		}
	}

	// Atributos normales en una línea; los packs (detalle con «×») se dejan en dos líneas.
	function inlineVariations() {
		doc.querySelectorAll('.plmc-drawer dl.variation, .plmc-cart-wrap dl.variation, .plmc-checkout-wrap dl.variation, .plmc-summary dl.variation').forEach(function (dl) {
			var pack = Array.prototype.some.call(dl.querySelectorAll('dd'), function (dd) { return dd.textContent.indexOf('×') !== -1; });
			dl.classList.toggle('plmc-dl-inline', !pack);
		});
	}

	/* ================================================================ */
	/* Carrito lateral                                                  */
	/* ================================================================ */
	var drawer, panel, lastFocus = null, busy = false;

	function contentEl() { return drawer ? drawer.querySelector('.plmc-drawer__content') : null; }

	function applyFragments(data) {
		if (!data || !data.fragments) { return; }
		Object.keys(data.fragments).forEach(function (key) {
			try {
				if ($) {
					$(key).replaceWith(data.fragments[key]);
				} else {
					doc.querySelectorAll(key).forEach(function (el) { el.outerHTML = data.fragments[key]; });
				}
			} catch (e) { /* selector no válido de otro plugin: se ignora */ }
		});

		// Mantener la caché de fragmentos de WooCommerce sincronizada.
		var p = window.wc_cart_fragments_params;
		if (p) {
			try {
				sessionStorage.setItem(p.fragment_name, JSON.stringify(data.fragments));
				if (data.cart_hash !== undefined) {
					localStorage.setItem(p.cart_hash_key, data.cart_hash);
					sessionStorage.setItem(p.cart_hash_key, data.cart_hash);
				}
			} catch (e) { /* almacenamiento no disponible */ }
		}
		if ($) { $(doc.body).trigger('wc_fragments_refreshed'); }
		inlineVariations();
	}

	function showError(msg) {
		var c = contentEl();
		if (!c) { return; }
		var old = c.querySelector('.plmc-error');
		if (old) { old.remove(); }
		var box = doc.createElement('div');
		box.className = 'plmc-error';
		box.setAttribute('role', 'alert');
		box.textContent = msg;
		var scroll = c.querySelector('.plmc-drawer__scroll');
		(scroll || c).insertBefore(box, (scroll || c).firstChild);
	}

	function announce(msg) {
		var s = drawer && drawer.querySelector('.plmc-drawer__status');
		if (s) { s.textContent = ''; setTimeout(function () { s.textContent = msg; }, 50); }
	}

	function request(op, extra, retried) {
		var c = contentEl();
		var body = new FormData();
		body.append('op', op);
		body.append('nonce', (c && c.getAttribute('data-nonce')) || '');
		Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });

		return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.json().catch(function () { return { success: false, data: { code: 'json' } }; }).then(function (json) {
					return { status: r.status, json: json };
				});
			})
			.then(function (res) {
				var json = res.json || {};
				// Nonce caducado (p. ej. página servida desde caché): refrescar y reintentar una vez.
				if (!json.success && json.data && json.data.code === 'nonce' && !retried && op !== 'refresh') {
					return request('refresh', {}, true).then(function () { return request(op, extra, true); });
				}
				if (!json.success) { throw new Error('plmc'); }
				applyFragments(json.data);
				if (json.data.errors && json.data.errors.length) { showError(json.data.errors.join(' ')); }
				return json.data;
			});
	}

	function setBusy(on, item) {
		busy = on;
		var c = contentEl();
		if (c) { c.classList.toggle('is-busy', on); }
		if (item) { item.classList.toggle('is-busy', on); }
	}

	function run(op, extra, item) {
		if (busy) { return; }
		setBusy(true, item);
		request(op, extra)
			.then(function () { announce(cfg.i18n && cfg.i18n.updated); })
			.catch(function () { showError((cfg.i18n && cfg.i18n.error) || 'Error'); })
			.then(function () { setBusy(false, item); });
	}

	function ensureLoaded() {
		var c = contentEl();
		if (c && c.getAttribute('data-loaded') !== '1') {
			request('refresh', {}).catch(function () { showError((cfg.i18n && cfg.i18n.error) || 'Error'); });
		}
	}

	function focusables() {
		return Array.prototype.filter.call(
			panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'),
			function (el) { return el.offsetParent !== null; }
		);
	}

	function openDrawer() {
		if (!drawer || drawer.classList.contains('is-open')) { ensureLoaded(); return; }
		lastFocus = doc.activeElement;
		drawer.hidden = false;
		doc.documentElement.classList.add('plmc-lock');
		// Forzar reflow para que se vea la animación.
		void drawer.offsetWidth;
		drawer.classList.add('is-open');
		ensureLoaded();
		setTimeout(function () { try { panel.focus({ preventScroll: true }); } catch (e) { panel.focus(); } }, 50);
	}

	function closeDrawer() {
		if (!drawer || !drawer.classList.contains('is-open')) { return; }
		drawer.classList.remove('is-open');
		doc.documentElement.classList.remove('plmc-lock');
		setTimeout(function () { if (!drawer.classList.contains('is-open')) { drawer.hidden = true; } }, 320);
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* nada */ } }
	}

	function matchesCartLink(target) {
		if (!cfg.selector) { return null; }
		try { return target.closest(cfg.selector); } catch (e) { return null; }
	}

	function initDrawer() {
		drawer = doc.getElementById('plmc-drawer');
		if (!drawer || !cfg.drawer) {
			// En carrito/checkout no hay panel: que la cookie no lo abra en la siguiente página.
			if (getCookie('plmc_open')) { deleteCookie('plmc_open'); }
			return;
		}
		panel = drawer.querySelector('.plmc-drawer__panel');

		doc.addEventListener('click', function (e) {
			var t = e.target;
			if (!t || !t.closest) { return; }

			if (t.closest('[data-plmc-open]')) { e.preventDefault(); openDrawer(); return; }

			if (drawer.contains(t)) {
				if (t.closest('[data-plmc-close]')) { e.preventDefault(); closeDrawer(); return; }

				var item = t.closest('.plmc-item');
				var qtyBtn = t.closest('[data-plmc-qty]');
				if (qtyBtn && item) {
					e.preventDefault();
					var box = item.querySelector('.plmc-qty');
					var cur = parseInt(box && box.getAttribute('data-qty'), 10) || 1;
					var next = Math.max(0, cur + parseInt(qtyBtn.getAttribute('data-plmc-qty'), 10));
					run('qty', { key: item.getAttribute('data-key'), qty: next }, item);
					return;
				}
				if (t.closest('[data-plmc-remove]') && item) {
					e.preventDefault();
					run('remove', { key: item.getAttribute('data-key') }, item);
					return;
				}
				var add = t.closest('[data-plmc-add]');
				if (add) {
					e.preventDefault();
					add.classList.add('is-loading');
					run('add', { product_id: add.getAttribute('data-plmc-add') }, add.closest('.plmc-rec'));
					return;
				}
				return;
			}

			// Icono del carrito de la cabecera.
			if (e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
				var link = matchesCartLink(t);
				if (link) { e.preventDefault(); openDrawer(); }
			}
		});

		doc.addEventListener('keydown', function (e) {
			if (!drawer.classList.contains('is-open')) { return; }
			if (e.key === 'Escape') { closeDrawer(); return; }
			if (e.key === 'Tab') {
				var f = focusables();
				if (!f.length) { e.preventDefault(); panel.focus(); return; }
				var first = f[0], last = f[f.length - 1];
				if (e.shiftKey && (doc.activeElement === first || doc.activeElement === panel)) { e.preventDefault(); last.focus(); }
				else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
			}
		});

		// Añadido con AJAX (botones del listado, temas que añaden por AJAX en la ficha…).
		if ($) {
			$(doc.body).on('added_to_cart', function () {
				if (cfg.autoOpen) { openDrawer(); }
			});
		}

		// Añadido con el formulario normal: el servidor deja una cookie de un solo uso.
		if (getCookie('plmc_open')) {
			deleteCookie('plmc_open');
			if (cfg.autoOpen) { openDrawer(); }
		}
	}

	/* ================================================================ */
	/* Página del carrito                                               */
	/* ================================================================ */
	var updateTimer = null;

	function scheduleCartUpdate(form) {
		clearTimeout(updateTimer);
		updateTimer = setTimeout(function () {
			var btn = form.querySelector('[name="update_cart"]');
			if (btn) { btn.disabled = false; btn.removeAttribute('aria-disabled'); btn.click(); }
		}, 650);
	}

	function decorateCartQuantities() {
		var form = doc.querySelector('.woocommerce-cart-form');
		if (!form) { return; }
		form.querySelectorAll('.cart_item .quantity').forEach(function (q) {
			var input = q.querySelector('input.qty');
			if (!input || input.type === 'hidden' || q.classList.contains('plmc-qty')) { return; }
			// Si el tema ya pone sus botones + / −, no duplicamos.
			if (q.querySelector('button, .plus, .minus, input[type="button"]')) { return; }

			q.classList.add('plmc-qty');
			var minus = doc.createElement('button');
			minus.type = 'button'; minus.textContent = '−'; minus.setAttribute('aria-label', (cfg.i18n && cfg.i18n.minus) || '-');
			var plus = doc.createElement('button');
			plus.type = 'button'; plus.textContent = '+'; plus.setAttribute('aria-label', (cfg.i18n && cfg.i18n.plus) || '+');
			q.insertBefore(minus, input);
			q.appendChild(plus);

			function step(dir) {
				var stepV = parseFloat(input.step) || 1;
				var min = input.min !== '' ? parseFloat(input.min) : 0;
				var max = input.max !== '' ? parseFloat(input.max) : Infinity;
				var val = (parseFloat(input.value) || 0) + dir * stepV;
				val = Math.max(isNaN(min) ? 0 : min, Math.min(isNaN(max) ? Infinity : max, val));
				input.value = val;
				if ($) { $(input).trigger('change'); } else { input.dispatchEvent(new Event('change', { bubbles: true })); }
			}
			minus.addEventListener('click', function () { step(-1); });
			plus.addEventListener('click', function () { step(1); });
		});
	}

	function initStickyCta() {
		var cta = doc.querySelector('.plmc-sticky-cta');
		var real = doc.querySelector('.wc-proceed-to-checkout') || doc.querySelector('.woocommerce-cart-form td.actions .checkout-button');
		if (!cta || !real || !('IntersectionObserver' in window)) { return; }
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) { cta.classList.toggle('is-hidden', en.isIntersecting); });
		});
		io.observe(real);
	}

	function initCartPage() {
		if (!cfg.cartPage) { return; }
		decorateCartQuantities();
		initStickyCta();

		doc.addEventListener('change', function (e) {
			var t = e.target;
			if (t && t.matches && t.matches('.woocommerce-cart-form input.qty')) {
				scheduleCartUpdate(t.form || doc.querySelector('.woocommerce-cart-form'));
			}
		});
		// Botones − / + propios del tema: actualizar el carrito tras pulsarlos.
		doc.addEventListener('click', function (e) {
			var b = e.target && e.target.closest && e.target.closest('.woocommerce-cart-form .quantity .plus, .woocommerce-cart-form .quantity .minus');
			if (b) { scheduleCartUpdate(b.form || doc.querySelector('.woocommerce-cart-form')); }
		});
		if ($) {
			// jQuery .trigger('change') no dispara listeners nativos: lo escuchamos también aquí.
			$(doc).on('change', '.woocommerce-cart-form input.qty', function (e) {
				if (e.isTrigger) { scheduleCartUpdate(this.form || doc.querySelector('.woocommerce-cart-form')); }
			});
			$(doc.body).on('updated_wc_div updated_cart_totals', function () {
				decorateCartQuantities();
				initStickyCta();
			});
		}
	}

	/* ================================================================ */
	/* Finalizar compra                                                 */
	/* ================================================================ */
	function t(key, fallback) { return (cfg.i18n && cfg.i18n[key]) || fallback; }

	// «¿Ya eres cliente?» y «Tarjeta regalo»: se ven unos segundos y se recogen en enlaces pequeños.
	var noticesDone = false;
	function initCheckoutNotices() {
		if (noticesDone) { return; }
		noticesDone = true;
		var items = [];
		var login = doc.querySelector('.woocommerce-form-login-toggle');
		if (login) {
			items.push({ el: login, link: login.querySelector('a'), label: t('login', 'Returning customer?'), form: doc.querySelector('form.woocommerce-form-login, form.login') });
		}
		var gift = doc.querySelector('.ywgc_have_code');
		if (gift) {
			items.push({ el: gift.closest('.woocommerce-info') || gift, link: gift.querySelector('a'), label: t('gift', 'Have a gift card?'), form: doc.querySelector('.ywgc_enter_code') });
		}
		if (!items.length) { return; }
		items.forEach(function (it) {
			it.el.classList.add('plmc-collapsible');
			it.el.style.maxHeight = it.el.scrollHeight + 40 + 'px';
		});

		setTimeout(function () {
			var todo = items.filter(function (it) {
				var formOpen = it.form && it.form.offsetParent !== null;
				return !formOpen && !it.el.contains(doc.activeElement);
			});
			if (!todo.length) { return; }
			var bar = doc.createElement('div');
			bar.className = 'plmc-minilinks';
			todo.forEach(function (it) {
				var b = doc.createElement('button');
				b.type = 'button';
				b.textContent = it.label;
				b.addEventListener('click', function () { if (it.link) { it.link.click(); } });
				bar.appendChild(b);
				it.el.classList.add('is-collapsed');
				it.el.setAttribute('aria-hidden', 'true');
			});
			todo[0].el.parentNode.insertBefore(bar, todo[0].el);
			requestAnimationFrame(function () { bar.classList.add('is-visible'); });
		}, 4500);
	}

	// Cupón dentro de «Tu pedido»: usa el AJAX de cupones de WooCommerce (no es un formulario anidado).
	function initCheckoutCoupon() {
		var box = doc.querySelector('.plmc-coupon');
		if (!box || !$ || !window.wc_checkout_params) { return; }
		var toggle = box.querySelector('.plmc-coupon__toggle');
		var form = box.querySelector('.plmc-coupon__form');
		var input = box.querySelector('input');
		var btn = box.querySelector('.plmc-coupon__apply');
		var msg = box.querySelector('.plmc-coupon__msg');

		toggle.addEventListener('click', function () {
			form.hidden = !form.hidden;
			toggle.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
			if (!form.hidden) { input.focus(); }
		});

		function apply() {
			var code = input.value.trim();
			if (!code) { msg.textContent = t('couponEmpty', ''); return; }
			btn.classList.add('is-loading');
			$.ajax({
				type: 'POST',
				url: String(wc_checkout_params.wc_ajax_url).replace('%%endpoint%%', 'apply_coupon'),
				data: {
					security: wc_checkout_params.apply_coupon_nonce,
					coupon_code: code,
					billing_email: $('form.checkout').find('input[name="billing_email"]').val()
				},
				dataType: 'html'
			}).done(function (res) {
				msg.innerHTML = res || '';
				if (res && res.indexOf('error') === -1) {
					input.value = '';
					$(doc.body).trigger('applied_coupon_in_checkout', [code]);
				}
				$(doc.body).trigger('update_checkout', { update_shipping_method: false });
			}).fail(function () {
				msg.textContent = t('error', 'Error');
			}).always(function () {
				btn.classList.remove('is-loading');
			});
		}

		btn.addEventListener('click', apply);
		// Enter dentro del campo no debe enviar el pedido.
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); apply(); }
		});
	}

	// Apple Pay / Google Pay: marcarlos para colocarlos en la columna derecha.
	function initExpressButtons() {
		var form = doc.querySelector('form.checkout');
		if (!form) { return; }
		function topChild(el) {
			while (el && el.parentElement !== form) { el = el.parentElement; }
			return el;
		}
		doc.querySelectorAll('#wc-stripe-express-checkout-wrapper, #wc-stripe-express-checkout-element, #wc-stripe-payment-request-wrapper, #wcpay-express-checkout-wrapper, .wcpay-express-checkout-wrapper').forEach(function (el) {
			var c = topChild(el);
			if (c && c.id !== 'customer_details' && c.id !== 'order_review') { c.classList.add('plmc-express'); }
		});
		doc.querySelectorAll('#wc-stripe-express-checkout-button-separator, #wc-stripe-payment-request-button-separator, #wcpay-express-checkout-button-separator').forEach(function (el) {
			var c = topChild(el);
			if (c && c.id !== 'customer_details' && c.id !== 'order_review') { c.classList.add('plmc-express-sep'); }
		});
		// Si el navegador no tiene Apple Pay / Google Pay, no dejar el separador «— O —» suelto.
		var ex = form.querySelector('.plmc-express');
		var sep = form.querySelector('.plmc-express-sep');
		if (sep) {
			var check = function () { sep.classList.toggle('is-empty', !ex || ex.offsetHeight < 24); };
			setTimeout(check, 3000);
			if (ex && 'ResizeObserver' in window) { new ResizeObserver(check).observe(ex); }
		}
	}

	function initCheckoutPage() {
		// Los avisos de acceso / tarjeta regalo se recogen también en la página del carrito.
		if (cfg.cartPage || cfg.checkoutPage) { initCheckoutNotices(); }
		if (!cfg.checkoutPage) { return; }
		initCheckoutCoupon();
		initExpressButtons();
	}

	/* ================================================================ */
	/* Elementor «Carrito del menú» (Menu Cart)                         */
	/* ================================================================ */
	// Se escucha en fase de captura para adelantarse al script de Elementor y que no abra su panel.
	function initElementorCart() {
		var mode = cfg.elementor;
		if (mode !== 'all' && mode !== 'side') { return; }
		doc.addEventListener('click', function (e) {
			var t = e.target;
			if (!t || !t.closest) { return; }
			var btn = t.closest('.elementor-menu-cart__toggle_button, .elementor-menu-cart__toggle .elementor-button, .elementor-menu-cart__toggle a');
			if (!btn) { return; }
			if (mode === 'side' && !btn.closest('.elementor-menu-cart--cart-type-side-cart')) { return; }
			e.preventDefault();
			e.stopPropagation();
			if (e.stopImmediatePropagation) { e.stopImmediatePropagation(); }
			if (drawer && cfg.drawer) {
				openDrawer();
			} else if (cfg.cartUrl && !cfg.cartPage) {
				// En carrito/checkout no hay panel lateral: ir a la página del carrito.
				window.location.href = cfg.cartUrl;
			}
		}, true);
	}

	onReady(function () {
		inlineVariations();
		if ($) {
			$(doc.body).on('wc_fragments_refreshed wc_fragments_loaded updated_wc_div updated_cart_totals updated_checkout added_to_cart', function () {
				setTimeout(inlineVariations, 0);
			});
		}
		initDrawer();
		initElementorCart();
		initCartPage();
		initCheckoutPage();
	});
})();
