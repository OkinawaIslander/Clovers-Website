<div align="center">

<img src="images/社团标志.jpg" width="112" alt="Clovers 动漫协会" />

# 🍀 Clovers' 动漫协会

**浙江农林大学 · 二次元同好社团官网**

一个不用前端构建、上传即可运行的 PHP + MySQL 社团整站。

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-2d8f4e.svg)](LICENSE)
[![No Build](https://img.shields.io/badge/build-none-2d8f4e.svg)](#-技术栈)

[快速开始](#-快速开始) · [功能](#-功能) · [项目结构](#-项目结构) · [部署](#-部署到线上) · [常见问题](#-常见问题)

</div>

---

## 🌱 这个项目是什么？

这是 Clovers' 动漫协会的官网，也是一个可以直接复用的高校社团网站模板。

它把「社团展示」和「成员一起玩」放在同一个站点里：

- 四个部门各有独立主页和群二维码；
- 用**热力值**串联交易所、农场和每日答题；
- 新番可以评分、评论、按评分 / 播出时间 / 评论数排序；
- 公告、投票、社刊投稿、留言板一应俱全；
- 每位成员都有自己的公开主页和后台面板。

> 没有 React / Vue / Webpack。所有页面都是静态 HTML + 原生 JS，后端是 PHP + PDO，上传到宝塔就能跑。

---

## ✨ 功能

| 模块 | 能做什么 |
|---|---|
| 📈 **热力交易所** | 模拟 FX 行情，1x–10x 杠杆，做多 / 做空，持仓、强平、排行榜、K 线走势 |
| 🥕 **像素农场** | 买种子、种菜、收菜、去别人家偷菜，作物 1–8 小时成熟 |
| 🧠 **每日答题** | 每天出 3 道题、选一个板块抽 3 题作答，答对换热力值；管理员可管理题库 |
| 📺 **本季新番** | Bangumi 数据导入、0–5 分评分、评论、按评分 / 播出时间 / 评论人数排序 |
| 🗳️ **公告 / 投票** | 管理员发公告、发起投票，成员一人一票 |
| 📚 **社刊投稿** | 上传作品、管理员审核、公开展示 |
| 💬 **留言板 / 动态** | 全站动态、留言、评论，带头像显示 |
| 👤 **个人主页** | 热力值、持仓、答题、投稿、评论、投票、设置与管理员授权 |

### 热力值怎么来？

```text
农场买卖  +  每日签到  +  每日答题  +  管理员发放
        ↓
     热力值
        ↓
  交易所保证金 / 农场种子
```

累计盈亏**只统计模拟股票**：已平仓 / 强平的 `positions.pnl` + 当前持仓浮盈亏。签到、答题、农场收入不会算进股票盈亏。

---

## 🛠 技术栈

| 层 | 使用 |
|---|---|
| 后端 | PHP 7.4+、PDO、MySQL 5.7+ / MariaDB |
| 前端 | 原生 HTML / CSS / JavaScript，无构建步骤 |
| 数据 | MySQL，自动建表 / 迁移，结构版本 **12** |
| 邮件 | QQ 邮箱 SMTP（注册验证码，可选） |
| 图片 | GD（头像压缩 / 二维码输出，可选） |
| 部署 | 宝塔 / Apache / Nginx，任何支持 PHP 的空间 |

---

## 🚀 快速开始

### 环境要求

- PHP **7.4+**，开启 `pdo_mysql`、`mbstring`、`openssl`；
- MySQL **5.7+** 或 MariaDB；
- 可选：`gd`（头像 / 二维码）、SMTP 授权码（邮箱注册）。

### 1. 获取代码

```bash
git clone https://gitee.com/你的用户名/你的仓库.git clovers-site
cd clovers-site
```

### 2. 配置

```bash
cp api/config.example.php api/config.php
```

编辑 `api/config.php`，至少填写：

| 常量 | 说明 |
|---|---|
| `DB_HOST` / `DB_PORT` | 数据库地址，通常 `127.0.0.1:3306` |
| `DB_NAME` / `DB_USER` / `DB_PASS` | 数据库名、用户名、密码 |
| `DIAG_KEY` | 自检页密钥，改成随机字符串 |
| `ADMIN_EMAILS` | 管理员邮箱数组 |
| `ADMIN_API_KEY` | 外部调用 `api/admin.php` 的密钥 |
| `SMTP_*` | QQ 邮箱 SMTP，注册验证码需要 |

> `api/config.php` 已被 `.gitignore` 忽略，**不要提交**。

### 3. 初始化数据库

新建数据库后，在 phpMyAdmin 里导入 `database.sql`：

```text
宝塔 → 数据库 → 新建库 → 导入 database.sql
```

也可以直接访问站点，第一次请求会自动建表。

### 4. 创建管理员

```bash
php scripts/create_admin.php admin@example.com '换成你的密码' 系统管理员 super
```

次级管理员：

```bash
php scripts/create_admin.php editor@example.com '换成你的密码' 编辑 admin
```

> 项目**不内置默认管理员密码**。`super` 是系统管理员，不出现在任何前台列表。

### 5. 本地预览

```bash
php -S 127.0.0.1:8000
```

打开 `http://127.0.0.1:8000`。数据库和 SMTP 仍需按上面配置。

### 6. 上线检查

- 打开 `/api/health.php?key=你的DIAG_KEY`，数据库 / SMTP 全部通过；
- 打开 `/api/migrate.php?key=...` 确认结构版本为 **12**；
- 改掉 `DIAG_KEY`、`ADMIN_API_KEY`、管理员密码；
- 配置 HTTPS 和备份。

---

## 🌐 部署到线上

以宝塔面板为例：

1. 新建站点，PHP 选择 **8.0 / 8.1**；
2. 把项目文件上传到站点根目录；
3. 在「数据库」里新建库，导入 `database.sql`；
4. 在站点根目录创建 `api/config.php`（从 `api/config.example.php` 复制并修改）；
5. 访问 `/api/health.php?key=你的DIAG_KEY` 自检；
6. 配置 HTTPS，关闭目录浏览，设置上传目录权限。

### 已有站点升级

上传新文件后访问：

```text
https://your-domain.com/api/migrate.php?key=你的DIAG_KEY
```

迁移只补表和字段，**不会删除已有数据**。结构版本会升到 **12**。

> 也可以直接访问首页或交易所，第一次请求会自动建表；`migrate.php` 只是更直观的检查入口。

---

## 📦 推送到 Gitee / GitHub

仓库已经初始化为 `main` 分支并完成首次提交，只需要添加远程地址：

```bash
git remote add origin https://gitee.com/你的用户名/你的仓库.git
git push -u origin main
```

GitHub 同理：

```bash
git remote add origin https://github.com/你的用户名/你的仓库.git
git push -u origin main
```

如果远程仓库已有 README / LICENSE：

```bash
git pull --rebase origin main
git push -u origin main
```

> `api/config.php`、`uploads/`、`data/bangumi-data.json`、海报原图都已写入 `.gitignore`。
> **不要用网页「上传整个文件夹」**，用 `git push` 才能确保配置和用户文件不被提交。

---

## ⚙️ 配置说明

所有配置集中在 `api/config.php`：

```php
// 初始热力值
define('START_HEAT', 1000);

// 每日答题
define('QUIZ_DAILY_SUBMIT', 3);
define('QUIZ_REWARD_PER_CORRECT', 30);

// 农场
define('FARM_PLOTS', 4);
define('FARM_STEAL_RATE', 0.2);

// 行情
define('MARKET_DAILY_VOL', 0.065);
define('MARKET_TRADE_IMPACT', 0.02);
```

完整参数见 [api/config.example.php](api/config.example.php)。

---

## 🗂 项目结构

```text
clovers-site/
├── index.html              # 首页：部门 / 热力中心 / 动态 / 留言板 / 排行榜
├── game.html               # 热力交易所：行情 / 持仓 / 排行榜 / 玩法
├── farm.html               # 像素农场
├── quiz.html               # 每日答题 / 出题 / 题库管理
├── anime.html              # 本季新番 + 评分 + 评论
├── vote.html               # 投票
├── submit.html             # 社刊投稿
├── works.html              # 投稿展示
├── profile.html            # 我的 / 公开个人主页
├── 四个部门页               # xuanbian / zhaiwu / wota / zhuoyou
├── css/                    # 全站样式 + 页面样式
├── js/                     # 原生 JS，无构建
├── api/                    # PHP 接口
│   ├── config.example.php  # 配置模板
│   ├── _lib.php            # 会话、权限、建表、行情引擎
│   ├── quiz.php            # 答题 / 出题 / 题库管理
│   ├── farm.php            # 农场
│   ├── market.php          # 行情
│   ├── trade.php           # 开仓 / 平仓
│   └── ...                 # 登录、注册、投稿、公告、投票等
├── scripts/create_admin.php
├── data/README.txt         # 可选的新番离线快照说明
├── uploads/                # 用户上传（运行时生成）
├── database.sql            # 完整表结构
├── 部署排查指南.md
└── LICENSE
```

---

## 📈 行情引擎

交易所不是随机数生成器，而是一个小型模拟市场：

- 每个部门有独立价格和趋势；
- 日波动率约 **6.5%**，用 GARCH 风格更新；
- 开仓 / 平仓会按名义价值推动价格，单笔最高 **12%**；
- 每分钟写入 `market_history`，所以 5 分钟 / 小时 / 天走势图都有真实采样点；
- 杠杆越高，爆仓越近：账户权益跌到保证金 **50%** 自动强平。

> 纯娱乐模拟盘，不涉及任何真实货币。

---

## 🔐 安全说明

- `api/config.php` 不进入 Git，数据库密码和 SMTP 授权码只保存在服务器；
- 不内置默认管理员，首次部署用 `scripts/create_admin.php` 创建；
- 管理员可以删除留言 / 评论、审核投稿、管理题库；
- 上传目录使用随机文件名，投稿文件只有作者和管理员可下载；
- 上线后请务必修改 `DIAG_KEY`、`ADMIN_API_KEY` 和管理员密码。

---

## ❓ 常见问题

| 问题 | 处理 |
|---|---|
| 页面空白 / 样式错乱 | `Ctrl+F5` 强刷；确认 `css/`、`js/` 已上传 |
| 数据库报 `1044 Access denied` | 检查 `DB_NAME` / `DB_USER` 大小写和授权 |
| 注册验证码收不到 | 检查 SMTP 授权码、465 / 587 端口和垃圾箱 |
| 管理员看不到「题库管理」 | 确认账号 `role = admin/super`，或邮箱在 `ADMIN_EMAILS` 里 |
| 上传投稿失败 | 检查 `uploads/` 权限、PHP 上传大小限制 |
| 新番导入失败 | 先放 `data/bangumi-data.json`，或检查服务器能否访问 Bangumi API |

更多排查见 [部署排查指南.md](部署排查指南.md)。

---

## 🤝 参与贡献

欢迎提 Issue / PR：

1. Fork 仓库；
2. 新建分支：`git checkout -b feat/your-feature`；
3. 提交前确认没有把 `api/config.php`、`uploads/`、`data/bangumi-data.json` 加进 Git；
4. 发起 Pull Request。

代码风格：PHP 4 空格缩进、原生 JS、不引入构建工具。

---

## 📄 License

[MIT](LICENSE) © 2026 Clovers' 动漫协会 contributors

---

<div align="center">

**如果这个项目对你有帮助，点个 ⭐ 吧。**

Made with 🍀 for Clovers

</div>
