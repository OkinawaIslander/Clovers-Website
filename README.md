# Clovers' 动漫协会 官网

含 **QQ 邮箱验证码注册**、**四部门主页 + 群二维码**、**热力交易所（模拟 FX）**、**每季新番 + 评论 / 评分**、**公告 / 投票 / 社刊投稿**、**四叶草幸存者**、**像素农场**，以及带小游戏热力、留言、评论、投稿管理的「我的」页面。

## 开源说明

- 这是一个可以直接部署到 PHP + MySQL 的整站项目，没有前端构建步骤；
- `api/config.php`、`uploads/`、`data/bangumi-data.json`、海报原图等本地/大文件已被 `.gitignore` 忽略；
- 不含默认管理员密码。首次部署请复制 `api/config.example.php`，再运行 `scripts/create_admin.php`；
- 许可证：[MIT](LICENSE)。

## 目录结构

```
clovers-site/
├── index.html            # 首页（部门 / 交易所入口 / 留言板 / 排行榜 / 登录）
├── game.html             # 热力交易所：行情 / 持仓 / 排行榜 / 玩法（全站动态已移到首页）
├── profile.html          # 我的：热力值、小游戏、持仓、留言、评论、投稿、投票、设置
├── vote.html             # 投票（管理员发起，成员一人一票）
├── submit.html           # 社刊投稿（上传文件 + 我的投稿）
├── works.html            # 投稿展示（仅管理员审核通过的稿件）
├── anime.html            # 本季新番（Bangumi 链接 + 每部番评论）
├── survivors.html        # 四叶草幸存者（WASD / 手机摇杆生存小游戏）
├── farm.html             # 像素农场（种菜 / 偷菜 / 热力值买卖）
├── xuanbian.html         # 宣编部（含 QQ 群二维码）
├── zhaiwu.html           # 宅舞部
├── wota.html             # WOTA艺部
├── zhuoyou.html          # 桌游部
├── css/
│   ├── style.css         # 全站样式
│   ├── game.css          # 交易所（深色终端）
│   ├── pages.css         # 个人主页 / 新番 / 投稿展示
│   ├── survivors.css     # 四叶草幸存者
│   └── farm.css          # 像素农场
├── js/
│   ├── common.js         # 公共工具（接口、登录态、toast、动态列表）
│   ├── app.js            # 首页
│   ├── game.js           # 交易所
│   ├── profile.js        # 我的 / 个人主页
│   ├── vote.js           # 投票
│   ├── submit.js         # 社刊投稿
│   ├── works.js          # 投稿展示
│   ├── anime.js          # 新番
│   ├── survivors.js      # 四叶草幸存者
│   ├── farm.js           # 像素农场
│   └── dept.js           # 部门页导航
├── api/
│   ├── config.php        # ★ 数据库 / SMTP / 管理员 / 经济参数
│   ├── _lib.php          # 会话、权限、自动建表、行情引擎、交易逻辑
│   ├── migrate.php       # ★ 一键升级数据库（不删数据）
│   ├── health.php        # ★ 一键自检
│   ├── register.php / login.php / logout.php / me.php
│   ├── send_code.php     # 邮箱验证码
│   ├── market.php / trade.php / checkin.php / game_score.php
│   ├── leaderboard.php / feed.php / profile.php
│   ├── posts.php         # 留言板（管理员可删）
│   ├── announcements.php # 公告
│   ├── polls.php         # 投票
│   ├── submissions.php   # 社刊投稿（上传 / 审核 / 删除）
│   ├── submission_file.php # 投稿文件下载（仅本人 / 管理员）
│   ├── works.php         # 已通过审核的投稿展示
│   ├── avatar.php        # 头像输出
│   ├── my.php            # 我的主页数据
│   ├── account.php       # 修改资料 / 头像 / 密码
│   ├── anime.php         # 新番列表 / 评论 / Bangumi 导入 / 手动添加
│   ├── farm.php          # 像素农场（种植 / 收获 / 偷菜）
│   ├── poster.php        # 招新海报缩略图（GD 可用时自动压缩）
│   ├── admin.php         # 管理员高级接口（会话或 X-Admin-Key）
│   └── qr.php            # 部门群二维码（按 PNG 输出）
├── data/
│   └── bangumi-data.json # 新番本地数据快照（约 7.9MB，离线导入用）
├── scripts/
│   └── create_admin.php  # 命令行创建 / 重置管理员
├── api/config.example.php # 配置模板（复制为 api/config.php）
├── .gitignore
├── LICENSE
├── lib/SmtpMailer.php
├── images/               # 部门二维码原图（PNG，扩展名是 .jpg 也能用）
├── uploads/submissions/  # 投稿文件（PHP 自动创建，随机文件名）
└── database.sql          # 完整建表语句（新库可直接导入）
```

