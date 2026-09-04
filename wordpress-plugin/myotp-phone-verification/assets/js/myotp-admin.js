/* global myotpPvAdmin */
(function () {
	'use strict';

	if (typeof myotpPvAdmin === 'undefined') {
		return;
	}

	var btn = document.getElementById('myotp_pv_test_send');
	var phone = document.getElementById('myotp_pv_test_phone');
	var out = document.getElementById('myotp_pv_test_result');
	if (!btn || !phone || !out) {
		return;
	}

	function show(text, ok) {
		out.textContent = '';
		var span = document.createElement('span');
		span.className = ok ? 'myotp-pv-ok' : 'myotp-pv-err';
		span.textContent = text;
		out.appendChild(span);
	}

	btn.addEventListener('click', function () {
		var digits = String(phone.value || '').replace(/[^0-9]/g, '');
		if (!digits) {
			show(myotpPvAdmin.i18n.needPhone, false);
			phone.focus();
			return;
		}
		btn.disabled = true;
		show(myotpPvAdmin.i18n.sending, true);

		var body = new FormData();
		body.append('action', 'myotp_pv_test');
		body.append('nonce', myotpPvAdmin.nonce);
		body.append('phone', digits);

		fetch(myotpPvAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.disabled = false;
				if (res && res.success) {
					var extra = [];
					if (res.data.status) {
						extra.push('status ' + res.data.status);
					}
					if (res.data.cost !== null && res.data.cost !== undefined) {
						extra.push('cost ' + res.data.cost);
					}
					show(res.data.message + (extra.length ? ' (' + extra.join(', ') + ')' : ''), true);
				} else {
					show((res && res.data && res.data.message) || myotpPvAdmin.i18n.network, false);
				}
			})
			.catch(function () {
				btn.disabled = false;
				show(myotpPvAdmin.i18n.network, false);
			});
	});
})();
