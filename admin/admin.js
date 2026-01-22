(function ($) {
  const $wrap = $('#hn-admin-chat');
  if (!$wrap.length) return;

  const $q = $wrap.find('.hn-admin-q');
  const $list = $wrap.find('.hn-admin-convList');
  const $title = $wrap.find('.hn-admin-convTitle');
  const $msgs = $wrap.find('.hn-admin-messages');
  const $input = $wrap.find('.hn-admin-input');
  const $send = $wrap.find('.hn-admin-send');

  let activeConvId = 0;

  function escHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function api(action, data) {
    return $.post(HNChatAdmin.ajax_url, Object.assign({
      action,
      nonce: HNChatAdmin.nonce
    }, data || {}));
  }

  function renderList(items) {
    $list.empty();
    if (!items || !items.length) {
      $list.append('<div class="hn-empty">Chưa có cuộc hội thoại</div>');
      return;
    }
    items.forEach(c => {
      const last = c.last_message_at || c.created_at || '';
      const cls = (activeConvId === parseInt(c.id, 10)) ? 'hn-conv hn-conv--active' : 'hn-conv';
      $list.append(
        `<button class="${cls}" data-id="${c.id}" type="button">
           <div class="hn-conv__contact">${escHtml(c.contact)}</div>
           <div class="hn-conv__meta">${escHtml(c.contact_type)} • ${escHtml(last)}</div>
         </button>`
      );
    });
  }

  function loadList() {
    api('hn_chat_admin_list_conversations', { q: ($q.val() || '').trim() })
      .done(res => {
        if (!res || !res.ok) return;
        renderList(res.data.conversations || []);
      });
  }

  function renderMessages(items) {
    $msgs.empty();
    (items || []).forEach(m => {
      const role = m.sender_role === 'admin' ? 'admin' : 'guest';
      $msgs.append(
        `<div class="hn-amsg hn-amsg--${role}">
           <div class="hn-amsg__bubble">${m.message}</div>
           <div class="hn-amsg__time">${escHtml(m.created_at)}</div>
         </div>`
      );
    });
    $msgs.scrollTop($msgs[0].scrollHeight);
  }

  function loadConversation(id) {
    activeConvId = parseInt(id, 10);
    api('hn_chat_admin_get_conversation', { conversation_id: activeConvId })
      .done(res => {
        if (!res || !res.ok) return;
        const conv = res.data.conversation;
        $title.text((conv.contact || '') + ' (' + (conv.contact_type || '') + ')');
        renderMessages(res.data.messages || []);
        $input.prop('disabled', false);
        $send.prop('disabled', false);
        loadList(); // refresh active highlight
      });
  }

  function sendAdmin() {
    const text = ($input.val() || '').trim();
    if (!text || !activeConvId) return;
    $input.val('');
    api('hn_chat_admin_send_message', { conversation_id: activeConvId, message: text })
      .done(res => {
        if (!res || !res.ok) return;
        loadConversation(activeConvId);
      });
  }

  $wrap.on('click', '.hn-conv', function () {
    loadConversation($(this).data('id'));
  });

  $send.on('click', sendAdmin);
  $input.on('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      sendAdmin();
    }
  });

  let t = null;
  $q.on('input', function () {
    clearTimeout(t);
    t = setTimeout(loadList, 250);
  });

  loadList();

  // refresh list mỗi 5s
  setInterval(loadList, 5000);

})(jQuery);
