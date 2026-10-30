/* ===== Clovers 首页交互 ===== */
let currentMe = null;

function userOf(me) {
  return (me && me.data && me.data.user) || null;
}

function applyMe(me) {
  currentMe = me;
  const u = userOf(me);
  const panel = $("#userPanel");
  const loginForm = $("#loginForm");
  const registerForm = $("#registerForm");
  const tabs = $(".auth-tabs");

  if (!panel) return;

  if (u) {
    panel.style.display = "block";
    loginForm.classList.remove("active");
    registerForm.classList.remove("active");
    tabs.style.display = "none";
    $("#userNickname").textContent = u.nickname;
    $("#userEmail").textContent = u.email;
    const avatarBox = $("#userPanelAvatar");
    if (avatarBox) {
      avatarBox.innerHTML = u.avatar_url
        ? `<img src="${escapeHtml(u.avatar_url)}" alt="" loading="lazy" />`
        : escapeHtml((u.nickname || "🍀").slice(0, 1));
    }
    const heat = $("#userHeat");
    if (heat) heat.textContent = fmt(u.heat, 0);
    $("#msgName").value = u.nickname;
  } else {
    panel.style.display = "none";
    tabs.style.display = "flex";
    loginForm.classList.add("active");
    registerForm.classList.remove("active");
    $$(".auth-tab").forEach((t, i) => t.classList.toggle("active", i === 0));
  }

  updateNavUser(me);
}

async function refreshMe() {
  const me = await getMe(true);
  applyMe(me);
  return me;
}

/* ---------- 登录 / 注册 ---------- */
$$(".auth-tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    $$(".auth-tab").forEach((t) => t.classList.remove("active"));
    tab.classList.add("active");
    const target = tab.dataset.auth;
    $("#loginForm").classList.toggle("active", target === "login");
    $("#registerForm").classList.toggle("active", target === "register");
  });
});

let codeCountdown = 0;
const sendCodeBtn = $("#sendCodeBtn");

sendCodeBtn?.addEventListener("click", async () => {
  if (codeCountdown > 0) return;
  const email = $("#regEmail").value.trim().toLowerCase();
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    toast("请先填写正确的邮箱");
    return;
  }
  sendCodeBtn.disabled = true;
  sendCodeBtn.textContent = "发送中...";
  try {
    const data = await apiPost("send_code.php", { email, purpose: "register" });
    if (data.ok) {
      toast(data.msg || "验证码已发送");
      codeCountdown = 60;
      const timer = setInterval(() => {
        codeCountdown--;
        if (codeCountdown <= 0) {
          clearInterval(timer);
          sendCodeBtn.disabled = false;
          sendCodeBtn.textContent = "获取验证码";
        } else {
          sendCodeBtn.textContent = `${codeCountdown}s 后重发`;
        }
      }, 1000);
    } else {
      toast(data.msg || "发送失败");
      sendCodeBtn.disabled = false;
      sendCodeBtn.textContent = "获取验证码";
    }
  } catch (err) {
    toast(err.message || "网络错误", 6000);
    sendCodeBtn.disabled = false;
    sendCodeBtn.textContent = "获取验证码";
  }
});

$("#registerForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const nickname = $("#regNickname").value.trim();
  const email = $("#regEmail").value.trim().toLowerCase();
  const code = $("#regCode").value.trim();
  const pwd = $("#regPassword").value;
  const pwd2 = $("#regPassword2").value;

  if (pwd !== pwd2) return toast("两次密码不一致");
  if (pwd.length < 6) return toast("密码至少 6 位");
  if (!/^\d{6}$/.test(code)) return toast("请输入 6 位验证码");

  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.textContent = "注册中...";
  try {
    const data = await apiPost("register.php", { nickname, email, password: pwd, code });
    if (data.ok) {
      toast("注册成功，已自动登录");
      e.target.reset();
      await refreshMe();
      await loadPosts();
      await loadLeaderboard();
      await loadNotices();
      $("#auth").scrollIntoView({ behavior: "smooth" });
    } else {
      toast(data.msg || "注册失败");
    }
  } catch (err) {
    toast(err.message || "网络错误", 6000);
  } finally {
    btn.disabled = false;
    btn.textContent = "注册";
  }
});

$("#loginForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const email = $("#loginEmail").value.trim().toLowerCase();
  const pwd = $("#loginPassword").value;
  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.textContent = "登录中...";
  try {
    const data = await apiPost("login.php", { email, password: pwd });
    if (data.ok) {
      toast("欢迎回来");
      e.target.reset();
      await refreshMe();
      await loadPosts();
      await loadLeaderboard();
      await loadNotices();
    } else {
      toast(data.msg || "登录失败");
    }
  } catch (err) {
    toast(err.message || "网络错误", 6000);
  } finally {
    btn.disabled = false;
    btn.textContent = "登录";
  }
});

$("#logoutBtn")?.addEventListener("click", async () => {
  await apiPost("logout.php", {});
  toast("已退出登录");
  await refreshMe();
  await loadPosts();
  await loadNotices();
});

