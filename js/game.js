/* ===== Clovers 热力交易所 ===== */
const state = {
  me: null,
  market: null,
  leaders: null,
  rankTab: "net",
  trade: null,
  chart: { symbol: null, period: "5m", data: null, geom: null },
};

const LEVERAGES = [1, 2, 4, 5, 10];
const COOLDOWN = { 1: 24, 2: 36, 4: 48, 5: 60, 10: 72 };

/* ---------- 顶部数据 ---------- */
function renderMe() {
  const d = (state.me && state.me.data) || {};
  const user = d.user || null;
  const heat = user ? Number(user.heat) : 0;

  const heatEl = document.getElementById("heatValue");
  const netEl = document.getElementById("netWorth");
  const winEl = document.getElementById("windowState");
  const tipEl = document.getElementById("loginTip");
  const checkBtn = document.getElementById("checkinBtn");
  const bankrupt = document.getElementById("bankruptBanner");

  heatEl.textContent = user ? fmt(heat, 0) : "--";
  netEl.textContent = user ? fmt(d.net_worth, 0) : "--";
  winEl.textContent = d.window && d.window.open ? "开放中 12-18" : "已关闭 · 12:00 开";

  if (user) {
    tipEl.innerHTML = `已登录：${avatarHtml(user)} <a href="profile.html">${escapeHtml(user.nickname)}</a> · <a href="profile.html">个人主页</a>`;
  } else {
    tipEl.innerHTML = '登录后可交易 · <a href="index.html#auth">去登录 / 注册</a>';
  }

  if (checkBtn) {
    const c = d.checkin;
    if (!user) {
      checkBtn.textContent = "登录后签到";
      checkBtn.disabled = false;
      checkBtn.dataset.needLogin = "1";
    } else if (c && c.available) {
      checkBtn.textContent = `签到 +${c.next_reward}`;
      checkBtn.disabled = false;
      checkBtn.dataset.needLogin = "";
    } else {
      checkBtn.textContent = `已签到 · 连续 ${c ? c.streak : 0} 天`;
      checkBtn.disabled = true;
      checkBtn.dataset.needLogin = "";
    }
  }

  if (bankrupt) {
    bankrupt.hidden = !(user && heat < 1 && (!d.positions || d.positions.length === 0));
  }

  updateNavUser(state.me);
  renderPositions();
}

async function loadMe(force = true) {
  state.me = await getMe(force);
  renderMe();
  return state.me;
}

/* ---------- 行情 ---------- */
async function loadMarket() {
  const res = await apiGet("market.php");
  if (!res.ok) {
    toast(res.msg || "行情加载失败");
    return;
  }
  state.market = res.data;
  renderMarket();
}

function renderMarket() {
  const box = document.getElementById("marketList");
  const d = state.market;
  if (!box || !d) return;

  if (!d.symbols || !d.symbols.length) {
    box.innerHTML = '<p class="fx-empty">暂时没有行情</p>';
    return;
  }

  const logged = !!(state.me && state.me.data && state.me.data.logged_in);
  const open = !!(d.window && d.window.open);

  box.innerHTML = d.symbols
    .map((s) => {
      const up = Number(s.change) >= 0;
      const pos = s.position;
      const posText = pos
        ? `<span class="fx-pos-badge">${pos.direction === "long" ? "做多" : "做空"} ${pos.leverage}x<br><b class="${pos.pnl >= 0 ? "up" : "down"}">${signed(pos.pnl, 0)}</b></span>`
        : '<span class="fx-pos-badge">—</span>';
      return `<div class="fx-market-row">
        <div class="fx-symbol">
          <span class="ico">${s.icon}</span>
          <div><strong>${escapeHtml(s.name)}</strong><span>${escapeHtml(s.symbol)}</span></div>
        </div>
        <div class="fx-price">${fmt(s.price)}</div>
        <div class="fx-change ${up ? "up" : "down"}">${up ? "+" : ""}${fmt(s.change)}%</div>
        <div class="fx-spark" data-chart="${s.symbol}" title="点击查看 5 分钟 / 小时 / 天走势">${sparkline(s.history, up ? "#4ade80" : "#f87171")}<em>走势</em></div>
        ${posText}
        <div class="fx-actions">
          <button class="fx-btn-buy" data-act="long" data-symbol="${s.symbol}" ${logged && open ? "" : "disabled"} title="${!logged ? "请先登录" : !open ? "12:00-18:00 才能开仓" : ""}">买入</button>
          <button class="fx-btn-short" data-act="short" data-symbol="${s.symbol}" ${logged && open ? "" : "disabled"} title="${!logged ? "请先登录" : !open ? "12:00-18:00 才能开仓" : ""}">做空</button>
        </div>
      </div>`;
    })
    .join("");
}

