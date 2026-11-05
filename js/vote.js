/* ===== 投票 ===== */
const V = { me: null, data: null, canManage: false };

const STATUS_LABEL = { open: "进行中", upcoming: "未开始", closed: "已截止" };

function pollTime(s) {
  return String(s || "").slice(5, 16);
}

function renderPolls() {
  const box = document.getElementById("pollList");
  const d = V.data;
  if (!box) return;
  if (!d || !d.items.length) {
    box.innerHTML = `<div class="anime-empty"><p>还没有投票。</p><p class="muted">${V.canManage ? "点右上角「发起投票」创建第一个。" : "等管理员发起吧。"}</p></div>`;
    return;
  }

  const logged = !!(V.me && V.me.data && V.me.data.logged_in);

  box.innerHTML = d.items
    .map((p) => {
      const status = p.status;
      const canVote = status === "open" && !p.my_option && logged;

      const options = p.options
        .map((o) => {
          if (canVote) {
            return `<label class="poll-choose">
              <input type="radio" name="poll-${p.id}" value="${o.id}" />
              <span>${escapeHtml(o.label)}</span>
            </label>`;
          }
          const mine = p.my_option === o.id;
          return `<div class="poll-result ${mine ? "mine" : ""}">
            <div class="poll-result-top">
              <span>${escapeHtml(o.label)}${mine ? " · 我投的" : ""}</span>
              <span>${o.votes} 票 · ${o.percent}%</span>
            </div>
            <div class="poll-bar"><i style="width:${o.percent}%"></i></div>
          </div>`;
        })
        .join("");

      let foot = "";
      if (canVote) {
        foot = `<button class="btn btn-primary btn-sm" data-vote="${p.id}" type="button">提交投票</button>`;
      } else if (status === "open" && !p.my_option && !logged) {
        foot = `登录后才能投票 · <a href="index.html#auth">去登录</a>`;
      } else if (status === "open" && p.my_option) {
        foot = "你已经投过票了，等待截止后公布最终结果。";
      } else if (status === "upcoming") {
        foot = "投票还没开始。";
      } else {
        foot = `共 ${p.total_votes} 票，结果已结算。`;
      }

      const manage = V.canManage
        ? `<div class="poll-manage">
            ${status !== "closed" ? `<button class="btn btn-outline btn-sm" data-close="${p.id}" type="button">提前截止</button>` : ""}
            <button class="btn btn-outline btn-sm" data-del="${p.id}" type="button">删除</button>
          </div>`
        : "";

      return `<article class="poll-card">
        <div class="poll-head">
          <div>
            <h2>${escapeHtml(p.title)}</h2>
            ${p.description ? `<p class="muted">${escapeHtml(p.description)}</p>` : ""}
          </div>
          <span class="poll-status ${status}">${STATUS_LABEL[status] || status}</span>
        </div>
        <p class="poll-time">开始 ${pollTime(p.starts_at)} · 截止 ${pollTime(p.ends_at)} · 共 ${p.total_votes} 票</p>
        <div class="poll-options">${options}</div>
        <div class="poll-foot">
          <span class="muted">${foot}</span>
          ${manage}
        </div>
      </article>`;
    })
    .join("");
}

async function loadPolls() {
  const res = await apiGet("polls.php");
  if (!res.ok) {
    toast(res.msg || "投票加载失败");
    return;
  }
  V.data = res.data;
  V.canManage = !!res.data.can_manage;
  document.getElementById("newPollBtn").hidden = !V.canManage;
  renderPolls();
}

async function vote(pollId) {
  const checked = document.querySelector(`input[name="poll-${pollId}"]:checked`);
  if (!checked) return toast("先选一个选项");
  try {
    const res = await apiPost("polls.php", { action: "vote", poll_id: Number(pollId), option_id: Number(checked.value) });
    if (!res.ok) return toast(res.msg || "投票失败");
    toast("投票成功");
    await loadPolls();
  } catch (e) {
    toast(e.message);
  }
}

async function closePoll(id) {
  if (!confirm("确定提前截止这个投票？")) return;
  const res = await apiPost("polls.php", { action: "close", poll_id: Number(id) });
  if (!res.ok) return toast(res.msg || "操作失败");
  toast("已截止");
  await loadPolls();
}

async function deletePoll(id) {
  if (!confirm("确定删除这个投票？")) return;
  const res = await apiPost("polls.php", { action: "delete", poll_id: Number(id) });
  if (!res.ok) return toast(res.msg || "操作失败");
  toast("已删除");
  await loadPolls();
}

function toDateTimeValue(v) {
  if (!v) return "";
  return String(v).replace(" ", "T").slice(0, 16);
}

document.addEventListener("DOMContentLoaded", async () => {
  initNav();
  V.me = await getMe();
  updateNavUser(V.me);

  const modal = document.getElementById("pollModal");
  document.getElementById("newPollBtn").addEventListener("click", () => {
    const now = new Date();
    const end = new Date(now.getTime() + 7 * 86400000);
    const fmtLocal = (d) => {
      const pad = (n) => String(n).padStart(2, "0");
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };
    document.getElementById("pollStart").value = toDateTimeValue(fmtLocal(now));
    document.getElementById("pollEnd").value = toDateTimeValue(fmtLocal(end));
    modal.classList.add("open");
  });
  document.getElementById("pollClose").addEventListener("click", () => modal.classList.remove("open"));
  modal.addEventListener("click", (e) => {
    if (e.target.id === "pollModal") modal.classList.remove("open");
  });

  document.getElementById("pollForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const options = document
      .getElementById("pollOptions")
      .value.split("\n")
      .map((s) => s.trim())
      .filter(Boolean);
    if (options.length < 2) return toast("至少写 2 个选项");

    try {
      const startVal = document.getElementById("pollStart").value;
      const endVal = document.getElementById("pollEnd").value;
      const res = await apiPost("polls.php", {
        action: "create",
        title: document.getElementById("pollTitle").value.trim(),
        description: document.getElementById("pollDesc").value.trim(),
        options,
        starts_at: startVal ? startVal.replace("T", " ") + ":00" : "",
        ends_at: endVal ? endVal.replace("T", " ") + ":00" : "",
      });
      if (!res.ok) return toast(res.msg || "创建失败");
      toast("投票已创建");
      e.target.reset();
      modal.classList.remove("open");
      await loadPolls();
    } catch (err) {
      toast(err.message);
    }
  });

  document.getElementById("pollList").addEventListener("click", (e) => {
    const voteBtn = e.target.closest("[data-vote]");
    if (voteBtn) return vote(voteBtn.dataset.vote);
    const closeBtn = e.target.closest("[data-close]");
    if (closeBtn) return closePoll(closeBtn.dataset.close);
    const delBtn = e.target.closest("[data-del]");
    if (delBtn) return deletePoll(delBtn.dataset.del);
  });

  await loadPolls();
});
