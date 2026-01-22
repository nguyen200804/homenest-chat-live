(function(){
  if (!window.HNCLAdmin) return;

  const cfg = window.HNCLAdmin;
  const $ = (s, r=document) => r.querySelector(s);

  const elList = $('[data-hncl-sessions]');
  const elSearch = $('[data-hncl-search]');
  const elMsgs = $('[data-hncl-messages]');
  const elReply = $('[data-hncl-reply]');
  const btnSend = $('[data-hncl-send]');

  const elActiveName = $('[data-hncl-active-name]');
  const elActiveMeta = $('[data-hncl-active-meta]');

  let activeSession = '';
  let sessionsCache = [];

  function esc(s){
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function formatTime(mysql){
    // YYYY-MM-DD HH:mm:ss -> HH:mm
    try {
      const t = String(mysql || '').split(' ')[1] || '';
      const [hh, mm] = t.split(':');
      return (hh && mm) ? `${hh}:${mm}` : '';
    } catch(e){ return ''; }
  }

  async function apiGet(path){
    const res = await fetch(`${cfg.restUrl}${path}`, {
      headers: { 'Accept':'application/json', 'X-WP-Nonce': cfg.nonce }
    });
    return res.json();
  }

  async function apiPost(path, body){
    const res = await fetch(`${cfg.restUrl}${path}`, {
      method:'POST',
      headers: { 'Accept':'application/json', 'Content-Type':'application/json', 'X-WP-Nonce': cfg.nonce },
      body: JSON.stringify(body)
    });
    return res.json();
  }

  function renderSessions(list){
    elList.innerHTML = '';
    list.forEach(s => {
      const div = document.createElement('div');
      div.className = 'hncl-sess' + (s.session_id === activeSession ? ' is-active' : '');
      const name = s.name || '(Chưa có tên)';
      const time = formatTime(s.last_time || s.updated_at);
      const sub = s.last_message || (s.phone ? `SĐT: ${s.phone}` : '');

      div.innerHTML = `
        <div class="hncl-sess__top">
          <div class="hncl-sess__name">${esc(name)}</div>
          <div class="hncl-sess__time">${esc(time)}</div>
        </div>
        <div class="hncl-sess__sub">${esc(sub)}</div>
      `;

      div.addEventListener('click', () => openSession(s));
      elList.appendChild(div);
    });
  }

  function renderMessages(msgs){
    elMsgs.innerHTML = '';
    msgs.forEach(m => {
      const wrap = document.createElement('div');
      wrap.className = 'hncl-msg' + (m.role === 'admin' ? ' hncl-msg--admin' : '');
      wrap.innerHTML = `
        <div class="hncl-msg__meta">
          <span>${esc(m.user_name || m.role || '')}</span>
          <span>${esc(formatTime(m.created_at))}</span>
        </div>
        <div class="hncl-msg__text">${esc(m.message || '').replaceAll('\n','<br>')}</div>
      `;
      elMsgs.appendChild(wrap);
    });
    elMsgs.scrollTop = elMsgs.scrollHeight;
  }

  async function loadSessions(q=''){
    const data = await apiGet(`/sessions${q ? `?q=${encodeURIComponent(q)}` : ''}`);
    if (!data || !data.ok) return;
    sessionsCache = data.sessions || [];
    renderSessions(sessionsCache);
  }

  async function openSession(s){
    activeSession = s.session_id;

    // header info
    elActiveName.textContent = s.name || '(Chưa có tên)';
    elActiveMeta.textContent = [
      s.phone ? `SĐT: ${s.phone}` : '',
      s.email ? `Email: ${s.email}` : '',
      `Session: ${s.session_id}`
    ].filter(Boolean).join(' • ');

    // enable composer
    elReply.disabled = false;
    btnSend.disabled = false;

    // highlight active
    renderSessions(sessionsCache);

    // load messages
    const data = await apiGet(`/messages?session_id=${encodeURIComponent(activeSession)}&limit=300`);
    if (data && data.ok) renderMessages(data.messages || []);
  }

  async function sendReply(){
    const msg = (elReply.value || '').trim();
    if (!msg || !activeSession) return;

    btnSend.disabled = true;

    const res = await apiPost('/reply', { session_id: activeSession, message: msg });

    btnSend.disabled = false;

    if (res && res.ok) {
      elReply.value = '';
      // reload messages
      const data = await apiGet(`/messages?session_id=${encodeURIComponent(activeSession)}&limit=300`);
      if (data && data.ok) renderMessages(data.messages || []);
      // refresh list to update last_message/time
      await loadSessions(elSearch.value.trim());
    } else {
      alert((res && res.error) ? res.error : 'Gửi thất bại');
    }
  }

  // events
  btnSend.addEventListener('click', sendReply);

  elReply.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      sendReply();
    }
  });

  let t = null;
  elSearch.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => loadSessions(elSearch.value.trim()), 250);
  });

  // init
  loadSessions();
})();