/* ---------- 持仓 ---------- */
function renderPositions() {
  const box = document.getElementById("positionsList");
  const count = document.getElementById("posCount");
  const d = (state.me && state.me.data) || {};
  const list = d.positions || [];

  if (count) count.textContent = list.length;
  if (!box) return;

  if (!d.logged_in) {
    box.innerHTML = '<p class="fx-empty">登录后查看持仓 · <a href="index.html#auth">去登录</a></p>';
    return;
  }
  if (!list.length) {
    box.innerHTML = '<p class="fx-empty">还没有持仓，去行情里开一单吧</p>';
    return;
  }

  box.innerHTML = list
    .map((p) => {
      const up = Number(p.pnl) >= 0;
      const ready = Number(p.close_in) <= 0;
      return `<div class="fx-position" data-pos="${p.id}">
        <div>
          <span class="fx-direction ${p.direction}">${p.direction === "long" ? "做多" : "做空"}</span>
          <b>${escapeHtml(p.name)}</b>
          <span class="lbl">${p.leverage}x · 保证金 ${fmt(p.margin, 0)}</span>
        </div>
        <div><span class="lbl">入场价</span><span class="val">${fmt(p.entry_price)}</span></div>
        <div><span class="lbl">现价</span><span class="val" data-price>${fmt(p.price)}</span></div>
        <div><span class="lbl">浮动盈亏</span><span class="val pnl ${up ? "up" : "down"}" data-pnl>${signed(p.pnl, 0)}</span></div>
        <div><span class="lbl">强平价</span><span class="val">${fmt(p.liq_price)}</span></div>
        <div><span class="lbl">可平仓</span><span class="val" data-countdown data-close-at="${Math.floor(parseDate(p.close_after).getTime() / 1000)}">${duration(Number(p.close_in))}</span></div>
        <div class="fx-close-wrap"><button class="fx-close-btn" data-close="${p.id}" ${ready ? "" : "disabled"}>${ready ? "平仓" : "冷却中"}</button></div>
      </div>`;
    })
    .join("");
}

function duration(seconds) {
  seconds = Math.max(0, Math.floor(Number(seconds) || 0));
  if (seconds <= 0) return "可平仓";
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0) return `${h}h ${m}m`;
  const s = seconds % 60;
  return `${m}m ${s}s`;
}

function tickCountdowns() {
  const now = Math.floor(Date.now() / 1000);
  document.querySelectorAll("[data-close-at]").forEach((el) => {
    const at = Number(el.dataset.closeAt || 0);
    const left = at - now;
    el.textContent = duration(left);
    const row = el.closest(".fx-position");
    const btn = row && row.querySelector("[data-close]");
    if (btn) {
      if (left <= 0) {
        btn.disabled = false;
        btn.textContent = "平仓";
      } else {
        btn.disabled = true;
        btn.textContent = "冷却中";
      }
    }
  });
}