---

## 部署 / 升级

### 1. 老站升级（已经有数据）

上传项目文件（`api/config.php` 只在服务器本地创建，不要提交），然后打开：

```
https://your-domain.com/api/migrate.php?key=你的DIAG_KEY
```

会自动补 `users` 的 `role` / `heat` 等字段，并创建 `positions`、`market`、`posts`、`anime`、
`announcements`、`polls`、`submissions` 等表，**不会删除已有数据**。看到「升级完成」即可。

> 结构版本 11 新增新番评分表 `anime_ratings`；结构版本 10 迁移旧投稿分类；结构版本 9 新建农场表、给小游戏记录加 `game` 字段。

> 也可以直接访问首页或交易所，第一次请求时会自动建表；`migrate.php` 只是更直观的检查入口。

### 2. 新站部署

1. 复制配置：

   ```bash
   cp api/config.example.php api/config.php
   ```

   编辑 `api/config.php`，填入数据库、SMTP、`DIAG_KEY`、`ADMIN_EMAILS`、`ADMIN_API_KEY`。
   `api/config.php` 已被 `.gitignore` 忽略，不会提交。

2. 新建数据库，导入 `database.sql`。
3. 打开 `/api/health.php?key=...` 确认数据库和 SMTP 全通过。
4. 创建第一个管理员：

   ```bash
   php scripts/create_admin.php admin@example.com '换成你的密码' 系统管理员 super
   ```

   也可以只创建次级管理员：

   ```bash
   php scripts/create_admin.php editor@example.com '换成你的密码' 编辑 admin
   ```

### 3. 管理员

`api/config.php` 的 `ADMIN_EMAILS` 里的邮箱登录后会自动成为管理员：

```php
define('ADMIN_EMAILS', array('admin@example.com'));
define('ADMIN_API_KEY', '改成别人猜不到的值');  // 外部调用 api/admin.php 的密钥
```

管理员在首页留言、番剧评论旁会看到「删除」按钮。

**系统管理员**（`role = super`）是最高级账号，不出现在排行榜、公开主页、留言/评论/动态等任何前台列表里；
它可以在 **我的 → 设置 → 管理员授权** 里把普通成员设为次级管理员（`role = admin`），也可以取消。
次级管理员能管理公告、投票、投稿审核和删帖，但不能修改角色、导出数据或调整系统管理员。
账号和密码只保存在数据库和部署者手里，不写在页面或文档中；项目也不再内置默认管理员账号。

### 4. 推送到 Gitee / GitHub

仓库已经初始化为 `main` 分支并完成首次提交，只需要添加远程地址并推送：

```bash
git remote add origin https://gitee.com/你的用户名/你的仓库.git
git push -u origin main
```

GitHub 同理，把 remote 换成 `https://github.com/你的用户名/你的仓库.git`。

> 如果远程仓库已有 README / LICENSE，首次 push 前先 `git pull --rebase origin main` 处理冲突。
> `api/config.php` 含数据库和 SMTP 密码，已写入 `.gitignore`；**不要**用 `git add -f` 强制添加，也不要用网页上传整个文件夹。

---

## 热力交易所规则

