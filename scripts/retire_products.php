<?php
/**
 * 商品を「取り扱い終了」にする（管理画面の商品編集の「取り扱い終了」と同じ処理を、まとめて行う）。
 *   retired_products に名前を残し → products から消し → product_log.jsonl に記録する。
 *   /product/<id> は404にならず「取り扱い終了」と出る。
 * サーバーで: php8.3 retire_products.php <exdirect の置き場所> <商品ID> [<商品ID> …]
 * 2026-11-21 追加（岸保が扱えない愛工舎の7点。ユーザー指示「販売できないから消せよ」）。
 */
declare(strict_types=1);
$root = rtrim($argv[1] ?? '', '/');
$ids = array_map('intval', array_slice($argv, 2));
if (!$root || !$ids) { fwrite(STDERR, "使い方: php retire_products.php <root> <id> ...\n"); exit(2); }
require $root . '/kv_lib.php';
require $root . '/kv_admin_catalog.php';
$db = kv_db();
$db->beginTransaction();
foreach ($ids as $id) {
    $p = kv_aproduct($id);
    if (!$p) { echo "{$id}: ありません（取り扱い終了済み？）\n"; continue; }
    $mk = $p['maker_id'] ? kv_maker((int)$p['maker_id']) : null;
    $db->prepare('INSERT OR REPLACE INTO retired_products (id,name,model_number,maker_name,category_id) VALUES (?,?,?,?,?)')
       ->execute([$id, $p['name'], $p['model_number'], $mk['name'] ?? '', $p['category_id']]);
    $db->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
    kv_plog($id, $p, ['retired' => 1], '取り扱い終了');
    echo "{$id}: 取り扱い終了 {$p['model_number']} " . mb_strimwidth((string)$p['name'], 0, 40, '…') . "\n";
}
$db->commit();