/* ---------- 排行榜 ---------- */
async function loadLeaderboard() {
  const res = await apiGet("leaderboard.php");
  if (!res.ok) {
    toast(res.msg || "排行榜加载失败");
    return;
  }
  state.leaders = res.data;
  renderLeaderboard();
}

function renderLeaderboard() {
  const box = document.getElementById("leaderList");
  if (!box || !state.leaders) return;
  const list = state.rankTab === "profit" ? state.leaders.profit : state.leaders.net_worth;

  if (!list || !list.length) {
    box.innerHTML = '<p class="fx-empty">还没有人上榜，先来交易一笔</p>';
    return;
  }

  box.innerHTML = `<table class="fx-rank-table">
    <thead><tr><th>#</th><th>成员</th><th>净资产</th><th>${state.rankTab === "profit" ? "收益率" : "累计盈亏"}</th></tr></thead>
    <tbody>
      ${list
        .map(
          (r, i) => `<tr>
            <td class="rank-no">${i + 1}</td>
            <td><span class="rank-user">${avatarHtml(r)}<a href="profile.html?id=${r.id}">${escapeHtml(r.nickname)}</a></span></td>
            <td>${fmt(r.net_worth, 0)}</td>
            <td class="${state.rankTab === "profit" ? (r.profit_pct >= 0 ? "up" : "down") : r.profit >= 0 ? "up" : "down"}">
              ${state.rankTab === "profit" ? `${r.profit_pct >= 0 ? "+" : ""}${fmt(r.profit_pct, 1)}%` : signed(r.profit, 0)}
            </td>
          </tr>`
        )
        .join("")}
    </tbody>
  </table>`;
}

/* ---------- 动态 ---------- */
async function loadFeed() {
  const list = document.getElementById("feedList");
  if (!list) return;
  const res = await apiGet("feed.php?limit=40");
  if (!res.ok) {
    list.innerHTML = '<li class="feed-empty">动态加载失败</li>';
    return;
  }
  renderFeed(list, res.data.items, "还没有动态");
}

/* ---------- 走势图 ---------- */
const PERIOD_LABEL = { "5m": "5 分钟", "1h": "每小时", "1d": "每天" };

async function openChart(symbol) {
  const s = state.market && state.market.symbols.find((x) => x.symbol === symbol);
  state.chart.symbol = symbol;
  state.chart.period = "5m";
  state.chart.data = null;
  state.chart.geom = null;

  document.querySelectorAll("#chartPeriods button").forEach((b) => {
    b.classList.toggle("active", b.dataset.period === "5m");
  });
  document.getElementById("chartTitle").textContent = (s ? s.name : "部门") + " · 热力走势";
  document.getElementById("chartPrice").textContent = s ? fmt(s.price) : "--";
  document.getElementById("chartSub").textContent = "";
  document.getElementById("chartMeta").textContent = "点击「5 分钟 / 每小时 / 每天」切换周期，鼠标移到图上查看时间和热力值";
  document.getElementById("chartModal").classList.add("open");

  await loadChart();
}

async function loadChart() {
  const { symbol, period } = state.chart;
  if (!symbol) return;
  try {
    const res = await apiGet(`chart.php?symbol=${encodeURIComponent(symbol)}&period=${period}`);
    if (!res.ok) {
      toast(res.msg || "走势加载失败");
      return;
    }
    state.chart.data = res.data;

    const priceEl = document.getElementById("chartPrice");
    priceEl.textContent = fmt(res.data.price);
    priceEl.className = "fx-chart-price " + (res.data.change >= 0 ? "up" : "down");

    document.getElementById("chartSub").textContent =
      `${escapeHtml(res.data.name)} · 区间 ${fmt(res.data.min)} ~ ${fmt(res.data.max)} · ${res.data.change >= 0 ? "+" : ""}${fmt(res.data.change)}% · 点差 ${fmt(res.data.spread_bps, 1)}bp`;
    document.getElementById("chartMeta").textContent =
      `${res.data.points.length} 个采样点 · ${PERIOD_LABEL[period]}聚合 · 指针放在折线上可以看到具体时间和当时的热力值`;

    renderChart();
  } catch (e) {
    toast(e.message);
  }
}

