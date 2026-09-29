(function () {
	'use strict';

	var tpl = document.getElementById('myotp-pv-block-template');
	if (!tpl || !tpl.content || !tpl.content.firstElementChild) {
		return;
	}
	var root = null;

	// With "same address for billing" the block checkout shows only the shipping form.
	function phoneField() {
		return document.getElementById('billing-phone') || document.getElementById('shipping-phone');
	}

	// Where the widget goes. A returning shopper sees the saved address as a
	// card with an Edit link while the form (phone field included) stays in the
	// page, hidden: then the widget goes right after the card, still bound to
	// the hidden phone field, which holds the saved number. While the form is
	// open it goes right after the phone field.
	function placement(input) {
		var wrapper = input.closest('.wc-block-components-address-address-wrapper');
		if (wrapper && wrapper.parentNode && !wrapper.classList.contains('is-editing')) {
			return wrapper;
		}
		return input.closest('.wc-block-components-address-form__phone') || input.parentNode;
	}

	function ensure() {
		var input = phoneField();
		if (!input) {
			return;
		}
		var anchor = placement(input);
		if (!anchor || !anchor.parentNode) {
			return;
		}
		if (!root) {
			root = tpl.content.firstElementChild.cloneNode(true);
		}
		var selector = '#' + input.id;
		if (root.getAttribute('data-phone-selector') !== selector) {
			root.setAttribute('data-phone-selector', selector);
		}
		if (root.previousElementSibling !== anchor) {
			anchor.parentNode.insertBefore(root, anchor.nextSibling);
		}
		document.dispatchEvent(new CustomEvent('myotp:bind'));
	}

	// A changed number is a different number: re-arm a verified widget.
	document.addEventListener('input', function (e) {
		var id = e.target && e.target.id;
		if (!root || (id !== 'billing-phone' && id !== 'shipping-phone')) {
			return;
		}
		if (root.getAttribute('data-verified') !== '1') {
			return;
		}
		root.setAttribute('data-verified', '0');
		var send = root.querySelector('.myotp-pv-send');
		if (send) {
			send.disabled = false;
			if (window.myotpPv) {
				send.textContent = window.myotpPv.i18n.send;
			}
		}
		var status = root.querySelector('.myotp-pv-status');
		if (status) {
			status.textContent = '';
		}
	});

	// React owns the checkout tree and re-renders it: put the widget back when it
	// goes, and move it when the address switches between card and form (a class change).
	var timer = null;
	new MutationObserver(function () {
		if (timer) {
			return;
		}
		timer = setTimeout(function () {
			timer = null;
			ensure();
		}, 100);
	}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
	ensure();
})();
