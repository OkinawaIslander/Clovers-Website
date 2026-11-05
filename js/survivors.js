/* ===== 四叶草幸存者 ===== */
(() => {
  const canvas = document.getElementById("svCanvas");
  const ctx = canvas.getContext("2d");
  const WORLD = { w: 2600, h: 2000 };
  const DURATION = 180;
  const BEST_KEY = "clovers_survivors_best";

  const view = { w: 0, h: 0, dpr: 1 };
  const cam = { x: 0, y: 0 };
  const keys = new Set();
  let state = null;
  let last = 0;
  let idSeq = 1;
  let shake = 0;
  let me = null;

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const rand = (a, b) => a + Math.random() * (b - a);
  const dist2 = (ax, ay, bx, by) => (ax - bx) ** 2 + (ay - by) ** 2;

  function resize() {
    view.dpr = Math.min(2, window.devicePixelRatio || 1);
    view.w = window.innerWidth;
    view.h = window.innerHeight;
    canvas.width = Math.floor(view.w * view.dpr);
    canvas.height = Math.floor(view.h * view.dpr);
    canvas.style.width = view.w + "px";
    canvas.style.height = view.h + "px";
  }
  window.addEventListener("resize", resize);
  resize();

  const UPGRADES = [
    { id: "dmg", icon: "🍀", name: "四叶草强化", desc: "所有攻击伤害 +25%", apply: (s) => { s.damage *= 1.25; } },
    { id: "rate", icon: "⚡", name: "高频发射", desc: "自动攻击间隔 -18%", apply: (s) => { s.atkInterval = Math.max(0.16, s.atkInterval * 0.82); } },
    { id: "speed", icon: "👟", name: "轻快脚步", desc: "移动速度 +8%", apply: (s) => { s.speed *= 1.08; } },
    { id: "hp", icon: "💗", name: "生命力", desc: "最大生命 +20，并回复 25", apply: (s) => { s.maxHp += 20; s.hp = Math.min(s.maxHp, s.hp + 25); } },
    { id: "magnet", icon: "🧲", name: "拾取范围", desc: "经验吸取范围 +45", apply: (s) => { s.magnet += 45; } },
    { id: "orb", icon: "🪄", name: "光棒 +1", desc: "环绕身体的光棒多一根", apply: (s) => { s.orbs = Math.min(8, s.orbs + 1); } },
    { id: "shot", icon: "✨", name: "散射 +1", desc: "每次多发射一枚四叶草弹", apply: (s) => { s.shots = Math.min(7, s.shots + 1); } },
    { id: "pierce", icon: "🎯", name: "穿透", desc: "四叶草弹可以多穿透一个敌人", apply: (s) => { s.pierce += 1; } },
    { id: "heal", icon: "🧃", name: "休息一下", desc: "立即回复 35 点生命", apply: (s) => { s.hp = Math.min(s.maxHp, s.hp + 35); } },
  ];

  function newState() {
    return {
      running: true,
      paused: false,
      over: false,
      time: 0,
      kills: 0,
      level: 1,
      xp: 0,
      xpNext: 10,
      hp: 100,
      maxHp: 100,
      speed: 235,
      px: WORLD.w / 2,
      py: WORLD.h / 2,
      inv: 0,
      atkTimer: 0.25,
      atkInterval: 0.52,
      damage: 10,
      shots: 1,
      pierce: 0,
      magnet: 90,
      orbs: 2,
      orbAngle: 0,
      enemies: [],
      bullets: [],
      ebullets: [],
      gems: [],
      parts: [],
      spawnTimer: 0.6,
      bossSpawned: false,
      submitted: false,
      touch: { active: false, id: null, sx: 0, sy: 0, dx: 0, dy: 0 },
    };
  }

  /* ---------- 输入 ---------- */
  window.addEventListener("keydown", (e) => {
    const k = e.key.toLowerCase();
    if (["w", "a", "s", "d", "arrowup", "arrowdown", "arrowleft", "arrowright"].includes(k)) {
      e.preventDefault();
      keys.add(k);
    }
    if (k === "p" || k === "escape") togglePause();
  });
  window.addEventListener("keyup", (e) => keys.delete(e.key.toLowerCase()));
  window.addEventListener("blur", () => keys.clear());

  canvas.addEventListener("pointerdown", (e) => {
    if (!state || !state.running || state.paused || state.over) return;
    state.touch.active = true;
    state.touch.id = e.pointerId;
    state.touch.sx = e.clientX;
    state.touch.sy = e.clientY;
    state.touch.dx = 0;
    state.touch.dy = 0;
    try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* 某些浏览器不支持 */ }
  });
  canvas.addEventListener("pointermove", (e) => {
    if (!state || !state.touch.active || e.pointerId !== state.touch.id) return;
    const dx = (e.clientX - state.touch.sx) / 55;
    const dy = (e.clientY - state.touch.sy) / 55;
    state.touch.dx = clamp(dx, -1, 1);
    state.touch.dy = clamp(dy, -1, 1);
  });
  const endTouch = (e) => {
    if (!state || e.pointerId !== state.touch.id) return;
    state.touch.active = false;
    state.touch.id = null;
    state.touch.dx = 0;
    state.touch.dy = 0;
  };
  canvas.addEventListener("pointerup", endTouch);
  canvas.addEventListener("pointercancel", endTouch);

  /* ---------- 手机摇杆 ---------- */
  let joyId = null;
  const joyBase = document.querySelector(".sv-joy-base");
  const joyKnob = document.getElementById("svJoyKnob");

  function joyMove(e) {
    if (!state || joyId === null || e.pointerId !== joyId) return;
    const rect = joyBase.getBoundingClientRect();
    const cx = rect.left + rect.width / 2;
    const cy = rect.top + rect.height / 2;
    let dx = e.clientX - cx;
    let dy = e.clientY - cy;
    const max = rect.width * 0.28;
    const len = Math.hypot(dx, dy);
    if (len > max) {
      dx = (dx / len) * max;
      dy = (dy / len) * max;
    }
    joyKnob.style.transform = `translate(${dx}px, ${dy}px)`;
    state.touch.active = true;
    state.touch.dx = dx / max;
    state.touch.dy = dy / max;
  }

  function joyEnd(e) {
    if (joyId === null || (e && e.pointerId !== joyId)) return;
    joyId = null;
    joyKnob.style.transform = "translate(0, 0)";
    if (state) {
      state.touch.active = false;
      state.touch.dx = 0;
      state.touch.dy = 0;
    }
  }

  if (joyBase) {
    joyBase.addEventListener("pointerdown", (e) => {
      if (!state || !state.running || state.paused || state.over) return;
      e.preventDefault();
      joyId = e.pointerId;
      try { joyBase.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      joyMove(e);
    });
    joyBase.addEventListener("pointermove", joyMove);
    joyBase.addEventListener("pointerup", joyEnd);
    joyBase.addEventListener("pointercancel", joyEnd);
  }

  /* ---------- 游戏流程 ---------- */
  function startGame() {
    state = newState();
    idSeq = 1;
    shake = 0;
    document.getElementById("svStart").hidden = true;
    document.getElementById("svEnd").hidden = true;
    document.getElementById("svPause").hidden = true;
    document.getElementById("svLevelModal").hidden = true;
    document.getElementById("svHeatResult").textContent = "";
    updateHud();
    last = performance.now();
  }

  function togglePause() {
    if (!state || !state.running || state.over) return;
    if (!document.getElementById("svLevelModal").hidden) return;
    state.paused = !state.paused;
    document.getElementById("svPause").hidden = !state.paused;
    if (!state.paused) last = performance.now();
  }

  function endGame(win) {
    if (!state) return;
    state.running = false;
    state.over = true;
    state.paused = false;
    document.getElementById("svLevelModal").hidden = true;
    document.getElementById("svPause").hidden = true;

    let best = 0;
    try {
      best = Number(localStorage.getItem(BEST_KEY) || 0);
    } catch (e) { /* ignore */ }
    if (state.time > best) {
      best = state.time;
      try {
        localStorage.setItem(BEST_KEY, String(Math.floor(best)));
      } catch (e) { /* ignore */ }
    }

    const sec = Math.floor(state.time);
    document.getElementById("svEndKicker").textContent = win ? "SURVIVED" : "GAME OVER";
    document.getElementById("svEndTitle").textContent = win ? "你活到了天亮" : "被枯叶埋住了";
    document.getElementById("svEndStats").innerHTML =
      `存活 <b>${sec}</b> 秒 · 击杀 <b>${state.kills}</b> · 等级 <b>${state.level}</b><br>最高纪录：${Math.floor(best)} 秒`;
    document.getElementById("svEnd").hidden = false;
    document.getElementById("svBest").textContent = `最高纪录：${Math.floor(best)} 秒`;
    document.getElementById("svHeatResult").textContent = "";
    submitHeat();
  }

  async function submitHeat() {
    if (!state || state.submitted) return;
    state.submitted = true;

    const score = Math.max(1, Math.floor(state.time * 4 + state.kills * 2 + state.level * 10));
    const el = document.getElementById("svHeatResult");
    if (!me || !me.logged_in) {
      el.textContent = `本局得分 ${score}。登录后可以把得分兑换成热力值。`;
      return;
    }

    el.textContent = "正在结算热力值...";
    try {
      const res = await fetch("api/game_score.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ game: "survivors", score }),
      });
      const data = await res.json();
      if (!data.ok) {
        el.textContent = data.msg || "兑换失败";
        return;
      }
      me.heat = data.data.heat;
      document.getElementById("svHeat").textContent = Math.round(data.data.heat);
      el.textContent = `本局得分 ${score} → +${Math.round(data.data.earned)} 热力值（今日还可兑换 ${Math.round(data.data.remaining_cap)}）`;
    } catch (e) {
      el.textContent = "兑换失败，请稍后再试";
    }
  }

  function maybeLevelUp() {
    if (!state || !state.running || state.over || state.paused) return false;
    if (state.xp < state.xpNext) return false;
    state.xp -= state.xpNext;
    state.level += 1;
    state.xpNext = Math.floor(state.xpNext * 1.32) + 5;
    openLevelUp();
    return true;
  }

  function openLevelUp() {
    state.paused = true;
    const pool = UPGRADES.slice();
    const picks = [];
    while (picks.length < 3 && pool.length) {
      picks.push(pool.splice(Math.floor(Math.random() * pool.length), 1)[0]);
    }
    document.getElementById("svUpgrades").innerHTML = picks
      .map((u) => `<button type="button" class="sv-upgrade" data-id="${u.id}">
        <span class="u-ico">${u.icon}</span>
        <strong>${u.name}</strong>
        <span>${u.desc}</span>
      </button>`)
      .join("");
    document.getElementById("svLevelModal").hidden = false;

    document.querySelectorAll(".sv-upgrade").forEach((btn) => {
      btn.onclick = () => {
        const u = UPGRADES.find((x) => x.id === btn.dataset.id);
        if (u) u.apply(state);
        document.getElementById("svLevelModal").hidden = true;
        state.paused = false;
        last = performance.now();
        updateHud();
        if (state.xp >= state.xpNext) setTimeout(maybeLevelUp, 80);
      };
    });
  }

  /* ---------- 生成 ---------- */
  function spawnEnemy(forceType) {
    if (state.enemies.length > 240) return;
    const t = state.time;
    let type = forceType || "leaf";
    if (!forceType) {
      const r = Math.random();
      if (t > 55 && r < 0.18) type = "bat";
      else if (t > 25 && r < 0.46) type = "keyboard";
    }

    const angle = Math.random() * Math.PI * 2;
    const radius = Math.max(view.w, view.h) * 0.62 + rand(80, 220);
    let x = state.px + Math.cos(angle) * radius;
    let y = state.py + Math.sin(angle) * radius;
    x = clamp(x, 30, WORLD.w - 30);
    y = clamp(y, 30, WORLD.h - 30);

    const base = {
      id: idSeq++,
      x, y,
      dead: false,
      orbCd: 0,
      hitFlash: 0,
      shootTimer: rand(1.5, 3),
    };

    if (type === "keyboard") {
      Object.assign(base, {
        type, emoji: "⌨️", r: 13, hp: 9 + t * 0.16, maxHp: 9 + t * 0.16,
        speed: rand(95, 125), dmg: 6, xp: 1,
      });
    } else if (type === "bat") {
      Object.assign(base, {
        type, emoji: "🦇", r: 16, hp: 22 + t * 0.28, maxHp: 22 + t * 0.28,
        speed: rand(58, 78), dmg: 9, xp: 2,
      });
    } else if (type === "boss") {
      Object.assign(base, {
        type, emoji: "👹", r: 46, hp: 900, maxHp: 900,
        speed: 46, dmg: 18, xp: 60, boss: true,
      });
    } else {
      Object.assign(base, {
        type: "leaf", emoji: "🍂", r: 15, hp: 13 + t * 0.2, maxHp: 13 + t * 0.2,
        speed: rand(48, 72), dmg: 8, xp: 1,
      });
    }
    state.enemies.push(base);
  }

  function spawnBoss() {
    state.bossSpawned = true;
    const angle = Math.random() * Math.PI * 2;
    const radius = Math.max(view.w, view.h) * 0.55;
    const base = {
      id: idSeq++,
      x: clamp(state.px + Math.cos(angle) * radius, 60, WORLD.w - 60),
      y: clamp(state.py + Math.sin(angle) * radius, 60, WORLD.h - 60),
      dead: false, orbCd: 0, hitFlash: 0, shootTimer: 1.4,
      type: "boss", emoji: "👹", r: 46, hp: 900, maxHp: 900,
      speed: 46, dmg: 18, xp: 60, boss: true,
    };
    state.enemies.push(base);
    shake = 10;
  }

  function fireBullet(enemy) {
    const baseAngle = Math.atan2(enemy.y - state.py, enemy.x - state.px);
    const n = state.shots;
    for (let i = 0; i < n; i++) {
      const off = (i - (n - 1) / 2) * 0.16;
      state.bullets.push({
        x: state.px,
        y: state.py,
        vx: Math.cos(baseAngle + off) * 540,
        vy: Math.sin(baseAngle + off) * 540,
        r: 7,
        dmg: state.damage,
        pierce: state.pierce,
        life: 1.35,
      });
    }
  }

  function dropGem(x, y, value) {
    state.gems.push({ x, y, value, phase: Math.random() * 6 });
  }

  function burst(x, y, color, count) {
    for (let i = 0; i < count; i++) {
      const a = Math.random() * Math.PI * 2;
      const sp = rand(30, 130);
      state.parts.push({
        x, y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp,
        life: rand(0.25, 0.6), max: 0.6, color, size: rand(2, 4),
      });
    }
  }

  /* ---------- 更新 ---------- */
  function update(dt) {
    const s = state;
    s.time += dt;

    if (s.time >= DURATION) {
      endGame(true);
      return;
    }

    if (!s.bossSpawned && s.time >= 120) spawnBoss();

    let dx = 0;
    let dy = 0;
    if (keys.has("a") || keys.has("arrowleft")) dx -= 1;
    if (keys.has("d") || keys.has("arrowright")) dx += 1;
    if (keys.has("w") || keys.has("arrowup")) dy -= 1;
    if (keys.has("s") || keys.has("arrowdown")) dy += 1;
    if (s.touch.active) {
      dx += s.touch.dx;
      dy += s.touch.dy;
    }
    const len = Math.hypot(dx, dy);
    if (len > 0) {
      dx /= len;
      dy /= len;
    }
    s.px = clamp(s.px + dx * s.speed * dt, 26, WORLD.w - 26);
    s.py = clamp(s.py + dy * s.speed * dt, 26, WORLD.h - 26);

    if (s.inv > 0) s.inv -= dt;

    // 自动攻击
    s.atkTimer -= dt;
    if (s.atkTimer <= 0) {
      let best = null;
      let bd = Infinity;
      for (const e of s.enemies) {
        if (e.dead) continue;
        const d = dist2(s.px, s.py, e.x, e.y);
        if (d < bd) { bd = d; best = e; }
      }
      if (best) {
        fireBullet(best);
        s.atkTimer = s.atkInterval;
      } else {
        s.atkTimer = 0.2;
      }
    }

    // 生成
    s.spawnTimer -= dt;
    if (s.spawnTimer <= 0) {
      const count = 1 + Math.floor(s.time / 45);
      for (let i = 0; i < count; i++) spawnEnemy();
      s.spawnTimer = Math.max(0.3, 1.15 - s.time * 0.0046);
    }

    // 荧光棒旋转
    s.orbAngle += dt * 2.3;

    // 玩家子弹
    for (const b of s.bullets) {
      b.x += b.vx * dt;
      b.y += b.vy * dt;
      b.life -= dt;
      for (const e of s.enemies) {
        if (e.dead) continue;
        if (dist2(b.x, b.y, e.x, e.y) < (b.r + e.r) ** 2) {
          e.hp -= b.dmg;
          e.hitFlash = 0.12;
          if (e.hp <= 0 && !e.dead) {
            e.dead = true;
            s.kills++;
            dropGem(e.x, e.y, e.xp);
            burst(e.x, e.y, e.boss ? "#ff8fc7" : "#9adf9a", e.boss ? 40 : 8);
            if (e.boss) shake = 14;
          }
          if (b.pierce > 0) b.pierce--;
          else { b.life = 0; break; }
        }
      }
    }
    s.bullets = s.bullets.filter((b) => b.life > 0 && b.x > -50 && b.x < WORLD.w + 50 && b.y > -50 && b.y < WORLD.h + 50);

    // 敌人
    for (const e of s.enemies) {
      if (e.dead) continue;
      if (e.orbCd > 0) e.orbCd -= dt;
      if (e.hitFlash > 0) e.hitFlash -= dt;

      const ang = Math.atan2(s.py - e.y, s.px - e.x);
      e.x += Math.cos(ang) * e.speed * dt;
      e.y += Math.sin(ang) * e.speed * dt;

      if (e.type === "bat" || e.boss) {
        e.shootTimer -= dt;
        if (e.shootTimer <= 0) {
          if (e.boss) {
            const n = 14;
            for (let i = 0; i < n; i++) {
              const a = (i / n) * Math.PI * 2 + s.time * 0.2;
              s.ebullets.push({
                x: e.x, y: e.y, vx: Math.cos(a) * 155, vy: Math.sin(a) * 155,
                r: 8, dmg: 8, life: 4,
              });
            }
            e.shootTimer = 2.1;
          } else {
            s.ebullets.push({
              x: e.x, y: e.y,
              vx: Math.cos(ang) * 190, vy: Math.sin(ang) * 190,
              r: 6, dmg: 6, life: 4,
            });
            e.shootTimer = rand(2.4, 3.6);
          }
        }
      }

      // 荧光棒碰撞
      for (let i = 0; i < s.orbs; i++) {
        const a = s.orbAngle + (i / s.orbs) * Math.PI * 2;
        const ox = s.px + Math.cos(a) * 88;
        const oy = s.py + Math.sin(a) * 88;
        if (e.orbCd <= 0 && dist2(ox, oy, e.x, e.y) < (e.r + 13) ** 2) {
          e.hp -= 7 * s.damage / 10;
          e.hitFlash = 0.1;
          e.orbCd = 0.42;
          if (e.hp <= 0 && !e.dead) {
            e.dead = true;
            s.kills++;
            dropGem(e.x, e.y, e.xp);
            burst(e.x, e.y, "#8fe3b0", e.boss ? 40 : 7);
          }
        }
      }

      // 接触伤害
      if (!e.dead && s.inv <= 0 && dist2(e.x, e.y, s.px, s.py) < (e.r + 15) ** 2) {
        s.hp -= e.dmg;
        s.inv = 0.85;
        shake = Math.max(shake, 7);
        burst(s.px, s.py, "#ff6b8f", 10);
        if (s.hp <= 0) {
          s.hp = 0;
          updateHud();
          endGame(false);
          return;
        }
      }
    }
    s.enemies = s.enemies.filter((e) => !e.dead);

    // 敌人子弹
    for (const b of s.ebullets) {
      b.x += b.vx * dt;
      b.y += b.vy * dt;
      b.life -= dt;
      if (s.inv <= 0 && dist2(b.x, b.y, s.px, s.py) < (b.r + 14) ** 2) {
        b.life = 0;
        s.hp -= b.dmg;
        s.inv = 0.7;
        shake = Math.max(shake, 5);
        if (s.hp <= 0) {
          s.hp = 0;
          updateHud();
          endGame(false);
          return;
        }
      }
    }
    s.ebullets = s.ebullets.filter((b) => b.life > 0);

    // 经验
    for (const g of s.gems) {
      g.phase += dt * 3;
      const d = Math.sqrt(dist2(g.x, g.y, s.px, s.py));
      if (d < s.magnet) {
        const a = Math.atan2(s.py - g.y, s.px - g.x);
        const sp = 260 + (s.magnet - d) * 4;
        g.x += Math.cos(a) * sp * dt;
        g.y += Math.sin(a) * sp * dt;
      }
      if (d < 26) {
        g.taken = true;
        s.xp += g.value;
      }
    }
    s.gems = s.gems.filter((g) => !g.taken);

    // 粒子
    for (const p of s.parts) {
      p.x += p.vx * dt;
      p.y += p.vy * dt;
      p.vx *= 0.92;
      p.vy *= 0.92;
      p.life -= dt;
    }
    s.parts = s.parts.filter((p) => p.life > 0);

    if (shake > 0) shake = Math.max(0, shake - dt * 40);

    maybeLevelUp();
    updateHud();
  }

  /* ---------- 渲染 ---------- */
  function updateCamera() {
    cam.x = clamp(state.px - view.w / 2, 0, Math.max(0, WORLD.w - view.w));
    cam.y = clamp(state.py - view.h / 2, 0, Math.max(0, WORLD.h - view.h));
  }

  function drawBg() {
    const g = ctx.createLinearGradient(0, 0, 0, view.h);
    g.addColorStop(0, "#0b1a12");
    g.addColorStop(1, "#050d09");
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, view.w, view.h);

    const ox = -cam.x;
    const oy = -cam.y;
    ctx.strokeStyle = "rgba(126,226,168,0.055)";
    ctx.lineWidth = 1;
    const step = 80;
    for (let x = Math.floor(cam.x / step) * step; x < cam.x + view.w + step; x += step) {
      ctx.beginPath();
      ctx.moveTo(ox + x, 0);
      ctx.lineTo(ox + x, view.h);
      ctx.stroke();
    }
    for (let y = Math.floor(cam.y / step) * step; y < cam.y + view.h + step; y += step) {
      ctx.beginPath();
      ctx.moveTo(0, oy + y);
      ctx.lineTo(view.w, oy + y);
      ctx.stroke();
    }
  }

  function drawGem(g) {
    const x = g.x;
    const y = g.y;
    const s = 6 + Math.sin(g.phase) * 1.2;
    ctx.fillStyle = "#b9ff8f";
    ctx.beginPath();
    ctx.moveTo(x, y - s);
    ctx.lineTo(x + s, y);
    ctx.lineTo(x, y + s);
    ctx.lineTo(x - s, y);
    ctx.closePath();
    ctx.fill();
  }

  function draw() {
    ctx.setTransform(view.dpr, 0, 0, view.dpr, 0, 0);
    ctx.clearRect(0, 0, view.w, view.h);
    drawBg();

    if (!state) return;
    updateCamera();
    const sx = shake > 0 ? rand(-shake, shake) : 0;
    const sy = shake > 0 ? rand(-shake, shake) : 0;

    ctx.save();
    ctx.translate(-cam.x + sx, -cam.y + sy);

    // 世界边界
    ctx.strokeStyle = "rgba(126,226,168,0.18)";
    ctx.lineWidth = 2;
    ctx.strokeRect(0, 0, WORLD.w, WORLD.h);

    for (const g of state.gems) drawGem(g);

    for (const e of state.enemies) {
      const x = e.x;
      const y = e.y;
      ctx.globalAlpha = e.hitFlash > 0 ? 0.65 : 1;
      if (e.boss) {
        ctx.fillStyle = "rgba(255,92,133,0.12)";
        ctx.beginPath();
        ctx.arc(x, y, e.r + 14, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.font = `${Math.max(24, e.r * 2.1)}px serif`;
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(e.emoji, x, y);
      ctx.globalAlpha = 1;

      if (e.boss) {
        const w = 100;
        ctx.fillStyle = "rgba(0,0,0,0.6)";
        ctx.fillRect(x - w / 2, y - e.r - 18, w, 7);
        ctx.fillStyle = "#ff6b8f";
        ctx.fillRect(x - w / 2, y - e.r - 18, w * Math.max(0, e.hp / e.maxHp), 7);
      }
    }

    for (const b of state.bullets) {
      ctx.fillStyle = "#b9ff8f";
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fill();
    }

    for (const b of state.ebullets) {
      ctx.fillStyle = "#ff7fae";
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fill();
    }

    // 光棒
    for (let i = 0; i < state.orbs; i++) {
      const a = state.orbAngle + (i / state.orbs) * Math.PI * 2;
      const ox = state.px + Math.cos(a) * 88;
      const oy = state.py + Math.sin(a) * 88;
      ctx.save();
      ctx.translate(ox, oy);
      ctx.rotate(a + Math.PI / 2);
      ctx.font = "24px serif";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.shadowColor = "#7ee2a8";
      ctx.shadowBlur = 14;
      ctx.fillText("🪄", 0, 0);
      ctx.restore();
    }

    // 玩家
    ctx.globalAlpha = state.inv > 0 ? 0.45 + Math.sin(state.time * 30) * 0.25 : 1;
    ctx.fillStyle = "rgba(126,226,168,0.12)";
    ctx.beginPath();
    ctx.arc(state.px, state.py, 28, 0, Math.PI * 2);
    ctx.fill();
    ctx.font = "34px serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText("🍀", state.px, state.py);
    ctx.globalAlpha = 1;

    for (const p of state.parts) {
      ctx.globalAlpha = Math.max(0, p.life / p.max);
      ctx.fillStyle = p.color;
      ctx.fillRect(p.x, p.y, p.size, p.size);
    }
    ctx.globalAlpha = 1;

    ctx.restore();
  }

  function updateHud() {
    if (!state) return;
    const hpPct = clamp(state.hp / state.maxHp, 0, 1) * 100;
    document.getElementById("svHpFill").style.width = hpPct + "%";
    document.getElementById("svHpText").textContent = `${Math.ceil(state.hp)} / ${state.maxHp}`;
    document.getElementById("svXpFill").style.width = clamp(state.xp / state.xpNext, 0, 1) * 100 + "%";
    document.getElementById("svLevel").textContent = "Lv." + state.level;
    document.getElementById("svKills").textContent = state.kills;
    document.getElementById("svHeat").textContent = me && me.logged_in
      ? Math.round(me.heat != null ? me.heat : (me.user ? me.user.heat : 0))
      : "--";

    const left = Math.max(0, DURATION - state.time);
    const mm = String(Math.floor(left / 60)).padStart(2, "0");
    const ss = String(Math.floor(left % 60)).padStart(2, "0");
    document.getElementById("svTimer").textContent = `${mm}:${ss}`;
  }

  function loop(now) {
    const dt = Math.min(0.033, (now - last) / 1000);
    last = now;
    if (state && state.running && !state.paused && !state.over) update(dt);
    draw();
    requestAnimationFrame(loop);
  }

  document.addEventListener("DOMContentLoaded", () => {
    let best = 0;
    try {
      best = Number(localStorage.getItem(BEST_KEY) || 0);
    } catch (e) { /* ignore */ }
    document.getElementById("svBest").textContent = `最高纪录：${Math.floor(best)} 秒`;

    document.getElementById("svStartBtn").addEventListener("click", startGame);
    document.getElementById("svAgainBtn").addEventListener("click", startGame);
    document.getElementById("svResumeBtn").addEventListener("click", togglePause);
    document.getElementById("svQuitBtn").addEventListener("click", () => { window.location.href = "index.html"; });
    document.getElementById("svBest").textContent = `最高纪录：${Math.floor(best)} 秒`;

    fetch("api/me.php", { credentials: "same-origin" })
      .then((r) => r.json())
      .then((data) => {
        if (data && data.ok && data.data && data.data.logged_in) {
          me = data.data;
          document.getElementById("svHeat").textContent = Math.round(me.user.heat || 0);
        }
      })
      .catch(() => { /* 未登录或接口不可用 */ });

    requestAnimationFrame((t) => {
      last = t;
      requestAnimationFrame(loop);
    });
  });
})();