function renderChart() {
  const svg = document.getElementById("chartSvg");
  const d = state.chart.data;
  if (!svg || !d) return;

  const W = 760;
  const H = 300;
  const pad = { l: 58, r: 16, t: 18, b: 38 };
  const innerW = W - pad.l - pad.r;
  const innerH = H - pad.t - pad.b;

  const pts = (d.points || []).map((p) => ({ ...p, t: Number(p.ts) * 1000 }));
  if (!pts.length) return;

  let t0 = pts[0].t;
  let t1 = pts[pts.length - 1].t;
  if (t1 <= t0) t1 = t0 + 60000;

  let lo = Math.min(...pts.map((p) => p.price));
  let hi = Math.max(...pts.map((p) => p.price));
  if (hi - lo < 0.01) {
    hi += 1;
    lo -= 1;
  }
  const padY = (hi - lo) * 0.12;
  lo -= padY;
  hi += padY;

  const x = (t) => pad.l + ((t - t0) / (t1 - t0)) * innerW;
  const y = (v) => pad.t + (1 - (v - lo) / (hi - lo)) * innerH;
  const color = d.change >= 0 ? "#4ade80" : "#f87171";

  let grid = "";
  for (let i = 0; i < 4; i++) {
    const v = lo + ((hi - lo) * i) / 3;
    const yy = y(v);
    grid += `<line x1="${pad.l}" y1="${yy.toFixed(1)}" x2="${W - pad.r}" y2="${yy.toFixed(1)}" stroke="rgba(255,255,255,.07)" stroke-width="1"/>`;
    grid += `<text x="${pad.l - 8}" y="${(yy + 4).toFixed(1)}" text-anchor="end" fill="#6f8a97" font-size="11">${fmt(v, 0)}</text>`;
  }

  const linePath = pts
    .map((p, i) => `${i ? "L" : "M"}${x(p.t).toFixed(1)},${y(p.price).toFixed(1)}`)
    .join(" ");
  const areaPath = `${linePath} L${x(pts[pts.length - 1].t).toFixed(1)},${pad.t + innerH} L${x(pts[0].t).toFixed(1)},${pad.t + innerH} Z`;

  const labelIdx = [...new Set([0, Math.floor((pts.length - 1) / 2), pts.length - 1])];
  const xLabels = labelIdx
    .map((i) => {
      const anchor = i === 0 ? "start" : i === pts.length - 1 ? "end" : "middle";
      return `<text x="${x(pts[i].t).toFixed(1)}" y="${H - 12}" text-anchor="${anchor}" fill="#6f8a97" font-size="11">${escapeHtml(pts[i].label)}</text>`;
    })
    .join("");

  svg.innerHTML = `
    <defs>
      <linearGradient id="chartArea" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${color}" stop-opacity="0.3"/>
        <stop offset="100%" stop-color="${color}" stop-opacity="0"/>
      </linearGradient>
    </defs>
    ${grid}
    <path d="${areaPath}" fill="url(#chartArea)"></path>
    <path d="${linePath}" fill="none" stroke="${color}" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"></path>
    ${pts.length === 1 ? `<circle cx="${x(pts[0].t)}" cy="${y(pts[0].price)}" r="3.5" fill="${color}"></circle>` : ""}
    ${xLabels}
    <g id="chartHover" style="display:none">
      <line id="chartHoverLine" y1="${pad.t}" y2="${pad.t + innerH}" stroke="${color}" stroke-dasharray="4 4" stroke-opacity=".6"></line>
      <circle id="chartHoverDot" r="4.5" fill="${color}" stroke="#0b1319" stroke-width="2"></circle>
    </g>
    <rect id="chartHit" x="${pad.l}" y="${pad.t}" width="${innerW}" height="${innerH}" fill="transparent" style="cursor:crosshair"></rect>
  `;

  state.chart.geom = { pts, x, y, pad, W, H, innerW, innerH, color };
  hideChartTip();
}

