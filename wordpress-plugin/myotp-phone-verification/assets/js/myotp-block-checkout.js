(function () {
	'use strict';

	var tpl = document.getElementById('myotp-pv-block-template');
	if (!tpl || !tpl.content || !tpl.content.firstElementChild) {
		return;
	}
	var root = null;
	var texts = window.myotpPvBlock || { refusals: [], addPhone: '' };

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
		return rowEnd(input.closest('.wc-block-components-address-form__phone') || input.parentNode);
	}

	// The address form lays fields out two to a row. The widget is a full-width
	// row, so it goes after the last field that shares the phone field's row.
	function rowEnd(field) {
		var end = field;
		var sib = field.nextElementSibling;
		while (sib && sib !== root && sib.offsetTop === field.offsetTop) {
			end = sib;
			sib = sib.nextElementSibling;
		}
		return end;
	}

	// Some WooCommerce versions do not render the address form at all while the
	// saved address is collapsed: then the widget goes after that card.
	function collapsedCard() {
		return document.querySelector('.wc-block-checkout__billing-fields .wc-block-components-address-address-wrapper:not(.is-editing)') ||
			document.querySelector('.wc-block-checkout__shipping-fields .wc-block-components-address-address-wrapper:not(.is-editing)');
	}

	// The collapsed address card the widget sits under, or null in form view.
	function cardAbove() {
		var prev = root && root.previousElementSibling;
		return prev && prev.classList.contains('wc-block-components-address-address-wrapper') && !prev.classList.contains('is-editing') ? prev : null;
	}

	function phoneValue() {
		var input = phoneField();
		return input ? String(input.value || '').replace(/[^0-9]/g, '') : '';
	}

	function setStatus(text) {
		var el = root && root.querySelector('.myotp-pv-status');
		if (!el || !text) {
			return;
		}
		el.textContent = '';
		var span = document.createElement('span');
		span.className = 'myotp-pv-err';
		span.textContent = text;
		el.appendChild(span);
	}

	// No phone to verify while the address is a card: open the address form with
	// the card's own Edit control, then, after React re-rendered it, move the widget
	// under the phone field and focus that field. One click and one timeout, no loop.
	// Returns false when the widget is not under a card (the caller carries on).
	function openAddress(message) {
		var card = cardAbove();
		if (!card) {
			return false;
		}
		var edit = card.querySelector('.wc-block-components-address-card__edit');
		if (!edit) {
			setStatus(texts.addPhone);
			return true;
		}
		edit.click();
		setTimeout(function () {
			ensure();
			if (cardAbove()) {
				// The form did not open: say what to do rather than focus a hidden field.
				setStatus(texts.addPhone);
				return;
			}
			var input = phoneField();
			if (input) {
				input.focus();
			}
			setStatus(message);
		}, 350); // WooCommerce focuses the form's first field 300 ms after it opens; focus the phone after that.
		return true;
	}

	// Send code pressed with no phone number (the verify script asks first).
	document.addEventListener('myotp:need-phone', function (e) {
		if (root && e.target === root && openAddress(window.myotpPv ? window.myotpPv.i18n.needPhone : '')) {
			e.preventDefault();
		}
	});

	// Place order refused for a missing or unverified phone while the address is a
	// card without a phone: the notice must not send the shopper to a hidden field,
	// so open the form. Each notice is handled once.
	function refusalShown() {
		if (!cardAbove() || '' !== phoneValue() || !texts.refusals || !texts.refusals.length) {
			return;
		}
		var notices = document.querySelectorAll('.wc-block-components-notice-banner:not([data-myotp-seen]), .wc-block-store-notice:not([data-myotp-seen])');
		for (var i = 0; i < notices.length; i++) {
			var text = notices[i].textContent || '';
			for (var j = 0; j < texts.refusals.length; j++) {
				if (texts.refusals[j] && text.indexOf(texts.refusals[j]) !== -1) {
					notices[i].setAttribute('data-myotp-seen', '1');
					openAddress(window.myotpPv ? window.myotpPv.i18n.needPhone : '');
					return;
				}
			}
		}
	}

	function ensure() {
		var input = phoneField();
		var anchor = input ? placement(input) : collapsedCard();
		if (!anchor || !anchor.parentNode) {
			return;
		}
		if (!root) {
			root = tpl.content.firstElementChild.cloneNode(true);
		}
		var selector = input ? '#' + input.id : root.getAttribute('data-phone-selector');
		if (root.getAttribute('data-phone-selector') !== selector) {
			root.setAttribute('data-phone-selector', selector);
		}
		if (root.previousElementSibling !== anchor) {
			anchor.parentNode.insertBefore(root, anchor.nextSibling);
		}
		document.dispatchEvent(new CustomEvent('myotp:bind'));
		refusalShown();
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
	// A resize can change which fields share the phone field's row.
	var timer = null;
	function later() {
		if (timer) {
			return;
		}
		timer = setTimeout(function () {
			timer = null;
			ensure();
		}, 100);
	}
	new MutationObserver(later).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
	window.addEventListener('resize', later);
	ensure();
})();