- **热力值来源**：幸存者（每天上限 1200）、像素农场买卖、每日签到（100 起，连续 +20，最多 200）、管理员发放。
- **交易时段**：每天 **12:00 - 18:00** 才能买入 / 做空；平仓随时可以，但受杠杆冷却限制。
- **杠杆与冷却**：1x = 24h、2x = 36h、4x = 48h、5x = 60h、10x = 72h。
- **强平**：账户权益（保证金 + 浮盈亏）跌到保证金的 **50%** 自动结算，退回剩余权益。
- **破产**：热力值归零且没有持仓时显示破产横幅，第二天签到可以领救济。
- **排行**：按净资产（含持仓浮盈）排名，另有收益率榜。
- **走势图**：点每个部门行情行里的「走势」，可以切换 **5 分钟 / 每小时 / 每天** 折线，指针放在线上会显示具体时间和当时的热力值。
- 纯娱乐模拟盘，不涉及真实货币；每个部门基础热力值 **1000**，行情由服务器生成。

经济参数都在 `api/config.php`：`START_HEAT`、`GAME_DAILY_CAP`、`SURVIVORS_DAILY_CAP`、`FARM_CROPS`、`MARKET_DAILY_VOL`、`MARKET_TRADE_IMPACT`、`TRADE_OPEN_START/END` 等。

---

## 热力值算法（v6）

### 旧版（已替换）

```
P(t+1) = P(t) × (1 + 2% × (基准价 - P(t)) / 基准价 + U(-1,1) × 1.2%)
```

优点简单，但每分钟 1.2% 的均匀噪声太大、均值回归太强，价格会剧烈抖动又永远被拉回 1000，
没有趋势、没有波动率聚集、没有点差，不像真实市场。

### 现在

价格改成**对数价格**，每分钟收益：

```
r(t) = k × (lnA(t) - lnP(t)) + σ(t) × z(t) × session(t) + jump(t)
lnP(t+1) = lnP(t) + r(t)
```

- **GARCH(1,1) 波动率**：`σ²(t) = ω + α·ε²(t-1) + β·σ²(t-1)`，`α=0.08, β=0.90`。
  长期日波动约 **6.5%**；大波动后面更容易跟着大波动（波动率聚集）。
  `σ²` 限制在长期值的 0.25x ~ 9x，避免失控。
- **慢速基本面锚 A**：`lnA` 自己随机游走（每分钟 0.06%），价格只以 `k=0.00018`
  的强度弱回归（半衰期约 3 天）。所以会出现持续几天的上涨/下跌趋势，而不是死守 1000。
- **交易时段波动**：按北京时间放大/缩小 `σ`：
  亚洲早盘 0.75、东京/香港 0.95、伦敦前 1.15、伦敦+纽约重叠 1.35、纽约午后 0.85；
  周末流动性差，系数 0.45。
- **新闻式跳空**：每分钟约 0.12% 概率（平均每天约 1.7 次），幅度约 1.8%，制造肥尾。
- **人为冲击**：开仓 / 平仓会按名义价值（保证金 × 杠杆）推动价格：
  `impact = min(12%, 0.02 × sqrt(名义价值 / 10000))`。
  1 万名义约 2%，10 万约 6.3%，最高 12%；平仓反向推动。30 人左右的模拟盘也能明显看出“谁在买/卖”。
- **点差**：每个品种 `spread_bps=6`（0.06%）。做多按 **ask** 成交、平多按 **bid**；
  做空反过来。UI 的买入价/卖出价和「点差成本」就是从这里来的。
- **价格边界**：限制在基准价的 0.3x ~ 3.5x，防止极端情况下跑飞。
- **历史**：每分钟写一条 `market_history`，所以 5 分钟/小时/天走势图都有真实采样点。
- **累计盈亏只算股票**：已平仓/强平的 `positions.pnl` + 当前持仓浮盈亏；签到、小游戏、农场、买种子、管理员发放都不算进去。
- **收益率**：累计股票盈亏 ÷ 所有仓位保证金合计，避免把签到和农场收入算进分母。

强平、杠杆逻辑不变，只是成交价现在含点差，开仓会立刻显示一点浮亏（和真实市场一样）。

### 和真实 FX 的关系

真实外汇价格不是用公式“算”出来的，而是**银行间市场里最后一笔成交/可成交报价**：

- 由订单簿里的买卖盘撮合决定，价格变动来自订单流（谁更急、量多大、流动性够不够）；
- 中长期受利率差、通胀、央行政策、经济数据、风险情绪影响，短期接近随机游走；
- 统计上有**波动率聚集、肥尾/跳空、时段效应、买卖点差**，但没有固定均值。

