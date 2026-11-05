/* ===== 投稿展示 ===== */
const W = { category: "", items: [] };

function formatSize(n) {
  n = Number(n || 0);
  if (n < 1024) return n + " B";
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + " KB";
  return (n / 1024 / 1024).toFixed(2) + " MB";
}

function renderWorks() {
  const grid = document.getElementById("worksGrid");
  if (!grid) return;
  if (!W.items.length) {
    grid.innerHTML = `<div class="anime-empty">
      <p>还没有通过审核的投稿。</p>
      <p class="muted">去 <a href="submit.html">社刊投稿</a> 投一篇，管理员通过后就会出现在这里。</p>
    </div>`;
    return;
  }

  grid.innerHTML = W.items
    .map((w) => {
      let cover;
      if (w.is_image) {
        cover = `<a class="work-cover" href="${w.preview_url}" target="_blank" rel="noopener">
          <img src="${w.preview_url}" alt="${escapeHtml(w.title)}" loading="lazy" />
        </a>`;
      } else {
        cover = `<div class="work-cover work-file">
          <span>${w.is_pdf ? "📕" : "📄"}</span>
          <em>${w.is_pdf ? "PDF" : escapeHtml((w.original_name || "").split(".").pop().toUpperCase())}</em>
        </div>`;
      }

      const avatar = w.avatar_url
        ? `<img class="work-avatar" src="${escapeHtml(w.avatar_url)}" alt="" />`
        : `<span class="work-avatar work-avatar-text">${escapeHtml((w.author || "🍀").slice(0, 1))}</span>`;

      return `<article class="work-card">
        ${cover}
        <div class="work-body">
          <span class="work-cat">${escapeHtml(w.category)}</span>
          <h3>${escapeHtml(w.title)}</h3>
          ${w.description ? `<p class="work-desc">${escapeHtml(w.description)}</p>` : ""}
          <div class="work-author">${avatar}<span>${escapeHtml(w.author)}</span><span class="muted">${escapeHtml(w.time_ago)}</span></div>
          <div class="work-actions">
            ${w.is_pdf ? `<a class="btn btn-outline btn-sm" href="${w.preview_url}" target="_blank" rel="noopener">打开 PDF</a>` : ""}
            <a class="btn btn-outline btn-sm" href="${w.download_url}">下载 · ${formatSize(w.file_size)}</a>
          </div>
        </div>
      </article>`;
    })
    .join("");
}

async function loadWorks() {
  const res = await apiGet("works.php" + (W.category ? "?category=" + encodeURIComponent(W.category) : ""));
  if (!res.ok) {
    document.getElementById("worksGrid").innerHTML = `<p class="muted">${escapeHtml(res.msg || "加载失败")}</p>`;
    return;
  }
  W.items = res.data.items;
  renderWorks();
}

document.addEventListener("DOMContentLoaded", async () => {
  initNav();
  updateNavUser(await getMe());

  document.getElementById("workFilters").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-cat]");
    if (!btn) return;
    document.querySelectorAll("#workFilters .season-tab").forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");
    W.category = btn.dataset.cat;
    loadWorks();
  });

  await loadWorks();
});
