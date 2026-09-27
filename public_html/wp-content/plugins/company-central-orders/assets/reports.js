(function () {
  'use strict';
  var root = document.getElementById('cco-reports-root');
  if (!root || !window.CCO_REPORTS) return;
  var state = { period: 'day', store: '', agent: '', from: '', to: '', data: null };
  var nf = new Intl.NumberFormat('fa-IR');
  var money = function (v) { return nf.format(Math.round(Number(v) || 0)) + ' تومان'; };
  var num = function (v) { return nf.format(Number(v) || 0); };
  var esc = function (v) { var d=document.createElement('div'); d.textContent=String(v==null?'':v); return d.innerHTML; };
  var icons = { orders:'◫', successful:'✓', agentOrders:'♙', revenue:'◈', agentRevenue:'♢', cancelled:'×', agentCancelled:'⊘', basket:'▦', average:'⌁' };
  var labels = { orders:'کل سفارش‌ها', successful:'سفارش موفق', agentOrders:'سفارش Agentها', revenue:'مبلغ سفارش', agentRevenue:'مبلغ سفارش Agentها', cancelled:'سفارش لغوشده', agentCancelled:'لغوشده Agentها', basket:'ترکیب سبد خرید', average:'میانگین سفارش روزانه' };
  var stored = JSON.parse(localStorage.getItem('ccoReportCardOrder') || 'null');
  var defaultOrder = ['orders','successful','cancelled','agentOrders','revenue','agentRevenue','agentCancelled','basket','average'];
  var cardOrder = Array.isArray(stored) ? stored.filter(function(k){return defaultOrder.indexOf(k)>-1;}) : defaultOrder.slice();
  defaultOrder.forEach(function(k){if(cardOrder.indexOf(k)===-1)cardOrder.push(k);});

  function delta(a,b) { if (!b) return a ? 100 : 0; return ((a-b)/Math.abs(b))*100; }
  function trend(a,b) { var d=delta(a,b), up=d>=0; return '<span class="trend '+(up?'up':'down')+'">'+(up?'↑':'↓')+' '+num(Math.abs(d).toFixed(1))+'٪</span>'; }
  function comparisonTitle() {
    var r=state.data.range;
    if(state.period==='day') return 'مقایسه دیروز ('+esc(r.fromJalali)+') با پریروز ('+esc(r.previousJalali)+')';
    return 'مقایسه بازه '+esc(r.fromJalali)+' تا '+esc(r.toJalali)+' با بازه قبل';
  }
  function previousLabel() { return state.period==='day' ? 'پریروز ('+esc(state.data.range.previousJalali)+')' : 'بازه قبل'; }
  function stores(metric) { var c=state.data.current.stores,p=state.data.previous.stores,names=state.data.stores; return Object.keys(names).map(function(k){return '<span><b>'+esc(names[k])+'</b><i>'+num((c[k]||{})[metric])+' | '+num((p[k]||{})[metric])+'</i></span>';}).join(''); }
  function card(key) {
    var c=state.data.current.totals,p=state.data.previous.totals, value=c[key], old=p[key], display=(key.indexOf('Revenue')>-1||key==='revenue')?money(value):num(value);
    var body='';
    if(key==='basket'){ display=num(c.singleItem+c.multiItem); old=p.singleItem+p.multiItem; body='<div class="basket-split"><span>تک‌کالا <b>'+num(c.singleItem)+'</b></span><span>چندکالا <b>'+num(c.multiItem)+'</b></span></div>'; }
    else if(key==='average'){ value=c.dailyAverage; old=p.dailyAverage; display=num(value); body='<div class="muted">بر اساس روزهای دارای سفارش</div>'; }
    else body='<div class="store-pairs">'+stores(key.replace('agent','').replace('Agent','').toLowerCase())+'</div>';
    return '<article class="metric-card" draggable="true" data-key="'+key+'" tabindex="0"><div class="drag">⠿</div><div class="metric-head"><span class="metric-icon">'+icons[key]+'</span><span>'+labels[key]+'</span></div><strong>'+display+'</strong><div class="compare"><span>'+previousLabel()+': '+(((key.indexOf('Revenue')>-1||key==='revenue')?money(old):num(old)))+'</span>'+trend(value,old)+'</div>'+body+'<svg class="spark" viewBox="0 0 100 22" preserveAspectRatio="none"><path d="M0 18 L15 12 L30 15 L45 6 L60 10 L75 3 L100 7"/></svg></article>';
  }
  function rows(obj, cols) { var entries=Object.keys(obj||{}); if(!entries.length)return '<div class="empty">داده‌ای در این بازه نیست.</div>'; return '<div class="report-table">'+entries.map(function(k){return '<button class="report-row" data-detail="'+esc(k)+'"><b>'+esc(k)+'</b>'+cols.map(function(col){return '<span><small>'+col[1]+'</small>'+((col[2]||num)((obj[k]||{})[col[0]]))+'</span>';}).join('')+'<i>←</i></button>';}).join('')+'</div>'; }
  function panel(id,title,sub,html,wide){return '<section class="report-panel '+(wide?'wide':'')+'" data-panel="'+id+'"><header><div><h2>'+title+'</h2><p>'+sub+'</p></div><button class="more" data-panel-detail="'+id+'">مشاهده جزئیات</button></header>'+html+'</section>';}
  function render() {
    var d=state.data,c=d.current,p=d.previous;
    root.innerHTML='<div class="report-shell"><aside><a class="brand" href="'+CCO_REPORTS.ordersUrl+'"><span>CO</span><div><b>سفارشات شرکت</b><small>CENTRAL ORDERS</small></div></a><nav><a href="'+CCO_REPORTS.ordersUrl+'">سفارش‌ها</a><a class="active">گزارش‌ها</a></nav><div class="access-note">گزارش محرمانه<br><small>فقط مدیران مجاز</small></div></aside><main><header class="top"><div><h1>گزارش‌های سفارش</h1><p>نمای یکپارچه عملکرد زوم بازار و اسمارت پیشرو</p></div><button id="reset-layout">بازنشانی چینش</button></header><div class="filters"><div class="periods">'+[['day','روز'],['week','هفته'],['month','ماه'],['year','سال'],['custom','دلخواه']].map(function(x){return '<button data-period="'+x[0]+'" class="'+(state.period===x[0]?'active':'')+'">'+x[1]+'</button>';}).join('')+'</div><select id="store"><option value="">هر دو سایت</option>'+Object.keys(d.stores).map(function(k){return '<option value="'+k+'" '+(state.store===k?'selected':'')+'>'+esc(d.stores[k])+'</option>';}).join('')+'</select><select id="agent"><option value="">همه محصولات</option>'+Object.keys(d.agents).map(function(k){return '<option value="'+esc(k)+'" '+(state.agent===k?'selected':'')+'>'+esc(d.agents[k])+'</option>';}).join('')+'</select><div class="custom-dates '+(state.period==='custom'?'show':'')+'"><input id="from" type="date" value="'+state.from+'"><span>تا</span><input id="to" type="date" value="'+state.to+'"><button id="apply">اعمال</button></div></div><div class="comparison-banner"><b>'+comparisonTitle()+'</b><span>تمام اعداد و درصدهای این صفحه بر اساس همین مقایسه هستند.</span></div><div class="range"><span>بازه جاری: '+esc(d.range.fromJalali)+' تا '+esc(d.range.toJalali)+'</span><span>هر کارت را برای جابه‌جایی بکشید</span></div><div class="metric-grid" id="metric-grid">'+cardOrder.map(card).join('')+'</div><div class="panels">'+panel('gateway','درگاه‌های پرداخت','موفق و ناموفق به تفکیک درگاه',rows(c.gateways,[['successful','موفق'],['cancelled','لغو'],['total','کل']]),true)+panel('shipping','روش‌های ارسال','تعداد، هزینه ارسال و دریافتی موفق',rows(c.shipping,[['orders','سفارش'],['cost','هزینه',money],['received','دریافتی',money]]),true)+panel('agents','عملکرد Agentها','فروش و لغو به تفکیک Agent',rows(c.agents,[['orders','سفارش'],['revenue','فروش',money],['cancelled','لغو']]),false)+panel('coupons','کدهای تخفیف','استفاده، تخفیف و فروش نهایی',rows(c.coupons,[['orders','استفاده'],['discount','تخفیف',money],['revenue','فروش',money]]),false)+panel('users','کاربران','کاربران یکتای سفارش‌دهنده در بازه','<div class="big-number">'+num(c.totals.customers)+'</div><p class="muted">عضویت مستقل کاربران در Sync فعلی منتقل نمی‌شود؛ این عدد کاربران یکتای سفارش‌دهنده است.</p>',false)+panel('unavailable','Campaign و Order Bump','نیازمند تکمیل داده مبدأ','<div class="data-warning"><b>داده کافی نیست</b><p>'+esc(d.availability.message)+'</p></div>',false)+'</div></main></div><div class="modal" id="modal" hidden><div class="modal-card"><button class="close">×</button><h2>جزئیات گزارش</h2><p>برای تحلیل عمیق‌تر، فیلترهای سایت، Agent و بازه زمانی بالای صفحه را تغییر دهید. جدول کامل همین بخش مطابق فیلتر فعلی نمایش داده شده است.</p></div></div>';
    syncSidebar();
    clarifyShipping();
    bind();
  }

  function syncSidebar(){
    var aside=root.querySelector('.report-shell aside');
    if(!aside)return;
    aside.innerHTML='<a class="brand" href="'+CCO_REPORTS.ordersUrl+'"><span>CC</span><div><b>Company</b><small>Operations</small></div></a><nav><a href="'+CCO_REPORTS.ordersUrl+'">سفارش‌ها</a><a class="disabled">تأمین کالا</a><a class="disabled">Agentها</a><a class="active">گزارش‌ها</a><hr><a class="disabled">تنظیمات</a></nav><div class="access-note">نسخه ۰.۱۷.۷ · پنل سفارشات</div>';
  }

  function clarifyShipping(){
    var panel=root.querySelector('[data-panel="shipping"]');
    if(!panel)return;
    var description=panel.querySelector('header p');
    if(description)description.textContent='مبلغ ثبت‌شده برای همه سفارش‌ها؛ دریافتی ارسال فقط برای سفارش‌های پرداخت‌شده';
    panel.querySelectorAll('.report-row small').forEach(function(label){if(label.textContent==='هزینه')label.textContent='مبلغ ثبت‌شده';});
  }
  function bind(){
    root.querySelectorAll('[data-period]').forEach(function(b){b.onclick=function(){state.period=b.dataset.period;if(state.period!=='custom')load();else render();};});
    root.querySelector('#store').onchange=function(e){state.store=e.target.value;load();}; root.querySelector('#agent').onchange=function(e){state.agent=e.target.value;load();};
    var apply=root.querySelector('#apply'); if(apply)apply.onclick=function(){state.from=root.querySelector('#from').value;state.to=root.querySelector('#to').value;if(state.from&&state.to)load();};
    root.querySelector('#reset-layout').onclick=function(){localStorage.removeItem('ccoReportCardOrder');location.reload();};
    var dragged=null; root.querySelectorAll('.metric-card').forEach(function(el){el.ondragstart=function(){dragged=el;el.classList.add('dragging');};el.ondragend=function(){el.classList.remove('dragging');cardOrder=Array.from(root.querySelectorAll('.metric-card')).map(function(x){return x.dataset.key;});localStorage.setItem('ccoReportCardOrder',JSON.stringify(cardOrder));};el.ondragover=function(e){e.preventDefault();if(dragged&&dragged!==el){var box=el.getBoundingClientRect();el.parentNode.insertBefore(dragged,e.clientX<box.left+box.width/2?el:el.nextSibling);}};el.onclick=openModal;});
    root.querySelectorAll('.more,.report-row').forEach(function(x){x.onclick=openModal;}); root.querySelector('.close').onclick=function(){root.querySelector('#modal').hidden=true;};
  }
  function openModal(){
    var modal=root.querySelector('#modal'), card=modal.querySelector('.modal-card'), panel=this.closest&&this.closest('[data-panel]');
    modal.hidden=false;
    if(!panel||panel.dataset.panel!=='shipping'||!this.dataset.detail)return;
    var method=this.dataset.detail;
    card.querySelector('h2').textContent='سفارش‌های روش ارسال «'+method+'»';
    card.querySelector('p').innerHTML='<span class="detail-loading">در حال دریافت سفارش‌ها…</span>';
    var q=new URLSearchParams({section:'shipping',value:method,period:state.period,store:state.store,agent:state.agent,from:state.from,to:state.to});
    fetch(CCO_REPORTS.url+'/orders?'+q,{headers:{'X-WP-Nonce':CCO_REPORTS.nonce}}).then(function(r){if(!r.ok)throw new Error();return r.json();}).then(function(d){
      var html='<div class="detail-meta">'+num(d.total)+' سفارش در بازه انتخابی'+(d.limited?' · نمایش ۱۰۰ مورد اول':'')+'</div>';
      if(!d.orders.length)html+='<div class="empty">سفارشی پیدا نشد.</div>';
      else html+='<div class="detail-orders">'+d.orders.map(function(o){return '<article><div><b>'+esc(o.store)+' · سفارش #'+esc(o.number)+'</b><span>'+esc(o.customer)+'</span></div><div><span>'+esc(o.status)+'</span><small>'+esc(o.date)+'</small></div><div><b>'+money(o.total)+'</b><small>ارسال: '+money(o.shipping)+'</small></div><a href="'+esc(o.url)+'" target="_blank" rel="noopener">مشاهده سفارش ↗</a></article>';}).join('')+'</div>';
      card.querySelector('p').innerHTML=html;
    }).catch(function(){card.querySelector('p').innerHTML='<div class="cco-report-error">دریافت سفارش‌ها ناموفق بود.</div>';});
  }
  function load(){var main=root.querySelector('.report-shell main');if(main)main.innerHTML='<div class="cco-report-loading"><i></i>در حال محاسبه گزارش…</div>';else root.innerHTML='<div class="cco-report-loading"><i></i>در حال محاسبه گزارش…</div>';var q=new URLSearchParams({period:state.period,store:state.store,agent:state.agent,from:state.from,to:state.to});fetch(CCO_REPORTS.url+'?'+q,{headers:{'X-WP-Nonce':CCO_REPORTS.nonce}}).then(function(r){if(!r.ok)throw new Error();return r.json();}).then(function(d){state.data=d;render();}).catch(function(){var target=root.querySelector('.report-shell main')||root;target.innerHTML='<div class="cco-report-error">دریافت گزارش ناموفق بود. دوباره تلاش کنید.</div>';});}
  load();
}());