上面的模型是在模拟这些**统计特征**，不是预测真实汇率，也不会连接任何真实行情源；
参数都在 `api/_lib.php` 的 `market_tick()` 里，想更刺激可以调大 `fx_base_variance()` 的日波动。

---

## 公告 / 投票 / 投稿

### 公告

首页「公告栏」，管理员登录后会看到「发布公告」按钮，可以发布、删除公告。

### 投票

`vote.html`（导航「投票」）：

- 管理员点「发起投票」，填标题、说明、选项（一行一个，2-10 个）、开始/截止时间；
- 成员登录后一人一票，实时显示票数和百分比；
- 开始前不能投、截止后自动结算；管理员可以提前截止或删除。

### 社刊投稿

`submit.html`（「我的 → 投稿」也能进）：

- 分类：**cosplay 摄影 / 文章 / 绘画插画 / 其他**（漫画已取消，旧漫画投稿会归到「其他」）；
- 支持 PDF / Word / 图片 / 压缩包等，单文件上限 `UPLOAD_MAX_MB`（默认 20MB）；
- 文件以随机名保存在 `uploads/submissions/`，只有作者本人和管理员能通过 `api/submission_file.php` 下载；
- 管理员在「我的 → 投稿 → 查看全部投稿」里可以 **通过 / 驳回** 并写备注；
- 作者可以删除自己还在「待审核」的投稿。

> 如果上传报「服务器无法创建 uploads/submissions 目录」，把网站根目录权限设为 755、属主 `www`，
> 或在宝塔文件管理里手动建 `uploads/submissions` 并给 www 写权限。
> PHP 的 `upload_max_filesize` / `post_max_size` 也要大于你要传的文件。

### 投稿展示

`works.html`（导航「作品」）：

- 只显示管理员审核通过（`status = accepted`）的投稿；
- 图片直接显示缩略图，PDF 可以在线打开，其他格式提供下载；
- 按分类筛选（cosplay 摄影 / 文章 / 绘画插画 / 其他）；
- 作者头像和昵称会显示，系统管理员的投稿不会出现在这里。

### 我的

`profile.html`（导航「我的」）分标签页：

- 概览：净资产、排名、小游戏累计/今日热力、最高分、动态；
- 持仓与交易：当前持仓、平仓、历史交易；
- 留言 / 评论：自己发过的留言和番剧评论（被管理员删除的会标出来）；
- 投稿：我的投稿、下载、删除；管理员可看全部并审核；
- 投票：我参与过的投票和选项；
- 设置：修改昵称、签名（30 字内）、部门（可多选）、头像（默认显示昵称第一个字）和密码；系统管理员还会看到「管理员授权」。

别人的公开主页仍然是 `profile.html?id=用户ID`，只显示公开统计和动态。

---

## 小游戏

首页 `#game`「热力中心」把交易所和两个小游戏放在同一个深色板块里；`game.html` 里也有小游戏快捷入口。

### 四叶草幸存者

`survivors.html`：

- WASD / 方向键移动；手机显示左下角虚拟摇杆；
- 自动攻击最近的敌人，🪄 光棒环绕身体；
- 击杀掉经验，升级时三选一词条（伤害 / 攻速 / 移速 / 生命 / 穿透 / 散射 / 光棒）；
- 活过 3 分钟，2 分钟刷 Boss；最高纪录存在浏览器 localStorage；
- 结算时按「存活秒数 ×4 + 击杀 ×2 + 等级 ×10」提交 `game_score.php`，每天最多换 1200 热力值。

### 像素农场

`farm.html`：

- 每人 4 块地，用热力值买种子，成熟后收获卖成热力值；
- 作物：四叶草 1h / 番茄 2h / 南瓜 4h / 星星花 8h，价格和成熟时间在 `api/config.php` 的 `FARM_CROPS`；
- 可以去别人家偷已经成熟的作物，偷菜者拿 20%，主人收获时拿 70%；
- 每天偷菜最多获得 500 热力值，防止刷；
- 数据表：`farm_plots`、`farm_steals`。

---

## 全站动态

