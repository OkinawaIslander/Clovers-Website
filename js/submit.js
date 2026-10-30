/* ===== 社刊投稿 ===== */
const SUBS = { me: null, items: [] };

const SUB_STATUS = {
  pending: { label: "待审核", cls: "pending" },
  accepted: { label: "已通过", cls: "accepted" },
  rejected: { label: "未通过", cls: "rejected" },
};

function formatBytes(n) {
  n = Number(n || 0);
  if (n < 1024) return n + " B";
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + " KB";
  return (n / 1024 / 1024).toFixed(2) + " MB";
}

function renderSubs() {
  const box = document.getElementById("mySubs");
  const count = document.getElementById("subCount");
  if (!box) return;
  if (count) count.textContent = `${SUBS.items.length} 篇`;

  if (!SUBS.items.length) {
    box.innerHTML = '<p class="muted">还没有投稿，左边投第一篇吧。</p>';
    return;
  }

  box.innerHTML = SUBS.items
    .map((s) => {
      const st = SUB_STATUS[s.status] || SUB_STATUS.pending;
      return `<div class="sub-item">
        <div class="sub-item-head">
          <strong>${escapeHtml(s.title)}</strong>
          <span class="sub-status ${st.cls}">${st.label}</span>
        </div>
        <p class="muted small">${escapeHtml(s.category)} · ${formatBytes(s.file_size)} · ${escapeHtml(s.time_ago)}</p>
        ${s.description ? `<p class="sub-desc">${escapeHtml(s.description)}</p>` : ""}
        ${s.admin_note ? `<p class="sub-note">管理员备注：${escapeHtml(s.admin_note)}</p>` : ""}
        <div class="sub-item-foot">
          <a href="${s.download_url}" class="panel-link">下载文件</a>
          ${s.can_delete ? `<button class="sub-del" data-del="${s.id}" type="button">删除</button>` : ""}
        </div>
      </div>`;
    })
    .join("");
}

async function loadSubs() {
  try {
    const res = await apiGet("submissions.php?scope=mine");
    if (!res.ok) {
      document.getElementById("mySubs").innerHTML = `<p class="muted">${escapeHtml(res.msg || "加载失败")}</p>`;
      return;
    }
    SUBS.items = res.data.items;
    renderSubs();
  } catch (e) {
    document.getElementById("mySubs").innerHTML = `<p class="muted">${escapeHtml(e.message)}</p>`;
  }
}

async function uploadSubmit(e) {
  e.preventDefault();
  const fileInput = document.getElementById("subFile");
  const file = fileInput.files && fileInput.files[0];
  if (!file) return toast("请选择文件");
  if (file.size > 20 * 1024 * 1024) return toast("文件不能超过 20MB");

  const btn = document.getElementById("subSubmitBtn");
  btn.disabled = true;
  btn.textContent = "上传中...";

  const fd = new FormData();
  fd.append("action", "create");
  fd.append("title", document.getElementById("subTitle").value.trim());
  fd.append("category", document.getElementById("subCategory").value);
  fd.append("description", document.getElementById("subDesc").value.trim());
  fd.append("file", file);

  try {
    const res = await fetch("api/submissions.php", { method: "POST", body: fd, credentials: "same-origin" });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (err) {
      throw new Error("服务器返回异常，可能是文件超过 PHP upload_max_filesize 限制");
    }
    if (!data.ok) {
      toast(data.msg || "投稿失败");
      return;
    }
    toast("投稿成功，等待管理员审核");
    e.target.reset();
    await loadSubs();
  } catch (err) {
    toast(err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = "提交投稿";
  }
}

async function deleteSub(id) {
  if (!confirm("确定删除这篇投稿？文件也会一起删除。")) return;
  const res = await apiPost("submissions.php", { action: "delete", id: Number(id) });
  if (!res.ok) return toast(res.msg || "删除失败");
  toast("已删除");
  await loadSubs();
}

document.addEventListener("DOMContentLoaded", async () => {
  initNav();
  SUBS.me = await getMe();
  updateNavUser(SUBS.me);

  if (!SUBS.me.data || !SUBS.me.data.logged_in) {
    location.href = "index.html#auth";
    return;
  }

  document.getElementById("submitForm").addEventListener("submit", uploadSubmit);
  document.getElementById("mySubs").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-del]");
    if (btn) deleteSub(btn.dataset.del);
  });

  await loadSubs();
});
