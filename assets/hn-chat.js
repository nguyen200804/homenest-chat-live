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

  function isEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  }
  function isPhone(v) {
    const digits = v.replace(/[^\d+]/g, '');
    const onlyNums = digits.replace(/\D/g, '');
    return onlyNums.length >= 8;
  }

  const $root = $('#hn-chat-widget');
  if (!$root.length) return;

  const $fab = $root.find('.hn-chat__fab');
  const $panel = $root.find('.hn-chat__panel');
  const $close = $root.find('.hn-chat__close');

  const $gate = $root.find('.hn-chat__contactGate');
  const $contactInput = $root.find('.hn-chat__contactInput');
  const $contactBtn = $root.find('.hn-chat__contactBtn');

  const $msgs = $root.find('.hn-chat__messages');
  const $footer = $root.find('.hn-chat__footer');
  const $input = $root.find('.hn-chat__input');
  const $send = $root.find('.hn-chat__send');

  let contact = getCookie(HNChat.cookie_contact || 'hn_chat_contact') || '';
  let afterId = 0;
  let pollTimer = null;
  let isOpen = false;

  function appendMsg(m) {
    const role = m.sender_role === 'admin' ? 'admin' : 'guest';
    const $item = $('<div class="hn-chat__msg hn-chat__msg--' + role + '"></div>');
    const $bubble = $('<div class="hn-chat__bubble"></div>').html(m.message);
    const $time = $('<div class="hn-chat__time"></div>').text(m.created_at || '');
    $item.append($bubble).append($time);
    $msgs.append($item);
    $msgs.scrollTop($msgs[0].scrollHeight);
    afterId = Math.max(afterId, parseInt(m.id, 10) || afterId);
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

  function saveContact(v) {
    return $.post(HNChat.ajax_url, {
      action: 'hn_chat_save_contact',
      nonce: HNChat.nonce,
      contact: v
    });
  }

  function sendMessage(text) {
    return $.post(HNChat.ajax_url, {
      action: 'hn_chat_send_message',
      nonce: HNChat.nonce,
      contact: contact,
      message: text
    });
  }

  function fetchMessages() {
    if (!contact) return;
    return $.post(HNChat.ajax_url, {
      action: 'hn_chat_fetch_messages',
      nonce: HNChat.nonce,
      contact: contact,
      after_id: afterId
    }).done(function (res) {
      if (!res || !res.ok) return;
      const list = (res.data && res.data.messages) ? res.data.messages : [];
      if (list.length) {
        list.forEach(appendMsg);
      }
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

  $contactBtn.on('click', function () {
    const v = ($contactInput.val() || '').trim();
    if (!v || (!isEmail(v) && !isPhone(v))) {
      alert('Vui lòng nhập SĐT hoặc Email hợp lệ.');
      return;
    }
    saveContact(v).done(function (res) {
      if (!res || !res.ok) {
        alert((res && res.error) ? res.error : 'Lỗi lưu thông tin.');
        return;
      }
      contact = v;
      setCookie(HNChat.cookie_contact || 'hn_chat_contact', v, 365);
      showChatUI();
      fetchMessages();
      startPolling();
    });
  });

  function doSend() {
    const text = ($input.val() || '').trim();
    if (!text) return;
    $input.val('');
    // append optimistic
    appendMsg({ id: afterId + 1, sender_role: 'guest', message: $('<div/>').text(text).html(), created_at: '' });

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

  // Nếu đã có contact -> chuẩn bị UI (nhưng không auto-open)
  if (contact) {
    showChatUI();
  } else {
    showGateUI();
  }

})(jQuery);
