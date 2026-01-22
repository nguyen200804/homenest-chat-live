(function ($) {
	function getCookie(name) {
		const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function setCookie(name, value, days) {
		const d = new Date();
		d.setTime(d.getTime() + (days || 365) * 24 * 60 * 60 * 1000);
		document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; expires=' + d.toUTCString();
	}

	function isEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
	function isPhone(v) {
		const digits = v.replace(/[^\d+]/g, '');
		const onlyNums = digits.replace(/\D/g, '');
		return onlyNums.length >= 8;
	}

	const $root = $('#hn-chat-widget');
	if (!$root.length) return;

	const $fab = $root.find('.hn-chat__fab');
	const $badge = $root.find('.hn-chat__badge');
	const $panel = $root.find('.hn-chat__panel');
	const $close = $root.find('.hn-chat__close');

	const $gate = $root.find('.hn-chat__contactGate');
	const $contactInput = $root.find('.hn-chat__contactInput');
	const $contactBtn = $root.find('.hn-chat__contactBtn');
	// container for duplicate warnings / actions
	let $contactWarn = $root.find('.hn-chat__contactWarn');
	if (!$contactWarn.length) {
		$contactWarn = $('<div class="hn-chat__contactWarn" style="margin-top:8px;font-size:13px;color:#b33;display:none;"></div>');
		$gate.append($contactWarn);
	}

	const $msgs = $root.find('.hn-chat__messages');
	const $footer = $root.find('.hn-chat__footer');
	const $input = $root.find('.hn-chat__input');
	const $send = $root.find('.hn-chat__send');

	const $file = $root.find('.hn-chat__file');

	let contact = getCookie(HNChat.cookie_contact || 'hn_chat_contact') || '';
	let afterId = 0;
	let pollTimer = null;
	let bgPollTimer = null;
	let isOpen = false;
	let unseenWhileClosed = 0;

	function setBadge(n) {
		if (n > 0) {
			$badge.text(n > 99 ? '99+' : String(n)).show();
		} else {
			$badge.hide();
		}
	}

	function escHtml(s) {
		return String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
	}

	function appendMsg(m) {
		const role = (m.sender_role === 'admin' || m.sender_role === 'agent') ? 'admin' : 'guest';
		// dedupe: if last message has same role and same text, skip append
		try {
			const last = $msgs.children('.hn-chat__msg').last();
			if (last && last.length) {
				const lastRole = last.hasClass('hn-chat__msg--admin') ? 'admin' : 'guest';
				const lastText = last.find('.hn-chat__bubble').text().trim();
				const curText = $('<div>').html(m.message || '').text().trim();
				if (lastRole === role && lastText && curText && lastText === curText) {
					// already displayed, update afterId and return
					const idNum2 = parseInt(m.id, 10) || 0;
					if (idNum2 > afterId) afterId = idNum2;
					return;
				}
			}
		} catch (e) {
			// ignore any error and continue
		}

		const $item = $('<div class="hn-chat__msg hn-chat__msg--' + role + '"></div>');
		const $bubble = $('<div class="hn-chat__bubble"></div>').html(m.message);
		const $time = $('<div class="hn-chat__time"></div>').text(m.created_at || '');
		$item.append($bubble).append($time);
		$msgs.append($item);
		$msgs.scrollTop($msgs[0].scrollHeight);
		const idNum = parseInt(m.id, 10) || 0;
		if (idNum > afterId) afterId = idNum;
	}

	function showChatUI() {
		$gate.hide();
		$msgs.show();
		$footer.show();
	}
	function showGateUI() {
		$gate.show();
		$msgs.hide();
		$footer.hide();
	}

	function api(action, data) {
		return $.post(HNChat.ajax_url, Object.assign({
			action,
			nonce: HNChat.nonce
		}, data || {}));
	}

	function saveContact(v) {
		return api('hn_chat_save_contact', { contact: v });
	}

	function sendMessage(text) {
		return api('hn_chat_send_message', {
			contact: contact,
			message: text
		});
	}

	function fetchMessages() {
		if (!contact) return;
		console.debug('hn-chat: fetchMessages start', { contact: contact, afterId: afterId });
		return api('hn_chat_fetch_messages', {
			contact: contact,
			after_id: afterId
		}).done(function (res) {
			console.debug('hn-chat: fetchMessages response', res);
			if (!res || !res.ok) return;
			const list = (res.data && res.data.messages) ? res.data.messages : [];
			console.debug('hn-chat: messages received', list.length);
			if (!list.length) return;

			list.forEach(function (m) {
				appendMsg(m);
				// nếu panel đóng mà nhận tin admin/agent => badge
				if (!isOpen && (m.sender_role === 'admin' || m.sender_role === 'agent')) {
					unseenWhileClosed++;
				}
			});

			if (!isOpen && unseenWhileClosed > 0) setBadge(unseenWhileClosed);
		});
	}

	function startPolling() {
		stopPolling();
		pollTimer = setInterval(fetchMessages, HNChat.poll_ms || 3000);
	}
	function stopPolling() {
		if (pollTimer) clearInterval(pollTimer);
		pollTimer = null;
	}

	function openPanel() {
		isOpen = true;
		unseenWhileClosed = 0;
		setBadge(0);
		$panel.show();
		if (contact) {
			showChatUI();
			fetchMessages();
			startPolling();
		} else {
			showGateUI();
		}
	}
	function closePanel() {
		isOpen = false;
		$panel.hide();
		stopPolling();
	}

	$fab.on('click', function () {
		if ($panel.is(':visible')) closePanel();
		else openPanel();
	});
	$close.on('click', closePanel);

	// Tìm đoạn này trong assets/hn-chat.js
	$contactBtn.on('click', function () {
		const v = ($contactInput.val() || '').trim();
		if (!v || (!isEmail(v) && !isPhone(v))) {
			alert('Vui lòng nhập email hoặc số điện thoại hợp lệ.');
			return;
		}

		// Thay vì check_contact (không tồn tại), hãy gọi thẳng saveContact
		saveContact(v).done(function (res) {
			if (res && res.ok) {
				contact = v;
				// Lưu cookie để khách không phải nhập lại khi tải lại trang
				setCookie(HNChat.cookie_contact || 'hn_chat_contact', v, 365);
				$contactWarn.hide().empty();
				showChatUI();
				startPolling();
			} else {
				// phát hiện trùng (backend có thể trả về res.data.exists hoặc thông điệp lỗi)
				const isExists = (res && ((res.data && res.data.exists) || (typeof res.error === 'string' && /exist|tồn tại|đã tồn tại/i.test(res.error))));
				if (isExists) {
					// Nếu cấu hình bắt buộc unique thì cấm tiếp tục
					if (HNChat && HNChat.force_unique) {
						$contactWarn.show().text('Số điện thoại / Email này đã tồn tại. Vui lòng nhập SĐT/Email khác.');
						$contactInput.val('').focus();
						return;
					}
					// Hiển thị lựa chọn: Tiếp tục với tài khoản hiện tại hoặc Nhập khác
					$contactWarn.html('Số điện thoại / Email này đã tồn tại. <button class="hn-chat__continueExisting" style="margin-left:8px">Tiếp tục</button> <button class="hn-chat__enterDifferent" style="margin-left:6px">Nhập số/Email khác</button>').show();
					$contactWarn.find('.hn-chat__continueExisting').on('click', function () {
						contact = v;
						setCookie(HNChat.cookie_contact || 'hn_chat_contact', v, 365);
						$contactWarn.hide().empty();
						showChatUI();
						startPolling();
					});
					$contactWarn.find('.hn-chat__enterDifferent').on('click', function () {
						$contactWarn.hide().empty();
						$contactInput.val('').focus();
					});
					return;
				}
				alert(res.error || 'Không thể lưu thông tin liên hệ.');
			}
		}).fail(function() {
			alert('Lỗi kết nối máy chủ.');
		});
	});

	function doSend() {
		const text = ($input.val() || '').trim();
		if (!text) return;
		$input.val('');

		// optimistic render (guest)
		appendMsg({ id: afterId + 1, sender_role: 'guest', message: escHtml(text), created_at: '' });

		sendMessage(text).done(function (res) {
			if (!res || !res.ok) {
				alert((res && res.error) ? res.error : 'Gửi thất bại.');
			} else {
				fetchMessages();
			}
		});
	}

	$send.on('click', doSend);
	$input.on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			doSend();
		}
	});

	function uploadFile(file) {
		if (!file) return;
		const max = (HNChat.max_upload_mb || 5) * 1024 * 1024;
		if (file.size > max) {
			alert('File quá lớn. Tối đa ' + (HNChat.max_upload_mb || 5) + 'MB.');
			return;
		}
		const fd = new FormData();
		fd.append('action', 'hn_chat_upload');
		fd.append('nonce', HNChat.nonce);
		fd.append('file', file);

		$input.prop('disabled', true);
		$send.prop('disabled', true);

		$.ajax({
			url: HNChat.ajax_url,
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
				html = `<a href="${url}" target="_blank" rel="noopener">🖼️ Ảnh</a><br><img src="${url}" alt="" style="max-width:180px;border-radius:10px;margin-top:6px;">`;
			} else {
				html = `<a href="${url}" target="_blank" rel="noopener">📎 Tệp đính kèm</a>`;
			}
			// gửi như message HTML
			sendMessage(html).done(function () {
				fetchMessages();
			});
		}).always(function () {
			$input.prop('disabled', false);
			$send.prop('disabled', false);
			$file.val('');
		});
	}

	$file.on('change', function () {
		const f = this.files && this.files[0];
		if (!contact) {
			alert('Vui lòng nhập SĐT/Email trước.');
			$file.val('');
			return;
		}
		uploadFile(f);
	});

	// init
	if (contact) showChatUI();
	else showGateUI();

	// background polling để hiện badge dù panel đóng (nhẹ)
	if (contact) {
		bgPollTimer = setInterval(fetchMessages, Math.max(5000, HNChat.poll_ms || 3000));
	}

})(jQuery);
