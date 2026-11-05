/* ===== 每日答题 ===== */
(() => {
  const Q = {
    state: null,
    category: "",
    tab: "answer",
    me: null,
    managePage: 1,
    manageCategory: "",
    manageKeyword: "",
    editing: null,
    manageCache: [],
  };
  const CAT_ICON = { 动漫: "📺", 游戏: "🎮", 其他: "❓" };
  const LETTERS = ["A", "B", "C", "D"];

  const $ = (id) => document.getElementById(id);

  function renderSide() {
    if (!Q.state) return;
    $("quizHeat").textContent = fmt(Q.state.heat, 0);
    const count = Q.state.submit_count || 0;
    const max = 3;
    $("quizSubmitCount").textContent = `${count} / ${max}`;
    $("quizReward").textContent = `${Q.state.reward_per_correct || 30} 热力值`;

    const a = Q.state.attempt;
    if (!a) $("quizAnswered").textContent = "未开始";
    else if (a.status === "pending") $("quizAnswered").textContent = "待交卷";
    else $("quizAnswered").textContent = `答对 ${a.correct_count}/3 · +${a.heat}`;

    $("quizFormTip").textContent = Q.state.submit_remaining > 0
      ? `今天还可以出 ${Q.state.submit_remaining} 道`
      : "今天的出题名额已经用完了";
    $("quizSubmitBtn").disabled = Q.state.submit_remaining <= 0;

    const manageTab = $("quizManageTab");
    if (manageTab) manageTab.hidden = !Q.state.is_admin;
    const createTab = $("quizCreateTab");
    if (createTab) createTab.hidden = false;
  }

  function renderAnswer() {
    const box = $("quizAnswerBody");
    if (!Q.state) {
      box.innerHTML = '<div class="quiz-login"><p>登录后才能答题。</p><a href="index.html#auth">去登录 / 注册 →</a></div>';
      return;
    }

    const a = Q.state.attempt;

    if (!a) {
      const cards = ["动漫", "游戏", "其他"].map((cat) => {
        const count = Number((Q.state.categories || {})[cat] || 0);
        return `<button type="button" class="quiz-cat ${Q.category === cat ? "selected" : ""}" data-qcat="${cat}" ${count < 3 ? "disabled" : ""}>
          <span class="cat-ico">${CAT_ICON[cat]}</span>
          <strong>${cat}</strong>
          <em>题库 ${count} 题${count < 3 ? "（不足）" : ""}</em>
        </button>`;
      }).join("");

      const selectedCount = Q.category ? Number((Q.state.categories || {})[Q.category] || 0) : 0;
      box.innerHTML = `<p class="quiz-intro">每天只能选一个板块，抽 3 道单选题。答对一题 +${Q.state.reward_per_correct || 30} 热力值。</p>
        <div class="quiz-cat-grid">${cards}</div>
        <button type="button" class="quiz-btn" id="quizStart" ${!Q.category || selectedCount < 3 ? "disabled" : ""}>开始今日答题 →</button>
        <p class="quiz-note">今天已经答过的话，明天再来。</p>`;
      return;
    }

    if (a.status === "pending") {
      const questions = a.questions || [];
      box.innerHTML = `<p class="quiz-intro">板块：<strong>${escapeHtml(a.category)}</strong> · 共 3 题，选完一起交卷。</p>
        <div id="quizQuestions">
          ${questions.map((q, index) => `<div class="quiz-question" data-qid="${q.id}">
            <div class="quiz-q-head"><span class="quiz-q-no">${index + 1}</span><span class="quiz-q-id">#${escapeHtml(q.number || "")}</span><span class="quiz-q-text">${escapeHtml(q.question)}</span></div>
            <div class="quiz-options">
              ${LETTERS.map((L) => `<label class="quiz-option"><input type="radio" name="q_${q.id}" value="${L}" /><span class="opt-letter">${L}.</span><span>${escapeHtml(q.options[L] || "")}</span></label>`).join("")}
            </div>
          </div>`).join("")}
        </div>
        <div class="quiz-submit-row">
          <button type="button" class="quiz-btn" id="quizSubmitAnswers">交卷 · 看看答对几题</button>
          <span class="quiz-note">交卷后今天不能再答。</span>
        </div>`;
      return;
    }

    const results = a.results || [];
    box.innerHTML = `<div class="quiz-result-head">
        <span class="quiz-result-score">${a.correct_count} / 3</span>
        <span class="quiz-result-heat">+${a.heat} 热力值</span>
        <span class="quiz-note">板块：${escapeHtml(a.category)} · 明天再来</span>
      </div>
      <div class="quiz-results">
        ${results.map((r, index) => `<div class="quiz-question">
          <div class="quiz-q-head"><span class="quiz-q-no">${index + 1}</span><span class="quiz-q-id">#${escapeHtml(r.number || "")}</span><span class="quiz-q-text">${escapeHtml(r.question)}</span></div>
          <div class="quiz-options">
            ${LETTERS.map((L) => {
              const cls = L === r.correct ? " correct" : (L === r.selected ? " wrong" : "");
              return `<div class="quiz-option${cls}"><span class="opt-letter">${L}.</span><span>${escapeHtml(r.options[L] || "")}</span></div>`;
            }).join("")}
          </div>
          <p class="quiz-note">${r.is_correct ? "✓ 答对了" : `✗ 你选了 ${r.selected || "—"}，正确答案是 ${r.correct}`}</p>
        </div>`).join("")}
      </div>`;
  }

  function renderMine() {
    const box = $("quizMine");
    if (!Q.state) {
      box.innerHTML = "";
      return;
    }
    const list = Q.state.my_questions || [];
    if (!list.length) {
      box.innerHTML = '<p class="quiz-note">你还没有出过题。</p>';
      return;
    }
    box.innerHTML = list.map((q) => `<div class="quiz-mine-item">
      <span class="quiz-q-id">#${escapeHtml(q.number || "")}</span>
      <strong>${escapeHtml(q.category)}</strong> · ${escapeHtml(q.question)}
      ${q.can_edit ? `<button type="button" class="quiz-mini-btn quiz-mine-edit" data-edit-id="${q.id}">修改</button>` : ""}
      <br />
      <span class="muted">正确答案：${escapeHtml(q.correct)} · ${escapeHtml(q.time_ago || "")} ${q.can_edit ? "· 今天可修改" : ""}</span>
    </div>`).join("");
  }

  function renderAll() {
    renderSide();
    renderAnswer();
    renderMine();
  }

  function renderManage(data) {
    const list = data.questions || [];
    Q.manageCache = list;
    $("manageCount").textContent = data.total
      ? `共 ${data.total} 道题 · 第 ${data.page} / ${data.pages} 页`
      : "题库还是空的";

    if (!list.length) {
      $("quizManageList").innerHTML = '<p class="quiz-note">没有找到题目。</p>';
      $("managePager").innerHTML = "";
      return;
    }

    $("quizManageList").innerHTML = `<div class="quiz-manage-list">${list.map((q) => `<div class="quiz-manage-item ${q.status === "hidden" ? "hidden-q" : ""}">
      <div class="quiz-manage-head">
        <span class="quiz-manage-no">#${escapeHtml(q.number || "")}</span>
        <span class="quiz-badge">${escapeHtml(q.category)}</span>
        ${q.status === "hidden" ? '<span class="quiz-badge hidden">已隐藏</span>' : ""}
      </div>
      <p class="quiz-manage-q">${escapeHtml(q.question)}</p>
      <p class="quiz-manage-meta">出题人：${escapeHtml(q.author || "未知")} · ${escapeHtml(q.time_ago || "")} · 正确答案 ${escapeHtml(q.correct)}</p>
      <div class="quiz-manage-actions">
        <button type="button" class="quiz-mini-btn" data-edit-id="${q.id}">编辑</button>
        <button type="button" class="quiz-mini-btn danger" data-toggle-id="${q.id}" data-current="${q.status}">${q.status === "hidden" ? "恢复" : "隐藏"}</button>
      </div>
    </div>`).join("")}</div>`;

    $("managePager").innerHTML = `
      <button type="button" data-mpage="${data.page - 1}" ${data.page <= 1 ? "disabled" : ""}>上一页</button>
      <span>${data.page} / ${data.pages}</span>
      <button type="button" data-mpage="${data.page + 1}" ${data.page >= data.pages ? "disabled" : ""}>下一页</button>`;
  }

  async function loadManage(page = 1) {
    if (!Q.state || !Q.state.is_admin) return;
    const params = new URLSearchParams({ action: "manage", page: String(page) });
    if (Q.manageCategory) params.set("category", Q.manageCategory);
    if (Q.manageKeyword) params.set("q", Q.manageKeyword);
    const res = await apiGet("quiz.php?" + params.toString());
    if (!res.ok) {
      $("quizManageList").innerHTML = `<p class="quiz-note">${escapeHtml(res.msg || "加载失败")}</p>`;
      return;
    }
    Q.managePage = res.data.page;
    renderManage(res.data);
  }

  function openEdit(q) {
    Q.editing = q;
    $("quizEditNumber").textContent = "#" + (q.number || "");
    $("quizEditCategory").value = q.category;
    $("quizEditQuestion").value = q.question;
    $("quizEditOptA").value = q.options.A || "";
    $("quizEditOptB").value = q.options.B || "";
    $("quizEditOptC").value = q.options.C || "";
    $("quizEditOptD").value = q.options.D || "";
    $("quizEditCorrect").value = q.correct;
    const dialog = $("quizEditDialog");
    if (!dialog.open) dialog.showModal();
  }

  function closeEdit() {
    Q.editing = null;
    const dialog = $("quizEditDialog");
    if (dialog.open) dialog.close();
  }

  async function saveEdit(e) {
    e.preventDefault();
    if (!Q.editing) return;
    const payload = {
      action: "edit_question",
      id: Q.editing.id,
      category: $("quizEditCategory").value,
      question: $("quizEditQuestion").value.trim(),
      options: ["A", "B", "C", "D"].map((L) => $("quizEditOpt" + L).value.trim()),
      correct: $("quizEditCorrect").value,
    };
    try {
      const res = await apiPost("quiz.php", payload);
      if (!res.ok) {
        toast(res.msg || "保存失败");
        return;
      }
      closeEdit();
      toast("题目已保存");
      await loadState();
      if (Q.tab === "manage") loadManage(Q.managePage);
    } catch (err) {
      toast(err.message);
    }
  }

  async function toggleQuestion(id) {
    if (!confirm("确定要隐藏 / 恢复这道题吗？")) return;
    try {
      const res = await apiPost("quiz.php", { action: "hide_question", id });
      if (!res.ok) {
        toast(res.msg || "操作失败");
        return;
      }
      toast(res.msg || "已更新");
      loadManage(Q.managePage);
    } catch (err) {
      toast(err.message);
    }
  }

  async function loadState() {
    const res = await apiGet("quiz.php?action=state");
    if (!res.ok) {
      Q.state = null;
      Q.tab = "answer";
      $("quizHeat").textContent = "--";
      $("quizAnswered").textContent = "未登录";
      $("quizCreateTab").hidden = true;
      $("quizManageTab").hidden = true;
      $("quizCreate").hidden = true;
      $("quizManage").hidden = true;
      $("quizAnswer").hidden = false;
      $("quizAnswerBody").innerHTML = `<div class="quiz-login"><p>${escapeHtml(res.msg || "请先登录")}</p><a href="index.html#auth">去登录 / 注册 →</a></div>`;
      return;
    }
    Q.state = res.data;
    if (!Q.state.is_admin && Q.tab === "manage") Q.tab = "answer";
    if (!Q.category) {
      const cats = Q.state.categories || {};
      Q.category = ["动漫", "游戏", "其他"].find((c) => Number(cats[c] || 0) >= 3) || "";
    }
    renderAll();
  }

  async function startQuiz() {
    if (!Q.category) return toast("先选一个板块");
    try {
      const res = await apiPost("quiz.php", { action: "start", category: Q.category });
      if (!res.ok) {
        toast(res.msg || "抽题失败");
        if ((res.msg || "").includes("已经")) loadState();
        return;
      }
      Q.state.attempt = res.data.attempt;
      renderAll();
      toast("抽题成功");
    } catch (e) {
      toast(e.message);
    }
  }

  async function submitAnswers() {
    const answers = {};
    document.querySelectorAll("#quizAnswerBody .quiz-question").forEach((card) => {
      const id = card.dataset.qid;
      const checked = card.querySelector('input[type="radio"]:checked');
      if (checked) answers[id] = checked.value;
    });
    if (Object.keys(answers).length < 3) return toast("请把三题都选完");

    try {
      const res = await apiPost("quiz.php", { action: "submit", answers });
      if (!res.ok) {
        toast(res.msg || "交卷失败");
        if ((res.msg || "").includes("交过")) loadState();
        return;
      }
      Q.state.attempt = res.data.attempt;
      Q.state.heat = res.data.heat;
      renderAll();
      toast(`答对 ${res.data.attempt.correct_count} 题，+${res.data.earned} 热力值`);
    } catch (e) {
      toast(e.message);
    }
  }

  function switchTab(tab) {
    if ((tab === "create" || tab === "manage") && !Q.state) {
      toast("请先登录");
      return;
    }
    if (tab === "manage" && !Q.state.is_admin) return;
    Q.tab = tab;
    document.querySelectorAll(".quiz-tab").forEach((b) => b.classList.toggle("active", b.dataset.qtab === tab));
    $("quizAnswer").hidden = tab !== "answer";
    $("quizCreate").hidden = tab !== "create";
    $("quizManage").hidden = tab !== "manage";
    if (tab === "create") renderMine();
    if (tab === "manage") {
      Q.manageCategory = $("manageCategory").value;
      Q.manageKeyword = $("manageSearch").value.trim();
      loadManage(1);
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    initNav();
    Q.me = await getMe();
    updateNavUser(Q.me);

    document.querySelectorAll(".quiz-tab").forEach((btn) => {
      btn.addEventListener("click", () => switchTab(btn.dataset.qtab));
    });

    $("quizAnswerBody").addEventListener("click", (e) => {
      const cat = e.target.closest("[data-qcat]");
      if (cat) {
        Q.category = cat.dataset.qcat;
        renderAnswer();
        return;
      }
      if (e.target.closest("#quizStart")) startQuiz();
      if (e.target.closest("#quizSubmitAnswers")) submitAnswers();
    });

    $("quizMine").addEventListener("click", (e) => {
      const btn = e.target.closest("[data-edit-id]");
      if (!btn || !Q.state) return;
      const q = (Q.state.my_questions || []).find((x) => String(x.id) === String(btn.dataset.editId));
      if (q) openEdit(q);
    });

    $("quizManageList").addEventListener("click", (e) => {
      const editBtn = e.target.closest("[data-edit-id]");
      if (editBtn) {
        const id = editBtn.dataset.editId;
        const res = Q.manageCache && Q.manageCache.find((x) => String(x.id) === String(id));
        if (res) openEdit(res);
        return;
      }
      const toggleBtn = e.target.closest("[data-toggle-id]");
      if (toggleBtn) toggleQuestion(toggleBtn.dataset.toggleId);
    });

    $("managePager").addEventListener("click", (e) => {
      const btn = e.target.closest("[data-mpage]");
      if (btn && !btn.disabled) loadManage(Number(btn.dataset.mpage));
    });

    $("manageReload").addEventListener("click", () => {
      Q.manageCategory = $("manageCategory").value;
      Q.manageKeyword = $("manageSearch").value.trim();
      loadManage(1);
    });

    $("manageSearch").addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        $("manageReload").click();
      }
    });

    $("quizEditCancel").addEventListener("click", closeEdit);
    $("quizEditForm").addEventListener("submit", saveEdit);
    $("quizEditDialog").addEventListener("click", (e) => {
      if (e.target === $("quizEditDialog")) closeEdit();
    });

    $("quizForm").addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!Q.state) return toast("登录后才能出题");
      const options = ["A", "B", "C", "D"].map((L) => $("quizOpt" + L).value.trim());
      const payload = {
        action: "create_question",
        category: $("quizCategory").value,
        question: $("quizQuestion").value.trim(),
        options,
        correct: $("quizCorrect").value,
      };
      try {
        const res = await apiPost("quiz.php", payload);
        if (!res.ok) {
          toast(res.msg || "提交失败");
          return;
        }
        toast("题目已加入题库");
        e.target.reset();
        await loadState();
        switchTab("create");
      } catch (err) {
        toast(err.message);
      }
    });

    await loadState();
  });
})();