function onChartMove(e) {
  const g = state.chart.geom;
  const svg = document.getElementById("chartSvg");
  const tip = document.getElementById("chartTip");
  if (!g || !svg) return;

  const rect = svg.getBoundingClientRect();
  const px = ((e.clientX - rect.left) / rect.width) * g.W;
  if (px < g.pad.l - 6 || px > g.W - g.pad.r + 6) {
    hideChartTip();
    return;
  }

  let best = 0;
  let bestDist = Infinity;
  for (let i = 0; i < g.pts.length; i++) {
    const dd = Math.abs(g.x(g.pts[i].t) - px);
    if (dd < bestDist) {
      bestDist = dd;
      best = i;
    }
  }

  const p = g.pts[best];
  const cx = g.x(p.t);
  const cy = g.y(p.price);

  const group = svg.querySelector("#chartHover");
  const line = svg.querySelector("#chartHoverLine");
  const dot = svg.querySelector("#chartHoverDot");
  if (group && line && dot) {
    group.style.display = "";
    line.setAttribute("x1", cx);
    line.setAttribute("x2", cx);
    dot.setAttribute("cx", cx);
    dot.setAttribute("cy", cy);
  }

  tip.hidden = false;
  tip.innerHTML = `<b>${escapeHtml(p.label)}</b><br>${fmt(p.price)} 热力值`;
  const leftPx = Math.min(Math.max((cx / g.W) * rect.width, 70), rect.width - 70);
  const topPx = (cy / g.H) * rect.height;
  tip.style.left = leftPx + "px";
  tip.style.top = topPx + "px";
  tip.style.transform = topPx < 80 ? "translate(-50%, 14%)" : "translate(-50%, -115%)";
}

function hideChartTip() {
  const svg = document.getElementById("chartSvg");
  const tip = document.getElementById("chartTip");
  if (tip) tip.hidden = true;
  if (svg) {
    const group = svg.querySelector("#chartHover");
    if (group) group.style.display = "none";
  }
}

/* ---------- 交易弹窗 ---------- */
function openTrade(symbol, direction) {
  const d = state.market;
  if (!d) return;
  const s = d.symbols.find((x) => x.symbol === symbol);
  if (!s) return;

  if (!state.me || !state.me.data.logged_in) {
    toast("请先登录");
    return;
  }
  if (!d.window.open) {
    toast("现在不是开仓时间，每天 12:00 - 18:00 开放");
    return;
  }

  const user = state.me.data.user;
  const heat = Number(user.heat);

  // 真实市场是买价/卖价两条线：做多按 ask 成交，做空按 bid 成交
  const spread = Number(s.spread_bps || 0);
  const exec = direction === "long" ? Number(s.ask || s.price) : Number(s.bid || s.price);

  state.trade = {
    symbol,
    direction,
    price: exec,
    mid: Number(s.price),
    spread,
    name: s.name,
    leverage: 1,
  };

  document.getElementById("tradeTitle").textContent = (direction === "long" ? "买入 " : "做空 ") + s.name;
  document.getElementById("tradePrice").textContent =
    `现价 ${fmt(s.price)} · ${direction === "long" ? "买入价" : "卖出价"} ${fmt(exec)} · 点差 ${fmt(spread, 1)}bp`;
  const marginInput = document.getElementById("tradeMargin");
  marginInput.value = Math.max(10, Math.min(100, Math.floor(heat)));
  marginInput.max = Math.max(10, Math.floor(heat));

  renderLeverage();
  updateSummary();
  document.getElementById("tradeModal").classList.add("open");
}

