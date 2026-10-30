这个目录用来存放社刊投稿文件和用户头像。

- 投稿文件放在 uploads/submissions/ 下，文件名是随机的 .bin；
- 头像放在 uploads/avatars/ 下，也是随机文件名；
- 已通过审核的投稿，任何人都可以通过 api/submission_file.php 预览/下载；
  待审核/驳回的只有作者本人和管理员能看；
- 需要给 www 用户写权限（目录 755、属主 www）；
- 如果上传报错，检查宝塔 PHP 的 upload_max_filesize / post_max_size 是否大于文件大小。
