<?php
/** ローカル検証用のルーター。**.htaccess と同じ振り分けをする。**
 *   php -S 127.0.0.1:18999 -t public tests/router.php
 * 実在ファイルはそのまま、それ以外は index.php へ。kv_data は拒否。 */
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (strpos($p, '/kv_data/') === 0) { http_response_code(403); echo 'forbidden'; return true; }
$f = __DIR__ . '/../public' . $p;
if ($p !== '/' && is_file($f)) { return false; }   // 実在ファイル（admin.php など）はPHPに任せる
require __DIR__ . '/../public/index.php';
return true;
