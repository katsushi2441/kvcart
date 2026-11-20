<?php
/**
 * Kurage Vibe-Cart — 在庫管理（2026-11-17・おちゃのこネットの「在庫管理」に合わせる）
 *
 *   - 在庫表示・通知設定：在庫の見せ方（在庫数／在庫あり・在庫わずか・在庫切れの文言）、在庫切れでも注文を受けるか、
 *     注文で在庫わずか・在庫切れになったら店にメールで知らせるか
 *   - 在庫数の増減：ショップでの注文で減らし、受注のキャンセルで戻す（在庫数を管理する商品だけ。0 より下にはしない）
 *   - 在庫数変更履歴：変更前・変更後・増減・イベント（ショップで購入／商品変更／在庫一括設定／CSV／受注キャンセル…）
 *   - 再入荷お知らせ：在庫切れの商品ページでメールアドレスを登録してもらい、入荷したら管理画面から送る
 *
 * 設定は kv_data/stock_settings.json。
 */
declare(strict_types=1);

function kv_stock_schema(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    kv_db()->exec("
    CREATE TABLE IF NOT EXISTS stock_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, product_id INTEGER, name TEXT,
      before INTEGER, after INTEGER, diff INTEGER, event TEXT, order_code TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_stock_log_pid ON stock_log(product_id);
    CREATE TABLE IF NOT EXISTS restock_requests (
      id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, email TEXT NOT NULL, created_at TEXT NOT NULL,
      notified_at TEXT, ip TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_restock_pid ON restock_requests(product_id);
    ");
}

function kv_stock_defaults(): array
{
    return [
        'show' => 1,                  // 在庫表示する
        'label' => '在庫数', 'unit' => '点',
        'show_count' => 0,            // 在庫数を出す（0 なら「在庫あり」の文言）
        'text_in' => '在庫あり',
        'text_unlimited' => '在庫あり／お取り寄せ',
        'low_line' => 3, 'text_low' => '在庫わずか',
        'text_out' => '在庫切れ（お取り寄せ・納期をご連絡します）',
        'backorder' => 1,             // 在庫切れでも注文を受け付ける（メーカー直送のお取り寄せ）
        'text_soldout' => 'SOLD OUT',
        'notify' => 1, 'notify_email' => '',   // 在庫わずか・在庫切れをメールで知らせる（空なら店のアドレス）
        'restock' => 1,               // 再入荷お知らせを使う（注文を受け付けない在庫切れの商品で出す）
        'restock_note' => '入荷したら、ご登録のメールアドレスにお知らせします。',
        'restock_subject' => '再入荷のお知らせ（{product_name}）',
        'restock_body' => "お待たせいたしました。ご登録いただいた商品が入荷しました。\n\n{product_name}\n{product_url}\n\n在庫には限りがあります。お早めにお求めください。\n\n{shop}\n{email}\n",
    ];
}

function kv_stock_settings(): array
{
    static $s = null;
    if ($s === null) {
        $f = KV_DATA . '/stock_settings.json';
        $s = array_merge(kv_stock_defaults(), is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []);
    }
    return $s;
}

function kv_stock_save_settings(array $v): void
{
    $cur = kv_stock_settings();
    $out = [];
    foreach (kv_stock_defaults() as $k => $d) { $out[$k] = array_key_exists($k, $v) ? (is_int($d) ? (int)$v[$k] : (string)$v[$k]) : (is_int($d) ? 0 : $cur[$k]); }
    file_put_contents(KV_DATA . '/stock_settings.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** 在庫数を管理している商品か（在庫数を管理しない＝メーカー直送のお取り寄せ品は false）。 */
function kv_stock_tracked(array $p): bool
{
    return (int)($p['stock_unlimited'] ?? 0) !== 1;
}

/** カートに入れられるか（在庫切れでも受け付ける設定なら true）。 */
function kv_stock_orderable(array $p): bool
{
    return !kv_stock_tracked($p) || (int)$p['stock'] > 0 || (int)kv_stock_settings()['backorder'] === 1;
}

/** 状態: unlimited / in / low / out。 */
function kv_stock_state(array $p): string
{
    if (!kv_stock_tracked($p)) { return 'unlimited'; }
    $n = (int)$p['stock'];
    if ($n <= 0) { return 'out'; }
    return $n <= (int)kv_stock_settings()['low_line'] ? 'low' : 'in';
}

/** 商品ページに出す在庫の文言。表示しない設定なら ''。 */
function kv_stock_label(array $p): string
{
    $s = kv_stock_settings();
    if (!(int)$s['show']) { return ''; }
    $st = kv_stock_state($p);
    if ($st === 'unlimited') { return $s['text_unlimited']; }
    if ($st === 'out') { return kv_stock_orderable($p) ? $s['text_out'] : $s['text_soldout']; }
    $txt = $st === 'low' ? $s['text_low'] : $s['text_in'];
    return (int)$s['show_count'] ? $s['label'] . ' ' . (int)$p['stock'] . $s['unit'] . ($st === 'low' ? '（' . $s['text_low'] . '）' : '') : $txt;
}

/** 構造化データ（Google）の availability。 */
function kv_stock_schema_availability(array $p): string
{
    $st = kv_stock_state($p);
    if ($st === 'out') { return kv_stock_orderable($p) ? 'https://schema.org/BackOrder' : 'https://schema.org/OutOfStock'; }
    return $st === 'low' ? 'https://schema.org/LimitedAvailability' : 'https://schema.org/InStock';
}

/** 在庫数を変えたら必ずここを通す（履歴を残す）。在庫数を管理しない商品の変更も記録だけはする。 */
function kv_stock_log(int $pid, ?int $before, ?int $after, string $event, string $code = ''): void
{
    kv_stock_schema();
    if ((string)$before === (string)$after) { return; }
    $name = (string)kv_db()->query('SELECT name FROM products WHERE id=' . $pid)->fetchColumn();
    kv_db()->prepare('INSERT INTO stock_log (at,product_id,name,before,after,diff,event,order_code) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([date('Y-m-d H:i:s'), $pid, $name, $before, $after, ($after ?? 0) - ($before ?? 0), $event, $code]);
    if ((int)$before <= 0 && (int)$after > 0) { kv_restock_staff_notice($pid); }
}

/**
 * 注文で在庫を減らす（$sign=-1）／キャンセルで戻す（+1）。在庫数を管理する商品だけ。
 * 減らしたあと在庫わずか・在庫切れになった商品は、まとめて店に知らせる（おちゃのこネットと同じく、ショップでの注文のときだけ）。
 */
function kv_stock_apply_order(int $oid, int $sign, string $event, bool $notify = false): void
{
    kv_stock_schema();
    $o = kv_order($oid);
    if (!$o) { return; }
    $low = []; $out = [];
    $line = (int)kv_stock_settings()['low_line'];
    foreach (kv_order_items($oid) as $it) {
        $p = kv_db()->query('SELECT id,name,stock,stock_unlimited FROM products WHERE id=' . (int)$it['product_id'])->fetch();
        if (!$p || !kv_stock_tracked($p)) { continue; }
        $b = (int)$p['stock']; $a = max(0, $b + $sign * (int)$it['qty']);
        if ($a === $b) { continue; }
        kv_db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$a, $p['id']]);
        kv_stock_log((int)$p['id'], $b, $a, $event, (string)$o['code']);
        if ($sign < 0) {
            if ($a <= 0 && $b > 0) { $out[] = $p['name'] . '（' . $b . ' → 0）'; }
            elseif ($a <= $line && $b > $line) { $low[] = $p['name'] . '（' . $b . ' → ' . $a . '）'; }
        }
    }
    $s = kv_stock_settings();
    if ($notify && ($low || $out) && (int)$s['notify']) {
        $to = trim($s['notify_email']) !== '' ? trim($s['notify_email']) : kv_shop('email');
        $body = '受注 ' . $o['code'] . " で在庫が減りました。\n\n" . ($low ? "■ 在庫わずか\n" . implode("\n", $low) . "\n\n" : '') . ($out ? "■ 在庫切れ\n" . implode("\n", $out) . "\n\n" : '')
              . "在庫一括設定: " . kv_site_url('admin.php?a=stock&st=low') . "\n";
        $h = 'From: ' . mb_encode_mimeheader(kv_shop('name')) . ' <' . kv_shop('email') . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        kv_mail($to, mb_encode_mimeheader(kv_mail_subject('【在庫通知】' . ($out ? '在庫切れ' : '在庫わずか') . 'の商品があります')), $body, $h);
    }
}

// ---------------- 再入荷お知らせ ----------------

/** 再入荷お知らせの登録欄を出すか（注文を受け付けない在庫切れの商品だけ）。 */
function kv_restock_enabled(array $p): bool
{
    return (int)kv_stock_settings()['restock'] === 1 && kv_stock_state($p) === 'out' && !kv_stock_orderable($p);
}

function kv_restock_register(int $pid, string $email): bool
{
    kv_stock_schema();
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
    $st = kv_db()->prepare('SELECT COUNT(*) FROM restock_requests WHERE product_id=? AND email=? AND notified_at IS NULL');
    $st->execute([$pid, $email]);
    if ((int)$st->fetchColumn() === 0) {
        kv_db()->prepare('INSERT INTO restock_requests (product_id,email,created_at,ip) VALUES (?,?,?,?)')
               ->execute([$pid, $email, date('Y-m-d H:i:s'), (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    }
    return true;
}

/** 在庫が 0 → 1 以上になったとき、待っている人がいれば店に知らせる（送るのは管理画面から・おちゃのこネットと同じ）。 */
function kv_restock_staff_notice(int $pid): void
{
    $n = (int)kv_db()->query('SELECT COUNT(*) FROM restock_requests WHERE notified_at IS NULL AND product_id=' . $pid)->fetchColumn();
    if (!$n) { return; }
    $name = (string)kv_db()->query('SELECT name FROM products WHERE id=' . $pid)->fetchColumn();
    $h = 'From: ' . mb_encode_mimeheader(kv_shop('name')) . ' <' . kv_shop('email') . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    kv_mail(kv_shop('email'), mb_encode_mimeheader(kv_mail_subject('【再入荷お知らせ】送れる商品があります')),
        $name . " が入荷しました（再入荷お知らせの登録 {$n}件）。\n\n再入荷お知らせ登録一覧から送ってください:\n" . kv_site_url('admin.php?a=restock') . "\n", $h);
}

/** 登録者に再入荷のお知らせを送る。送った件数を返す。 */
function kv_restock_send(int $pid): int
{
    kv_stock_schema();
    $p = kv_db()->query('SELECT * FROM products WHERE id=' . $pid)->fetch();
    if (!$p) { return 0; }
    $s = kv_stock_settings();
    $map = ['{product_name}' => trim(preg_replace('/【[^】]*】/u', '', (string)$p['name'])), '{product_url}' => kv_site_url('product/' . $pid),
            '{shop}' => kv_shop('name') . '（' . kv_shop('company') . '）', '{email}' => kv_shop('email')];
    $subj = kv_mail_subject(strtr($s['restock_subject'], $map));
    $body = strtr($s['restock_body'], $map);
    if (trim(kv_shop('mail_footer')) !== '') { $body = rtrim($body) . "\n\n" . trim(kv_shop('mail_footer')) . "\n"; }
    $h = 'From: ' . mb_encode_mimeheader(kv_shop('name')) . ' <' . kv_shop('email') . ">\r\nReply-To: " . kv_shop('email') . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $n = 0;
    $up = kv_db()->prepare('UPDATE restock_requests SET notified_at=? WHERE id=?');
    foreach (kv_db()->query('SELECT id,email FROM restock_requests WHERE notified_at IS NULL AND product_id=' . $pid)->fetchAll() as $r) {
        if (kv_mail($r['email'], mb_encode_mimeheader($subj), $body, $h)) { $up->execute([date('Y-m-d H:i:s'), $r['id']]); $n++; }
    }
    return $n;
}

/**
 * メーカーごとの納期（2026-11-18）。kv_data/maker_leadtime.json: {"メーカーID": {"min": 営業日, "max": 営業日, "note": "表示文"}}
 * 受注生産などで発送まで日数がかかるメーカーに設定する。Google ショッピングのフィード（発送までの日数）と商品ページの表示に使う。
 */
function kv_maker_leadtime($makerId): ?array
{
    static $all = null;
    if ($all === null) {
        $f = KV_DATA . '/maker_leadtime.json';
        $all = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    $v = $all[(string)(int)$makerId] ?? null;
    return is_array($v) && (int)($v['max'] ?? 0) > 0 ? $v : null;
}

/**
 * 商品ごとの納期（2026-11-20 ユーザー指示「価格表に無い商品は在庫0・納期お問い合わせくださいに」）。
 * kv_data/product_leadtime.json: {"商品ID": {"min": 営業日, "max": 営業日, "note": "表示文"}}。商品の指定があればメーカーの指定より優先。
 */
function kv_product_leadtime(array $p): ?array
{
    static $all = null;
    if ($all === null) {
        $f = KV_DATA . '/product_leadtime.json';
        $all = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    $v = $all[(string)(int)($p['id'] ?? 0)] ?? null;
    if (is_array($v) && (int)($v['max'] ?? 0) > 0) { return $v; }
    return kv_maker_leadtime($p['maker_id'] ?? 0);
}

/**
 * メーカーごと・商品ごとのお支払い方法（2026-11-20 ユーザー指示）。
 * kv_data/payment_rules.json: {"makers": {"メーカーID": "bank"}, "products": {"商品ID": "bank"|"stripe"|"all"}}
 *   bank = 銀行振込のみ／stripe = クレジットカードのみ／all = 制限なし（メーカーの指定を商品だけ外すとき）
 * 商品の指定がメーカーの指定より優先。カートに複数の商品があるときは、全商品で使える方法だけを出す。
 */
const KV_PAY_LABELS = ['all' => 'すべて', 'bank' => '銀行振込のみ', 'stripe' => 'クレジットカードのみ'];

function kv_payment_rules(bool $reload = false): array
{
    static $r = null;
    if ($r === null || $reload) {
        $f = KV_DATA . '/payment_rules.json';
        $r = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        $r += ['makers' => [], 'products' => []];
    }
    return $r;
}

/** 'maker' か 'product' の指定を保存する。$rule が 'all'（メーカー）や ''（商品）なら指定を消す。 */
function kv_payment_rule_set(string $kind, int $id, string $rule): void
{
    $r = kv_payment_rules(true);
    $k = $kind === 'maker' ? 'makers' : 'products';
    if ($rule === '' || ($kind === 'maker' && $rule === 'all') || !isset(KV_PAY_LABELS[$rule])) { unset($r[$k][(string)$id]); }
    else { $r[$k][(string)$id] = $rule; }
    file_put_contents(KV_DATA . '/payment_rules.json', json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    kv_payment_rules(true);
}

/** その商品の指定（'all' / 'bank' / 'stripe'）。 */
function kv_payment_rule(array $p): string
{
    $r = kv_payment_rules();
    return (string)($r['products'][(string)(int)($p['id'] ?? 0)] ?? $r['makers'][(string)(int)($p['maker_id'] ?? 0)] ?? 'all');
}

/** その商品で使えるお支払い方法。 */
function kv_payment_methods(array $p): array
{
    $rule = kv_payment_rule($p);
    return $rule === 'all' ? ['bank', 'stripe'] : [$rule];
}

/** カート全体で使えるお支払い方法と、制限している商品名。全商品で共通の方法が無ければ銀行振込にする。 */
function kv_cart_payment_methods(array $r): array
{
    $m = ['bank', 'stripe']; $why = [];
    foreach ($r['lines'] as $l) {
        $pm = kv_payment_methods($l['product']);
        if (count($pm) < 2) { $why[] = (string)$l['product']['name']; }
        $m = array_values(array_intersect($m, $pm));
    }
    return ['methods' => $m ?: ['bank'], 'restricted' => $why];
}
