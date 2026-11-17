(function () {
	var config = window.rhBlueprintBuilder;
	var form = document.getElementById('rh-bb-form');
	if (!config || !form) {
		return;
	}

	var steps = ['setup', 'theme', 'plugins', 'options', 'content', 'review'];
	var status = form.querySelector('.rh-bb-status');

	function setStatus(message) {
		if (status) {
			status.textContent = message || '';
		}
	}

	function show(step, refresh) {
		var index;
		for (index = 0; index < steps.length; index++) {
			var panel = document.getElementById('rh-bb-panel-' + steps[index]);
			var jump = form.querySelector('.rh-bb-jump[data-step="' + steps[index] + '"]');
			var active = steps[index] === step;
			if (panel) {
				panel.hidden = !active;
				panel.classList.toggle('is-active', active);
			}
			if (jump) {
				jump.classList.toggle('is-current', active);
				if (active) {
					jump.setAttribute('aria-current', 'step');
				} else {
					jump.removeAttribute('aria-current');
				}
			}
		}

		var field = form.querySelector('[name="rh_bb_step"]');
		if (field) {
			field.value = step;
		}

		var previous = form.querySelector('.rh-bb-prev');
		var next = form.querySelector('.rh-bb-next');
		if (previous) {
			previous.hidden = step === 'setup';
		}
		if (next) {
			next.hidden = step === 'review';
		}

		var heading = document.getElementById('rh-bb-panel-' + step);
		if (heading && refresh) {
			heading.focus();
		}
		if (refresh && step === 'review') {
			refreshPreview();
		}
	}

	function currentStep() {
		var field = form.querySelector('[name="rh_bb_step"]');
		return field ? field.value : 'setup';
	}

	function save() {
		var data = new FormData(form);
		data.append('action', config.actions.save);
		return fetch(config.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error('save');
			}
			setStatus(config.strings.saved);
			return payload.data;
		});
	}

	function go(step) {
		save().then(function () {
			if (step === 'options') {
				window.location = config.pageUrl + '&step=options';
				return;
			}
			show(step, true);
		}).catch(function () {
			setStatus(config.strings.saveFailed);
		});
	}

	function refreshPreview() {
		var data = new FormData(form);
		data.append('action', config.actions.preview);
		return fetch(config.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error('preview');
			}
			paintReview(payload.data);
			return payload.data;
		}).catch(function (error) {
			setStatus(config.strings.previewFailed);
			throw error;
		});
	}

	function paintReview(data) {
		var json = document.getElementById('rh-bb-json');
		var list = document.getElementById('rh-bb-messages');
		var download = document.getElementById('rh-bb-download');
		if (json) {
			json.textContent = data.json || '';
		}
		if (list) {
			list.textContent = '';
			(data.messages || []).forEach(function (message) {
				var item = document.createElement('li');
				item.className = 'is-' + (message.type || 'info');
				item.textContent = message.message || '';
				list.appendChild(item);
			});
		}
		if (download) {
			download.disabled = !!data.blocked;
		}
	}

	function bindToggle(input) {
		var target = document.getElementById(input.getAttribute('data-toggle'));
		if (!target) {
			return;
		}
		var sync = function () {
			target.hidden = !input.checked;
		};
		input.addEventListener('change', sync);
		sync();
	}

	Array.prototype.forEach.call(form.querySelectorAll('[data-toggle]'), bindToggle);

	Array.prototype.forEach.call(form.querySelectorAll('.rh-bb-jump'), function (button) {
		button.addEventListener('click', function () {
			go(button.getAttribute('data-step'));
		});
	});

	var previous = form.querySelector('.rh-bb-prev');
	var next = form.querySelector('.rh-bb-next');
	if (previous) {
		previous.addEventListener('click', function () {
			var index = steps.indexOf(currentStep());
			if (index > 0) {
				go(steps[index - 1]);
			}
		});
	}
	if (next) {
		next.addEventListener('click', function () {
			var index = steps.indexOf(currentStep());
			if (index < steps.length - 1) {
				go(steps[index + 1]);
			}
		});
	}

	var username = document.getElementById('rh-bb-login-username');
	var playgroundUser = document.getElementById('rh-bb-playground-user');
	if (username && playgroundUser) {
		username.addEventListener('input', function () {
			playgroundUser.textContent = username.value.trim() || 'admin';
		});
	}

	var searchButton = document.getElementById('rh-bb-option-search-button');
	var searchInput = document.getElementById('rh-bb-option-search');
	var searchResults = document.getElementById('rh-bb-search-results');

	function runSearch() {
		if (!searchInput || !searchResults) {
			return;
		}
		var term = searchInput.value.trim();
		searchResults.textContent = '';
		if (term.length < 2) {
			return;
		}
		var pending = document.createElement('li');
		pending.textContent = config.strings.searching;
		searchResults.appendChild(pending);

		var data = new FormData();
		data.append('action', config.actions.search);
		data.append('term', term);
		var nonce = form.querySelector('[name="rh_blueprint_builder_nonce"]');
		if (nonce) {
			data.append('rh_blueprint_builder_nonce', nonce.value);
		}

		fetch(config.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			searchResults.textContent = '';
			var results = payload && payload.success ? payload.data.results : [];
			if (!results.length) {
				var empty = document.createElement('li');
				empty.textContent = config.strings.noResults;
				searchResults.appendChild(empty);
				return;
			}
			results.forEach(function (result) {
				var item = document.createElement('li');
				var name = document.createElement('code');
				name.textContent = result.name;
				var add = document.createElement('button');
				add.type = 'button';
				add.className = 'button';
				add.textContent = listed(result.name) ? config.strings.alreadyListed : config.strings.add;
				add.disabled = listed(result.name);
				add.addEventListener('click', function () {
					addOption(result);
					add.disabled = true;
					add.textContent = config.strings.alreadyListed;
				});
				item.appendChild(name);
				if (result.secret) {
					var flag = document.createElement('span');
					flag.className = 'rh-bb-flag';
					flag.textContent = config.strings.likelySecret;
					item.appendChild(flag);
				}
				if (result.preview) {
					var preview = document.createElement('code');
					preview.className = 'rh-bb-preview';
					preview.textContent = result.preview;
					item.appendChild(preview);
				}
				item.appendChild(add);
				searchResults.appendChild(item);
			});
		}).catch(function () {
			searchResults.textContent = '';
			var failed = document.createElement('li');
			failed.textContent = config.strings.saveFailed;
			searchResults.appendChild(failed);
		});
	}

	function listed(name) {
		return !!form.querySelector('.rh-bb-option[data-option="' + cssEscape(name) + '"]');
	}

	function cssEscape(value) {
		if (window.CSS && CSS.escape) {
			return CSS.escape(value);
		}
		return String(value).replace(/["\\]/g, '\\$&');
	}

	function addOption(result) {
		var template = document.getElementById('rh-bb-option-template');
		var group = document.getElementById('rh-bb-added-group');
		var list = document.getElementById('rh-bb-added-options');
		if (!template || !group || !list || listed(result.name)) {
			return;
		}
		unhide(result.name);
		var row = template.content.firstElementChild.cloneNode(true);
		row.setAttribute('data-option', result.name);
		row.classList.toggle('is-secret', !!result.secret);
		var box = row.querySelector('input[type="checkbox"]');
		var code = row.querySelector('.rh-bb-option-name');
		var preview = row.querySelector('.rh-bb-preview code');
		var flag = row.querySelector('.rh-bb-flag');
		var present = row.querySelector('[name="rh_bb_options_present[]"]');
		var added = row.querySelector('[name="rh_bb_options_added[]"]');
		if (code) {
			code.textContent = result.name;
		}
		if (preview) {
			preview.textContent = result.preview || '';
		}
		if (flag) {
			flag.hidden = !result.secret;
		}
		if (box) {
			box.value = result.name;
			box.checked = !result.secret;
			box.id = 'rh-bb-opt-added-' + list.children.length;
		}
		var label = row.querySelector('label');
		if (label && box) {
			label.setAttribute('for', box.id);
		}
		if (present) {
			present.value = result.name;
		}
		if (added) {
			added.value = result.name;
		}
		list.appendChild(row);
		group.hidden = false;
	}

	function unhide(name) {
		Array.prototype.forEach.call(form.querySelectorAll('[name="rh_bb_options_hidden[]"]'), function (input) {
			if (input.value === name && input.parentNode) {
				input.parentNode.removeChild(input);
			}
		});
	}

	if (searchButton) {
		searchButton.addEventListener('click', runSearch);
	}
	if (searchInput) {
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				runSearch();
			}
		});
	}

	form.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('.rh-bb-remove-option') : null;
		if (!button) {
			return;
		}
		var row = button.closest ? button.closest('.rh-bb-option') : null;
		if (!row) {
			return;
		}
		var name = row.getAttribute('data-option');
		var holder = document.getElementById('rh-bb-hidden-options');
		if (name && holder) {
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'rh_bb_options_hidden[]';
			hidden.value = name;
			holder.appendChild(hidden);
		}
		if (row.parentNode) {
			row.parentNode.removeChild(row);
		}
	});

	var download = document.getElementById('rh-bb-download');
	if (download) {
		download.addEventListener('click', function (event) {
			event.preventDefault();
			refreshPreview().then(function (data) {
				if (!data || data.blocked) {
					show('review', false);
					return;
				}
				var injected = form.querySelector('input[data-rh-bb-action]');
				if (!injected) {
					injected = document.createElement('input');
					injected.type = 'hidden';
					injected.name = 'action';
					injected.setAttribute('data-rh-bb-action', '1');
					form.appendChild(injected);
				}
				injected.value = 'rh_blueprint_builder_download_blueprint';
				var previousAction = form.getAttribute('action');
				form.setAttribute('action', config.adminPost);
				HTMLFormElement.prototype.submit.call(form);
				form.setAttribute('action', previousAction);
			}).catch(function () {
				show('review', false);
			});
		});
	}

	var wxr = document.getElementById('rh-bb-wxr');
	if (wxr) {
		wxr.addEventListener('click', function () {
			var injected = form.querySelector('input[data-rh-bb-action]');
			if (injected && injected.parentNode) {
				injected.parentNode.removeChild(injected);
			}
		});
	}

	show(steps.indexOf(config.step) === -1 ? 'setup' : config.step, false);
})();
