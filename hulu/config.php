<?php
/**
 * 葫芦侠接口配置文件
 * 从根目录的 config.php 读取配置信息
 */

// 引入根目录的配置文件
require_once __DIR__ . '/../config.php';

// 从根目录配置中读取葫芦侠配置
$config = array(
    'phone' => defined('HULUXIA_PHONE') ? HULUXIA_PHONE : '',
    'password' => defined('HULUXIA_PASSWORD') ? HULUXIA_PASSWORD : '',
    'uid' => defined('HULUXIA_UID') ? HULUXIA_UID : null,
    'key' => defined('HULUXIA_KEY') ? HULUXIA_KEY : ''
);

// task 配置保持不变,用于 task.php 的密钥验证
$task = array(
    'key' => ''
);