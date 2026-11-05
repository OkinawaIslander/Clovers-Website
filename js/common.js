/* ===== Clovers 全站公共脚本 ===== */
const API_BASE = "api";

const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];

function escapeHtml(str) {
  const div = document.createElement("div");
  div.textContent = String(str == null ? "" : str);
  return div.innerHTML;
}

function toast(msg, duration = 2600) {
  let t = document.getElementById("toast");
  if (!t) {
    t = document.createElement("div");
    t.id = "toast";
    t.className = "toast";
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.classList.add("show");
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove("show"), duration);
}

async function api(path, options = {}) {
  const { method = "GET", body = null } = options;
  const init = {
    method,
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  };
  if (body !== null) {
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(`${API_BASE}/${path}`, init);
  } catch (e) {
    throw new Error("网络连接失败，请检查网站是否正常");
  }

  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    const plain = text.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 160);
    throw new Error(`接口 ${path} 返回异常（HTTP ${res.status}）：${plain || "空内容"}`);
  }
  if (!data || typeof data.ok === "undefined") {
    throw new Error(`接口 ${path} 返回格式不正确，请检查 PHP`);
  }
  return data;
}

const apiGet = (path) => api(path);
const apiPost = (path, body) => api(path, { method: "POST", body });

let meCache = null;
async function getMe(force = false) {
  if (meCache && !force) return meCache;
  try {
    meCache = await apiGet("me.php");
  } catch (e) {
    meCache = { ok: false, data: { logged_in: false } };
  }
  return meCache;
}

function currentUser(me) {
  return (me && me.data && me.data.user) || null;
}

function fmt(n, digits = 2) {
  const v = Number(n || 0);
  return v.toLocaleString("zh-CN", { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function fmtInt(n) {
  return Number(n || 0).toLocaleString("zh-CN", { maximumFractionDigits: 0 });
}

function signed(n, digits = 0) {
  const v = Number(n || 0);
  return (v > 0 ? "+" : "") + v.toLocaleString("zh-CN", { maximumFractionDigits: digits });
}

function parseDate(str) {
  if (!str) return null;
  // 后端统一是 "YYYY-MM-DD HH:MM:SS"（Asia/Shanghai）
  return new Date(String(str).replace(" ", "T") + "+08:00");
}

function timeAgo(str) {
  const d = parseDate(str);
  if (!d) return "";
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return "刚刚";
  if (diff < 3600) return Math.floor(diff / 60) + " 分钟前";
  if (diff < 86400) return Math.floor(diff / 3600) + " 小时前";
  if (diff < 86400 * 30) return Math.floor(diff / 86400) + " 天前";
  return str.slice(0, 10);
}

function updateNavUser(me) {
  const btn = document.getElementById("authNavBtn");
  const user = currentUser(me);
  if (!btn) return;
  if (user) {
    btn.textContent = "我的";
    btn.setAttribute("href", "profile.html");
  } else {
    btn.textContent = "登录 / 注册";
    btn.setAttribute("href", "index.html#auth");
  }
}

async function requireLogin(redirect = "index.html#auth") {
  const me = await getMe();
  if (!me.data || !me.data.logged_in) {
    location.href = redirect;
    return null;
  }
  return me;
}

function heatEl(id) {
  return document.getElementById(id);
}

function setHeat(me) {
  const el = heatEl("heatValue");
  const user = currentUser(me);
  if (el && user) el.textContent = fmt(user.heat, 0);
}

function sparkline(values, color = "#4caf6e", width = 120, height = 36) {
  const nums = (values || []).map(Number).filter((n) => isFinite(n));
  if (nums.length < 2) return "";
  const min = Math.min(...nums);
  const max = Math.max(...nums);
  const span = max - min || 1;
  const step = width / (nums.length - 1);
  const points = nums
    .map((v, i) => `${(i * step).toFixed(1)},${(height - 2 - ((v - min) / span) * (height - 4)).toFixed(1)}`)
    .join(" ");
  return `<svg class="spark" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" aria-hidden="true">
    <polyline points="${points}" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`;
}

function avatarHtml(user, cls = "avatar-sm") {
  const name = (user && user.nickname) || "🍀";
  if (user && user.avatar_url) {
    return `<img class="${cls}" src="${escapeHtml(user.avatar_url)}" alt="" loading="lazy" referrerpolicy="no-referrer" />`;
  }
  return `<span class="${cls} avatar-fallback">${escapeHtml(name.slice(0, 1))}</span>`;
}

function feedItem(item) {
  const sign = item.amount == null ? "" : `<span class="feed-amount ${Number(item.amount) >= 0 ? "up" : "down"}">${signed(item.amount, 0)}</span>`;
  const avatar = avatarHtml({ nickname: item.nickname, avatar_url: item.avatar_url });
  const who = item.user_id
    ? `<a href="profile.html?id=${item.user_id}">${escapeHtml(item.nickname)}</a>`
    : escapeHtml(item.nickname);
  return `<li class="feed-item">
    ${avatar}
    <span class="feed-type type-${escapeHtml(item.type)}">${escapeHtml(item.type_label || "")}</span>
    <span class="feed-text">${who} ${escapeHtml(item.text)}</span>
    ${sign}
    <span class="feed-time">${escapeHtml(item.time_ago || timeAgo(item.created_at))}</span>
  </li>`;
}

function renderFeed(container, items, emptyText = "还没有动态") {
  if (!container) return;
  if (!items || !items.length) {
    container.innerHTML = `<li class="feed-empty">${escapeHtml(emptyText)}</li>`;
    return;
  }
  container.innerHTML = items.map(feedItem).join("");
}

function requireEl(id) {
  return document.getElementById(id);
}

function initNav() {
  const nav = document.getElementById("navbar");
  const links = document.getElementById("navLinks");
  const burger = document.getElementById("hamburger");
  if (burger && links) {
    burger.addEventListener("click", () => links.classList.toggle("open"));
  }
  if (links) {
    links.querySelectorAll("a").forEach((a) => {
      a.addEventListener("click", () => links.classList.remove("open"));
    });
  }
  const onScroll = () => {
    if (nav) nav.classList.toggle("scrolled", window.scrollY > 20);
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();
}
