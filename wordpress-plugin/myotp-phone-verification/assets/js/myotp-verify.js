/* global myotpPv */
(function () {
	'use strict';

	if (typeof myotpPv === 'undefined') {
		return;
	}

	function post(action, fields, nonce) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce || myotpPv.nonce);
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
		// Held order after an express wallet payment: the server takes the phone from the order.
		var orderId = root.getAttribute('data-order') || '';
		var orderFields = orderId ? { order_id: orderId, key: root.getAttribute('data-order-key') || '' } : null;
		var orderNonce = orderId ? root.getAttribute('data-nonce') || '' : '';

		function lock(el, on) {
			if (el) {
				el.disabled = !!on;
			}
		}

		// After a send, "Send again" waits 30 seconds (display only; the server limits are unchanged).
		var countdown = null;
		function stopCountdown() {
			if (countdown) {
				clearInterval(countdown);
				countdown = null;
			}
		}
		function startCountdown() {
			var left = 30;
			stopCountdown();
			lock(sendBtn, true);
			sendBtn.textContent = myotpPv.i18n.sendAgainIn.replace('%d', left);
			countdown = setInterval(function () {
				left -= 1;
				if (left > 0) {
					sendBtn.textContent = myotpPv.i18n.sendAgainIn.replace('%d', left);
					return;
				}
				stopCountdown();
				sendBtn.textContent = myotpPv.i18n.sendAgain;
				lock(sendBtn, root.getAttribute('data-verified') === '1');
			}, 1000);
		}

		sendBtn.addEventListener('click', function () {
			var input = phoneInput(root);
			var phone = digits(input ? input.value : '');
			var hiddenPhone = root.querySelector('.myotp-pv-phone-hidden');
			if (hiddenPhone) {
				hiddenPhone.value = phone;
			}
			if (!phone) {
				setStatus(root, myotpPv.i18n.needPhone, false);
				// The block checkout script takes this over when the phone field is in a
				// collapsed address (it opens the form); never focus a field nobody can see.
				var need = new CustomEvent('myotp:need-phone', { bubbles: true, cancelable: true });
				if (root.dispatchEvent(need) && input && input.getClientRects().length > 0) {
					input.focus();
				}
				return;
			}
			lock(sendBtn, true);
			setStatus(root, myotpPv.i18n.sending, true);
			var sending = orderFields ? post('myotp_pv_order_send', orderFields, orderNonce) : post('myotp_pv_send', { phone: phone });
			sending.then(function (res) {
				lock(sendBtn, false);
				if (res && res.success) {
					sentTo = res.data.phone || phone;
					root.setAttribute('data-verified', '0');
					codeRow.hidden = false;
					startCountdown();
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
			var checking = orderFields
				? post('myotp_pv_order_verify', Object.assign({ otp: otp }, orderFields), orderNonce)
				: post('myotp_pv_verify', { phone: phone, otp: otp });
			checking.then(function (res) {
				lock(verifyBtn, false);
				if (res && res.success) {
					root.setAttribute('data-verified', '1');
					codeRow.hidden = true;
					stopCountdown();
					lock(sendBtn, true);
					if (input && !root.getAttribute('data-phone-selector') && !orderFields) {
						input.value = res.data.phone || phone;
						input.disabled = true;
					}
					var hidden = root.querySelector('.myotp-pv-phone-hidden');
					if (hidden) {
						hidden.value = res.data.phone || phone;
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

		// Keep the hidden posted field in step with the inline input.
		var inline = root.getAttribute('data-phone-selector') ? null : phoneInput(root);
		if (inline) {
			inline.addEventListener('input', function () {
				var h = root.querySelector('.myotp-pv-phone-hidden');
				if (h) {
					h.value = digits(inline.value);
				}
			});
		}

		// If the bound external phone field changes after verification, re-arm the widget.
		var ext = root.getAttribute('data-phone-selector') ? phoneInput(root) : null;
		if (ext) {
			ext.addEventListener('input', function () {
				if (root.getAttribute('data-verified') === '1') {
					root.setAttribute('data-verified', '0');
					stopCountdown();
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
	// Widgets injected later (block checkout) ask for a bind with this event.
	document.addEventListener('myotp:bind', bindAll);
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', bindAll);
	}
})();
