/* ===== Clovers 像素农场 ===== */
const F = {
  state: null,
  visiting: null,
  crops: {},
  plantIndex: null,
  tick: null,
};

const el = (id) => document.getElementById(id);

function farmMoney(n) {
  return Number(n || 0).toLocaleString("zh-CN", { maximumFractionDigits: 0 });
}

function farmAvatar(user) {
  if (user && user.avatar_url) {
    return `<span class="farmer-avatar"><img src="${escapeHtml(user.avatar_url)}" alt="" /></span>`;
  }
  return `<span class="farmer-avatar">${escapeHtml((user && user.nickname || "🍀").slice(0, 1))}</span>`;
}

function farmGrowText(seconds) {
  const s = Math.max(0, Math.floor(Number(seconds) || 0));
  if (s >= 3600) {
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return `${h} 小时${m ? " " + m + " 分" : ""}`;
  }
  if (s >= 60) {
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m} 分${r ? " " + r + " 秒" : ""}`;
  }
  return `${s} 秒`;
}

function farmCropInfo(key) {
  return F.crops[key] || { name: key, emoji: "🌱", cost: 0, sell: 0, grow: 60 };
}

function farmRemaining(slot) {
  if (!slot._endsAt) return slot.remaining || 0;
  return Math.max(0, Math.ceil((slot._endsAt - Date.now()) / 1000));
}

function renderFarm() {
  const data = F.visiting || F.state;
  if (!data) return;

  const own = !F.visiting;
  el("farmOwner").textContent = own ? "我的农场" : `${data.user.nickname} 的农场`;
  el("backOwn").hidden = own;
  el("farmSub").textContent = own
    ? "用热力值买种子，成熟后卖出；也可以去别人家偷菜。"
    : `正在逛 ${data.user.nickname} 的农场，成熟的作物可以偷一份。`;

  if (data.steal_remaining != null) {
    el("stealLeft").textContent = farmMoney(data.steal_remaining);
  }

  const plots = data.plots || [];
  let crops = 0;
  const html = plots.map((slot) => {
    if (slot.empty) {
      if (own) {
        return `<div class="plot empty">
          <span class="plot-emoji">＋</span>
          <span class="plot-name">空地</span>
          <button type="button" class="farm-btn farm-btn-small" data-plant="${slot.index}">种点什么</button>
        </div>`;
      }
      return `<div class="plot empty"><span class="plot-emoji">🟫</span><span class="plot-name">空地</span></div>`;
    }

    crops++;
    const remaining = farmRemaining(slot);
    if (remaining <= 0) {
      slot.ready = true;
      if (!own) slot.can_steal = !slot.stolen;
    }
    const pct = slot.grow ? Math.min(100, Math.max(0, Math.round((1 - remaining / slot.grow) * 100))) : 0;
    const badge = slot.stolen
      ? '<span class="plot-flag stolen">被偷过</span>'
      : (slot.ready ? '<span class="plot-flag">成熟</span>' : "");

    let actions = "";
    if (own && slot.ready) {
      actions = `<button type="button" class="farm-btn farm-btn-gold farm-btn-small" data-harvest="${slot.id}">收获 · ${farmMoney(slot.payout)}</button>`;
    } else if (!own && slot.can_steal && !slot.stolen && slot.ready) {
      actions = `<button type="button" class="farm-btn farm-btn-red farm-btn-small" data-steal="${slot.id}">偷菜 +${farmMoney(slot.steal_reward)}</button>`;
    } else if (own) {
      actions = `<span class="plot-time">还需 ${farmGrowText(remaining)}</span>`;
    } else if (slot.stolen) {
      actions = `<span class="plot-time">已经被偷过了</span>`;
    } else {
      actions = `<span class="plot-time">还需 ${farmGrowText(remaining)}</span>`;
    }

    return `<div class="plot ${slot.ready ? "ready" : ""}">
      ${badge}
      <span class="plot-emoji ${slot.ready ? "" : "grow"}">${slot.emoji}</span>
      <span class="plot-name">${escapeHtml(slot.name)}</span>
      <div class="plot-progress"><i style="width:${pct}%"></i></div>
      <div class="plot-actions">${actions}</div>
    </div>`;
  }).join("");

  el("farmPlots").innerHTML = html;
  el("cropCount").textContent = crops;
  el("farmTip").textContent = own
    ? "点击空地种菜；成熟后收获卖成热力值。去右边「别人家」可以偷已经成熟的作物。"
    : "只有成熟且没被偷过的作物才能偷；偷菜每天有热力值上限。";
}

function renderSeeds() {
  const crops = F.crops || {};
  el("seedList").innerHTML = Object.entries(crops).map(([key, c]) => `
    <div class="seed-item">
      <span class="s-emoji">${c.emoji}</span>
      <div><strong>${escapeHtml(c.name)}</strong><em>种子 ${farmMoney(c.cost)} · 卖 ${farmMoney(c.sell)} · ${farmGrowText(c.grow)}</em></div>
      <button type="button" class="farm-btn farm-btn-small" data-buy="${key}">种下</button>
    </div>
  `).join("");
}

function renderSeedPick() {
  const crops = F.crops || {};
  el("seedPick").innerHTML = Object.entries(crops).map(([key, c]) => `
    <button type="button" data-pick="${key}">
      <span class="p-emoji">${c.emoji}</span>
      <strong>${escapeHtml(c.name)}</strong>
      <span>种子 ${farmMoney(c.cost)} · 卖 ${farmMoney(c.sell)}</span>
      <span>${farmGrowText(c.grow)}成熟</span>
    </button>
  `).join("");
}

async function loadFarm() {
  const res = await apiGet("farm.php?action=state");
  if (!res.ok) {
    el("farmPlots").innerHTML = `<p class="farm-empty">${escapeHtml(res.msg || "请先登录")} · <a href="index.html#auth">去登录</a></p>`;
    el("farmHeat").textContent = "--";
    el("seedList").innerHTML = '<p class="farm-empty">登录后才能买种子</p>';
    return;
  }

  F.state = res.data;
  F.visiting = null;
  F.crops = res.data.crops || F.crops || {};
  (F.state.plots || []).forEach((p) => {
    if (!p.empty) p._endsAt = Date.now() + (p.remaining || 0) * 1000;
  });

  el("farmHeat").textContent = farmMoney(res.data.user.heat);
  renderSeeds();
  renderFarm();
  await loadFarmers();
}

async function loadFarmers() {
  const res = await apiGet("farm.php?action=users");
  if (!res.ok) {
    el("farmerList").innerHTML = `<p class="farm-empty">${escapeHtml(res.msg || "加载失败")}</p>`;
    return;
  }
  if (!res.data.items.length) {
    el("farmerList").innerHTML = '<p class="farm-empty">还没有别的农场，先自己种一片吧。</p>';
    return;
  }
  el("farmerList").innerHTML = res.data.items.map((u) => `
    <div class="farmer-item" data-visit="${u.id}">
      ${farmAvatar(u)}
      <div class="farmer-meta"><strong>${escapeHtml(u.nickname)}</strong><span>${u.ripe ? "有成熟作物" : "农场安静"}</span></div>
      <span class="farmer-ripe">${u.ripe ? "🥕 " + u.ripe : ""}</span>
    </div>
  `).join("");
}

async function visitFarm(id) {
  const res = await apiGet("farm.php?action=visit&id=" + encodeURIComponent(id));
  if (!res.ok) return toast(res.msg || "进不去这个农场");
  F.visiting = res.data;
  F.visiting.plots.forEach((p) => {
    if (!p.empty) p._endsAt = Date.now() + (p.remaining || 0) * 1000;
  });
  renderFarm();
}

async function plant(index, crop) {
  const res = await apiPost("farm.php", { action: "plant", plot_index: index, crop });
  if (!res.ok) return toast(res.msg || "种不下去");
  toast(`种下了 ${F.crops[crop] ? F.crops[crop].name : crop}`);
  closeSeed();
  await loadFarm();
}

async function harvest(plotId) {
  const res = await apiPost("farm.php", { action: "harvest", plot_id: plotId });
  if (!res.ok) return toast(res.msg || "收获失败");
  toast(`卖出 +${farmMoney(res.data.payout)} 热力值`);
  await loadFarm();
}

async function steal(plotId) {
  const res = await apiPost("farm.php", { action: "steal", plot_id: plotId });
  if (!res.ok) return toast(res.msg || "偷不到");
  toast(`偷到 +${farmMoney(res.data.reward)} 热力值`);
  el("farmHeat").textContent = farmMoney(res.data.heat);
  await visitFarm(F.visiting.user.id);
  await loadFarmers();
}

function openSeed(index) {
  F.plantIndex = index;
  renderSeedPick();
  el("seedModal").hidden = false;
}

function closeSeed() {
  el("seedModal").hidden = true;
  F.plantIndex = null;
}

function plantToFirst(crop) {
  if (!F.state) return;
  const empty = (F.state.plots || []).find((p) => p.empty);
  if (!empty) return toast("四块地都种满了");
  plant(empty.index, crop);
}

document.addEventListener("DOMContentLoaded", () => {
  renderSeeds();

  el("farmPlots").addEventListener("click", (e) => {
    const plantBtn = e.target.closest("[data-plant]");
    if (plantBtn) return openSeed(Number(plantBtn.dataset.plant));
    const harvestBtn = e.target.closest("[data-harvest]");
    if (harvestBtn) return harvest(Number(harvestBtn.dataset.harvest));
    const stealBtn = e.target.closest("[data-steal]");
    if (stealBtn) return steal(Number(stealBtn.dataset.steal));
  });

  el("seedList").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-buy]");
    if (!btn) return;
    plantToFirst(btn.dataset.buy);
  });

  el("seedPick").addEventListener("click", (e) => {
    const btn = e.target.closest("[data-pick]");
    if (!btn || F.plantIndex == null) return;
    plant(F.plantIndex, btn.dataset.pick);
  });

  el("farmerList").addEventListener("click", (e) => {
    const item = e.target.closest("[data-visit]");
    if (item) visitFarm(Number(item.dataset.visit));
  });

  el("backOwn").addEventListener("click", loadFarm);
  el("seedClose").addEventListener("click", closeSeed);
  el("seedModal").addEventListener("click", (e) => {
    if (e.target === el("seedModal")) closeSeed();
  });

  F.tick = setInterval(() => {
    if (F.state || F.visiting) renderFarm();
  }, 1000);

  loadFarm();
});
