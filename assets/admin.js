/* Progressive enhancement for the BC MCP settings dashboard. No network calls. */
(function () {
	'use strict';
	var root = document.getElementById('bc-mcp-admin');
	if (!root) {
		return;
	}

	var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-bc-tab]'));
	var panels = Array.prototype.slice.call(root.querySelectorAll('.bc-tab-panel'));
	var known = tabs.map(function (tab) { return tab.getAttribute('data-bc-tab'); });
	var storeKey = 'bc_mcp_dashboard_active_tab';
	var strings = window.bcMcpSettings || {};
	function text(key, fallback) {
		return typeof strings[key] === 'string' ? strings[key] : fallback;
	}

	function switchTo(name, focus) {
		if (known.indexOf(name) === -1) {
			name = 'overview';
		}
		tabs.forEach(function (tab) {
			var active = tab.getAttribute('data-bc-tab') === name;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.setAttribute('tabindex', active ? '0' : '-1');
			if (active && focus) {
				tab.focus();
			}
		});
		panels.forEach(function (panel) {
			panel.hidden = panel.id !== 'bc-panel-' + name;
		});
		try {
			window.sessionStorage.setItem(storeKey, name);
		} catch (ignore) {
			// Browser privacy settings may disable session storage.
		}
	}

	var start = 'overview';
	var hashName = window.location.hash.replace(/^#bc-panel-/, '');
	if (known.indexOf(hashName) !== -1) {
		start = hashName;
	} else {
		try {
			var stored = window.sessionStorage.getItem(storeKey);
			if (known.indexOf(stored) !== -1) {
				start = stored;
			}
		} catch (ignore) {
			// A regular first-tab fallback is sufficient.
		}
	}
	switchTo(start, false);
	root.classList.add('bc-ui-ready');

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {
			switchTo(tab.getAttribute('data-bc-tab'), false);
		});
		tab.addEventListener('keydown', function (event) {
			var next = index;
			if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
				next = (index + 1) % tabs.length;
			} else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
				next = (index - 1 + tabs.length) % tabs.length;
			} else if (event.key === 'Home') {
				next = 0;
			} else if (event.key === 'End') {
				next = tabs.length - 1;
			} else {
				return;
			}
			event.preventDefault();
			switchTo(tabs[next].getAttribute('data-bc-tab'), true);
		});
	});

	Array.prototype.slice.call(root.querySelectorAll('[data-bc-goto]')).forEach(function (button) {
		button.addEventListener('click', function () {
			switchTo(button.getAttribute('data-bc-goto'), true);
		});
	});

	Array.prototype.slice.call(root.querySelectorAll('[data-bc-copy]')).forEach(function (copy) {
		var originalLabel = copy.textContent;
		copy.addEventListener('click', function () {
			var input = document.getElementById(copy.getAttribute('data-bc-copy'));
			if (!input) {
				return;
			}
			function copied() {
				copy.textContent = text('copied', 'Copied!');
				window.setTimeout(function () { copy.textContent = originalLabel; }, 1800);
			}
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(input.value).then(copied, function () {
					input.focus();
					input.select();
				});
			} else {
				input.focus();
				input.select();
				if (document.execCommand && document.execCommand('copy')) {
					copied();
				}
			}
		});
	});

	var search = root.querySelector('[data-bc-search]');
	var controls = Array.prototype.slice.call(root.querySelectorAll('[data-bc-permission]'));
	var enabledCount = root.querySelector('[data-bc-enabled-count]');
	var empty = root.querySelector('[data-bc-no-results]');
	function updateCounts() {
		var enabled = controls.filter(function (item) {
			var input = item.querySelector('input[type="checkbox"]');
			return input && input.checked;
		}).length;
		if (enabledCount) {
			enabledCount.textContent = String(enabled);
		}
	}
	function filterAbilities() {
		var term = (search ? search.value : '').toLocaleLowerCase().trim();
		var visible = 0;
		controls.forEach(function (item) {
			var text = item.getAttribute('data-bc-search-text') || '';
			var match = !term || text.indexOf(term) !== -1;
			item.hidden = !match;
			if (match) {
				visible++;
			}
		});
		Array.prototype.slice.call(root.querySelectorAll('[data-bc-group]')).forEach(function (group) {
			group.hidden = !group.querySelector('[data-bc-permission]:not([hidden])');
		});
		if (empty) {
			empty.hidden = visible !== 0;
		}
	}
	if (search) {
		search.addEventListener('input', filterAbilities);
	}
	controls.forEach(function (item) {
		item.querySelector('input[type="checkbox"]').addEventListener('change', updateCounts);
	});
	updateCounts();

	Array.prototype.slice.call(root.querySelectorAll('[data-bc-clear-activity]')).forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.confirm(text('confirmClear', 'Permanently clear the stored BC MCP activity history?'))) {
				event.preventDefault();
			}
		});
	});

	Array.prototype.slice.call(root.querySelectorAll('[data-bc-revoke-all]')).forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.confirm(text('confirmRevoke', 'Disconnect every connected AI assistant?'))) {
				event.preventDefault();
			}
		});
	});
})();
