(function () {
  'use strict';
  var root = document.getElementById('cco-reports-root');
  if (!root || !window.CCO_REPORTS || !CCO_REPORTS.locationsApi) return;
  var period = 'last_week';
  var nf = new Intl.NumberFormat('fa-IR');
  var esc = function (v) { var d=document.createElement('div'); d.textContent=String(v==null?'':v); return d.innerHTML; };
  function url(province) { var q=new URLSearchParams({period:period}); if(province)q.set('province',province); return CCO_REPORTS.locationsUrl+'?'+q; }
  function tabs() { return '<div class="geo-tabs">'+[['last_week','هفته گذشته'],['week','هفته جاری'],['month','ماه جاری'],['year','سال جاری'],['day','دیروز'],['today','امروز']].map(function(x){return '<button data-geo-period="'+x[0]+'" class="'+(period===x[0]?'active':'')+'">'+x[1]+'</button>';}).join('')+'</div>'; }
  function render(data) {
    var holder=root.querySelector('#cco-geography-section'); if(!holder)return;
    var cities=data.topCities.map(function(c,i){return '<a class="top-city-row" href="'+url(c.province)+'"><i>'+nf.format(i+1)+'</i><span><b>'+esc(c.city)+'</b><small>'+esc(c.province)+'</small></span><strong>'+nf.format(c.orders)+' <small>سفارش</small></strong></a>';}).join('') || '<div class="geo-empty">در این بازه سفارشی ثبت نشده است.</div>';
    holder.innerHTML='<section class="geo-panel iran-map-card"><header><div><h2>پراکندگی سفارش‌ها در ایران</h2><p>تعداد سفارش ثبت‌شده به تفکیک استان</p></div>'+tabs()+'</header><div class="iran-map" id="iran-map-stage"><div class="map-loading">در حال ترسیم نقشه…</div><div class="iran-map-legend"><span>کم</span><i></i><span>زیاد</span></div></div><footer><span>مجموع سفارش‌های دارای آدرس: <b>'+nf.format(data.totalOrders)+'</b></span><a href="'+url('')+'">مشاهده همه شهرها ←</a></footer></section><section class="geo-panel top-cities-card"><header><div><h2>۱۰ شهر پُرخرید</h2><p>رتبه‌بندی بر اساس تعداد سفارش</p></div>'+tabs()+'</header><div class="top-city-list">'+cities+'</div><footer><a class="geo-details" href="'+url('')+'">مشاهده جزئیات همه شهرها</a></footer></section>';
    drawMap(data.provinces);
    bind();
  }
  function drawMap(counts){
    var stage=root.querySelector('#iran-map-stage');
    fetch(CCO_REPORTS.iranMapUrl).then(function(r){if(!r.ok)throw new Error();return r.text();}).then(function(markup){
      var doc=new DOMParser().parseFromString(markup,'image/svg+xml'),svg=doc.documentElement;
      stage.querySelector('.map-loading').remove(); stage.insertBefore(document.importNode(svg,true),stage.firstChild);
      svg=stage.querySelector('svg'); var max=Math.max.apply(null,Object.keys(counts).map(function(k){return counts[k];}).concat([1]));
      svg.querySelectorAll('[data-province]').forEach(function(shape){
        var name=shape.getAttribute('data-province'),count=counts[name]||0,ratio=count/max,box=shape.getBBox();
        shape.style.setProperty('--province-heat',(.1+ratio*.42).toFixed(2));
        var link=document.createElementNS('http://www.w3.org/2000/svg','a'); link.setAttribute('href',url(name)); link.setAttribute('aria-label',name+'، '+count+' سفارش');
        var circle=document.createElementNS('http://www.w3.org/2000/svg','circle'),label=document.createElementNS('http://www.w3.org/2000/svg','text');
        circle.setAttribute('cx',box.x+box.width/2);circle.setAttribute('cy',box.y+box.height/2);circle.setAttribute('r',10+Math.sqrt(ratio)*24);circle.setAttribute('class','province-bubble');circle.style.setProperty('--bubble-opacity',(.32+ratio*.68).toFixed(2));
        label.setAttribute('x',box.x+box.width/2);label.setAttribute('y',box.y+box.height/2);label.setAttribute('class','province-count');label.textContent=nf.format(count);
        link.appendChild(circle);link.appendChild(label);svg.appendChild(link);
        shape.addEventListener('click',function(){location.href=url(name);});
        var title=document.createElementNS('http://www.w3.org/2000/svg','title');title.textContent=name+' — '+nf.format(count)+' سفارش';shape.appendChild(title);
      });
    }).catch(function(){var loading=stage.querySelector('.map-loading');if(loading)loading.textContent='ترسیم نقشه ناموفق بود.';});
  }
  function bind(){root.querySelectorAll('[data-geo-period]').forEach(function(b){b.onclick=function(){period=b.dataset.geoPeriod;load();};});}
  function load(){var holder=root.querySelector('#cco-geography-section');if(!holder){var panels=root.querySelector('.panels');if(!panels){setTimeout(load,100);return;}holder=document.createElement('div');holder.id='cco-geography-section';holder.className='geography-grid';panels.parentNode.insertBefore(holder,panels);}holder.innerHTML='<div class="geo-loading">در حال محاسبه پراکندگی سفارش‌ها…</div>';fetch(CCO_REPORTS.locationsApi+'?period='+period,{headers:{'X-WP-Nonce':CCO_REPORTS.nonce}}).then(function(r){if(!r.ok)throw new Error();return r.json();}).then(render).catch(function(){holder.innerHTML='<div class="geo-error">دریافت گزارش استان و شهر ناموفق بود.</div>';});}
  load();
}());
