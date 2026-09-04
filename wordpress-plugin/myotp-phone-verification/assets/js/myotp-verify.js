/* global myotpPv */
(function () {
	'use strict';

	if (typeof myotpPv === 'undefined') {
		return;
	}

	function post(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', myotpPv.nonce);
		Object.keys(fields).forEach(function (k) {
			body.append(k, fields[k]);
		});
		return fetch(myotpPv.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) {
				return r.json().catch(function () {
					return { success: false, data: { message: myotpPv.i18n.network } };
				});
			});
	}

	function digits(v) {
		return String(v || '').replace(/[^0-9]/g, '');
	}

	function setStatus(root, text, ok) {
		var el = root.querySelector('.myotp-pv-status');
		if (!el) {
			return;
		}
		el.textContent = '';
		if (!text) {
			return;
		}
		var span = document.createElement('span');
		span.className = ok ? 'myotp-pv-ok' : 'myotp-pv-err';
		span.textContent = text;
		el.appendChild(span);
	}

	function phoneInput(root) {
		var selector = root.getAttribute('data-phone-selector');
		if (selector) {
			return document.querySelector(selector);
		}
		return root.querySelector('.myotp-pv-phone');
	}

	function bind(root) {
		var sendBtn = root.querySelector('.myotp-pv-send');
		var verifyBtn = root.querySelector('.myotp-pv-verify');
		var codeRow = root.querySelector('.myotp-pv-code-row');
		var codeInput = root.querySelector('.myotp-pv-code');
		var sentTo = '';

		function lock(el, on) {
			if (el) {
				el.disabled = !!on;
			}
		}

		sendBtn.addEventListener('click', function () {
			var input = phoneInput(root);
			var phone = digits(input ? input.value : '');
			if (!phone) {
				setStatus(root, myotpPv.i18n.needPhone, false);
				if (input) {
					input.focus();
				}
				return;
			}
			lock(sendBtn, true);
			setStatus(root, myotpPv.i18n.sending, true);
			post('myotp_pv_send', { phone: phone }).then(function (res) {
				lock(sendBtn, false);
				if (res && res.success) {
					sentTo = res.data.phone || phone;
					root.setAttribute('data-verified', '0');
					codeRow.hidden = false;
					sendBtn.textContent = myotpPv.i18n.sendAgain;
					setStatus(root, res.data.message || myotpPv.i18n.sent, true);
					codeInput.value = '';
					codeInput.focus();
				} else {
					setStatus(root, (res && res.data && res.data.message) || myotpPv.i18n.network, false);
				}
			}).catch(function () {
				lock(sendBtn, false);
				setStatus(root, myotpPv.i18n.network, false);
			});
		});

		function verify() {
			var otp = digits(codeInput.value);
			if (!otp) {
				setStatus(root, myotpPv.i18n.needCode, false);
				codeInput.focus();
				return;
			}
			var input = phoneInput(root);
			var phone = digits(input ? input.value : '') || sentTo;
			lock(verifyBtn, true);
			setStatus(root, myotpPv.i18n.checking, true);
			post('myotp_pv_verify', { phone: phone, otp: otp }).then(function (res) {
				lock(verifyBtn, false);
				if (res && res.success) {
					root.setAttribute('data-verified', '1');
					codeRow.hidden = true;
					lock(sendBtn, true);
					if (input && !root.getAttribute('data-phone-selector')) {
						input.disabled = true;
					}
					setStatus(root, res.data.message || myotpPv.i18n.verified, true);
					document.dispatchEvent(new CustomEvent('myotp:verified', {
						detail: { phone: res.data.phone || phone, context: root.getAttribute('data-context') || '' }
					}));
				} else {
					setStatus(root, (res && res.data && res.data.message) || myotpPv.i18n.network, false);
					codeInput.select();
				}
			}).catch(function () {
				lock(verifyBtn, false);
				setStatus(root, myotpPv.i18n.network, false);
			});
		}

		verifyBtn.addEventListener('click', verify);
		codeInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				verify();
			}
		});

		// If the bound external phone field changes after verification, re-arm the widget.
		var ext = root.getAttribute('data-phone-selector') ? phoneInput(root) : null;
		if (ext) {
			ext.addEventListener('input', function () {
				if (root.getAttribute('data-verified') === '1') {
					root.setAttribute('data-verified', '0');
					lock(sendBtn, false);
					sendBtn.textContent = myotpPv.i18n.send;
					setStatus(root, '', true);
				}
			});
		}
	}

	function bindAll() {
		document.querySelectorAll('.myotp-pv:not([data-bound])').forEach(function (root) {
			root.setAttribute('data-bound', '1');
			bind(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindAll);
	} else {
		bindAll();
	}
	// WooCommerce re-renders checkout fragments; rebind after each update.
	document.addEventListener('updated_checkout', bindAll);
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', bindAll);
	}
})();