function renderLeverage() {
  const box = document.getElementById("levRow");
  if (!box || !state.trade) return;
  box.innerHTML = LEVERAGES.map(
    (l) => `<button type="button" data-lev="${l}" class="${state.trade.leverage === l ? "active" : ""}">${l}x</button>`
  ).join("");
}

function closeTrade() {
  document.getElementById("tradeModal").classList.remove("open");
}

function updateSummary() {
  const t = state.trade;
  if (!t) return;
  const margin = Math.max(0, Number(document.getElementById("tradeMargin").value) || 0);
  const lev = t.leverage;
  const notional = margin * lev;
  const liq = t.direction === "long" ? t.price * (1 - 0.5 / lev) : t.price * (1 + 0.5 / lev);
  const hours = COOLDOWN[lev] || 24;
  const spreadCost = (notional * (t.spread || 0)) / 10000;

  document.getElementById("tradeSummary").innerHTML = `
    方向：<b>${t.direction === "long" ? "买入 / 做多" : "卖出 / 做空"}</b><br>
    仓位价值：<b>${fmt(notional, 0)}</b> 热力值（${fmt(margin, 0)} × ${lev}x）<br>
    强平价：<b>${fmt(liq)}</b>（约 ${t.direction === "long" ? "跌" : "涨"} ${(50 / lev).toFixed(1)}%）<br>
    点差成本：<b>${fmt(spreadCost, 2)}</b> 热力值（开平各一半）<br>
    平仓冷却：<b>${hours} 小时</b><br>
    最大损失：<b>${fmt(margin * 0.5, 0)}</b> 热力值（触发强平）
  `;
}

async function submitTrade() {
  const t = state.trade;
  if (!t) return;
  const btn = document.getElementById("tradeSubmit");
  const margin = Number(document.getElementById("tradeMargin").value) || 0;

  if (margin < 10) {
    toast("保证金至少 10 热力值");
    return;
  }

  btn.disabled = true;
  btn.textContent = "提交中...";
  try {
    const res = await apiPost("trade.php", {
      action: "open",
      symbol: t.symbol,
      direction: t.direction,
      margin,
      leverage: t.leverage,
    });
    if (!res.ok) {
      toast(res.msg || "开仓失败");
      return;
    }
    toast(`开仓成功：${t.name} ${t.direction === "long" ? "做多" : "做空"} ${t.leverage}x`);
    closeTrade();
    await Promise.all([loadMe(true), loadMarket(), loadFeed()]);
  } catch (e) {
    toast(e.message);
  } finally {
    btn.disabled = false;
    btn.textContent = "确认开仓";
  }
}

async function closePosition(id) {
  const btn = document.querySelector(`[data-close="${id}"]`);
  if (btn) {
    btn.disabled = true;
    btn.textContent = "平仓中...";
  }
  try {
    const res = await apiPost("trade.php", { action: "close", position_id: id });
    if (!res.ok) {
      toast(res.msg || "平仓失败");
      await loadMe(true);
      return;
    }
    toast(`平仓成功，${res.data.pnl >= 0 ? "盈利" : "亏损"} ${fmt(Math.abs(res.data.pnl), 0)} 热力值`);
    await Promise.all([loadMe(true), loadMarket(), loadLeaderboard(), loadFeed()]);
  } catch (e) {
    toast(e.message);
  }
}

/* ---------- 签到 ---------- */
async function doCheckin() {
  const btn = document.getElementById("checkinBtn");
  if (!btn) return;
  if (btn.dataset.needLogin === "1") {
    location.href = "index.html#auth";
    return;
  }
  btn.disabled = true;
  try {
    const res = await apiPost("checkin.php", {});
    if (!res.ok) {
      toast(res.msg || "签到失败");
      return;
    }
    toast(res.msg || `签到成功 +${res.data.reward}`);
    await Promise.all([loadMe(true), loadFeed()]);
  } catch (e) {
    toast(e.message);
  } finally {
    btn.disabled = false;
  }
}

