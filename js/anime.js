/* ===== 本季新番 ===== */
const A = { season: null, data: null, me: null, subject: null, sort: "air", dir: "asc" };

function bgmSeasonUrl(season) {
  const m = /^(\d{4})Q([1-4])$/.exec(season || "");
  if (!m) return "https://bgm.tv/anime/browser";
  const month = (Number(m[2]) - 1) * 3 + 1;
  return `https://bgm.tv/anime/browser/airtime/${m[1]}/${month}`;
}

function renderSeasonTabs(quarters, current) {
  const box = document.getElementById("seasonTabs");
  if (!box) return;
  box.innerHTML = quarters
    .map(
      (q) =>
        `<button type="button" class="season-tab ${q === current ? "active" : ""}" data-season="${q}">${q.replace("Q", " 年 Q")}</button>`
    )
    .join("");
}

const RATING_LABELS = ["未评分", "拉完了", "NPC", "人上人", "顶级", "夯"];

function ratingLabel(avg) {
  if (avg == null) return "暂无评分";
  if (avg < 1.5) return "拉完了";
  if (avg < 2.5) return "NPC";
  if (avg < 3.5) return "人上人";
  if (avg < 4.5) return "顶级";
  return "夯";
}

function sortedAnime(items) {
  const dir = A.dir === "asc" ? 1 : -1;
  return items.slice().sort((a, b) => {
    let va = null;
    let vb = null;
    if (A.sort === "rating") {
      va = a.rating_count ? Number(a.rating_avg) : null;
      vb = b.rating_count ? Number(b.rating_avg) : null;
    } else if (A.sort === "comments") {
      va = Number(a.comments || 0);
      vb = Number(b.comments || 0);
    } else {
      va = a.air_date || null;
      vb = b.air_date || null;
    }

    // 没有数据的永远排最后
    if (va == null && vb == null) return String(a.title).localeCompare(String(b.title), "zh");
    if (va == null) return 1;
    if (vb == null) return -1;
    if (va === vb) return Number(a.id) - Number(b.id);
    return (va < vb ? -1 : 1) * dir;
  });
}

function renderAnime(d) {
  document.getElementById("seasonTitle").textContent = d.label + " 新番";
  const items = sortedAnime(d.items || []);

  document.getElementById("seasonMeta").textContent = items.length
    ? `${items.length} 部 · 大家可以给每部打 0-5 分`
    : "还没有导入这一季的新番";
  document.getElementById("bgmSeasonLink").href = bgmSeasonUrl(d.season);

  const importBtn = document.getElementById("importBtn");
  importBtn.hidden = !d.can_import;
  const addBtn = document.getElementById("addBtn");
  if (addBtn) addBtn.hidden = !(d.can_manage || d.can_import);

  const grid = document.getElementById("animeGrid");
  if (!d.items.length) {
    grid.innerHTML = `<div class="anime-empty">
      <p>这一季还没有收录新番。</p>
      <p class="muted">${d.can_import ? "点右上角「从 Bangumi 导入本季」试试（优先读本地数据）。" : "管理员还没有导入，先去 Bangumi 看看本季有哪些吧。"}</p>
      <a class="btn btn-outline btn-sm" href="${bgmSeasonUrl(d.season)}" target="_blank" rel="noopener">去 Bangumi 看这一季 →</a>
    </div>`;
    return;
  }

  const logged = !!(A.me && A.me.data && A.me.data.logged_in);
  grid.innerHTML = items
    .map((it, index) => {
      const title = it.title_cn || it.title;
      const sub = it.title_cn && it.title && it.title_cn !== it.title ? it.title : "";
      const avg = it.rating_avg;
      const count = it.rating_count || 0;
      const my = it.my_rating;

      const buttons = [0, 1, 2, 3, 4, 5]
        .map((n) => `<button type="button" class="rating-btn ${my === n ? "active" : ""}" data-score="${n}" title="${RATING_LABELS[n]}">${n}</button>`)
        .join("");

      return `<article class="anime-row">
        <div class="anime-index">${String(index + 1).padStart(2, "0")}</div>
        <div class="anime-main">
          <h3><a href="${it.bgm_url}" target="_blank" rel="noopener">${escapeHtml(title)}</a></h3>
          <p class="anime-sub">${sub ? escapeHtml(sub) + " · " : ""}${it.air_date ? "首播 " + escapeHtml(it.air_date) : "放送中"}</p>
          ${it.summary ? `<p class="anime-summary">${escapeHtml((it.summary || "").replace(/\s+/g, " ").slice(0, 100))}…</p>` : ""}
        </div>
        <div class="anime-score" data-rate-id="${it.id}">
          <div class="anime-score-top">
            <strong>${count ? Number(avg).toFixed(1) : "--"}</strong>
            <span class="rating-badge">${ratingLabel(count ? avg : null)}</span>
            <em>${count} 人</em>
          </div>
          <div class="rating-buttons">${buttons}</div>
          <p class="anime-my-rating">${my == null ? (logged ? "还没打分" : "登录后打分") : "我的评分：" + my + " · " + RATING_LABELS[my]}</p>
        </div>
        <div class="anime-row-actions">
          <a href="${it.bgm_url}" target="_blank" rel="noopener">Bangumi</a>
          <button type="button" data-comment="${it.id}" data-title="${escapeHtml(title)}" data-link="${it.bgm_url}">评论 <em>${it.comments || 0}</em></button>
        </div>
      </article>`;
    })
    .join("");
}

