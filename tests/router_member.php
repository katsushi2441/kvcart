<?php
/** 会員機能の試験用ルーター。本番の DB の写し（outputs/member_test/shop.sqlite）とセッションの置き場を使う。
 *   php -d sendmail_path="tee -a outputs/member_test/mail.log >/dev/null" -S 127.0.0.1:<port> -t public tests/router_member.php
 * メールは送らずに mail.log へ書き出す（kv_config.php に KV_SMTP が無いときだけ。ある環境では使わない）。 */
define('KV_DATA', __DIR__ . '/../outputs/member_test');
define('KV_DB', KV_DATA . '/shop.sqlite');
require __DIR__ . '/router.php';