/* ---------- 留言板 ---------- */
function renderPosts(data) {
  const list = $("#messageList");
  if (!list) return;
  const items = (data && data.items) || [];

  if (!items.length) {
    list.innerHTML = '<p class="msg-empty">还没有留言，来做第一个吧 🍀</p>';
    return;
  }

  list.innerHTML = items
    .map(
      (m) => `<div class="message-card" data-post="${m.id}">
        <div class="msg-header">
          <span class="msg-user">${avatarHtml(m)}<span class="msg-name">${escapeHtml(m.nickname)}</span></span>
          <span class="msg-time">${escapeHtml(m.time_ago || m.created_at)}</span>
          ${m.can_delete ? `<button class="msg-del" type="button" data-del="${m.id}" title="管理员删除">删除</button>` : ""}
        </div>
        <div class="msg-content">${escapeHtml(m.content)}</div>
      </div>`
    )
    .join("");
}

async function loadPosts() {
  try {
    const res = await apiGet("posts.php");
    if (res.ok) renderPosts(res.data);
  } catch (e) {
    const list = $("#messageList");
    if (list) list.innerHTML = `<p class="msg-empty">留言加载失败：${escapeHtml(e.message)}</p>`;
  }
}

$("#messageList")?.addEventListener("click", async (e) => {
  const btn = e.target.closest("[data-del]");
  if (!btn) return;
  if (!confirm("确定删除这条留言？")) return;
  try {
    const res = await apiPost("posts.php", { action: "delete", id: btn.dataset.del });
    if (!res.ok) {
      toast(res.msg || "删除失败");
      return;
    }
    toast("已删除");
    await loadPosts();
  } catch (err) {
    toast(err.message);
  }
});

$("#messageForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  const nickname = $("#msgName").value.trim();
  const content = $("#msgContent").value.trim();
  if (!content) return;

  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  try {
    const res = await apiPost("posts.php", { nickname, content });
    if (!res.ok) {
      toast(res.msg || "发布失败");
      return;
    }
    toast("留言成功");
    $("#msgContent").value = "";
    await loadPosts();
  } catch (err) {
    toast(err.message);
  } finally {
    btn.disabled = false;
  }
});

/* ---------- 排行榜 ---------- */
async function loadLeaderboard() {
  const tbody = $("#gameRankBody");
  const empty = $("#gameRankEmpty");
  if (!tbody) return;
  try {
    const res = await apiGet("leaderboard.php");
    const list = (res.data && res.data.net_worth) || [];
    if (!list.length) {
      tbody.innerHTML = "";
      empty.style.display = "block";
      return;
    }
    empty.style.display = "none";
    tbody.innerHTML = list
      .slice(0, 10)
      .map((r, i) => {
        const cls = i === 0 ? "gold" : i === 1 ? "silver" : i === 2 ? "bronze" : "";
        return `<tr>
          <td class="rank-num ${cls}">${i + 1}</td>
          <td><span class="rank-user">${avatarHtml(r)}<a href="profile.html?id=${r.id}">${escapeHtml(r.nickname)}</a></span></td>
          <td><strong>${fmt(r.net_worth, 0)}</strong></td>
          <td class="${r.profit >= 0 ? "up" : "down"}">${signed(r.profit, 0)}</td>
        </tr>`;
      })
      .join("");
  } catch (e) {
    empty.style.display = "block";
    empty.textContent = "排行榜加载失败";
  }
}

/* ---------- 评分榜 Tabs ---------- */
$$(".rank-tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    $$(".rank-tab").forEach((t) => t.classList.remove("active"));
    tab.classList.add("active");
    const target = tab.dataset.tab;
    $("#rankGame").classList.toggle("active", target === "game");
    $("#rankRate").classList.toggle("active", target === "rate");
  });
});

/* ---------- 社团评分（本地） ---------- */
function storageGet(key, def = null) {
  try {
    const v = localStorage.getItem("clovers_" + key);
    return v ? JSON.parse(v) : def;
  } catch {
    return def;
  }
}

function storageSet(key, val) {
  localStorage.setItem("clovers_" + key, JSON.stringify(val));
}

let selectedRate = 0;
const stars = $$("#starRate span");

stars.forEach((s) => {
  s.addEventListener("mouseenter", () => {
    const v = +s.dataset.v;
    stars.forEach((x) => x.classList.toggle("active", +x.dataset.v <= v));
  });
  s.addEventListener("mouseleave", () => {
    stars.forEach((x) => x.classList.toggle("active", +x.dataset.v <= selectedRate));
  });
  s.addEventListener("click", () => {
    selectedRate = +s.dataset.v;
    stars.forEach((x) => x.classList.toggle("active", +x.dataset.v <= selectedRate));
  });
});

$("#submitRateBtn")?.addEventListener("click", () => {
  if (selectedRate < 1) return toast("请先选择星级");
  const comment = $("#rateComment").value.trim();
  const rates = storageGet("rates", []);
  const u = userOf(currentMe);
  const name = u ? u.nickname : "匿名用户";
  rates.push({ name, score: selectedRate, comment, time: new Date().toISOString() });
  storageSet("rates", rates);
  toast("感谢评分！");
  selectedRate = 0;
  stars.forEach((x) => x.classList.remove("active"));
  $("#rateComment").value = "";
  renderRates();
});

