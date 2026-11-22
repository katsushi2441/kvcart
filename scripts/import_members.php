<?php
/**
 * おちゃのこネットの会員を kvcart の DB に入れる（2026-11-22）。build_member_import.py の JSON を読む。
 *   サーバーで: php8.3 import_members.php <exdirect の置き場所> <members.json> [<DBの写し（試験用）>]
 * 何度流してもよい。会員はメールアドレスで照合し、**この店で更新した会員（updated_at が移行より後）は上書きしない**。
 * 届け先は同じものを二重に入れない。おちゃのこ時代の注文は ocnk_order_id で一度だけ入れる。
 */
declare(strict_types=1);
$root = rtrim($argv[1] ?? '', '/');
$json = $argv[2] ?? '';
if (!$root || !is_file($json)) { fwrite(STDERR, "使い方: php import_members.php <root> <members.json>\n"); exit(2); }
if (!empty($argv[3])) { define('KV_DB', $argv[3]); }   // 試験用: 写しの DB に入れる
require $root . '/kv_shop.php';
require $root . '/kv_lib.php';
require $root . '/kv_order.php';
$d = json_decode((string)file_get_contents($json), true);
if (!is_array($d) || !isset($d['members'])) { fwrite(STDERR, "JSON が読めません\n"); exit(1); }
kv_member_schema();
$db = kv_db();
$db->beginTransaction();
$now = date('Y-m-d H:i:s');
$ins = $db->prepare('INSERT INTO members (email,name,name_kana,company,department,zip,pref,address1,address2,address3,tel,fax,dm,source,ocnk_id,'
    . 'ocnk_sales_count,ocnk_sales_total,ocnk_last_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'ocnk\',?,?,?,?,?,NULL)');
$upd = $db->prepare('UPDATE members SET name=?,name_kana=?,company=?,department=?,zip=?,pref=?,address1=?,address2=?,address3=?,tel=?,fax=?,dm=?,'
    . 'ocnk_id=?,ocnk_sales_count=?,ocnk_sales_total=?,ocnk_last_order=? WHERE id=? AND source=\'ocnk\' AND updated_at IS NULL');
$stat = ['新規' => 0, '更新' => 0, 'この店で更新済みのため触らず' => 0];
foreach ($d['members'] as $m) {
    $ex = kv_member_by_email((string)$m['email']);
    $v = [$m['name'], $m['name_kana'], $m['company'], $m['department'], $m['zip'], $m['pref'], $m['address1'], $m['address2'], $m['address3'],
          $m['tel'], $m['fax'], (int)$m['dm'], $m['ocnk_id'], (int)$m['ocnk_sales_count'], (int)$m['ocnk_sales_total'], $m['ocnk_last_order']];
    if (!$ex) {
        $ins->execute(array_merge([kv_member_email((string)$m['email'])], $v, [$m['created_at'] ?: $now]));
        $stat['新規']++;
    } else {
        $upd->execute(array_merge($v, [(int)$ex['id']]));
        $stat[$upd->rowCount() ? '更新' : 'この店で更新済みのため触らず']++;
    }
}
$ids = [];
foreach ($db->query('SELECT id, email FROM members') as $r) { $ids[mb_strtolower((string)$r['email'])] = (int)$r['id']; }
$na = 0;
foreach ($d['addresses'] as $a) {
    $mid = $ids[mb_strtolower((string)$a['email'])] ?? 0;
    if (!$mid) { continue; }
    $before = (int)$db->query('SELECT COUNT(*) FROM member_addresses WHERE member_id=' . $mid)->fetchColumn();
    kv_member_address_save($mid, $a);
    $st = $db->prepare('UPDATE member_addresses SET last_used_at=? WHERE member_id=? AND id=(SELECT MAX(id) FROM member_addresses WHERE member_id=?) AND ? <> \'\'');
    if ((int)$db->query('SELECT COUNT(*) FROM member_addresses WHERE member_id=' . $mid)->fetchColumn() > $before) {
        $st->execute([$a['last_used_at'], $mid, $mid, $a['last_used_at']]);
        $na++;
    }
}
$po = $db->prepare('INSERT OR IGNORE INTO member_past_orders (email,ocnk_order_id,ordered_at,total,items,ship_name,ship_address) VALUES (?,?,?,?,?,\'\',?)');
$np = 0;
foreach ($d['past_orders'] as $o) {
    $po->execute([kv_member_email((string)$o['email']), (int)$o['ocnk_order_id'], $o['ordered_at'], (int)$o['total'],
                  json_encode($o['items'], JSON_UNESCAPED_UNICODE), (string)$o['ship_address']]);
    $np += $po->rowCount();
}
$db->commit();
foreach ($stat as $k => $n) { echo "会員 $k: $n\n"; }
echo "届け先 追加: $na\n";
echo "おちゃのこ時代の注文 追加: $np\n";
echo '会員の合計: ' . $db->query('SELECT COUNT(*) FROM members')->fetchColumn() . "\n";
