<?php
declare(strict_types=1);
// テスト用の鍵（pk_test/sk_test）を入れた kv_config.php と複製DBを置いた作業フォルダで回す。本番の DB・本番の鍵では回さない（2026-11-15 通し確認済み）。
require __DIR__ . '/kv_shop.php'; require __DIR__ . '/kv_lib.php'; require __DIR__ . '/kv_order.php'; require __DIR__ . '/kv_stripe.php';
kv_order_schema(); $db = kv_db();
// 実在の商品2点でテスト受注を作る（複製DBの中だけ）
$p1 = $db->query("SELECT * FROM products WHERE id=38978")->fetch(); $p2 = $db->query("SELECT * FROM products WHERE id=7415")->fetch();
$lines = [['product'=>$p1,'unit'=>(int)$p1['price'],'qty'=>2,'amount'=>2*(int)$p1['price'],'tax'=>intdiv(2*(int)$p1['price'],10)],
          ['product'=>$p2,'unit'=>(int)$p2['price'],'qty'=>1,'amount'=>(int)$p2['price'],'tax'=>intdiv((int)$p2['price'],10)]];
$sub = array_sum(array_column($lines,'amount')); $tax = array_sum(array_column($lines,'tax'));
$oid = kv_order_create(['lines'=>$lines,'subtotal'=>$sub,'tax'=>$tax,'total'=>$sub+$tax],
  ['name'=>'テスト 太郎','email'=>'test@example.com','company'=>'テスト株式会社','payment'=>'stripe']);
$o = kv_order($oid); echo "受注 {$o['code']} 合計 {$o['total']} payment={$o['payment']}\n";
// 送料を足す（管理画面で保存したのと同じ計算）
$db->prepare('UPDATE orders SET shipping=?, total=? WHERE id=?')->execute([1650, $sub+$tax+1650, $oid]);
$inv = kv_stripe_send_invoice($oid);
echo "請求書1 {$inv['id']} {$inv['status']} {$inv['amount_due']}円 {$inv['hosted_invoice_url']}\n";
// 同じ金額で押し直し → 使い回し
$inv2 = kv_stripe_send_invoice($oid); echo "押し直し(同額) {$inv2['id']} 同じ=" . ($inv2['id']===$inv['id']?'yes':'no') . "\n";
// 送料を変えて押し直し → 前を取り消して作り直し
$db->prepare('UPDATE orders SET shipping=?, total=? WHERE id=?')->execute([2200, $sub+$tax+2200, $oid]);
$inv3 = kv_stripe_send_invoice($oid); $old = kv_stripe_call('GET','/invoices/'.$inv['id']);
echo "作り直し {$inv3['id']} {$inv3['amount_due']}円 / 前の請求書 {$old['status']}\n";
foreach ($inv3['lines']['data'] ?? [] as $l) { echo "   明細: {$l['description']} {$l['amount']}\n"; }
// テストカードで支払う（Stripe のテスト用支払い方法）
$pm = kv_stripe_call('POST','/payment_methods/pm_card_visa/attach',['customer'=>$inv3['customer']]);
$paid = kv_stripe_call('POST','/invoices/'.$inv3['id'].'/pay',['payment_method'=>$pm['id']]);
echo "支払い {$paid['status']} {$paid['amount_paid']}円\n";
file_put_contents(__DIR__.'/oid.txt', (string)$oid);
file_put_contents(__DIR__.'/inv.txt', $inv3['id'].' '.$inv['id']);
