<?php
declare(strict_types=1);
// public/ をコピーした作業フォルダ（kv_data/shop.sqlite も複製）に置いて php で実行する。本番の DB では回さない。
require __DIR__ . '/kv_shop.php'; require __DIR__ . '/kv_lib.php'; require __DIR__ . '/kv_order.php'; require __DIR__ . '/kv_stripe.php';
$ok = 0; $ng = 0;
function check(string $n, bool $c) { global $ok, $ng; echo ($c ? 'OK ' : 'NG ') . $n . "\n"; $c ? $ok++ : $ng++; }
kv_order_schema();
$db = kv_db();
$db->exec("INSERT INTO orders (code,created_at,name,email,payment,subtotal,tax,shipping,total,f_received,stripe_id) VALUES ('T-1','2026-11-15','テスト','test@example.com','stripe',1000,100,0,1100,1,'in_NEW')");
$oid = (int)$db->lastInsertId();
$sec = 'whsec_testsecret';
$mk = function (string $type, string $inv) use ($oid) { return json_encode(['type' => $type, 'data' => ['object' => ['id' => $inv, 'amount_paid' => 1100, 'metadata' => ['kv_order_id' => (string)$oid]]]]); };
$sign = fn(string $p, int $t) => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $p, $sec);
$p = $mk('invoice.paid', 'in_NEW');
check('正しい署名は通る', kv_stripe_verify($p, $sign($p, time()), $sec));
check('中身を変えた署名は通らない', !kv_stripe_verify(str_replace('1100', '9', $p), $sign($p, time()), $sec));
check('別のシークレットでは通らない', !kv_stripe_verify($p, $sign($p, time()), 'whsec_other'));
check('10分前の署名は通らない', !kv_stripe_verify($p, $sign($p, time() - 600), $sec));
check('取り消した古い請求書のイベントは無視', kv_stripe_handle(json_decode($mk('invoice.paid', 'in_OLD'), true)) === 'ignored: old invoice');
check('未入金のまま', (int)kv_order($oid)['f_paid'] === 0);
check('invoice.paid で入金になる', kv_stripe_handle(json_decode($p, true)) === 'paid' && (int)kv_order($oid)['f_paid'] === 1);
check('同じイベントの再送は二重にしない', kv_stripe_handle(json_decode($p, true)) === 'already paid');
check('支払い失敗は記録だけ', kv_stripe_handle(json_decode($mk('invoice.payment_failed', 'in_NEW'), true)) === 'failed logged');
check('受注が無いものは無視', kv_stripe_handle(['type' => 'invoice.paid', 'data' => ['object' => ['id' => 'x', 'metadata' => []]]]) === 'ignored: no order');
foreach (kv_order_logs($oid) as $l) { echo '  log: ' . $l['kind'] . ' ' . $l['detail'] . "\n"; }
[$s, $b] = kv_mail_render($oid, 'stripe_invoice'); echo "  mail: $s\n";
echo "OK $ok / NG $ng\n";