- 从 `game.html` 的小板块移到首页 `#feed`，加载 `api/feed.php`；
- 开仓、平仓、签到、小游戏、留言、投稿、评论、农场动态都会汇总；
- 动态、留言、番剧评论、排行榜、作品等显示用户的地方会带头像（`avatarHtml()`），没有头像时显示昵称首字。

---

## 新番（Bangumi）

- 页面：`anime.html`，按季度切换，**列表不显示封面**；每部番有 Bangumi 外链、评论区，以及 0-5 分评分。
- 支持按 **播出时间 / 评分 / 评论人数** 排序，正序、倒序切换；
- 评分等级：0 未评分 / 1 拉完了 / 2 NPC / 3 人上人 / 4 顶级 / 5 夯；登录后每人每部只能有一个评分，重复打分覆盖。
- 管理员点「从 Bangumi 导入本季」时，服务端按顺序尝试：
  1. **本地快照** `data/bangumi-data.json`（随站点上传，7.9MB，完全离线可用，速度最快）；
  2. 官方 API `api.bgm.tv`；
  3. 国内可访问的 **bangumi-data 静态镜像**（jsDelivr / unpkg / GitHub raw）；
  4. 都失败时用「手动添加」，评论和评分功能照常。
- 本地快照只有标题、中文名、首播日期和 Bangumi ID，没有封面/简介；列表只显示标题、日期、评分和评论。
  想更新到最新季度，下载 `https://cdn.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json`
  覆盖 `data/bangumi-data.json` 即可。
- 不想优先用本地快照时，把 `api/config.php` 的 `BANGUMI_LOCAL_FIRST` 改为 `false`；
  镜像地址在 `BANGUMI_DATA_MIRRORS` 数组里增删。
- 自建 Bangumi 反代（例如 Cloudflare Worker 的 bangumi-proxy）时，把 `BANGUMI_API_BASE` 改成反代地址。
- 数据表：`anime`、`anime_comments`、`anime_ratings`。

---

## 管理员高级接口

`api/admin.php`，两种鉴权方式：管理员登录会话，或请求头 `X-Admin-Key: ADMIN_API_KEY`。

| action | 说明 |
|---|---|
| `stats` | 站点统计 |
| `users` | 用户列表 |
| `adjust_heat` | 调整热力值 `{user_id, amount, note}` |
| `set_role` | 设置角色 `{user_id, role}` |
| `delete_post` / `delete_comment` | 删留言 / 删评论 |
| `market_adjust` | 修正行情 `{symbol, price}` |
| `broadcast` | 全站公告 `{text}` |
| `export` | 导出数据（不含密码） |
| `ban_user` / `reset_market` / `import_anime` / `send_mail` | **预留**，返回 501 |

示例：

```bash
curl -H "X-Admin-Key: 你的密钥" "https://your-domain.com/api/admin.php?action=stats"
```

---

## 常见问题

| 现象 | 处理 |
|---|---|
| 别的设备打不开 | 先查域名解析/实名认证/备案，见《部署排查指南.md》 |
| 注册提示数据库错误 | 库名区分大小写；打开 `api/health.php` 看具体项 |
| 登录报 `Access denied ... to database '...'` | 数据库名大小写不一致，把 `config.php` 的 `DB_NAME` 改成实际数据库名 |
| 邮件发不出去 | 自检页会说明是授权码、openssl 还是端口问题 |
| 交易所打不开/一直加载 | 确认已跑过 `migrate.php`，并看浏览器控制台/接口返回 |
| 新番导入失败 | 服务器访问不了 `api.bgm.tv`，用「手动添加」或稍后再试 |
| 留言/评论不能删 | 当前账号不是管理员，检查 `ADMIN_EMAILS` 或 `users.role` |

---

## 安全提示

- `api/config.php` 里有数据库密码、邮箱授权码、管理员密钥，**不要**公开到 GitHub。
- 上线后改掉 `DIAG_KEY` 和 `ADMIN_API_KEY`。
- `api/health.php`、`api/migrate.php` 都靠 `key` 保护，别忘了改。
- 系统管理员账号不出现在任何前台页面；账号密码只由部署者保存，建议定期更换。
- `uploads/` 目录里是投稿原文件，建议定期备份，不要把目录权限开成 777。
