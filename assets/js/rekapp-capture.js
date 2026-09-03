/**
 * Captura precoce de contato no checkout (clássico e em blocos).
 *
 * Listeners delegados no documento, não nos inputs: o checkout em blocos é
 * React e recria os campos a cada re-render — um listener preso ao input morre
 * junto com ele. `blur` não borbulha, então vai em fase de captura.
 *
 * Envia no blur e numa pausa de digitação (debounce), e faz um flush final em
 * pagehide/visibilitychange via sendBeacon — é exatamente o momento "fechou a
 * aba" que define o carrinho abandonado.
 */
(function () {
	"use strict";

	if (typeof window.rekappCapture === "undefined") {
		return;
	}

	var cfg = window.rekappCapture;
	var DEBOUNCE_MS = 1200;

	// Cobre o clássico (billing_*), os blocos (email / billing-* / shipping-*)
	// e, por autocomplete/type, campos renomeados por plugins de checkout.
	var FIELD_MAP = [
		{ field: "email", match: matchesAny(["#billing_email", "#email"], { type: "email" }) },
		{ field: "phone", match: matchesAny(["#billing_phone", "#billing-phone", "#shipping-phone"], { type: "tel" }) },
		{ field: "first_name", match: matchesAny(["#billing_first_name", "#billing-first_name", "#shipping-first_name"], { autocomplete: "given-name" }) },
		{ field: "last_name", match: matchesAny(["#billing_last_name", "#billing-last_name", "#shipping-last_name"], { autocomplete: "family-name" }) }
	];

	var pending = {};
	var lastSent = {};
	var debounceTimer = null;

	function matchesAny(selectors, attrs) {
		return function (el) {
			for (var i = 0; i < selectors.length; i++) {
				if (el.matches(selectors[i])) {
					return true;
				}
			}
			if (attrs.type && el.type === attrs.type) {
				return true;
			}
			if (attrs.autocomplete && el.getAttribute("autocomplete") === attrs.autocomplete) {
				return true;
			}
			return false;
		};
	}

	function fieldFor(el) {
		if (!el || el.tagName !== "INPUT") {
			return null;
		}
		for (var i = 0; i < FIELD_MAP.length; i++) {
			if (FIELD_MAP[i].match(el)) {
				return FIELD_MAP[i].field;
			}
		}
		return null;
	}

	function collect(el) {
		var field = fieldFor(el);
		if (!field) {
			return false;
		}
		var value = (el.value || "").trim();
		if (value === "" || value === lastSent[field]) {
			return false;
		}
		pending[field] = value;
		return true;
	}

	function buildBody() {
		var body = new URLSearchParams();
		body.set("action", cfg.action);
		body.set("nonce", cfg.nonce);
		var has = false;
		for (var field in pending) {
			if (Object.prototype.hasOwnProperty.call(pending, field)) {
				body.set(field, pending[field]);
				has = true;
			}
		}
		return has ? body : null;
	}

	function send() {
		var body = buildBody();
		if (!body) {
			return;
		}
		var sent = Object.assign({}, pending);
		pending = {};
		fetch(cfg.ajaxUrl, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
			body: body.toString()
		}).then(function (response) {
			if (response.ok) {
				for (var field in sent) {
					if (Object.prototype.hasOwnProperty.call(sent, field)) {
						lastSent[field] = sent[field];
					}
				}
			}
		}).catch(function () {
			// Silêncio deliberado: captura é best-effort e nunca pode
			// atrapalhar o checkout.
		});
	}

	function flushWithBeacon() {
		var body = buildBody();
		if (!body) {
			return;
		}
		pending = {};
		if (navigator.sendBeacon) {
			var blob = new Blob([body.toString()], {
				type: "application/x-www-form-urlencoded;charset=UTF-8"
			});
			navigator.sendBeacon(cfg.ajaxUrl, blob);
		}
	}

	function scheduleSend() {
		if (debounceTimer) {
			window.clearTimeout(debounceTimer);
		}
		debounceTimer = window.setTimeout(send, DEBOUNCE_MS);
	}

	document.addEventListener(
		"blur",
		function (event) {
			if (collect(event.target)) {
				send();
			}
		},
		true
	);

	document.addEventListener("input", function (event) {
		if (collect(event.target)) {
			scheduleSend();
		}
	});

	document.addEventListener("visibilitychange", function () {
		if (document.visibilityState === "hidden") {
			flushWithBeacon();
		}
	});
	window.addEventListener("pagehide", flushWithBeacon);
})();
