(function () {
	'use strict';
	var config = window.CCO_REPORTS || {};
	if (!config.url || !config.nonce) return;
	var fa = new Intl.NumberFormat('fa-IR');
	var active = null;

	function esc(value) {
		var node = document.createElement('div');
		node.textContent = String(value == null ? '' : value);
		return node.innerHTML;
	}

	function num(value) { return fa.format(Number(value) || 0); }
	function money(value) { return num(Math.round(Number(value) || 0)) + ' تومان'; }
	function value(selector) { var field=document.querySelector(selector); return field ? field.value : ''; }

	function baseQuery(method) {
		var period=document.querySelector('[data-period].active');
		return {
			section:'shipping', value:method, period:period ? period.dataset.period : 'day',
			store:value('#store'), agent:value('#agent'), from:value('#from'), to:value('#to')
		};
	}

	function filters(stores) {
		return '<form class="detail-filters"><label>فروشگاه<select name="detail_store"><option value="">همه فروشگاه‌ها</option>'+Object.keys(stores||{}).map(function(key){return '<option value="'+esc(key)+'">'+esc(stores[key])+'</option>';}).join('')+'</select></label><label>از تاریخ<input name="detail_from" type="date"></label><label>تا تاریخ<input name="detail_to" type="date"></label><label>حداقل مبلغ<input name="min_total" type="number" min="0" step="1000" placeholder="۰"></label><label>حداکثر مبلغ<input name="max_total" type="number" min="0" step="1000" placeholder="بدون محدودیت"></label><button type="submit">اعمال فیلتر</button><button type="button" data-clear-detail>پاک‌کردن</button></form>';
	}

	function table(data) {
		if (!data.orders.length) return '<div class="empty">سفارشی با این فیلترها پیدا نشد.</div>';
		return '<div class="detail-table-wrap"><table class="detail-table"><thead><tr><th>ردیف</th><th>فروشگاه</th><th>شماره سفارش</th><th>مشتری</th><th>وضعیت</th><th>تاریخ ثبت</th><th>مبلغ سفارش</th><th>مبلغ ارسال</th><th></th></tr></thead><tbody>'+data.orders.map(function(order,index){return '<tr><td>'+num(index+1)+'</td><td><span class="store-badge">'+esc(order.store)+'</span></td><td><b>#'+esc(order.number)+'</b></td><td>'+esc(order.customer)+'</td><td>'+esc(order.status)+'</td><td class="nowrap">'+esc(order.date)+'</td><td class="nowrap">'+money(order.total)+'</td><td class="nowrap">'+money(order.shipping)+'</td><td><a href="'+esc(order.url)+'" target="_blank" rel="noopener">مشاهده ↗</a></td></tr>';}).join('')+'</tbody></table></div>';
	}

	function request(method, form, content) {
		var params=baseQuery(method);
		if(form)new FormData(form).forEach(function(v,k){params[k]=v;});
		content.querySelector('.detail-results').innerHTML='<div class="detail-loading">در حال دریافت سفارش‌ها…</div>';
		fetch(config.url+'/orders?'+new URLSearchParams(params),{headers:{'X-WP-Nonce':config.nonce}}).then(function(response){if(!response.ok)throw new Error();return response.json();}).then(function(data){
			var result=content.querySelector('.detail-results');
			result.innerHTML='<div class="detail-meta"><b>'+num(data.total)+'</b> سفارش پیدا شد'+(data.limited?' · نمایش ۱۰۰ مورد اول':'')+'</div>'+table(data);
			var storeSelect=content.querySelector('[name="detail_store"]');
			if(storeSelect&&storeSelect.options.length===1){storeSelect.insertAdjacentHTML('beforeend',Object.keys(data.stores||{}).map(function(key){return '<option value="'+esc(key)+'">'+esc(data.stores[key])+'</option>';}).join(''));}
		}).catch(function(){content.querySelector('.detail-results').innerHTML='<div class="cco-report-error">دریافت سفارش‌ها ناموفق بود. دوباره تلاش کنید.</div>';});
	}

	function open(row,event) {
		if(!row.closest('.report-panel[data-panel="shipping"]')||!row.dataset.detail)return;
		event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation();
		var modal=document.querySelector('#cco-reports-root #modal'), card=modal&&modal.querySelector('.modal-card');
		if(!card)return;
		active={method:row.dataset.detail}; modal.hidden=false;
		card.querySelector('h2').textContent='سفارش‌های روش ارسال «'+active.method+'»';
		var holder=card.querySelector('p');
		holder.innerHTML='<div class="detail-workspace">'+filters({})+'<div class="detail-results"></div></div>';
		var form=holder.querySelector('.detail-filters');
		form.addEventListener('submit',function(e){e.preventDefault();request(active.method,form,holder);});
		form.querySelector('[data-clear-detail]').addEventListener('click',function(){form.reset();request(active.method,form,holder);});
		request(active.method,form,holder);
	}

	document.addEventListener('click',function(event){var row=event.target.closest&&event.target.closest('.report-row');if(row)open(row,event);},true);
}());