function renderRates() {
  const rates = storageGet("rates", []);
  const avgEl = $("#avgScore");
  const list = $("#rateList");
  if (!list) return;
  if (!rates.length) {
    avgEl.textContent = "—";
    list.innerHTML = '<p style="color:var(--text-muted);font-size:0.9rem;">暂无评分</p>';
    return;
  }
  const avg = (rates.reduce((s, r) => s + r.score, 0) / rates.length).toFixed(1);
  avgEl.textContent = avg;
  list.innerHTML = rates
    .slice()
    .reverse()
    .slice(0, 30)
    .map(
      (r) => `<div class="rate-item">
        <span class="stars">${"★".repeat(r.score)}${"☆".repeat(5 - r.score)}</span>
        <strong>${escapeHtml(r.name)}</strong>
        ${r.comment ? " · " + escapeHtml(r.comment) : ""}
      </div>`
    )
    .join("");
}

/* ---------- 公告 ---------- */
let noticeCanManage = false;

async function loadNotices() {
  const list = document.getElementById("noticeList");
  if (!list) return;
  try {
    const res = await apiGet("announcements.php");
    if (!res.ok) {
      list.innerHTML = `<p class="muted">${escapeHtml(res.msg || "公告加载失败")}</p>`;
      return;
    }
    noticeCanManage = !!res.data.can_manage;
    const toggle = document.getElementById("noticeToggle");
    if (toggle) toggle.hidden = !noticeCanManage;

    if (!res.data.items.length) {
      list.innerHTML = '<p class="muted">暂无公告。</p>';
      return;
    }
    list.innerHTML = res.data.items
      .map(
        (n) => `<article class="notice-item">
          <div class="notice-item-head">
            <strong>${escapeHtml(n.title)}</strong>
            <span class="muted">${escapeHtml(n.time_ago || n.created_at)} · ${escapeHtml(n.nickname)}</span>
            ${noticeCanManage ? `<button class="msg-del" data-notice-del="${n.id}" type="button">删除</button>` : ""}
          </div>
          <p>${escapeHtml(n.content)}</p>
        </article>`
      )
      .join("");
  } catch (e) {
    list.innerHTML = `<p class="muted">${escapeHtml(e.message)}</p>`;
  }
}

document.getElementById("noticeToggle")?.addEventListener("click", () => {
  const form = document.getElementById("noticeForm");
  form.hidden = !form.hidden;
  if (!form.hidden) document.getElementById("noticeTitle").focus();
});

document.getElementById("noticeForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  try {
    const res = await apiPost("announcements.php", {
      action: "create",
      title: document.getElementById("noticeTitle").value.trim(),
      content: document.getElementById("noticeContent").value.trim(),
    });
    if (!res.ok) return toast(res.msg || "发布失败");
    toast("公告已发布");
    e.target.reset();
    e.target.hidden = true;
    await loadNotices();
  } catch (err) {
    toast(err.message);
  }
});

document.getElementById("noticeList")?.addEventListener("click", async (e) => {
  const btn = e.target.closest("[data-notice-del]");
  if (!btn) return;
  if (!confirm("确定删除这条公告？")) return;
  try {
    const res = await apiPost("announcements.php", { action: "delete", id: btn.dataset.noticeDel });
    if (!res.ok) return toast(res.msg || "删除失败");
    toast("已删除");
    await loadNotices();
  } catch (err) {
    toast(err.message);
  }
});

/* ---------- 全站动态 ---------- */
async function loadHomeFeed() {
  const list = $("#feedList");
  if (!list) return;
  try {
    const res = await apiGet("feed.php?limit=40");
    if (!res.ok) {
      list.innerHTML = '<li class="feed-empty">动态加载失败</li>';
      return;
    }
    renderFeed(list, res.data.items, "还没有动态");
  } catch (e) {
    list.innerHTML = '<li class="feed-empty">动态加载失败</li>';
  }
}

/* ---------- 初始化 ---------- */
document.addEventListener("DOMContentLoaded", async () => {
  initNav();
  initScrollSpy();
  renderRates();
  await refreshMe();
  await Promise.all([loadPosts(), loadLeaderboard(), loadNotices(), loadHomeFeed()]);
});

function initScrollSpy() {
  window.addEventListener("scroll", () => {
    const sections = $$("section[id]");
    let current = "";
    sections.forEach((s) => {
      if (window.scrollY >= s.offsetTop - 100) current = s.id;
    });
    $$(".nav-link").forEach((a) => {
      const href = a.getAttribute("href") || "";
      if (href.startsWith("#")) a.classList.toggle("active", href === "#" + current);
    });
  });
}

console.log("%c🍀 Clovers' 动漫协会官网已加载", "color:#2d8f4e;font-size:14px;font-weight:bold");
