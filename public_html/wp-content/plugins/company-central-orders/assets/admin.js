(function () {
	'use strict';

	var selectAll = document.getElementById('cco-select-all');
	var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.cco-order-checkbox'));
	var buttons = Array.prototype.slice.call(document.querySelectorAll('.cco-export-button, .cco-print-button'));
	var count = document.querySelector('.cco-selection-count span');
	var printForm = document.getElementById('cco-bulk-print-form');

	if (!selectAll || !checkboxes.length) {
		return;
	}

	function refresh() {
		var selectedBoxes = checkboxes.filter(function (checkbox) { return checkbox.checked; });
		var selected = selectedBoxes.length;
		selectAll.checked = selected === checkboxes.length;
		selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
		buttons.forEach(function (button) { button.disabled = selected === 0; });
		if (count) {
			count.textContent = selected.toLocaleString('fa-IR');
		}
		if (printForm) {
			Array.prototype.slice.call(printForm.querySelectorAll('.cco-print-order-id')).forEach(function (input) { input.remove(); });
			selectedBoxes.forEach(function (checkbox) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'order_ids[]';
				input.value = checkbox.value;
				input.className = 'cco-print-order-id';
				printForm.appendChild(input);
			});
		}
	}

	selectAll.addEventListener('change', function () {
		checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
		refresh();
	});

	checkboxes.forEach(function (checkbox) {
		checkbox.addEventListener('change', refresh);
	});

	refresh();
}());
