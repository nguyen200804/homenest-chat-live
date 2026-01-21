(function () {
  const root = document.getElementById("hn-chat-root");
  if (!root || !window.HN_CHAT) return;

  const toggleBtn = root.querySelector(".hn-chat-toggle");
  const panel = root.querySelector(".hn-chat-panel");
  const closeBtn = root.querySelector(".hn-chat-close");
  const form = root.querySelector(".hn-chat-form");
  const input = root.querySelector(".hn-chat-input");
  const list = root.querySelector(".hn-chat-messages");
  const gate = root.querySelector(".hn-chat-gate");
    const gateForm = root.querySelector(".hn-chat-gate-form");
    const gateInput = root.querySelector(".hn-chat-gate-input");
    const gateError = root.querySelector(".hn-chat-gate-error");


  let isOpen = false;
  let lastId = 0;
  let pollTimer = null;

  const escapeHtml = (s) =>
    String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  function setOpen(next) {
    isOpen = next;
    toggleBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    panel.hidden = !isOpen;

    if (isOpen) {
  const hasContact = !!(HN_CHAT.contact && String(HN_CHAT.contact).trim());
  if (!hasContact) {
    gate.hidden = false;
    list.style.display = "none";
    form.style.display = "none";
    gateInput.focus();
    stopPolling();
    return;
  } else {
    gate.hidden = true;
    list.style.display = "";
    form.style.display = "";
  }

  input.focus();
  startPolling();
  fetchMessages();
}

  }

  async function setContact(contactVal) {
  gateError.hidden = true;

  const data = await post("hn_chat_set_contact", {
    chat_id: HN_CHAT.chatId,
    contact: contactVal
  });

  // lưu lại để phiên hiện tại khỏi hỏi lại
  HN_CHAT.contact = data.contact;

  // mở chat bình thường
  gate.hidden = true;
  list.style.display = "";
  form.style.display = "";
  input.focus();
  startPolling();
  fetchMessages();
}

gateForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const v = (gateInput.value || "").trim();
  if (!v) return;

  try {
    await setContact(v);
  } catch (err) {
    gateError.textContent = err.message || "Lỗi, vui lòng thử lại";
    gateError.hidden = false;
  }
});


  async function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", HN_CHAT.nonce);
    Object.keys(data).forEach((k) => fd.append(k, data[k]));

    const res = await fetch(HN_CHAT.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: fd,
    });

    const json = await res.json();
    if (!json || !json.success) {
      const msg = (json && json.data && json.data.message) ? json.data.message : "Request failed";
      throw new Error(msg);
    }
    return json.data;
  }

  function renderMessage(m) {
    const item = document.createElement("div");
    item.className = "hn-chat-msg " + (m.sender_type === "user" ? "is-me" : "is-other");

    const meta = document.createElement("div");
    meta.className = "hn-chat-meta";
    meta.innerHTML = `<span class="hn-chat-name">${escapeHtml(m.sender_name || "Guest")}</span>
                      <span class="hn-chat-time">${escapeHtml(m.created_at || "")}</span>`;

    const bubble = document.createElement("div");
    bubble.className = "hn-chat-bubble";
    bubble.innerHTML = escapeHtml(m.message || "");

    item.appendChild(meta);
    item.appendChild(bubble);
    list.appendChild(item);

    // auto scroll
    list.scrollTop = list.scrollHeight;
  }

  async function fetchMessages() {
    try {
      const data = await post("hn_chat_fetch", {
        chat_id: HN_CHAT.chatId,
        after_id: String(lastId),
      });

      (data.messages || []).forEach((m) => {
        const idNum = parseInt(m.id, 10);
        if (!isNaN(idNum)) lastId = Math.max(lastId, idNum);
        renderMessage(m);
      });
    } catch (e) {
      // im lặng để tránh spam lỗi
    }
  }

  async function sendMessage(text) {
    const msg = text.trim();
    if (!msg) return;

    // render optimistic
    renderMessage({
      id: lastId + 1,
      sender_type: "user",
      sender_name: HN_CHAT.meName || "Me",
      message: msg,
      created_at: new Date().toLocaleString(),
    });

    input.value = "";

    try {
      const data = await post("hn_chat_send", {
        chat_id: HN_CHAT.chatId,
        message: msg,
      });

      if (data && data.id) {
        lastId = Math.max(lastId, parseInt(data.id, 10) || lastId);
      }
      // fetch để sync
      fetchMessages();
    } catch (e) {
      // báo lỗi nhẹ
      const err = document.createElement("div");
      err.className = "hn-chat-error";
      err.textContent = "Gửi thất bại: " + e.message;
      list.appendChild(err);
      list.scrollTop = list.scrollHeight;
    }
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(fetchMessages, HN_CHAT.pollMs || 2000);
  }

  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  // Events
  toggleBtn.addEventListener("click", () => setOpen(!isOpen));
  closeBtn.addEventListener("click", () => setOpen(false));

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    sendMessage(input.value);
  });

  // optional: Enter to send, Shift+Enter newline (ở đây input là single-line nên Enter send luôn)
})();
