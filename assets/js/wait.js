/**
 * WooCommerce Mpesa Express: "check your phone" screen on the order-received page.
 * Polls the order every few seconds; reloads the page when paid; offers a resend.
 */
(function () {
	'use strict';
	var cfg = window.wcMpesaWait;
	var box = document.querySelector('.wc-mpesa-wait');
	if (!cfg || !box || !window.fetch) return;

	var message = box.querySelector('.wc-mpesa-wait__message');
	var resend = box.querySelector('.wc-mpesa-wait__resend');
	var started = Date.now();
	var timer = null;
	var busy = false;

	function call(url) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ key: cfg.key })
		}).then(function (r) { return r.json(); });
	}

	function show(data) {
		if (!data || !data.state) return;
		box.setAttribute('data-state', data.state);
		if (data.state === 'paid') {
			message.textContent = cfg.text.paid;
			clearTimeout(timer);
			window.location.replace(cfg.reloadUrl);
			return;
		}
		if (data.message) {
			message.textContent = data.message;
		} else if (data.state === 'failed') {
			message.textContent = data.note || cfg.text.failed;
		} else if (data.note) {
			message.textContent = data.note;
		}
	}

	function poll() {
		if (Date.now() - started > cfg.timeout) {
			box.setAttribute('data-state', 'timeout');
			message.textContent = cfg.text.timeout;
			return;
		}
		call(cfg.statusUrl).then(function (data) {
			show(data);
			if (data.state === 'waiting') timer = setTimeout(poll, cfg.interval);
		}, function () {
			message.textContent = cfg.text.error;
			timer = setTimeout(poll, cfg.interval * 2);
		});
	}

	if (resend) {
		resend.addEventListener('click', function () {
			if (busy) return;
			busy = true;
			resend.disabled = true;
			message.textContent = cfg.text.sending;
			call(cfg.resendUrl).then(function (data) {
				show(data);
				if (data.resent) {
					started = Date.now();
					box.setAttribute('data-state', 'waiting');
					clearTimeout(timer);
					timer = setTimeout(poll, cfg.interval);
				}
			}, function () {
				message.textContent = cfg.text.error;
			}).then(function () {
				busy = false;
				setTimeout(function () { resend.disabled = false; }, 5000);
			});
		});
	}

	if (box.getAttribute('data-state') === 'waiting') timer = setTimeout(poll, cfg.interval);
})();
