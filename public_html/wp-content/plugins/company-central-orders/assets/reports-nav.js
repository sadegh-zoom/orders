(function () {
	'use strict';
	function removeReportsItem() {
		document.querySelectorAll('.cco-ant-sider .ant-menu-item').forEach(function (item) {
			if (item.textContent.trim() !== 'گزارش‌ها') return;
			item.remove();
		});
	}
	var observer = new MutationObserver(removeReportsItem);
	observer.observe(document.documentElement, { childList: true, subtree: true });
	removeReportsItem();
	window.setTimeout(function () { observer.disconnect(); removeReportsItem(); }, 5000);
}());
