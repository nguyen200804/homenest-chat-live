(function ($) {
	const $wrap = $('#hn-admin-chat');
	if (!$wrap.length) return;

	const $q = $wrap.find('.hn-admin-q');
	const $list = $wrap.find('.hn-admin-convList');

	const $title = $wrap.find('.hn-admin-convTitle');
	const $meta = $wrap.find('.hn-admin-convMeta');

	const $msgs = $wrap.find('.hn-admin-messages');

	const $input = $wrap.find('.hn-admin-input');
	const $send = $wrap.find('.hn-admin-send');

	const $btnAssignMe = $wrap.find('.hn-btnAssignMe');
	const $assignSelect = $wrap.find('.hn-admin-assignSelect');
	const $btnToggleStatus = $wrap.find('.hn-btnToggleStatus');

	const $tags = $wrap.find('.hn-admin-tags');
	const $note = $wrap.find('.hn-admin-note');
	const $btnSaveTags = $wrap.find('.hn-btnSaveTags');
	const $btnSaveNote = $wrap.find('.hn-btnSaveNote');

	const $file = $wrap.find('.hn-admin-file');

	let activeConvId = 0;
	let activeConv = null;
	let currentFilter = 'all';
	let agents = [];

	function escHtml(s) {
		return String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
	}

	function api(action, data) {
		return $.post(HNChatAdmin.ajax_url, Object.assign({
			action,
			nonce: HNChatAdmin.nonce
		}, data || {}));
	}

	function parseTags(jsonText) {
		try {
			const arr = JSON.parse(jsonText || '[]');
			return Array.isArray(arr) ? arr : [];
		} catch (e) {
			return [];
		}
	}

	function renderList(items) {
		$list.empty();
		if (!items || !items.length) {
			$list.append('<div class="hn-empty">Chưa có cuộc hội thoại</div>');
			return;
		}

		items.forEach(c => {
			const last = c.last_message_at || c.created_at || '';
			const unread = parseInt(c.unread_for_admin, 10) || 0;
			const assigned = parseInt(c.assigned_user_id, 10) || 0;

			const status = c.status || 'open';
			const statusDot = status === 'closed' ? '<span class="hn-dot hn-dot--closed"></span>' : '<span class="hn-dot hn-dot--open"></span>';

			const tagArr = parseTags(c.tags);
			const tagsHtml = tagArr.length ? `<div class="hn-conv__tags">${tagArr.slice(0,3).map(t=>`<span class="hn-tag">${escHtml(t)}</span>`).join('')}</div>` : '';

			const meMark = assigned === HNChatAdmin.me ? '<span class="hn-pill">Của tôi</span>' : (assigned ? '<span class="hn-pill hn-pill--assigned">Đã gán</span>' : '<span class="hn-pill hn-pill--unassigned">Chưa gán</span>');

			const cls = (activeConvId === parseInt(c.id, 10)) ? 'hn-conv hn-conv--active' : 'hn-conv';

			// Tìm đoạn $list.append trong hàm renderList và thay đổi nội dung bên trong:
			$list.append(
				`<div class="hn-conv-wrapper" style="position:relative;">
<button class="${cls}" data-id="${c.id}" type="button">
<div class="hn-conv__row1">
<div class="hn-conv__contact">${statusDot}${escHtml(c.contact)}</div>
${unread > 0 ? `<span class="hn-unread">${unread > 99 ? '99+' : unread}</span>` : ''}
</div>
<div class="hn-conv__row2">
<div class="hn-conv__meta">${escHtml(c.contact_type)} • ${escHtml(last)}</div>
${meMark}
</div>
${tagsHtml}
</button>
<button class="hn-btnDeleteConv" data-id="${c.id}" 
style="position:absolute; top:10px; right:10px; background:none; border:none; color:#e11d48; cursor:pointer;" 
title="Xóa đoạn chat">✕</button>
</div>`
			);
		});
	}

	function loadAgents() {
		return api('hn_chat_admin_list_agents').done(res => {
			if (!res || !res.ok) return;
			agents = res.data.agents || [];
			if (HNChatAdmin.is_admin) {
				$assignSelect.show().empty();
				agents.forEach(a => {
					$assignSelect.append(`<option value="${a.id}">${escHtml(a.name)}</option>`);
				});
			} else {
				$assignSelect.hide();
			}
		});
	}

	function loadList() {
		api('hn_chat_admin_list_conversations', {
			q: ($q.val() || '').trim(),
			filter: currentFilter
		}).done(res => {
			if (!res || !res.ok) return;
			renderList(res.data.conversations || []);
		});
	}

	function renderMessages(items) {
		$msgs.empty();
		(items || []).forEach(m => {
			const role = (m.sender_role === 'admin' || m.sender_role === 'agent') ? 'admin' : 'guest';
			const who = m.sender_role === 'guest' ? 'Khách' : (m.sender_name || m.sender_role);
			$msgs.append(
				`<div class="hn-amsg hn-amsg--${role}">
<div class="hn-amsg__who">${escHtml(who)}</div>
<div class="hn-amsg__bubble">${m.message}</div>
<div class="hn-amsg__time">${escHtml(m.created_at)}</div>
</div>`
			);
		});
		$msgs.scrollTop($msgs[0].scrollHeight);
	}

	function enableRight(enabled) {
		$input.prop('disabled', !enabled);
		$send.prop('disabled', !enabled);
		$btnAssignMe.prop('disabled', !enabled);
		$btnToggleStatus.prop('disabled', !enabled);
		$tags.prop('disabled', !enabled);
		$note.prop('disabled', !enabled);
		$btnSaveTags.prop('disabled', !enabled);
		$btnSaveNote.prop('disabled', !enabled);
	}

	function updateTopbar(conv) {
		const status = conv.status || 'open';
		const assigned = parseInt(conv.assigned_user_id, 10) || 0;
		const tagsArr = parseTags(conv.tags);

		$title.text((conv.contact || '') + ' (' + (conv.contact_type || '') + ')');
		$meta.text(
			`#${conv.id} • ${status === 'closed' ? 'Đã đóng' : 'Đang mở'} • Assigned: ${assigned || '—'}`
		);

		$btnToggleStatus.text(status === 'closed' ? 'Mở lại' : 'Đóng');
		$tags.val(tagsArr.join(', '));
		$note.val(conv.note || '');

		// select assign value for admin
		if (HNChatAdmin.is_admin && $assignSelect.is(':visible')) {
			$assignSelect.val(String(assigned || HNChatAdmin.me));
		}
	}

	function markReadIfPossible(messages) {
		const last = (messages && messages.length) ? messages[messages.length - 1] : null;
		const lastId = last ? (parseInt(last.id, 10) || 0) : 0;
		if (!activeConvId || !lastId) return;

		api('hn_chat_admin_mark_read', { conversation_id: activeConvId, last_msg_id: lastId })
			.done(() => loadList());
	}

	function loadConversation(id) {
		activeConvId = parseInt(id, 10);
		enableRight(false);

		api('hn_chat_admin_get_conversation', { conversation_id: activeConvId })
			.done(res => {
			if (!res || !res.ok) return;

			activeConv = res.data.conversation;
			const messages = res.data.messages || [];

			updateTopbar(activeConv);
			renderMessages(messages);

			enableRight(true);

			// mark read -> unread_for_admin = 0
			markReadIfPossible(messages);

			// refresh list highlight/unread
			loadList();
		});
	}

	function sendAdmin(textHtml) {
		if (!activeConvId) return;
		const text = (textHtml || ($input.val() || '').trim());
		if (!text) return;

		if (!textHtml) $input.val('');

		api('hn_chat_admin_send_message', { conversation_id: activeConvId, message: text })
			.done(res => {
			if (!res || !res.ok) {
				alert((res && res.error) ? res.error : 'Gửi thất bại');
				return;
			}
			loadConversation(activeConvId);
		});
	}

	function toggleStatus() {
		if (!activeConvId || !activeConv) return;
		const next = (activeConv.status === 'closed') ? 'open' : 'closed';
		api('hn_chat_admin_set_status', { conversation_id: activeConvId, status: next })
			.done(res => {
			if (!res || !res.ok) return;
			loadConversation(activeConvId);
		});
	}

	function assignTo(userId) {
		if (!activeConvId) return;
		api('hn_chat_admin_assign', { conversation_id: activeConvId, user_id: userId })
			.done(res => {
			if (!res || !res.ok) {
				alert((res && res.error) ? res.error : 'Assign thất bại');
				return;
			}
			loadConversation(activeConvId);
		});
	}

	function saveTags() {
		if (!activeConvId) return;
		api('hn_chat_admin_set_tags', { conversation_id: activeConvId, tags: ($tags.val() || '').trim() })
			.done(res => {
			if (!res || !res.ok) return;
			loadConversation(activeConvId);
		});
	}

	function saveNote() {
		if (!activeConvId) return;
		api('hn_chat_admin_set_note', { conversation_id: activeConvId, note: ($note.val() || '').trim() })
			.done(res => {
			if (!res || !res.ok) return;
			loadConversation(activeConvId);
		});
	}

	function uploadFile(file) {
		if (!file) return;

		const fd = new FormData();
		fd.append('action', 'hn_chat_upload'); // dùng chung endpoint front (nonce khác) -> ta gọi bằng ajax thường không có nonce front
		// trick: endpoint upload đang check hn_chat_nonce (front), nên ta sẽ upload bằng admin nonce? Không được.
		// => Giải pháp nhanh: upload admin dùng front nonce riêng là khó.
		// Cách ổn: upload admin gửi như message link bằng media URL có sẵn (không upload).
		// Nhưng bạn muốn upload trong admin: ta làm cách "2 bước" - gọi admin endpoint upload riêng.
	}

	// --- Admin upload: tạo endpoint riêng bằng admin nonce (đi kèm dưới)
	function adminUpload(file) {
		if (!file) return;
		const fd = new FormData();
		fd.append('action', 'hn_chat_admin_upload');
		fd.append('nonce', HNChatAdmin.nonce);
		fd.append('file', file);

		$input.prop('disabled', true);
		$send.prop('disabled', true);

		$.ajax({
			url: HNChatAdmin.ajax_url,
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,
		}).done(function (res) {
			if (!res || !res.ok) {
				alert((res && res.error) ? res.error : 'Upload thất bại.');
				return;
			}
			const url = res.data.url;
			const mime = res.data.mime || '';
			let html = '';
			if (mime.startsWith('image/')) {
				html = `<a href="${url}" target="_blank" rel="noopener">🖼️ Ảnh</a><br><img src="${url}" alt="" style="max-width:220px;border-radius:10px;margin-top:6px;">`;
			} else {
				html = `<a href="${url}" target="_blank" rel="noopener">📎 Tệp đính kèm</a>`;
			}
			sendAdmin(html);
		}).always(function () {
			$input.prop('disabled', false);
			$send.prop('disabled', false);
			$file.val('');
		});
	}

	// Events
	$wrap.on('click', '.hn-conv', function () {
		loadConversation($(this).data('id'));
	});

	$send.on('click', function () { sendAdmin(); });

	$input.on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			sendAdmin();
		}
	});

	$btnToggleStatus.on('click', toggleStatus);

	$btnAssignMe.on('click', function () {
		assignTo(HNChatAdmin.me);
	});

	$assignSelect.on('change', function () {
		if (!HNChatAdmin.is_admin) return;
		const uid = parseInt($(this).val(), 10) || 0;
		if (uid) assignTo(uid);
	});

	$btnSaveTags.on('click', saveTags);
	$btnSaveNote.on('click', saveNote);

	let t = null;
	$q.on('input', function () {
		clearTimeout(t);
		t = setTimeout(loadList, 250);
	});

	$wrap.find('.hn-filter').on('click', function () {
		$wrap.find('.hn-filter').removeClass('hn-filter--active');
		$(this).addClass('hn-filter--active');
		currentFilter = $(this).data('filter') || 'all';
		loadList();
	});

	$file.on('change', function () {
		const f = this.files && this.files[0];
		if (!activeConvId) { alert('Chọn cuộc hội thoại trước.'); $file.val(''); return; }
		adminUpload(f);
	});

	// Thêm sự kiện xóa đoạn chat
	$wrap.on('click', '.hn-btnDeleteConv', function () {
		const convId = $(this).data('id');
		if (!convId || !confirm('Bạn có chắc chắn muốn xóa đoạn chat này?')) return;

		api('hn_chat_admin_delete_conversation', { conversation_id: convId }).done(res => {
			if (res.ok) {
				alert('Đoạn chat đã được xóa.');
				loadList();
			} else {
				alert(res.error || 'Không thể xóa đoạn chat.');
			}
		});
	});

	// init
	enableRight(false);
	loadAgents().always(loadList);

	// refresh list every 5s
	setInterval(loadList, 5000);

})(jQuery);
