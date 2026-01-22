(function () {
  // chạy sau khi DOM sẵn sàng
  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  ready(function () {
    if (!window.HNChatLive) return;
    const cfg = window.HNChatLive;

    // helper
    const esc = (s) =>
      String(s ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

    const formatTime = (mysqlDateTime) => {
      try {
        const t = String(mysqlDateTime || "").split(" ")[1] || "";
        const [hh, mm] = t.split(":");
        return hh && mm ? `${hh}:${mm}` : "";
      } catch (e) {
        return "";
      }
    };

    // ====== STORAGE pending message (để không mất khi mở lead) ======
    let hasLead = !!cfg.hasLead;
    const PENDING_KEY = "hncl_pending_message";
    const setPending = (msg) => {
      msg = (msg || "").trim();
      if (msg) sessionStorage.setItem(PENDING_KEY, msg);
      else sessionStorage.removeItem(PENDING_KEY);
    };
    const getPending = () => sessionStorage.getItem(PENDING_KEY) || "";

    // ====== API ======
    async function apiGetMessages(lastId) {
      const url = `${cfg.restUrl}/messages?since_id=${encodeURIComponent(
        lastId || 0
      )}&limit=${encodeURIComponent(cfg.maxMessages || 60)}`;
      const res = await fetch(url, { headers: { Accept: "application/json" } });
      return res.json();
    }

    async function apiSendMessage(message) {
      const url = `${cfg.restUrl}/messages`;
      const res = await fetch(url, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce || "",
        },
        body: JSON.stringify({ message }),
      });
      return res.json();
    }

    async function apiSaveLead(name, phone) {
      const url = `${cfg.restUrl}/lead`;
      const res = await fetch(url, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-WP-Nonce": cfg.nonce || "",
        },
        body: JSON.stringify({ name, phone }),
      });
      return res.json();
    }

    // ====== RENDER ======
    function renderMessage(m) {
      const wrap = document.createElement("div");
      wrap.className =
        "hncl-msg " + (m.role === "user" ? "hncl-msg--me" : "hncl-msg--other");
      wrap.innerHTML = `
        <div class="hncl-msg__meta">
          <span class="hncl-msg__name">${esc(m.user_name || "User")}</span>
          <span class="hncl-msg__time">${esc(formatTime(m.created_at || ""))}</span>
        </div>
        <div class="hncl-msg__text">${esc(m.message || "").replaceAll("\n", "<br>")}</div>
      `;
      return wrap;
    }

    // ====== STATE per widget ======
    // Vì bạn có thể đặt shortcode nhiều nơi, ta xử lý theo từng root.
    const stateMap = new WeakMap();

    function getState(root) {
      if (stateMap.has(root)) return stateMap.get(root);

      const st = {
        root,
        panel: root.querySelector("[data-hncl-panel]"),
        screenWelcome: root.querySelector('[data-hncl-screen="welcome"]'),
        screenChat: root.querySelector('[data-hncl-screen="chat"]'),
        elMessages: root.querySelector("[data-hncl-messages]"),
        elInput: root.querySelector("[data-hncl-input]"),
        elSend: root.querySelector("[data-hncl-send]"),
        elStatus: root.querySelector("[data-hncl-status]"),
        leadWrap: root.querySelector("[data-hncl-lead]"),
        leadName: root.querySelector("[data-hncl-lead-name]"),
        leadPhone: root.querySelector("[data-hncl-lead-phone]"),
        leadSubmit: root.querySelector("[data-hncl-lead-submit]"),
        lastId: 0,
        timer: null,
      };

      stateMap.set(root, st);
      return st;
    }

    function showPanel(st) {
      if (!st.panel) return;
      st.panel.setAttribute("aria-hidden", "false");
      st.panel.classList.add("is-open");
      showWelcome(st);
    }

    function hidePanel(st) {
      if (!st.panel) return;
      st.panel.setAttribute("aria-hidden", "true");
      st.panel.classList.remove("is-open");
      hideLead(st);
      stopPolling(st);
      setPending(""); // tránh kẹt pending nếu user đóng popup
      showWelcome(st);
    }

    function showWelcome(st) {
      if (st.screenWelcome) st.screenWelcome.hidden = false;
      if (st.screenChat) st.screenChat.hidden = true;
    }

    function showChat(st) {
      if (st.screenWelcome) st.screenWelcome.hidden = true;
      if (st.screenChat) st.screenChat.hidden = false;
      startPolling(st);
      setTimeout(() => st.elInput && st.elInput.focus(), 50);
    }

    function showLead(st) {
      if (!st.leadWrap) return;
      st.leadWrap.hidden = false;
      st.leadWrap.classList.add("is-open");
      setTimeout(() => st.leadName && st.leadName.focus(), 50);
    }

    function hideLead(st) {
      if (!st.leadWrap) return;
      st.leadWrap.hidden = true;
      st.leadWrap.classList.remove("is-open");
    }

    function setStatus(st, t) {
      if (st.elStatus) st.elStatus.textContent = t;
    }

    function appendMessages(st, list) {
      if (!st.elMessages || !Array.isArray(list) || !list.length) return;

      const nearBottom =
        st.elMessages.scrollTop + st.elMessages.clientHeight >=
        st.elMessages.scrollHeight - 50;

      list.forEach((m) => {
        const id = parseInt(m.id, 10) || 0;
        if (id > st.lastId) st.lastId = id;
        st.elMessages.appendChild(renderMessage(m));
      });

      const max = cfg.maxMessages || 60;
      while (st.elMessages.children.length > max) {
        st.elMessages.removeChild(st.elMessages.firstElementChild);
      }

      if (nearBottom) st.elMessages.scrollTop = st.elMessages.scrollHeight;
    }

    async function poll(st) {
      try {
        setStatus(st, "Đang kết nối…");
        const data = await apiGetMessages(st.lastId);
        if (data && data.ok) {
          appendMessages(st, data.messages || []);
          setStatus(st, "Online");
        } else {
          setStatus(st, "Lỗi tải tin nhắn");
        }
      } catch (e) {
        setStatus(st, "Mất kết nối (đang thử lại…)");
      }
    }

    function startPolling(st) {
      stopPolling(st);
      poll(st);
      st.timer = setInterval(() => poll(st), cfg.pollIntervalMs || 2000);
    }

    function stopPolling(st) {
      if (st.timer) clearInterval(st.timer);
      st.timer = null;
    }

    // ====== FLOW: gửi -> nếu chưa lead thì hiện form, chưa gửi ======
    async function sendFlow(st) {
      const msg = (st.elInput?.value || "").trim();
      if (!msg) return; // chưa nhập tin nhắn -> không hiện form

      if (!hasLead) {
        setPending(msg);
        st.elInput.value = "";
        showLead(st);
        setStatus(st, "Vui lòng nhập Tên + SĐT để gửi");
        return;
      }

      st.elSend.disabled = true;
      try {
        const data = await apiSendMessage(msg);
        if (data && data.ok) {
          st.elInput.value = "";
          appendMessages(st, [data.message]);
          setStatus(st, "Đã gửi");
        } else {
          setStatus(st, (data && data.error) ? data.error : "Gửi thất bại");
        }
      } catch (e) {
        setStatus(st, "Lỗi khi gửi");
      } finally {
        st.elSend.disabled = false;
        st.elInput.focus();
      }
    }

    async function submitLeadAndSendPending(st) {
      const name = (st.leadName?.value || "").trim();
      const phone = (st.leadPhone?.value || "").trim();

      if (!name) return setStatus(st, "Bạn chưa nhập tên"), st.leadName?.focus();
      if (!phone) return setStatus(st, "Bạn chưa nhập số điện thoại"), st.leadPhone?.focus();

      st.leadSubmit.disabled = true;

      try {
        const leadRes = await apiSaveLead(name, phone);
        if (!leadRes || !leadRes.ok) {
          setStatus(st, (leadRes && leadRes.error) ? leadRes.error : "Lưu thông tin thất bại");
          return;
        }

        hasLead = true;
        hideLead(st);

        const pending = getPending();
        if (pending) {
          setPending(""); // clear trước
          setStatus(st, "Đang gửi…");
          const data = await apiSendMessage(pending);
          if (data && data.ok) {
            appendMessages(st, [data.message]);
            setStatus(st, "Đã gửi");
          } else {
            // nếu fail thì restore lại pending
            setPending(pending);
            setStatus(st, (data && data.error) ? data.error : "Gửi thất bại");
          }
        } else {
          setStatus(st, "Online");
        }
      } catch (e) {
        setStatus(st, "Lỗi kết nối");
      } finally {
        st.leadSubmit.disabled = false;
        st.elInput?.focus();
      }
    }

    // ====== EVENT DELEGATION (FIX CHÍNH CHO VẤN ĐỀ POPUP KHÔNG MỞ) ======
    document.addEventListener("click", function (e) {
      const openBtn = e.target.closest("[data-hncl-open]");
      if (openBtn) {
        const root = openBtn.closest("[data-hncl-root]");
        if (!root) return;
        showPanel(getState(root));
        return;
      }

      const closeBtn = e.target.closest("[data-hncl-close]");
      if (closeBtn) {
        const root = closeBtn.closest("[data-hncl-root]");
        if (!root) return;
        hidePanel(getState(root));
        return;
      }

      const startBtn = e.target.closest("[data-hncl-start-chat]");
      if (startBtn) {
        const root = startBtn.closest("[data-hncl-root]");
        if (!root) return;
        showChat(getState(root));
        return;
      }

      const sendBtn = e.target.closest("[data-hncl-send]");
      if (sendBtn) {
        const root = sendBtn.closest("[data-hncl-root]");
        if (!root) return;
        sendFlow(getState(root));
        return;
      }

      const leadSubmitBtn = e.target.closest("[data-hncl-lead-submit]");
      if (leadSubmitBtn) {
        const root = leadSubmitBtn.closest("[data-hncl-root]");
        if (!root) return;
        submitLeadAndSendPending(getState(root));
        return;
      }
    });

    // Enter trong input gửi
    document.addEventListener("keydown", function (e) {
      if (e.key !== "Enter") return;

      const input = e.target.closest("[data-hncl-input]");
      if (input) {
        e.preventDefault();
        const root = input.closest("[data-hncl-root]");
        if (!root) return;
        sendFlow(getState(root));
      }
    });

    // Init: đảm bảo welcome sẵn
    document.querySelectorAll("[data-hncl-root]").forEach((root) => {
      const st = getState(root);
      showWelcome(st);
    });
  });
})();
