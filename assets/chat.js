(function () {
  if (!window.HNChatLive) return;
  const cfg = window.HNChatLive;

  const root = document.querySelector('[data-hncl-root]');
  if (!root) return;

  const btnOpen = root.querySelector('[data-hncl-open]');
  const btnClose = root.querySelector('[data-hncl-close]');
  const panel = root.querySelector('[data-hncl-panel]');

  const screenWelcome = root.querySelector('[data-hncl-screen="welcome"]');
  const screenChat = root.querySelector('[data-hncl-screen="chat"]');
  const btnStartChat = root.querySelector('[data-hncl-start-chat]');

  const elWelcomeTitle = root.querySelector('[data-hncl-welcome-title]');
  const elWelcomeSub = root.querySelector('[data-hncl-welcome-sub]');

  const elMessages = root.querySelector('[data-hncl-messages]');
  const elInput = root.querySelector('[data-hncl-input]');
  const elSend = root.querySelector('[data-hncl-send]');
  const elStatus = root.querySelector('[data-hncl-status]');

  const leadWrap = root.querySelector('[data-hncl-lead]');
  const leadName = root.querySelector('[data-hncl-lead-name]');
  const leadPhone = root.querySelector('[data-hncl-lead-phone]');
  const leadSubmit = root.querySelector('[data-hncl-lead-submit]');

  let isOpen = false;
  let lastId = 0;
  let timer = null;

let hasLead = !!cfg.hasLead;

const PENDING_KEY = 'hncl_pending_message';
function setPending(msg){
  pendingMessage = msg || '';
  if (pendingMessage) sessionStorage.setItem(PENDING_KEY, pendingMessage);
  else sessionStorage.removeItem(PENDING_KEY);
}
function getPending(){
  if (pendingMessage) return pendingMessage;
  return sessionStorage.getItem(PENDING_KEY) || '';
}


  function setStatus(t) {
    if (elStatus) elStatus.textContent = t;
  }

  function esc(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function formatTime(mysqlDateTime) {
    try {
      const parts = String(mysqlDateTime).split(' ');
      const t = parts[1] || '';
      const hm = t.split(':');
      return (hm[0] && hm[1]) ? `${hm[0]}:${hm[1]}` : '';
    } catch (e) {
      return '';
    }
  }

  function showPanel() {
    isOpen = true;
    panel.setAttribute('aria-hidden', 'false');
    panel.classList.add('is-open');
  }
  function hidePanel() {
    isOpen = false;
    panel.setAttribute('aria-hidden', 'true');
    panel.classList.remove('is-open');
    hideLead();
    stopPolling();
    // về lại welcome để đúng flow 1->2 lần sau
    showWelcome();
    setPending('');
  }

  function showWelcome() {
    screenWelcome.hidden = false;
    screenChat.hidden = true;
    elWelcomeTitle.textContent = cfg.welcomeTitle || 'Chào Bạn 👋';
    elWelcomeSub.textContent = cfg.welcomeSub || '';
  }

  function showChat() {
    screenWelcome.hidden = true;
    screenChat.hidden = false;
    startPolling();
    setTimeout(() => elInput && elInput.focus(), 50);
  }

  function showLead() {
    leadWrap.hidden = false;
    leadWrap.classList.add('is-open');
    setTimeout(() => leadName && leadName.focus(), 50);
  }

  function hideLead() {
    leadWrap.hidden = true;
    leadWrap.classList.remove('is-open');
  }

  async function apiGetMessages() {
    const url = `${cfg.restUrl}/messages?since_id=${encodeURIComponent(lastId)}&limit=${encodeURIComponent(cfg.maxMessages || 60)}`;
    const res = await fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } });
    return res.json();
  }

  async function apiSendMessage(message) {
    const url = `${cfg.restUrl}/messages`;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
      },
      body: JSON.stringify({ message })
    });
    return res.json();
  }

  async function apiSaveLead(name, phone) {
    const url = `${cfg.restUrl}/lead`;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
      },
      body: JSON.stringify({ name, phone })
    });
    return res.json();
  }

  function renderMessage(m) {
    const wrap = document.createElement('div');
    wrap.className = 'hncl-msg ' + (m.role === 'user' ? 'hncl-msg--me' : 'hncl-msg--other');

    wrap.innerHTML = `
      <div class="hncl-msg__meta">
        <span class="hncl-msg__name">${esc(m.user_name || 'User')}</span>
        <span class="hncl-msg__time">${esc(formatTime(m.created_at || ''))}</span>
      </div>
      <div class="hncl-msg__text">${esc(m.message || '').replaceAll('\n', '<br>')}</div>
    `;
    return wrap;
  }

  function appendMessages(list) {
    if (!Array.isArray(list) || !list.length) return;

    const nearBottom = (elMessages.scrollTop + elMessages.clientHeight) >= (elMessages.scrollHeight - 50);

    list.forEach(m => {
      const id = parseInt(m.id, 10) || 0;
      if (id > lastId) lastId = id;
      elMessages.appendChild(renderMessage(m));
    });

    // limit DOM
    const max = cfg.maxMessages || 60;
    while (elMessages.children.length > max) elMessages.removeChild(elMessages.firstElementChild);

    if (nearBottom) elMessages.scrollTop = elMessages.scrollHeight;
  }

  async function poll() {
    try {
      setStatus('Đang kết nối…');
      const data = await apiGetMessages();
      if (data && data.ok) {
        appendMessages(data.messages || []);
        setStatus('Online');
      } else {
        setStatus('Lỗi tải tin nhắn');
      }
    } catch (e) {
      setStatus('Mất kết nối (đang thử lại…)');
    }
  }

  function startPolling() {
    stopPolling();
    poll();
    timer = setInterval(poll, cfg.pollIntervalMs || 2000);
  }

  function stopPolling() {
    if (timer) clearInterval(timer);
    timer = null;
  }

  async function sendFlow() {
    const msg = (elInput.value || '').trim();
    if (!msg) return;

    // (3)->(4): nếu chưa có lead thì chặn gửi, lưu pendingMessage và bật form
    if (!hasLead) {
  setPending(msg); // lưu bền vững
  elInput.value = '';
  showLead();
  setStatus('Vui lòng nhập Tên + SĐT để gửi');
  return;
}


    // đã có lead thì gửi luôn
    elSend.disabled = true;
    try {
      const data = await apiSendMessage(msg);
      if (data && data.ok) {
        appendMessages([data.message]);
        setStatus('Đã gửi');
      } else {
        setStatus((data && data.error) ? data.error : 'Gửi thất bại');
      }
    } catch (e) {
      setStatus('Lỗi khi gửi');
    } finally {
      elSend.disabled = false;
      elInput.focus();
    }
  }

  async function submitLeadAndSendPending() {
    const name = (leadName.value || '').trim();
    const phone = (leadPhone.value || '').trim();

    if (!name) { setStatus('Bạn chưa nhập tên'); leadName.focus(); return; }
    if (!phone) { setStatus('Bạn chưa nhập số điện thoại'); leadPhone.focus(); return; }

    leadSubmit.disabled = true;

    try {
      const leadRes = await apiSaveLead(name, phone);
      if (!leadRes || !leadRes.ok) {
        setStatus((leadRes && leadRes.error) ? leadRes.error : 'Lưu thông tin thất bại');
        return;
      }

      hasLead = true;
      hideLead();
      setStatus('Đã lưu thông tin. Đang gửi…');

      // (5): gửi pending message (nếu có)
      const msg = getPending();
if (msg) {
  setPending(''); // clear trước để tránh gửi trùng nếu refresh
  const data = await apiSendMessage(msg);

  if (data && data.ok) {
    appendMessages([data.message]);
    setStatus('Đã gửi');
  } else {
    // nếu gửi fail thì restore lại pending để người dùng thử lại
    setPending(msg);
    setStatus((data && data.error) ? data.error : 'Gửi thất bại');
  }
} else {
  setStatus('Online');
}

    } catch (e) {
      setStatus('Lỗi kết nối');
    } finally {
      leadSubmit.disabled = false;
      elInput.focus();
    }
  }

  // Events
  btnOpen.addEventListener('click', function () {
    showPanel();
    showWelcome(); // (2)
  });

  btnClose.addEventListener('click', hidePanel);

  btnStartChat.addEventListener('click', function () {
    showChat(); // (3)
  });

  elSend.addEventListener('click', sendFlow);
  elInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      sendFlow();
    }
  });

  leadSubmit.addEventListener('click', submitLeadAndSendPending);
  leadPhone.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      submitLeadAndSendPending();
    }
  });

  // init
  showWelcome();
})();