async function rateAnime(id, score) {
  if (!(A.me && A.me.data && A.me.data.logged_in)) {
    toast("登录后才能给新番打分");
    return;
  }
  try {
    const res = await apiPost("anime.php", { action: "rate", subject_id: id, score });
    if (!res.ok) {
      toast(res.msg || "评分失败");
      return;
    }
    const item = (A.data.items || []).find((x) => Number(x.id) === Number(id));
    if (item) {
      item.my_rating = res.data.my_rating;
      item.rating_avg = res.data.rating_avg;
      item.rating_count = res.data.rating_count;
    }
    renderAnime(A.data);
    toast(`已打 ${score} 分 · ${RATING_LABELS[score]}`);
  } catch (e) {
    toast(e.message);
  }
}

async function loadAnime(season) {
  const res = await apiGet("anime.php" + (season ? "?season=" + encodeURIComponent(season) : ""));
  if (!res.ok) {
    toast(res.msg || "新番加载失败");
    return;
  }
  A.season = res.data.season;
  A.data = res.data;
  renderSeasonTabs(res.data.quarters, res.data.season);
  renderAnime(res.data);

  const url = new URL(location.href);
  url.searchParams.set("season", res.data.season);
  history.replaceState(null, "", url.toString());
}

/* ---------- 评论 ---------- */
async function openComments(id, title, link) {
  A.subject = Number(id);
  document.getElementById("cmTitle").textContent = title;
  document.getElementById("cmLink").href = link || "https://bgm.tv/subject/" + id;
  document.getElementById("cmList").innerHTML = '<p class="muted">加载中...</p>';
  document.getElementById("commentModal").classList.add("open");

  const logged = !!(A.me && A.me.data && A.me.data.logged_in);
  document.getElementById("cmForm").hidden = !logged;
  document.getElementById("cmLoginTip").hidden = logged;

  await loadComments();
}

async function loadComments() {
  const res = await apiGet("anime.php?action=comments&subject_id=" + A.subject);
  const box = document.getElementById("cmList");
  if (!res.ok) {
    box.innerHTML = `<p class="muted">${escapeHtml(res.msg || "评论加载失败")}</p>`;
    return;
  }
  if (!res.data.items.length) {
    box.innerHTML = '<p class="muted">还没有评论，来抢第一条。</p>';
    return;
  }
  box.innerHTML = res.data.items
    .map(
      (c) => `<div class="comment-item">
        <div class="comment-head">
          ${avatarHtml(c)}
          <a href="profile.html?id=${c.user_id}">${escapeHtml(c.nickname)}</a>
          <span class="muted">${escapeHtml(c.time_ago)}</span>
          ${c.can_delete ? `<button class="comment-del" data-del="${c.id}" type="button">删除</button>` : ""}
        </div>
        <p>${escapeHtml(c.content)}</p>
      </div>`
    )
    .join("");
}

async function postComment(e) {
  e.preventDefault();
  if (!A.subject) return;
  const input = document.getElementById("cmContent");
  const content = input.value.trim();
  if (!content) {
    toast("先写点内容");
    return;
  }
  try {
    const res = await apiPost("anime.php", { action: "comment", subject_id: A.subject, content });
    if (!res.ok) {
      toast(res.msg || "评论失败");
      return;
    }
    input.value = "";
    toast("评论成功");
    await loadComments();
    await loadAnime(A.season);
  } catch (err) {
    toast(err.message);
  }
}

