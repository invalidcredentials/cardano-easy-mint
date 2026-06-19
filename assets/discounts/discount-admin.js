/* Cardano Easy Mint — Discounts admin. Vanilla, no deps. */
(function () {
	'use strict';
	var CFG = window.CM_DISCOUNT_ADMIN || {};
	if (!CFG.ajax_url) return;

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', CFG.nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(CFG.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); });
	}

	function csvUrl(campaignId) {
		return CFG.ajax_url + '?action=cardano_discount_export_csv&campaign_id=' +
			encodeURIComponent(campaignId) + '&nonce=' + encodeURIComponent(CFG.nonce);
	}

	/* ---- conditional fields (discount type + code mode) ---- */
	function syncConditionals() {
		var type = (document.querySelector('input[name="discount_type"]:checked') || {}).value;
		var mode = (document.querySelector('input[name="code_mode"]:checked') || {}).value;
		$all('[data-when]').forEach(function (el) {
			var w = el.getAttribute('data-when');
			var show = (w === type) || (w === mode);
			el.hidden = !show;
		});
	}

	/* ---- create ---- */
	function onCreate() {
		var form = $('#cmd-create-form');
		var status = $('#cmd-create-status');
		var btn = $('#cmd-create-btn');
		var fd = new FormData(form);
		var data = {};
		fd.forEach(function (v, k) { data[k] = v; });

		status.className = 'cmd-status';
		status.textContent = 'Creating…';
		btn.disabled = true;

		post('cardano_discount_create_campaign', data).then(function (res) {
			btn.disabled = false;
			if (!res || !res.success) {
				status.className = 'cmd-status is-error';
				status.textContent = (res && res.data && res.data.message) || 'Could not create campaign.';
				return;
			}
			status.className = 'cmd-status is-success';
			status.textContent = 'Created ' + res.data.count + ' code' + (res.data.count === 1 ? '' : 's') + '.';

			var box = $('#cmd-generated');
			box.hidden = false;
			$('#cmd-generated-count').textContent = '(' + res.data.count + ')';
			$('#cmd-generated-list').value = (res.data.codes || []).join('\n');
			$('#cmd-download-codes').setAttribute('href', csvUrl(res.data.campaign_id));

			loadCampaigns();
		}).catch(function () {
			btn.disabled = false;
			status.className = 'cmd-status is-error';
			status.textContent = 'Network error.';
		});
	}

	/* ---- list ---- */
	function loadCampaigns() {
		var tbody = $('#cmd-campaigns tbody');
		post('cardano_discount_list_campaigns', {}).then(function (res) {
			if (!res || !res.success) { tbody.innerHTML = '<tr><td colspan="6">Could not load.</td></tr>'; return; }
			var rows = res.data.campaigns || [];
			if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6">No campaigns yet.</td></tr>'; return; }
			tbody.innerHTML = rows.map(function (c) {
				var type = c.discount_type === 'percent'
					? (trimNum(c.percent_off) + '% off')
					: ('$' + trimNum(c.fixed_off_usd) + ' off');
				var paused = c.status === 'paused';
				return '<tr>' +
					'<td>' + esc(c.title) + '</td>' +
					'<td>' + esc(type) + '</td>' +
					'<td>' + esc(c.code_count) + '</td>' +
					'<td>' + esc(c.redeemed_count) + '</td>' +
					'<td><span class="cmd-pill cmd-' + esc(c.status) + '">' + esc(c.status) + '</span></td>' +
					'<td class="cmd-actions">' +
						'<button class="button-link" data-view="' + esc(c.id) + '">View</button> · ' +
						'<button class="button-link" data-toggle="' + esc(c.id) + '" data-next="' + (paused ? 'active' : 'paused') + '">' + (paused ? 'Activate' : 'Pause') + '</button> · ' +
						'<a href="' + csvUrl(c.id) + '">CSV</a>' +
					'</td>' +
				'</tr>';
			}).join('');
		});
	}

	// Codes can run into the hundreds (a 50–500 batch), so the detail view
	// paginates them 10 at a time.
	var detailCodes = [];
	var detailPage = 0;
	var CODES_PER_PAGE = 10;

	function renderCodesPage() {
		var host = document.getElementById('cmd-codes-host');
		if (!host) return;
		var total = detailCodes.length;
		var pages = Math.max(1, Math.ceil(total / CODES_PER_PAGE));
		if (detailPage >= pages) detailPage = pages - 1;
		if (detailPage < 0) detailPage = 0;
		var start = detailPage * CODES_PER_PAGE;
		var rows = detailCodes.slice(start, start + CODES_PER_PAGE).map(function (k) {
			var dis = k.status === 'disabled';
			return '<tr><td><code>' + esc(k.code) + '</code></td><td>' + esc(k.uses_count) + '/' +
				(Number(k.uses_allowed) === 0 ? '∞' : esc(k.uses_allowed)) + '</td><td>' + esc(k.status) + '</td>' +
				'<td>' + (dis ? '' : '<button class="button-link" data-disable="' + esc(k.id) + '">disable</button>') + '</td></tr>';
		}).join('') || '<tr><td colspan="4">none</td></tr>';
		var pager = total > CODES_PER_PAGE
			? '<div class="cmd-pager">' +
				'<button class="button" data-codes-prev ' + (detailPage === 0 ? 'disabled' : '') + '>‹ Prev</button>' +
				'<span>Page ' + (detailPage + 1) + ' / ' + pages + ' · ' + total + ' codes</span>' +
				'<button class="button" data-codes-next ' + (detailPage >= pages - 1 ? 'disabled' : '') + '>Next ›</button>' +
			  '</div>'
			: '';
		host.innerHTML = '<table class="widefat striped"><thead><tr><th>Code</th><th>Uses</th><th>Status</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' + pager;
	}

	function viewCampaign(id) {
		var detail = $('#cmd-detail');
		detail.hidden = false;
		detail.innerHTML = 'Loading…';
		post('cardano_discount_view_campaign', { campaign_id: id }).then(function (res) {
			if (!res || !res.success) { detail.innerHTML = 'Could not load campaign.'; return; }
			var c = res.data.campaign, reds = res.data.redemptions || [];
			detailCodes = res.data.codes || [];
			detailPage = 0;
			var redRows = reds.map(function (r) {
				return '<tr><td><code>' + esc(r.code) + '</code></td><td>' + esc(r.status) + '</td><td>' +
					esc(r.payment_method) + '</td><td>$' + esc(r.final_usd) + '</td><td><code>' +
					esc((r.wallet_address || '').slice(0, 16)) + '</code></td><td>' + esc(r.redeemed_at || '') + '</td></tr>';
			}).join('') || '<tr><td colspan="6">none</td></tr>';
			detail.innerHTML =
				'<h3>' + esc(c.title) + ' <a class="button" href="' + csvUrl(c.id) + '">Export CSV</a></h3>' +
				'<div class="cmd-detail-grid">' +
					'<div><h4>Codes (' + detailCodes.length + ')</h4><div id="cmd-codes-host"></div></div>' +
					'<div><h4>Redemptions (' + reds.length + ')</h4><table class="widefat striped"><thead><tr><th>Code</th><th>Status</th><th>Pay</th><th>Paid</th><th>Wallet</th><th>When</th></tr></thead><tbody>' + redRows + '</tbody></table></div>' +
				'</div>';
			renderCodesPage();
		});
	}

	function trimNum(n) { n = parseFloat(n) || 0; return (n % 1 === 0) ? String(n) : String(n); }

	/* ---- wire up ---- */
	document.addEventListener('DOMContentLoaded', function () {
		if (!$('#cmd-create-form')) return;
		syncConditionals();
		$all('input[name="discount_type"], input[name="code_mode"]').forEach(function (el) {
			el.addEventListener('change', syncConditionals);
		});
		$('#cmd-create-btn').addEventListener('click', onCreate);

		$('#cmd-copy-codes').addEventListener('click', function () {
			var ta = $('#cmd-generated-list'); ta.select();
			try { document.execCommand('copy'); this.textContent = 'Copied!'; var b = this; setTimeout(function () { b.textContent = 'Copy all'; }, 1500); } catch (e) {}
		});

		document.addEventListener('click', function (e) {
			var t = e.target;
			if (t.matches('[data-view]')) { e.preventDefault(); viewCampaign(t.getAttribute('data-view')); }
			else if (t.matches('[data-toggle]')) {
				e.preventDefault();
				post('cardano_discount_set_status', { campaign_id: t.getAttribute('data-toggle'), status: t.getAttribute('data-next') }).then(loadCampaigns);
			} else if (t.matches('[data-disable]')) {
				e.preventDefault();
				if (!window.confirm('Disable this code? It can no longer be redeemed.')) return;
				var id = t.getAttribute('data-disable');
				post('cardano_discount_disable_code', { code_id: id }).then(function () {
					var hit = detailCodes.filter(function (k) { return String(k.id) === String(id); })[0];
					if (hit) hit.status = 'disabled';
					renderCodesPage();
				});
			} else if (t.matches('[data-codes-prev]')) { e.preventDefault(); detailPage--; renderCodesPage(); }
			else if (t.matches('[data-codes-next]')) { e.preventDefault(); detailPage++; renderCodesPage(); }
		});

		loadCampaigns();
	});
})();
