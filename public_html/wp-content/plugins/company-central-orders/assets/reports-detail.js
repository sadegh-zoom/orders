(function () {
	'use strict';
	var config = window.CCO_REPORTS || {};
	if (!config.url || !config.nonce) return;

	function escapeHtml(value) {
		var node = document.createElement('div');
		node.textContent = String(value == null ? '' : value);
		return node.innerHTML;
	}

	function number(value) {
		return new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
	}

	function money(value) {
		return number(Math.round(Number(value) || 0)) + ' تومان';
	}

	function filterValue(selector) {
		var field = document.querySelector(selector);
		return field ? field.value : '';
	}

	function openShippingOrders(row, event) {
		var panel = row.closest('.report-panel[data-panel="shipping"]');
		if (!panel || !row.dataset.detail) return false;
		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();

		var modal = document.querySelector('#cco-reports-root #modal');
		if (!modal) return true;
		var card = modal.querySelector('.modal-card');
		var content = card.querySelector('p');
		var method = row.dataset.detail;
		modal.hidden = false;
		card.querySelector('h2').textContent = 'سفارش‌های روش ارسال «' + method + '»';
		content.innerHTML = '<span class="detail-loading">در حال دریافت سفارش‌ها…</span>';

		var activePeriod = document.querySelector('[data-period].active');
		var query = new URLSearchParams({
			section: 'shipping',
			value: method,
			period: activePeriod ? activePeriod.dataset.period : 'day',
			store: filterValue('#store'),
			agent: filterValue('#agent'),
			from: filterValue('#from'),
			to: filterValue('#to')
		});

		fetch(config.url + '/orders?' + query.toString(), { headers: { 'X-WP-Nonce': config.nonce } })
			.then(function (response) {
				if (!response.ok) throw new Error('request_failed');
				return response.json();
			})
			.then(function (data) {
				var html = '<div class="detail-meta">' + number(data.total) + ' سفارش در بازه انتخابی' + (data.limited ? ' · نمایش ۱۰۰ مورد اول' : '') + '</div>';
				if (!data.orders.length) {
					html += '<div class="empty">سفارشی برای این روش پیدا نشد.</div>';
				} else {
					html += '<div class="detail-orders">' + data.orders.map(function (order) {
						return '<article><div><b>' + escapeHtml(order.store) + ' · سفارش #' + escapeHtml(order.number) + '</b><span>' + escapeHtml(order.customer) + '</span></div><div><span>' + escapeHtml(order.status) + '</span><small>' + escapeHtml(order.date) + '</small></div><div><b>' + money(order.total) + '</b><small>ارسال: ' + money(order.shipping) + '</small></div><a href="' + escapeHtml(order.url) + '" target="_blank" rel="noopener">مشاهده سفارش ↗</a></article>';
					}).join('') + '</div>';
				}
				content.innerHTML = html;
			})
			.catch(function () {
				content.innerHTML = '<div class="cco-report-error">دریافت سفارش‌ها ناموفق بود. دوباره تلاش کنید.</div>';
			});
		return true;
	}

	document.addEventListener('click', function (event) {
		var row = event.target.closest && event.target.closest('.report-row');
		if (row) openShippingOrders(row, event);
	}, true);
}());
