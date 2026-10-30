# scripts

## create_admin.php

在服务器命令行创建 / 重置管理员：

```bash
php scripts/create_admin.php admin@example.com 'your-password' 系统管理员 super
php scripts/create_admin.php editor@example.com 'your-password' 编辑 admin
```

- 需要先配置好 `api/config.php`，并导入 `database.sql` / 运行迁移；
- `super` 是系统管理员，不出现在前台列表；
- `admin` 是次级管理员，可以审核投稿、发公告、删帖；
- 创建后请登录并修改密码。
