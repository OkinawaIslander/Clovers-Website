/* ===== 我的 / 个人主页 ===== */
const P = { me: null, data: null, isSelf: false, subsScope: "mine" };

const DEPT_LABEL = { xuanbian: "宣编部", zhaiwu: "宅舞部", wota: "WOTA艺部", zhuoyou: "桌游部" };

const SUB_STATUS = {
  pending: { label: "待审核", cls: "pending" },
  accepted: { label: "已通过", cls: "accepted" },
  rejected: { label: "未通过", cls: "rejected" },
};

function duration(seconds) {
  seconds = Math.max(0, Math.floor(Number(seconds) || 0));
  if (seconds <= 0) return "可平仓";
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m ${seconds % 60}s`;
}

function formatBytes(n) {
  n = Number(n || 0);
  if (n < 1024) return n + " B";
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + " KB";
  return (n / 1024 / 1024).toFixed(2) + " MB";
}

function renderHero(d, self) {
  const u = d.user;
  document.getElementById("nickname").textContent = u.nickname;

  const avatar = document.getElementById("avatar");
  if (u.avatar_url) {
    avatar.innerHTML = `<img src="${escapeHtml(u.avatar_url)}" alt="${escapeHtml(u.nickname)}" />`;
  } else {
    avatar.textContent = (u.nickname || "🍀").slice(0, 1);
  }

  const sig = document.getElementById("signature");
  if (sig) sig.textContent = u.signature || "";

  const depts = document.getElementById("deptBadges");
  if (depts) {
    depts.innerHTML = (u.departments || [])
      .map((x) => `<span class="dept-badge">${escapeHtml(DEPT_LABEL[x] || x)}</span>`)
      .join("");
  }

  const role = (u.role === "super")
    ? '<span class="role-badge">系统管理员</span>'
    : (u.role === "admin")
      ? '<span class="role-badge">管理员</span>'
      : '<span class="role-badge user">成员</span>';
  const joined = String(u.created_at || "").slice(0, 10);
  document.getElementById("meta").innerHTML = `${role} 加入于 ${escapeHtml(joined)}`;

  document.getElementById("statNet").textContent = fmt(d.net_worth !== undefined ? d.net_worth : (d.stats && d.stats.net_worth), 0);
  document.getElementById("statRank").textContent = d.rank ? "第 " + d.rank + " 名" : (d.stats && d.stats.rank ? "第 " + d.stats.rank + " 名" : "--");
  const profit = d.profit !== undefined ? d.profit : (d.stats && d.stats.profit) || 0;
  const pct = d.profit_pct !== undefined ? d.profit_pct : (d.stats && d.stats.profit_pct) || 0;
  document.getElementById("statProfit").textContent = signed(profit, 0);
  document.getElementById("statProfit").className = profit >= 0 ? "up" : "down";
  document.getElementById("statPct").textContent = `${pct >= 0 ? "+" : ""}${fmt(pct, 1)}%`;
  document.getElementById("statPct").className = pct >= 0 ? "up" : "down";

  const quick = document.getElementById("profileQuick");
  if (self) {
    const c = d.checkin || {};
    quick.innerHTML = `
      <span class="heat-chip">🔥 ${fmt(u.heat, 0)} 热力值</span>
      <button class="btn btn-primary btn-sm" id="checkinBtn" ${c.available ? "" : "disabled"}>
        ${c.available ? `签到 +${c.next_reward}` : `已签到 · 连续 ${c.streak} 天`}
      </button>
      <button class="btn btn-outline btn-sm" id="logoutBtn">退出登录</button>`;
    bindCheckin(c);
    document.getElementById("logoutBtn").addEventListener("click", async () => {
      await apiPost("logout.php", {});
      location.href = "index.html";
    });
  } else {
    quick.innerHTML = `<a class="btn btn-outline btn-sm" href="game.html">去交易所</a>`;
  }
}

function bindCheckin(c) {
  const btn = document.getElementById("checkinBtn");
  if (!btn || !c.available) return;
  btn.addEventListener("click", async () => {
    btn.disabled = true;
    try {
      const res = await apiPost("checkin.php", {});
      toast(res.ok ? (res.msg || "签到成功") : (res.msg || "签到失败"));
      if (res.ok) setTimeout(() => location.reload(), 600);
    } catch (e) {
      toast(e.message);
      btn.disabled = false;
    }
  });
}

/* ---------- 持仓 / 交易记录 ---------- */
function renderPositions(list) {
  const box = document.getElementById("positions");
  if (!box) return;
  if (!list || !list.length) {
    box.innerHTML = '<p class="muted">还没有持仓，去 <a href="game.html">交易所</a> 开一单。</p>';
    return;
  }
  box.innerHTML = list
    .map((p) => {
      const up = Number(p.pnl) >= 0;
      return `<div class="mini-position">
        <div class="mp-main">
          <span class="pill ${p.direction}">${p.direction === "long" ? "做多" : "做空"} ${p.leverage}x</span>
          <strong>${escapeHtml(p.icon)} ${escapeHtml(p.name)}</strong>
        </div>
        <div class="mp-nums">
          <span>入场 ${fmt(p.entry_price)}</span>
          <span>现价 ${fmt(p.price)}</span>
          <span class="pnl ${up ? "up" : "down"}">${signed(p.pnl, 0)}</span>
        </div>
        <div class="mp-bottom">
          <span class="muted" data-close-at="${Math.floor(parseDate(p.close_after).getTime() / 1000)}">${duration(p.close_in)}</span>
          <button class="btn btn-outline btn-sm" data-close="${p.id}" ${Number(p.close_in) > 0 ? "disabled" : ""}>平仓</button>
        </div>
      </div>`;
    })
    .join("");
}

function renderHistory(list) {
  const box = document.getElementById("history");
  if (!box) return;
  if (!list || !list.length) {
    box.innerHTML = '<p class="muted">还没有完成过交易。</p>';
    return;
  }
  box.innerHTML = `<table class="mini-table">
    <thead><tr><th>部门</th><th>方向</th><th>杠杆</th><th>盈亏</th><th>时间</th></tr></thead>
    <tbody>${list
      .map(
        (h) => `<tr>
          <td>${escapeHtml(h.icon)} ${escapeHtml(h.name)}</td>
          <td>${h.direction === "long" ? "做多" : "做空"}</td>
          <td>${h.leverage}x</td>
          <td class="pnl ${h.pnl >= 0 ? "up" : "down"}">${signed(h.pnl, 0)}</td>
          <td class="muted">${String(h.closed_at || "").slice(5, 16)}${h.status === "liquidated" ? " · 强平" : ""}</td>
        </tr>`
      )
      .join("")}</tbody>
  </table>`;
}

/* ---------- 留言 / 评论 ---------- */
function renderPosts(list) {
  const box = document.getElementById("myPosts");
  if (!box) return;
  if (!list || !list.length) {
    box.innerHTML = '<p class="muted">还没有留言。</p>';
    return;
  }
  box.innerHTML = list
    .map(
      (p) => `<div class="me-item ${p.is_deleted ? "deleted" : ""}">
        <p>${escapeHtml(p.content)}</p>
        <div class="me-item-foot">
          <span class="muted">${escapeHtml(p.time_ago || p.created_at)}</span>
          ${p.is_deleted ? '<span class="me-flag">已被管理员删除</span>' : ""}
        </div>
      </div>`
    )
    .join("");
}

function renderComments(list) {
  const box = document.getElementById("myComments");
  if (!box) return;
  if (!list || !list.length) {
    box.innerHTML = '<p class="muted">还没有评论过新番。</p>';
    return;
  }
  box.innerHTML = list
    .map(
      (c) => `<div class="me-item ${c.is_deleted ? "deleted" : ""}">
        <p><a href="anime.html" class="me-link">${escapeHtml(c.title || "番剧 #" + c.subject_id)}</a>：${escapeHtml(c.content)}</p>
        <div class="me-item-foot">
          <span class="muted">${escapeHtml(c.time_ago || c.created_at)}</span>
          ${c.is_deleted ? '<span class="me-flag">已被管理员删除</span>' : ""}
        </div>
      </div>`
    )
    .join("");
}

/* ---------- 投稿 ---------- */
function renderSubs(list, scope) {
  const box = document.getElementById("mySubs");
  if (!box) return;
  if (!list || !list.length) {
    box.innerHTML = '<p class="muted">还没有投稿。</p>';
    return;
  }
  const admin = P.data && P.data.is_admin;
  box.innerHTML = list
    .map((s) => {
      const st = SUB_STATUS[s.status] || SUB_STATUS.pending;
      const review = admin
        ? `<div class="sub-review">
             <button class="btn btn-outline btn-sm" data-review="${s.id}" data-status="accepted" type="button">通过</button>
             <button class="btn btn-outline btn-sm" data-review="${s.id}" data-status="rejected" type="button">驳回</button>
           </div>`
        : "";
      return `<div class="sub-item">
        <div class="sub-item-head">
          <strong>${escapeHtml(s.title)}</strong>
          <span class="sub-status ${st.cls}">${st.label}</span>
        </div>
        <p class="muted small">${escapeHtml(s.category)} · ${formatBytes(s.file_size)} · ${escapeHtml(s.time_ago || s.created_at)}${scope === "all" ? " · " + avatarHtml(s) + " " + escapeHtml(s.nickname || "") : ""}</p>
        ${s.description ? `<p class="sub-desc">${escapeHtml(s.description)}</p>` : ""}
        ${s.admin_note ? `<p class="sub-note">管理员备注：${escapeHtml(s.admin_note)}</p>` : ""}
        <div class="sub-item-foot">
          <a href="${s.download_url}" class="panel-link">下载文件</a>
          ${s.can_delete ? `<button class="sub-del" data-del="${s.id}" type="button">删除</button>` : ""}
        </div>
        ${review}
      </div>`;
    })
    .join("");
}

async function loadSubs(scope) {
  P.subsScope = scope || "mine";
  const res = await apiGet("submissions.php?scope=" + P.subsScope);
  if (!res.ok) {
    document.getElementById("mySubs").innerHTML = `<p class="muted">${escapeHtml(res.msg || "加载失败")}</p>`;
    return;
  }
  renderSubs(res.data.items, P.subsScope);
  const btn = document.getElementById("allSubsBtn");
  if (btn && P.data && P.data.is_admin) btn.hidden = false;
  if (btn) btn.textContent = P.subsScope === "all" ? "只看我的" : "查看全部投稿";
}

async function reviewSub(id, status) {
  let note = "";
  if (status === "rejected") {
    note = prompt("驳回原因（可留空）") || "";
  }
  const res = await apiPost("submissions.php", { action: "review", id: Number(id), status, note });
  if (!res.ok) return toast(res.msg || "操作失败");
  toast("已更新");
  await loadSubs(P.subsScope);
}

async function deleteSub(id) {
  if (!confirm("确定删除这篇投稿？文件也会一起删除。")) return;
  const res = await apiPost("submissions.php", { action: "delete", id: Number(id) });
  if (!res.ok) return toast(res.msg || "删除失败");
  toast("已删除");
  await loadSubs(P.subsScope);
}

/* ---------- 投票 ---------- */
function renderVotes(list) {
  const box = document.getElementById("myVotes");
  if (!box) return;
  if (!list || !list.length) {
    box.innerHTML = '<p class="muted">还没有参与过投票。</p>';
    return;
  }
  const label = { open: "进行中", upcoming: "未开始", closed: "已截止" };
  box.innerHTML = list
    .map(
      (v) => `<div class="me-item">
        <p><a href="vote.html" class="me-link">${escapeHtml(v.title)}</a> · 我投了「${escapeHtml(v.option)}」</p>
        <div class="me-item-foot"><span class="muted">${escapeHtml(v.created_at)}</span><span class="me-flag">${label[v.status] || v.status}</span></div>
      </div>`
    )
    .join("");
}

function fillSettings(u) {
  const nick = document.getElementById("setNickname");
  if (!nick) return;
  nick.value = u.nickname || "";
  document.getElementById("setSignature").value = u.signature || "";
  const mine = u.departments || [];
  document.querySelectorAll("#panel-settings .dept-checks input").forEach((cb) => {
    cb.checked = mine.includes(cb.value);
  });
  const preview = document.getElementById("avatarPreview");
  if (preview) {
    if (u.avatar_url) preview.innerHTML = `<img src="${escapeHtml(u.avatar_url)}" alt="头像" />`;
    else preview.textContent = (u.nickname || "🍀").slice(0, 1);
  }
}

async function uploadAvatar() {
  const input = document.getElementById("avatarFile");
  const file = input.files && input.files[0];
  if (!file) return toast("请先选择图片");
  if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) return toast("只支持 JPG / PNG / WebP / GIF");
  if (file.size > 2 * 1024 * 1024) return toast("头像不能超过 2MB");

  const fd = new FormData();
  fd.append("action", "avatar");
  fd.append("file", file);

  const btn = document.getElementById("avatarBtn");
  btn.disabled = true;
  btn.textContent = "上传中...";
  try {
    const res = await fetch("api/account.php", { method: "POST", body: fd, credentials: "same-origin" });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      throw new Error("服务器返回异常，请检查 PHP 上传限制");
    }
    if (!data.ok) return toast(data.msg || "上传失败");
    P.data.user.avatar_url = data.data.avatar_url;
    renderHero(P.data, true);
    fillSettings(P.data.user);
    input.value = "";
    toast("头像已更新");
  } catch (err) {
    toast(err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = "上传头像";
  }
}

async function saveProfile(e) {
  e.preventDefault();
  const departments = [...document.querySelectorAll("#panel-settings .dept-checks input:checked")].map((cb) => cb.value);
  try {
    const res = await apiPost("account.php", {
      action: "profile",
      nickname: document.getElementById("setNickname").value.trim(),
      signature: document.getElementById("setSignature").value.trim(),
      departments,
    });
    if (!res.ok) return toast(res.msg || "保存失败");
    P.data.user.nickname = res.data.nickname;
    P.data.user.signature = res.data.signature;
    P.data.user.departments = res.data.departments;
    renderHero(P.data, true);
    fillSettings(P.data.user);
    toast("资料已更新");
  } catch (err) {
    toast(err.message);
  }
}

async function loadAdminUsers() {
  const box = document.getElementById("userAdminList");
  if (!box) return;
  try {
    const res = await apiGet("admin.php?action=users");
    if (!res.ok) {
      box.innerHTML = `<p class="muted">${escapeHtml(res.msg || "加载失败")}</p>`;
      return;
    }
    document.getElementById("superPanel").hidden = false;
    const selfId = P.data.user.id;
    box.innerHTML = res.data.items
      .map((u) => {
        const roleLabel = u.role === "super" ? "系统管理员" : u.role === "admin" ? "管理员" : "成员";
        let action = "";
        if (u.role !== "super") {
          const next = u.role === "admin" ? "user" : "admin";
          action = `<button class="btn btn-outline btn-sm" data-uid="${u.id}" data-role="${next}" type="button">${next === "admin" ? "设为管理员" : "取消管理员"}</button>`;
        }
        return `<div class="admin-user-row">
          ${avatarHtml(u)}
          <div><strong>${escapeHtml(u.nickname)}</strong>${u.id === selfId ? "（我）" : ""}<span class="muted small"> ${escapeHtml(u.email || "")}</span></div>
          <span class="role-badge ${u.role === "user" ? "user" : ""}">${roleLabel}</span>
          ${action}
        </div>`;
      })
      .join("");
  } catch (e) {
    box.innerHTML = `<p class="muted">${escapeHtml(e.message)}</p>`;
  }
}

/* ---------- 自己 ---------- */
async function renderSelf(d) {
  P.isSelf = true;
  P.data = d;
  renderHero(d, true);
  fillSettings(d.user);
  if (d.is_super) await loadAdminUsers();
  document.getElementById("meTabs").hidden = false;
  document.getElementById("publicPanel").hidden = true;

  document.getElementById("gameStats").innerHTML = `
    <div class="stat-card"><span>小游戏累计热力</span><strong>${fmt(d.game.total_heat, 0)}</strong></div>
    <div class="stat-card"><span>今日小游戏热力</span><strong>${fmt(d.game.today_heat, 0)}</strong></div>
    <div class="stat-card"><span>小游戏最高分</span><strong>${d.game.best}</strong></div>
    <div class="stat-card"><span>游戏局数</span><strong>${d.game.runs}</strong></div>`;

  renderPositions(d.positions);
  renderHistory(d.history);
  renderPosts(d.posts);
  renderComments(d.comments);
  renderVotes(d.votes);
  await loadSubs("mine");

  const feed = await apiGet("feed.php?limit=30&user_id=" + d.user.id);
  renderFeed(document.getElementById("feedList"), feed.ok ? feed.data.items : [], "还没有动态");
}

/* ---------- 别人 ---------- */
function renderPublic(d) {
  P.isSelf = false;
  P.data = d;
  renderHero(d, false);
  document.getElementById("meTabs").hidden = true;
  document.getElementById("mePanels").style.display = "none";
  document.getElementById("publicNote").hidden = false;
  document.getElementById("publicPanel").hidden = false;
  renderFeed(document.getElementById("publicFeed"), d.activities || [], "还没有动态");
}

/* ---------- 初始化 ---------- */
document.addEventListener("DOMContentLoaded", async () => {
  initNav();
  P.me = await getMe();
  updateNavUser(P.me);

  const params = new URLSearchParams(location.search);
  const id = params.get("id");
  const myId = P.me.data && P.me.data.user ? P.me.data.user.id : null;

  if (!id && !(P.me.data && P.me.data.logged_in)) {
    location.href = "index.html#auth";
    return;
  }

  const isOther = id && String(id) !== String(myId);
  try {
    if (isOther) {
      const res = await apiGet("profile.php?id=" + encodeURIComponent(id));
      if (!res.ok) {
        document.getElementById("nickname").textContent = res.msg || "加载失败";
        return;
      }
      renderPublic(res.data);
    } else {
      const res = await apiGet("my.php");
      if (!res.ok) {
        document.getElementById("nickname").textContent = res.msg || "加载失败";
        return;
      }
      await renderSelf(res.data);
    }
  } catch (e) {
    document.getElementById("nickname").textContent = "加载失败";
    document.getElementById("meta").textContent = e.message;
  }

  // tabs
  document.querySelectorAll(".me-tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      document.querySelectorAll(".me-tab").forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");
      document.querySelectorAll(".me-panel").forEach((p) => p.classList.remove("active"));
      const panel = document.getElementById("panel-" + tab.dataset.tab);
      if (panel) panel.classList.add("active");
    });
  });

  // 平仓
  document.getElementById("positions").addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-close]");
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    try {
      const res = await apiPost("trade.php", { action: "close", position_id: btn.dataset.close });
      if (!res.ok) {
        toast(res.msg || "平仓失败");
        btn.disabled = false;
        return;
      }
      toast("平仓成功，刷新中...");
      setTimeout(() => location.reload(), 600);
    } catch (err) {
      toast(err.message);
      btn.disabled = false;
    }
  });

  // 投稿：删除 / 审核 / 全部
  document.getElementById("mySubs").addEventListener("click", (e) => {
    const del = e.target.closest("[data-del]");
    if (del) return deleteSub(del.dataset.del);
    const rev = e.target.closest("[data-review]");
    if (rev) return reviewSub(rev.dataset.review, rev.dataset.status);
  });

  document.getElementById("allSubsBtn").addEventListener("click", () => {
    loadSubs(P.subsScope === "all" ? "mine" : "all");
  });

  // 修改资料 / 头像
  document.getElementById("profileForm").addEventListener("submit", saveProfile);
  document.getElementById("avatarBtn").addEventListener("click", uploadAvatar);

  // 系统管理员：授予/取消次级管理员
  document.getElementById("userAdminList").addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-uid]");
    if (!btn) return;
    const res = await apiPost("admin.php", {
      action: "set_role",
      user_id: Number(btn.dataset.uid),
      role: btn.dataset.role,
    });
    if (!res.ok) return toast(res.msg || "操作失败");
    toast("角色已更新");
    await loadAdminUsers();
  });

  // 修改密码
  document.getElementById("pwdForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const current = document.getElementById("pwdCurrent").value;
    const n1 = document.getElementById("pwdNew").value;
    const n2 = document.getElementById("pwdNew2").value;
    if (n1 !== n2) return toast("两次新密码不一致");
    try {
      const res = await apiPost("account.php", { action: "password", current, new_password: n1 });
      if (!res.ok) return toast(res.msg || "修改失败");
      toast(res.msg || "密码已修改");
      e.target.reset();
    } catch (err) {
      toast(err.message);
    }
  });

  setInterval(() => {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll("[data-close-at]").forEach((el) => {
      const left = Number(el.dataset.closeAt || 0) - now;
      el.textContent = duration(left);
      const row = el.closest(".mini-position");
      const btn = row && row.querySelector("[data-close]");
      if (btn) btn.disabled = left > 0;
    });
  }, 1000);
});
