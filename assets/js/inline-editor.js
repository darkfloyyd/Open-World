/**
 * Open World — Inline Translation Editor (Frontend)
 *
 * Scans DOM text nodes and matches them against the server-provided string map.
 * Wraps matched text in <span> tags client-side (avoiding server escaping issues).
 * Provides a permanently visible sidebar with a list of strings and inline editing.
 */
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	const $ = (sel, ctx) => (ctx || document).querySelector(sel);
	const $$ = (sel, ctx) => [...(ctx || document).querySelectorAll(sel)];

	const sidebar     = $('#ow-inline-sidebar');
	const body        = $('#ow-sidebar-body');
	const footer      = $('#ow-sidebar-footer');
	const closeBtn    = $('#ow-sidebar-close');
	const deeplAllBtn = $('#ow-deepl-all');
	const saveAllBtn  = $('#ow-save-all');
	const languages   = window.owInlineLanguages || [];
	const stringMap   = window.owStringMap || {};
	const cfg         = window.owInline || {};

	if (!sidebar || !languages.length || !Object.keys(stringMap).length) {
		console.warn('OW Inline Editor: Initialization aborted. Missing dependencies.');
		return;
	}

	let activeMsgid   = null;
	let activeEl      = null;

	// Keep track of which msgids actually appear on the page
	const pageStrings = new Set();

	// ── Scan DOM and wrap matching text nodes ─────────────────────────────
	function scanAndWrap() {
		const walker = document.createTreeWalker(
			document.body,
			NodeFilter.SHOW_TEXT,
			{
				acceptNode: function (node) {
					const tag = node.parentElement ? node.parentElement.tagName : '';
					if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'INPUT', 'CODE', 'PRE'].indexOf(tag) >= 0) {
						return NodeFilter.FILTER_REJECT;
					}
					const parent = node.parentElement;
					if (parent && (parent.closest('#wpadminbar') || parent.closest('#ow-inline-sidebar'))) {
						return NodeFilter.FILTER_REJECT;
					}
					if (parent && parent.classList && parent.classList.contains('ow-translatable')) {
						return NodeFilter.FILTER_REJECT;
					}
					return NodeFilter.FILTER_ACCEPT;
				}
			}
		);

		const nodes = [];
		while (walker.nextNode()) {
			nodes.push(walker.currentNode);
		}

		nodes.forEach(function (textNode) {
			const text = textNode.textContent.trim();
			if (text.length < 2) return;

			if (stringMap[text]) {
				wrapTextNode(textNode, text, stringMap[text]);
				pageStrings.add(stringMap[text].msgid);
			}
		});
	}

	function wrapTextNode(textNode, matchedText, entry) {
		const span = document.createElement('span');
		span.className = 'ow-translatable';
		span.dataset.owMsgid = entry.msgid;
		span.dataset.owDomain = entry.domain;
		textNode.parentNode.replaceChild(span, textNode);
		span.appendChild(textNode);
	}

	// ── Render String List in Sidebar ─────────────────────────────────────
	function renderStringList() {
		let html = '';
		// Build a list of unique msgids from stringMap
		const uniqueStrings = [];
		const seen = new Set();
		Object.values(stringMap).forEach(entry => {
			if (!seen.has(entry.msgid)) {
				seen.add(entry.msgid);
				uniqueStrings.push(entry);
			}
		});

		// Sort: strings found on page first
		uniqueStrings.sort((a, b) => {
			const aOnPage = pageStrings.has(a.msgid) ? 1 : 0;
			const bOnPage = pageStrings.has(b.msgid) ? 1 : 0;
			return bOnPage - aOnPage;
		});

		if (uniqueStrings.length === 0) {
			body.innerHTML = '<p class="ow-sidebar__hint">No translatable strings found on this page.</p>';
			return;
		}

		uniqueStrings.forEach(entry => {
			const isOnPage = pageStrings.has(entry.msgid);
			const badge = isOnPage ? '<span class="ow-sidebar__list-badge">On Page</span>' : '';
			
			html += `<div class="ow-sidebar__list-item" data-msgid="${escAttr(entry.msgid)}" data-domain="${escAttr(entry.domain)}">`;
			html += `<div class="ow-sidebar__list-header">`;
			html += `<div class="ow-sidebar__list-text">${esc(entry.msgid)}</div>`;
			html += badge;
			html += `</div>`;
			html += `<div class="ow-sidebar__list-editor" style="display:none;"></div>`;
			html += `</div>`;
		});

		body.innerHTML = html;
		footer.style.display = 'flex';

		// Bind clicks to list items
		$$('.ow-sidebar__list-header', body).forEach(header => {
			header.addEventListener('click', function() {
				const item = this.closest('.ow-sidebar__list-item');
				activateString(item.dataset.owMsgid || item.getAttribute('data-msgid'), item.dataset.domain || item.getAttribute('data-domain'));
			});
		});
	}

	// ── Activate a String (from page click or list click) ─────────────────
	function activateString(msgid, domain) {
		if (activeMsgid === msgid) return; // Already active

		// Deactivate previous
		const prevActive = $('.ow-sidebar__list-item--active', body);
		if (prevActive) {
			prevActive.classList.remove('ow-sidebar__list-item--active');
			$('.ow-sidebar__list-editor', prevActive).style.display = 'none';
			$('.ow-sidebar__list-editor', prevActive).innerHTML = ''; // Clear to save memory
		}

		if (activeEl) {
			activeEl.classList.remove('ow-translatable--active');
			activeEl = null;
		}

		activeMsgid = msgid;

		// Activate sidebar item
		const item = $(`.ow-sidebar__list-item[data-msgid="${escAttr(msgid)}"]`, body);
		if (item) {
			item.classList.add('ow-sidebar__list-item--active');
			const editorEl = $('.ow-sidebar__list-editor', item);
			editorEl.style.display = 'block';
			editorEl.innerHTML = '<p class="ow-sidebar__loading">⏳ Loading translations…</p>';
			
			// Scroll sidebar to item smoothly
			item.scrollIntoView({ behavior: 'smooth', block: 'center' });

			// Highlight on page if it exists
			const pageEls = $$(`.ow-translatable[data-ow-msgid="${escAttr(msgid)}"]`);
			if (pageEls.length > 0) {
				activeEl = pageEls[0];
				activeEl.classList.add('ow-translatable--active');
				activeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}

			// Load translations
			ajax('ow_inline_get_translations', { msgid, domain }).then(data => {
				renderEditorFields(editorEl, msgid, domain, data);
			}).catch(err => {
				editorEl.innerHTML = '<p class="ow-sidebar__error">❌ ' + esc(err) + '</p>';
			});
		}
	}

	// ── Render Language Fields for a specific string ──────────────────────
	function renderEditorFields(container, msgid, domain, translations) {
		let html = '';
		languages.forEach(lang => {
			const val       = translations[lang.code] || '';
			const isEmpty   = !val.trim();
			const statusCls = isEmpty ? 'ow-field--empty' : 'ow-field--filled';
			const statusBadge = lang.status === 'pending'
				? ' <span class="ow-sidebar__pending">[P]</span>'
				: '';

			html += `<div class="ow-sidebar__field ${statusCls}" data-lang="${escAttr(lang.code)}">`;
			html += `<label class="ow-sidebar__label">${esc(lang.flag)} ${esc(lang.name)}${statusBadge}</label>`;
			html += `<div class="ow-sidebar__input-row">`;
			html += `<input type="text" class="ow-sidebar__input" data-msgid="${escAttr(msgid)}" data-domain="${escAttr(domain)}" data-lang="${escAttr(lang.code)}" value="${escAttr(val)}" placeholder="…">`;
			if (isEmpty) {
				html += `<button class="ow-sidebar__deepl-btn" data-lang="${escAttr(lang.code)}" title="Translate with DeepL">🤖</button>`;
			}
			html += `</div>`;
			html += `<span class="ow-sidebar__field-status" data-lang="${escAttr(lang.code)}"></span>`;
			html += `</div>`;
		});

		container.innerHTML = html;

		// Bind events
		$$('.ow-sidebar__deepl-btn', container).forEach(btn => {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				deeplSingle(msgid, domain, btn.dataset.lang, btn, container);
			});
		});

		$$('.ow-sidebar__input', container).forEach(inp => {
			inp.addEventListener('input', function() {
				inp.closest('.ow-sidebar__field').classList.add('ow-field--dirty');
				const statusEl = $('.ow-sidebar__field-status[data-lang="' + inp.dataset.lang + '"]', container);
				if (statusEl) {
					statusEl.textContent = '• Unsaved changes';
					statusEl.style.color = 'var(--ow-danger, #C0392B)';
				}
			});
		});
	}

	// ── DeepL single translation ──────────────────────────────────────────
	function deeplSingle(msgid, domain, langCode, btn, container) {
		btn.disabled = true;
		btn.textContent = '⏳';

		ajax('ow_inline_deepl_single', { msgid, lang: langCode }).then(data => {
			const input = $('.ow-sidebar__input[data-lang="' + langCode + '"]', container);
			if (input && data.translation) {
				input.value = data.translation;
				const field = input.closest('.ow-sidebar__field');
				field.classList.remove('ow-field--empty');
				field.classList.add('ow-field--filled', 'ow-field--dirty');
				btn.remove();
				
				const statusEl = $('.ow-sidebar__field-status[data-lang="' + langCode + '"]', container);
				if (statusEl) {
					statusEl.textContent = '🤖 Draft translation ready - Click Save All';
					statusEl.style.color = 'var(--ow-teal, #0EACC5)';
				}
			}
		}).catch(err => {
			btn.disabled = false;
			btn.textContent = '🤖';
			const statusEl = $('.ow-sidebar__field-status[data-lang="' + langCode + '"]', container);
			if (statusEl) {
				statusEl.textContent = '❌ ' + err;
				statusEl.style.color = 'var(--ow-danger, #C0392B)';
			}
		});
	}

	// ── DeepL All Empty ───────────────────────────────────────────────────
	deeplAllBtn.addEventListener('click', function () {
		if (!activeMsgid) {
			alert('Please select a string from the list first to translate its empty fields.');
			return;
		}

		const container = $('.ow-sidebar__list-item--active .ow-sidebar__list-editor', body);
		if (!container) return;

		const emptyInputs = $$('.ow-sidebar__input', container).filter(inp => !inp.value.trim());
		if (!emptyInputs.length) return;

		deeplAllBtn.disabled = true;
		deeplAllBtn.textContent = '⏳ Translating…';

		let remaining = emptyInputs.length;
		emptyInputs.forEach(inp => {
			const langCode = inp.dataset.lang;
			const btn = $('.ow-sidebar__deepl-btn[data-lang="' + langCode + '"]', container);

			ajax('ow_inline_deepl_single', { msgid: activeMsgid, lang: langCode }).then(data => {
				if (data.translation) {
					inp.value = data.translation;
					const field = inp.closest('.ow-sidebar__field');
					field.classList.remove('ow-field--empty');
					field.classList.add('ow-field--filled', 'ow-field--dirty');
					if (btn) btn.remove();
					const statusEl = $('.ow-sidebar__field-status[data-lang="' + langCode + '"]', container);
					if (statusEl) {
						statusEl.textContent = '🤖 Draft ready';
						statusEl.style.color = 'var(--ow-teal, #0EACC5)';
					}
				}
			}).catch(() => {
				const statusEl = $('.ow-sidebar__field-status[data-lang="' + langCode + '"]', container);
				if (statusEl) {
					statusEl.textContent = '❌ Error';
					statusEl.style.color = 'var(--ow-danger, #C0392B)';
				}
			}).finally(() => {
				remaining--;
				if (remaining <= 0) {
					deeplAllBtn.disabled = false;
					deeplAllBtn.textContent = '🤖 DeepL All Empty';
				}
			});
		});
	});

	// ── Save All ──────────────────────────────────────────────────────────
	saveAllBtn.addEventListener('click', function () {
		const dirtyFields = $$('.ow-field--dirty', body);
		if (!dirtyFields.length) return;

		saveAllBtn.disabled = true;
		saveAllBtn.textContent = '⏳ Saving…';

		let remaining = dirtyFields.length;
		dirtyFields.forEach(field => {
			const input = $('.ow-sidebar__input', field);
			const langCode = input.dataset.lang;
			const msgid = input.dataset.msgid;
			const domain = input.dataset.domain;

			ajax('ow_inline_save_translation', { msgid, domain, lang: langCode, msgstr: input.value }).then(() => {
				field.classList.remove('ow-field--dirty');
				const statusEl = $('.ow-sidebar__field-status[data-lang="' + langCode + '"]', field);
				if (statusEl) {
					statusEl.textContent = '✓ Saved';
					statusEl.style.color = 'var(--ow-emerald, #2EB89A)';
				}
			}).catch(() => {
				const statusEl = $('.ow-sidebar__field-status[data-lang="' + langCode + '"]', field);
				if (statusEl) {
					statusEl.textContent = '❌ Failed to save';
					statusEl.style.color = 'var(--ow-danger, #C0392B)';
				}
			}).finally(() => {
				remaining--;
				if (remaining <= 0) {
					saveAllBtn.disabled = false;
					saveAllBtn.textContent = '💾 Save All';
				}
			});
		});
	});

	// ── Initialization & Events ───────────────────────────────────────────
	
	// Add class to body to push site content so sidebar doesn't overlap
	document.body.classList.add('ow-inline-active');
	
	// Run scan
	scanAndWrap();
	
	// Render sidebar strings list
	renderStringList();

	// Show sidebar permanently
	sidebar.classList.remove('ow-sidebar--minimized');

	// CAPTURE phase click handler to intercept clicks inside links
	document.addEventListener('click', function (e) {
		let el = e.target;
		while (el && el !== document.body) {
			if (el.classList && el.classList.contains('ow-translatable')) break;
			el = el.parentElement;
		}
		if (!el || el === document.body || !el.classList.contains('ow-translatable')) return;

		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();

		const msgid  = el.dataset.owMsgid;
		const domain = el.dataset.owDomain || 'default';
		
		activateString(msgid, domain);
	}, true);

	// Close sidebar acts as "Minimize to strip" (optional functionality later, removing for now)
	if (closeBtn) closeBtn.style.display = 'none'; // We want it permanently open

	// ── Helpers ────────────────────────────────────────────────────────────
	function ajax(action, data) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('_ajax_nonce', cfg.nonce);
		Object.keys(data).forEach(k => fd.append(k, data[k]));

		return fetch(cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		}).then(r => r.json()).then(r => {
			if (r.success) return r.data;
			throw r.data || 'Unknown error';
		});
	}

	function esc(s) {
		const d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function escAttr(s) {
		return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}
});
