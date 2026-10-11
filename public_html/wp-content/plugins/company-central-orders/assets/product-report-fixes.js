(function () {
	'use strict';
	var config = window.CCO_PRODUCT_REPORT || {};
	var fa = new Intl.NumberFormat('fa-IR');
	var detailState = { name:'', sku:'', page:1 };
	function esc(value) { var node=document.createElement('div'); node.textContent=String(value==null?'':value); return node.innerHTML; }
	function num(value) { return fa.format(Number(value)||0); }
	function money(value) { return num(Math.round(Number(value)||0))+' تومان'; }
	function syncPeriod() {
		var select = document.querySelector('#pr-filters [name="period"]');
		if (!select) return;
		if (!select.querySelector('[value="yesterday"]')) {
			var option = document.createElement('option');
			option.value = 'yesterday'; option.textContent = 'دیروز';
			select.insertBefore(option, select.firstChild);
		}
		if (!select.dataset.initialized) { select.value = config.period || 'yesterday'; select.dataset.initialized = '1'; }
	}
	function syncViewColumns() {
		var metric = document.querySelector('#pr-filters [name="metric"]');
		var table = document.querySelector('.pr-table-wrap table');
		if (!metric || !table) return;
		var isViews = metric.value === 'views';
		table.querySelectorAll('tr').forEach(function (row) {
			var cells = row.children;
			var quantityLabel = isViews ? 'تعداد بازدید' : 'تعداد';
			var display = isViews ? 'none' : '';
			if (cells[3] && row.parentElement.tagName === 'THEAD' && cells[3].textContent !== quantityLabel) cells[3].textContent = quantityLabel;
			if (cells[4] && cells[4].style.display !== display) cells[4].style.display = display;
			if (cells[5] && cells[5].style.display !== display) cells[5].style.display = display;
		});
	}
	function syncCancelledRows() {
		var metric = document.querySelector('#pr-filters [name="metric"]');
		var table = document.querySelector('.pr-table-wrap table');
		if (!metric || !table) return;
		var cancelled = metric.value === 'cancelled';
		table.classList.toggle('is-cancelled-report', cancelled);
		table.querySelectorAll('tbody tr').forEach(function (row) {
			row.title = cancelled ? 'نمایش سفارش‌های لغوشده این محصول' : '';
			row.setAttribute('tabindex', cancelled ? '0' : '-1');
		});
		var heading = document.querySelector('.pr-heading p');
		var headingText = cancelled ? 'برای مشاهده سفارش‌های لغوشده روی هر محصول کلیک کنید.' : 'رتبه‌بندی کامل محصولات با فیلتر و مرتب‌سازی';
		if (heading && heading.textContent !== headingText) heading.textContent = headingText;
	}
	function modal() {
		var item=document.getElementById('product-cancelled-orders'); if(item)return item;
		item=document.createElement('div'); item.id='product-cancelled-orders'; item.className='product-orders-modal'; item.hidden=true;
		item.innerHTML='<div class="product-orders-dialog"><button class="product-orders-close" aria-label="بستن">×</button><header><p>سفارش‌های لغوشده محصول</p><h2 id="product-orders-title"></h2><span id="product-orders-meta"></span></header><div id="product-orders-body"></div><nav id="product-orders-pages"></nav></div>';
		document.body.appendChild(item); item.querySelector('.product-orders-close').onclick=function(){item.hidden=true;}; item.onclick=function(e){if(e.target===item)item.hidden=true;}; return item;
	}
	function detailQuery() {
		var form=document.querySelector('#pr-filters');
		return new URLSearchParams({name:detailState.name,sku:detailState.sku,period:form.period.value,store:form.store.value,page:detailState.page,per_page:50});
	}
	function loadProductOrders() {
		var box=modal(),body=box.querySelector('#product-orders-body'),pages=box.querySelector('#product-orders-pages'); box.hidden=false;
		box.querySelector('#product-orders-title').textContent=detailState.name; box.querySelector('#product-orders-meta').textContent='SKU: '+detailState.sku; body.innerHTML='<div class="product-orders-loading">در حال دریافت سفارش‌ها…</div>'; pages.innerHTML='';
		fetch(config.productOrdersUrl+'?'+detailQuery(),{headers:{'X-WP-Nonce':config.nonce}}).then(function(r){if(!r.ok)throw new Error();return r.json();}).then(function(d){
			box.querySelector('#product-orders-meta').textContent='SKU: '+d.product.sku+' · '+num(d.total)+' سفارش';
			if(!d.orders.length){body.innerHTML='<div class="product-orders-empty">سفارش لغوشده‌ای پیدا نشد.</div>';return;}
			body.innerHTML='<div class="product-orders-table-wrap"><table class="product-orders-table"><thead><tr><th>سفارش</th><th>فروشگاه</th><th>مشتری</th><th>تاریخ</th><th>وضعیت</th><th>تعداد محصول</th><th>مبلغ محصول</th><th>مبلغ سفارش</th><th></th></tr></thead><tbody>'+d.orders.map(function(o){return '<tr><td><b>#'+esc(o.number)+'</b></td><td>'+esc(o.store)+'</td><td>'+esc(o.customer)+'</td><td class="nowrap">'+esc(o.date)+'</td><td><span class="cancelled-status">'+esc(o.status)+'</span></td><td>'+num(o.quantity)+'</td><td class="nowrap">'+money(o.productTotal)+'</td><td class="nowrap">'+money(o.orderTotal)+'</td><td><a href="'+esc(o.url)+'" target="_blank" rel="noopener">مشاهده ↗</a></td></tr>';}).join('')+'</tbody></table></div>';
			if(d.totalPages>1)pages.innerHTML='<button '+(d.page<=1?'disabled':'')+' data-detail-page="'+(d.page-1)+'">قبلی</button><span>صفحه '+num(d.page)+' از '+num(d.totalPages)+'</span><button '+(d.page>=d.totalPages?'disabled':'')+' data-detail-page="'+(d.page+1)+'">بعدی</button>';
			pages.querySelectorAll('button').forEach(function(button){button.onclick=function(){detailState.page=Number(button.dataset.detailPage);loadProductOrders();};});
		}).catch(function(){body.innerHTML='<div class="product-orders-empty error">دریافت سفارش‌ها ناموفق بود.</div>';});
	}
	function openRow(row) {
		var metric=document.querySelector('#pr-filters [name="metric"]'); if(!metric||metric.value!=='cancelled')return;
		detailState.name=row.dataset.productName||(row.children[1]||{}).textContent||''; detailState.sku=row.dataset.productSku||(row.children[2]||{}).textContent||'—'; detailState.page=1; loadProductOrders();
	}
	function sync() { syncPeriod(); syncViewColumns(); syncCancelledRows(); }
	new MutationObserver(sync).observe(document.documentElement, { childList:true, subtree:true });
	document.addEventListener('change', function (event) { if (event.target && event.target.name === 'metric') setTimeout(function(){syncViewColumns();syncCancelledRows();}, 0); });
	document.addEventListener('click',function(event){var trigger=event.target.closest&&event.target.closest('.show-cancelled-orders');var row=event.target.closest&&event.target.closest('.pr-table-wrap tbody tr');if(trigger){event.preventDefault();event.stopPropagation();openRow(trigger.closest('tr'));return;}if(row&&!event.target.closest('a'))openRow(row);});
	document.addEventListener('keydown',function(event){var row=event.target.closest&&event.target.closest('.pr-table-wrap tbody tr');if(row&&!event.target.closest('a')&&(event.key==='Enter'||event.key===' ')){event.preventDefault();openRow(row);}});
	sync();
}());
