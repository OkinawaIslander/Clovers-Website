这里的 bangumi-data.json 是「新番」页面的可选本地番剧数据快照。

- 来源：https://github.com/bangumi-data/bangumi-data 的 dist/data.json
- 仓库默认不提交这个 7.9MB 文件（已写入 .gitignore）；
- 需要完全离线导入时，在能上网的电脑下载
  https://cdn.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json
  放到本目录，命名为 bangumi-data.json；
- `api/anime.php` 会优先读本地文件；没有时会退回官方 API / jsDelivr / unpkg 镜像；
- 快照只含标题、首播日期、Bangumi ID，不含封面和简介。