/* ---------- 四叶草热身 ---------- */
const game = {
  running: false,
  score: 0,
  time: 30,
  timer: null,
  spawn: null,
  best: Number(localStorage.getItem("clovers_game_best") || 0),
};

function initClover() {
  const bestEl = document.getElementById("gameBest");
  if (bestEl) bestEl.textContent = game.best;

  const startBtn = document.getElementById("startGameBtn");
  const area = document.getElementById("gameArea");
  if (!startBtn || !area) return;

  startBtn.addEventListener("click", startClover);
  area.addEventListener("click", (e) => {
    const item = e.target.closest(".fx-game-item");
    if (!item || !game.running) return;
    const good = item.dataset.good === "1";
    game.score = Math.max(0, game.score + (good ? 10 : -15));
    document.getElementById("gameScore").textContent = game.score;
    item.style.opacity = "0";
    item.style.transform = "scale(1.4)";
    setTimeout(() => item.remove(), 180);
  });
}

function startClover() {
  const area = document.getElementById("gameArea");
  const overlay = document.getElementById("gameStartOverlay");
  const tip = document.getElementById("gameTip");
  if (game.running) return;

  game.running = true;
  game.score = 0;
  game.time = 30;
  document.getElementById("gameScore").textContent = "0";
  document.getElementById("gameTime").textContent = "30";
  overlay.style.display = "none";
  area.querySelectorAll(".fx-game-item").forEach((el) => el.remove());

  game.timer = setInterval(() => {
    game.time--;
    document.getElementById("gameTime").textContent = game.time;
    if (game.time <= 0) endClover();
  }, 1000);

  game.spawn = setInterval(spawnClover, 620);
  spawnClover();
}

function spawnClover() {
  if (!game.running) return;
  const area = document.getElementById("gameArea");
  const good = Math.random() > 0.28;
  const el = document.createElement("div");
  el.className = "fx-game-item";
  el.textContent = good ? "🍀" : "🍂";
  el.dataset.good = good ? "1" : "0";

  const size = 32 + Math.random() * 16;
  el.style.fontSize = size + "px";
  el.style.left = Math.random() * (area.clientWidth - size - 8) + "px";
  el.style.top = Math.random() * (area.clientHeight - size - 8) + "px";

  area.appendChild(el);
  setTimeout(() => {
    if (el.parentNode) {
      el.style.opacity = "0";
      setTimeout(() => el.remove(), 200);
    }
  }, 1500 + Math.random() * 700);
}

async function endClover() {
  game.running = false;
  clearInterval(game.timer);
  clearInterval(game.spawn);
  const area = document.getElementById("gameArea");
  area.querySelectorAll(".fx-game-item").forEach((el) => el.remove());

  const overlay = document.getElementById("gameStartOverlay");
  overlay.style.display = "flex";
  overlay.querySelector("button").textContent = "再玩一次";
  overlay.querySelector("p").textContent = `本局得分：${game.score}`;

  if (game.score > game.best) {
    game.best = game.score;
    localStorage.setItem("clovers_game_best", String(game.best));
    document.getElementById("gameBest").textContent = game.best;
  }

  const tip = document.getElementById("gameTip");
  if (!state.me || !state.me.data.logged_in) {
    tip.textContent = `本局 ${game.score} 分。登录后得分可以兑换成热力值。`;
    return;
  }

  if (game.score <= 0) {
    tip.textContent = "本局没有得分，再来一局吧。";
    return;
  }

  tip.textContent = "正在兑换热力值...";
  try {
    const res = await apiPost("game_score.php", { score: game.score });
    if (res.ok) {
      tip.textContent = `本局 ${game.score} 分 → +${fmt(res.data.earned, 0)} 热力值（今日还可兑换 ${fmt(res.data.remaining_cap, 0)}）`;
      await Promise.all([loadMe(true), loadFeed()]);
    } else {
      tip.textContent = res.msg || "兑换失败";
    }
  } catch (e) {
    tip.textContent = e.message;
  }
}

