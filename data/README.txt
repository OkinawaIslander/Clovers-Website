这里的 bangumi-data.json 是「新番」页面的本地番剧数据快照。

- 来源：https://github.com/bangumi-data/bangumi-data 的 dist/data.json
- 当前文件包含截至 2026-10-29 的番剧数据，约 7.9MB；
- `api/anime.php` 会优先读本地文件，所以即使服务器完全连不上外网，也能导入新番；
- 想更新到最新季度：在能上网的电脑上下载
  https://cdn.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json
  覆盖本文件即可；
- 如果删除本文件，程序会退回官方 API / jsDelivr / unpkg 镜像。
- 本文件只含标题、首播日期、Bangumi ID，不含封面和简介。
