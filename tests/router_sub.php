<?php
/** サブパス配置（/exdirect）をローカルで再現するルーター。
 *  **これで検証しないと、本番だけ404になる罠を踏む**（2026-09-14）。
 *   php -S 127.0.0.1:18998 -t public tests/router_sub.php */
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (strpos($p, '/exdirect/kv_data/') === 0) { http_response_code(403); echo 'forbidden'; return true; }
if (strpos($p, '/exdirect') !== 0) { http_response_code(404); echo 'outside'; return true; }
$rel = substr($p, strlen('/exdirect')) ?: '/';
$f = __DIR__ . '/../public' . $rel;
if ($rel !== '/' && is_file($f)) { return false; }
require __DIR__ . '/../public/index.php';
return true;
