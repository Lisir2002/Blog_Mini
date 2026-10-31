<?php
/**
 * PenBlog 配置样例:复制为 config.php 后修改,或用 bin/install.php 自动生成。
 * 环境变量覆盖规则:PENBLOG_<键路径大写>,如 PENBLOG_DB_DRIVER=mysql。
 */

return [
    'app' => [
        'debug' => false,
        'url' => 'http://localhost:8080',
        'secret_key' => null, // 设置后 Secret 静态加密(建议 32+ 字节随机串)
    ],
    'db' => [
        'driver' => 'sqlite',            // sqlite | mysql
        'path' => __DIR__ . '/../storage/data.db',
        // mysql:
        // 'host' => '127.0.0.1', 'port' => 3306,
        // 'database' => 'penblog', 'username' => 'root', 'password' => '',
    ],
    'paths' => [
        'logs' => __DIR__ . '/../storage/logs/app.log',
        'themes' => __DIR__ . '/../themes',
        'plugins' => __DIR__ . '/../plugins',
    ],
];