async function deleteComment(id) {
  try {
    const res = await apiPost("admin.php", { action: "delete_comment", id });
    if (!res.ok) {
      toast(res.msg || "删除失败");
      return;
    }
    toast("已删除");
    await loadComments();
    await loadAnime(A.season);
  } catch (err) {
    toast(err.message);
  }
}

/* ---------- 初始化 ---------- */
document.addEventListener("DOMContentLoaded", async () => {
  initNav();

  A.me = await getMe();
  updateNavUser(A.me);

  const season = new URLSearchParams(location.search).get("season") || "";

  document.getElementById("seasonTabs").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-season]");
    if (btn) loadAnime(btn.dataset.season);
  });

  function updateSortUi() {
    document.querySelectorAll(".anime-sort-btn").forEach((b) => {
      b.classList.toggle("active", b.dataset.sort === A.sort);
    });
    const dirBtn = document.getElementById("animeDir");
    if (dirBtn) {
      dirBtn.textContent = A.dir === "asc" ? "正序 ↑" : "倒序 ↓";
      dirBtn.dataset.dir = A.dir;
    }
  }

  document.getElementById("animeSort").addEventListener("click", (e) => {
    const sortBtn = e.target.closest("[data-sort]");
    if (sortBtn) {
      A.sort = sortBtn.dataset.sort;
      updateSortUi();
      if (A.data) renderAnime(A.data);
      return;
    }
    const dirBtn = e.target.closest("#animeDir");
    if (dirBtn) {
      A.dir = A.dir === "asc" ? "desc" : "asc";
      updateSortUi();
      if (A.data) renderAnime(A.data);
    }
  });

  document.getElementById("animeGrid").addEventListener("click", (e) => {
    const rateBox = e.target.closest("[data-rate-id]");
    if (rateBox) {
      const scoreBtn = e.target.closest("[data-score]");
      if (scoreBtn) rateAnime(Number(rateBox.dataset.rateId), Number(scoreBtn.dataset.score));
      return;
    }
    const btn = e.target.closest("[data-comment]");
    if (btn) openComments(btn.dataset.comment, btn.dataset.title, btn.dataset.link);
  });

  document.getElementById("cmClose").addEventListener("click", () => {
    document.getElementById("commentModal").classList.remove("open");
  });
  document.getElementById("commentModal").addEventListener("click", (e) => {
    if (e.target.id === "commentModal") document.getElementById("commentModal").classList.remove("open");
  });

  document.getElementById("cmForm").addEventListener("submit", postComment);

  document.getElementById("cmList").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-del]");
    if (btn) deleteComment(btn.dataset.del);
  });

  document.getElementById("importBtn").addEventListener("click", async () => {
    const btn = document.getElementById("importBtn");
    btn.disabled = true;
    btn.textContent = "导入中...";
    try {
      const res = await apiPost("anime.php", { action: "import", season: A.season });
      if (!res.ok) {
        toast(res.msg || "导入失败");
        return;
      }
      toast(`导入成功：${res.data.count} 部${res.data.source ? "（" + res.data.source + "）" : ""}`);
      await loadAnime(A.season);
    } catch (err) {
      toast(err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = "从 Bangumi 导入本季";
    }
  });

  const addModal = document.getElementById("addModal");
  document.getElementById("addBtn").addEventListener("click", () => addModal.classList.add("open"));
  document.getElementById("addClose").addEventListener("click", () => addModal.classList.remove("open"));
  addModal.addEventListener("click", (e) => {
    if (e.target.id === "addModal") addModal.classList.remove("open");
  });

  document.getElementById("addForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const title = document.getElementById("addTitle").value.trim();
    if (!title) return toast("标题不能为空");
    const bgmRaw = document.getElementById("addBgm").value.trim();
    const m = /(\d{3,})/.exec(bgmRaw);
    try {
      const res = await apiPost("anime.php", {
        action: "add",
        season: A.season,
        title,
        title_cn: document.getElementById("addCn").value.trim(),
        air_date: document.getElementById("addDate").value,
        summary: document.getElementById("addSummary").value.trim(),
        bgm_id: m ? Number(m[1]) : 0,
      });
      if (!res.ok) {
        toast(res.msg || "添加失败");
        return;
      }
      toast("已添加");
      addModal.classList.remove("open");
      e.target.reset();
      await loadAnime(A.season);
    } catch (err) {
      toast(err.message);
    }
  });

  await loadAnime(season);
});