/* ---------- 标签页 ---------- */
function initTabs() {
  $$(".fx-tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      $$(".fx-tab").forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");
      $$(".fx-panel").forEach((p) => p.classList.remove("active"));
      const panel = document.getElementById("panel-" + tab.dataset.tab);
      if (panel) panel.classList.add("active");
      if (tab.dataset.tab === "leaderboard" && !state.leaders) loadLeaderboard();
      if (tab.dataset.tab === "feed") loadFeed();
    });
  });

  $$(".fx-subtab").forEach((tab) => {
    tab.addEventListener("click", () => {
      $$(".fx-subtab").forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");
      state.rankTab = tab.dataset.rank;
      renderLeaderboard();
    });
  });
}

/* ---------- 初始化 ---------- */
document.addEventListener("DOMContentLoaded", async () => {
  initNav();
  initTabs();

  document.getElementById("checkinBtn").addEventListener("click", doCheckin);
  document.getElementById("refreshBtn").addEventListener("click", async () => {
    await Promise.all([loadMarket(), loadMe(true)]);
    toast("行情已刷新");
  });

  document.getElementById("marketList").addEventListener("click", (e) => {
    const chartEl = e.target.closest("[data-chart]");
    if (chartEl) {
      openChart(chartEl.dataset.chart);
      return;
    }
    const btn = e.target.closest("[data-act]");
    if (!btn || btn.disabled) return;
    openTrade(btn.dataset.symbol, btn.dataset.act);
  });

  document.getElementById("chartClose").addEventListener("click", () => {
    document.getElementById("chartModal").classList.remove("open");
    hideChartTip();
  });
  document.getElementById("chartModal").addEventListener("click", (e) => {
    if (e.target.id === "chartModal") {
      document.getElementById("chartModal").classList.remove("open");
      hideChartTip();
    }
  });
  document.getElementById("chartPeriods").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-period]");
    if (!btn) return;
    state.chart.period = btn.dataset.period;
    document.querySelectorAll("#chartPeriods button").forEach((b) => {
      b.classList.toggle("active", b.dataset.period === state.chart.period);
    });
    loadChart();
  });
  const chartSvg = document.getElementById("chartSvg");
  if (chartSvg) {
    chartSvg.addEventListener("pointermove", onChartMove);
    chartSvg.addEventListener("pointerleave", hideChartTip);
  }

  document.getElementById("positionsList").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-close]");
    if (!btn || btn.disabled) return;
    closePosition(btn.dataset.close);
  });

  document.getElementById("tradeClose").addEventListener("click", closeTrade);
  document.getElementById("tradeModal").addEventListener("click", (e) => {
    if (e.target.id === "tradeModal") closeTrade();
  });
  document.getElementById("levRow").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-lev]");
    if (!btn || !state.trade) return;
    state.trade.leverage = Number(btn.dataset.lev);
    renderLeverage();
    updateSummary();
  });
  document.querySelectorAll(".fx-quick button").forEach((btn) => {
    btn.addEventListener("click", () => {
      const user = state.me && state.me.data.user;
      const heat = user ? Number(user.heat) : 0;
      const pct = Number(btn.dataset.pct) / 100;
      document.getElementById("tradeMargin").value = Math.max(10, Math.floor(heat * pct));
      updateSummary();
    });
  });
  document.getElementById("tradeMargin").addEventListener("input", updateSummary);
  document.getElementById("tradeSubmit").addEventListener("click", submitTrade);

  await loadMe(false);
  await Promise.all([loadMarket(), loadLeaderboard(), loadFeed()]);

  setInterval(tickCountdowns, 1000);
  setInterval(async () => {
    if (document.hidden) return;
    await loadMarket();
    if (state.me && state.me.data.logged_in) await loadMe(true);
  }, 30000);
});
