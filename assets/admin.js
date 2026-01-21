(function($){
  if (!window.HN_CHAT_ADMIN) return;

  const $list = $('#hnAdminConvList');
  const $body = $('#hnAdminChatBody');
  const $title = $('#hnAdminChatTitle');
  const $sub = $('#hnAdminChatSub');
  const $form = $('#hnAdminChatForm');
  const $input = $('#hnAdminChatInput');
  const $btn = $form.find('button');
  const $search = $('#hnAdminSearch');
  const $deleteBtn = $('#hnAdminDeleteBtn');

  let convs = [];
  let activeChatId = '';
  let lastId = 0;
  let pollTimer = null;

  function post(action, data){
    return $.ajax({
      url: HN_CHAT_ADMIN.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: Object.assign({ action, nonce: HN_CHAT_ADMIN.nonce }, data || {})
    }).then(res => {
      if (!res || !res.success) throw new Error(res?.data?.message || 'Request failed');
      return res.data;
    });
  }

  function esc(s){
    return String(s||'')
      .replaceAll('&','&amp;').replaceAll('<','&lt;')
      .replaceAll('>','&gt;').replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function avatarText(contact, chatId){
    const s = (contact || chatId || 'H').toString();
    return s.slice(0,1).toUpperCase();
  }

  function renderList(filterText=''){
    const ft = (filterText||'').toLowerCase();
    $list.empty();

    convs
      .filter(c => {
        const name = (c.contact_any || c.chat_id || '').toLowerCase();
        const prev = (c.last_message || '').toLowerCase();
        return !ft || name.includes(ft) || prev.includes(ft);
      })
      .forEach(c => {
        const active = c.chat_id === activeChatId ? 'active' : '';
        const name = c.contact_any || c.chat_id;
        const time = c.last_time || '';
        const prev = c.last_message || '';

        const $item = $(`
          <div class="hn-conv-item ${active}" data-chat-id="${esc(c.chat_id)}">
            <div class="hn-conv-avatar">${esc(avatarText(c.contact_any, c.chat_id))}</div>
            <div class="hn-conv-main">
              <div class="hn-conv-top">
                <div class="hn-conv-name">${esc(name)}</div>
                <div class="hn-conv-time">${esc(time)}</div>
              </div>
              <div class="hn-conv-preview">${esc(prev)}</div>
            </div>
          </div>
        `);

        $item.on('click', () => openChat(c.chat_id, c.contact_any));
        $list.append($item);
      });
  }

  function renderMsg(m){
    const isMe = (m.sender_type === 'admin');
    const cls = isMe ? 'hn-msg me' : 'hn-msg';
    const name = m.sender_name || (isMe ? 'Admin' : 'Guest');
    const t = m.created_at || '';
    const html = `
      <div class="${cls}">
        <div class="meta">${esc(name)} — ${esc(t)}</div>
        <div class="bubble">${esc(m.message)}</div>
      </div>
    `;
    $body.append(html);
    $body.scrollTop($body[0].scrollHeight);
  }

  async function refreshList(){
    const data = await post('hn_admin_list_conversations');
    convs = data.conversations || [];
    renderList($search.val());
  }

  async function fetchMessages(){
    if (!activeChatId) return;
    const data = await post('hn_admin_fetch_messages', {
      chat_id: activeChatId,
      after_id: String(lastId)
    });

    (data.messages || []).forEach(m => {
      const id = parseInt(m.id, 10) || 0;
      if (id > lastId) lastId = id;
      renderMsg(m);
    });
  }

  function startPolling(){
    stopPolling();
    pollTimer = setInterval(() => {
      fetchMessages().catch(()=>{});
      refreshList().catch(()=>{});
    }, HN_CHAT_ADMIN.pollMs || 2000);
  }
  function stopPolling(){
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  async function openChat(chatId, contact){
    activeChatId = chatId;
    lastId = 0;
    $body.empty();

    $title.text(contact ? contact : chatId);
    $sub.text('Chat ID: ' + chatId);

    $input.prop('disabled', false);
    $btn.prop('disabled', false);
    $deleteBtn.show();

    renderList($search.val());

    await fetchMessages();
    startPolling();
  }

  $form.on('submit', async function(e){
    e.preventDefault();
    const msg = ($input.val() || '').trim();
    if (!msg || !activeChatId) return;

    $input.val('');
    try{
      const data = await post('hn_admin_send_message', { chat_id: activeChatId, message: msg });
      // sync nhanh
      lastId = Math.max(lastId, parseInt(data.id, 10) || lastId);
      await fetchMessages();
      await refreshList();
    }catch(err){
      alert(err.message || 'Send failed');
    }
  });

  $search.on('input', () => renderList($search.val()));

  // Delete chat
  window.deleteChat = async function(){
    if (!activeChatId) return;
    if (!confirm('Bạn có chắc muốn xóa cuộc chat này?')) return;

    try{
      await post('hn_admin_delete_conversation', { chat_id: activeChatId });
      activeChatId = '';
      lastId = 0;
      $body.empty();
      $input.prop('disabled', true);
      $btn.prop('disabled', true);
      $deleteBtn.hide();
      $title.text('Chọn một cuộc chat');
      $sub.text('');
      stopPolling();
      await refreshList();
    }catch(err){
      alert(err.message || 'Delete failed');
    }
  };

  // init
  refreshList().catch(()=>{});
})(jQuery);
