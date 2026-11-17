<?php
/**
 * Kurage Vibe-Cart — 受注。
 *
 * **おちゃのこネットに合わせて、単一ステータスにしない。**
 * 公式マニュアルのとおり「受注・入金・発送・その他」を**それぞれ独立にチェック**する作りにする
 * （https://www.ocnk.net/webmanual/index.php?action=artikel&id=6）。
 * 入金だけ済んで発送がまだ、発送したが入金がまだ（掛け売り）という実務がそのまま表せる。
 *
 * チェックとメール送信は連動させる（チェックを入れた時にテンプレートから送る）。
 */
declare(strict_types=1);

const KV_FLAGS = [
    'received' => '受注',
    'paid'     => '入金',
    'shipped'  => '発送',
    'other'    => 'その他',
];

function kv_order_schema(): void
{
    kv_db()->exec("
    CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT UNIQUE NOT NULL,
      created_at TEXT NOT NULL,
      name TEXT, company TEXT, email TEXT, tel TEXT, zip TEXT, address TEXT, note TEXT,
      name_kana TEXT, department TEXT, fax TEXT, dm INTEGER DEFAULT 0,
      ship_to TEXT, ship_name TEXT, ship_zip TEXT, ship_address TEXT, ship_tel TEXT,
      payment TEXT,                 -- bank / stripe
      subtotal INTEGER, tax INTEGER, shipping INTEGER DEFAULT 0, total INTEGER,
      f_received INTEGER DEFAULT 0, f_paid INTEGER DEFAULT 0,
      f_shipped INTEGER DEFAULT 0,  f_other INTEGER DEFAULT 0,
      received_at TEXT, paid_at TEXT, shipped_at TEXT, other_at TEXT,
      cancelled INTEGER DEFAULT 0, hidden INTEGER DEFAULT 0,
      admin_memo TEXT, tracking TEXT,
      stripe_id TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_orders_created ON orders(created_at);
    CREATE INDEX IF NOT EXISTS ix_orders_email ON orders(email);
    CREATE TABLE IF NOT EXISTS order_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      order_id INTEGER NOT NULL,
      product_id INTEGER, name TEXT, model_number TEXT,
      unit INTEGER, qty INTEGER, amount INTEGER, tax INTEGER
    );
    CREATE INDEX IF NOT EXISTS ix_items_order ON order_items(order_id);
    CREATE TABLE IF NOT EXISTS order_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      order_id INTEGER NOT NULL, at TEXT NOT NULL, kind TEXT, detail TEXT
    );
    CREATE TABLE IF NOT EXISTS mail_templates (
      key TEXT PRIMARY KEY, subject TEXT, body TEXT
    );
    CREATE TABLE IF NOT EXISTS order_mails (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      order_id INTEGER NOT NULL, at TEXT NOT NULL, to_addr TEXT, cc TEXT, subject TEXT, body TEXT, ok INTEGER
    );
    CREATE INDEX IF NOT EXISTS ix_mails_order ON order_mails(order_id);
    ");
    // メールマネジメント（おちゃのこネット）の区分。送ったメールがどの区分か（受注・発送・入金・その他）と、テンプレートの名前・区分
    static $up = false;
    if (!$up) {
        $up = true;
        $has = fn(string $t, string $c) => in_array($c, array_column(kv_db()->query("PRAGMA table_info($t)")->fetchAll(), 'name'), true);
        if (!$has('order_mails', 'kind'))    { kv_db()->exec('ALTER TABLE order_mails ADD COLUMN kind TEXT'); }
        if (!$has('mail_templates', 'label')) { kv_db()->exec('ALTER TABLE mail_templates ADD COLUMN label TEXT'); }
        if (!$has('mail_templates', 'kind'))  { kv_db()->exec('ALTER TABLE mail_templates ADD COLUMN kind TEXT'); }
        // 区分を記録する前に送ったメールは、件名から区分を当てる
        kv_db()->exec("UPDATE order_mails SET kind = CASE WHEN subject LIKE '%発送%' THEN 'shipped' WHEN subject LIKE '%入金%' THEN 'paid'"
                      . " WHEN subject LIKE '%ご注文%' OR subject LIKE '%納期%' OR subject LIKE '%承り%' THEN 'received' ELSE 'other' END WHERE kind IS NULL");
    }
}

/** 購入者の入力から、保存する住所文字列を組み立てる（都道府県＋市区町村＋番地＋建物）。 */
function kv_join_address(array $p, string $prefix = ''): string
{
    $k = fn($n) => trim((string)($p[$prefix . $n] ?? ''));
    return trim($k('pref') . $k('address1') . $k('address2') . ' ' . $k('address3'));
}

function kv_order_create(array $cart, array $post): int
{
    kv_order_schema();
    $db = kv_db();
    $now = date('Y-m-d H:i:s');
    $code = date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $db->beginTransaction();
    $ship_other = ($post['ship_to'] ?? 'same') === 'other';
    $st = $db->prepare('INSERT INTO orders (code,created_at,name,name_kana,company,department,email,tel,fax,'
        . 'zip,address,note,dm,ship_to,ship_name,ship_zip,ship_address,ship_tel,'
        . 'payment,subtotal,tax,total,f_received,received_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $st->execute([$code, $now,
        trim((string)($post['name'] ?? '')), trim((string)($post['name_kana'] ?? '')),
        trim((string)($post['company'] ?? '')), trim((string)($post['department'] ?? '')),
        trim((string)($post['email'] ?? '')), trim((string)($post['tel'] ?? '')),
        trim((string)($post['fax'] ?? '')),
        trim((string)($post['zip'] ?? '')), kv_join_address($post),
        trim((string)($post['note'] ?? '')), (string)($post['dm'] ?? '0') === '1' ? 1 : 0,
        $ship_other ? 'other' : 'same',
        $ship_other ? trim((string)($post['s_name'] ?? '')) : '',
        $ship_other ? trim((string)($post['s_zip'] ?? '')) : '',
        $ship_other ? kv_join_address($post, 's_') : '',
        $ship_other ? trim((string)($post['s_tel'] ?? '')) : '',
        ($post['payment'] ?? 'bank') === 'stripe' && kv_stripe_ready() ? 'stripe' : 'bank',
        $cart['subtotal'], $cart['tax'], $cart['total'], $now]);
    $oid = (int)$db->lastInsertId();
    $si = $db->prepare('INSERT INTO order_items (order_id,product_id,name,model_number,unit,qty,amount,tax)'
                       . ' VALUES (?,?,?,?,?,?,?,?)');
    foreach ($cart['lines'] as $l) {
        $p = $l['product'];
        $si->execute([$oid, (int)$p['id'], $p['name'] . (!empty($l['opt']) ? '（' . $l['opt'] . '）' : ''), $p['model_number'],
                      $l['unit'], $l['qty'], $l['amount'], $l['tax']]);
    }
    kv_log($oid, 'created', '注文を受け付けました');
    $db->commit();
    // カード払いは、決済が終わってから受付メールを送る（2026-11-17 ユーザー指示「カード決済してから注文受付の自動メール」）。
    // 決済の完了は Stripe の通知（kv_stripe_handle の checkout.session.completed）で受け、order_paid_card を送る
    $o = kv_order($oid);
    if ($o && $o['payment'] === 'stripe') {
        kv_log($oid, 'mail', 'カード払いのため、受付メールはお支払いの完了後に送ります');
    } else {
        kv_mail_send($oid, 'order_received');
    }
    return $oid;
}

function kv_order(int $id): ?array
{
    kv_order_schema();
    $st = kv_db()->prepare('SELECT * FROM orders WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function kv_order_items(int $id): array
{
    $st = kv_db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll();
}

function kv_log(int $oid, string $kind, string $detail): void
{
    $st = kv_db()->prepare('INSERT INTO order_logs (order_id,at,kind,detail) VALUES (?,?,?,?)');
    $st->execute([$oid, date('Y-m-d H:i:s'), $kind, $detail]);
}

function kv_order_logs(int $oid): array
{
    $st = kv_db()->prepare('SELECT * FROM order_logs WHERE order_id=? ORDER BY id DESC');
    $st->execute([$oid]);
    return $st->fetchAll();
}

/** 4系統のチェックを個別に立てる／降ろす。メール送信と連動させる。 */
function kv_order_flag(int $oid, string $flag, bool $on, bool $send_mail = false): void
{
    if (!isset(KV_FLAGS[$flag])) { return; }
    $col = 'f_' . $flag;
    $at = $flag . '_at';
    $st = kv_db()->prepare("UPDATE orders SET $col=?, $at=? WHERE id=?");
    $st->execute([$on ? 1 : 0, $on ? date('Y-m-d H:i:s') : null, $oid]);
    kv_log($oid, $flag, KV_FLAGS[$flag] . ($on ? 'の処理を完了にしました' : 'の完了を取り消しました'));
    if ($on && $send_mail) {
        kv_mail_send($oid, 'flag_' . $flag);
    }
}

function kv_orders(array $f = [], int $page = 1, int $per = 30): array
{
    kv_order_schema();
    $w = ['1=1'];
    $a = [];
    if (empty($f['show_hidden'])) { $w[] = 'hidden=0'; }
    foreach (array_keys(KV_FLAGS) as $k) {
        if (isset($f['f_' . $k]) && $f['f_' . $k] !== '') {
            $w[] = 'f_' . $k . '=?';
            $a[] = (int)$f['f_' . $k];
        }
    }
    if (!empty($f['from']))  { $w[] = 'created_at>=?'; $a[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))    { $w[] = 'created_at<=?'; $a[] = $f['to'] . ' 23:59:59'; }
    if (!empty($f['q'])) {
        $w[] = '(code LIKE ? OR name LIKE ? OR company LIKE ? OR email LIKE ? OR tel LIKE ? OR admin_memo LIKE ?)';
        for ($i = 0; $i < 6; $i++) { $a[] = '%' . $f['q'] . '%'; }
    }
    if (isset($f['cancelled']) && $f['cancelled'] !== '') { $w[] = 'cancelled=?'; $a[] = (int)$f['cancelled']; }
    $sql = 'FROM orders WHERE ' . implode(' AND ', $w);
    $st = kv_db()->prepare('SELECT COUNT(*) ' . $sql);
    $st->execute($a);
    $total = (int)$st->fetchColumn();
    $off = (max(1, $page) - 1) * $per;
    $st = kv_db()->prepare('SELECT * ' . $sql . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)$off);
    $st->execute($a);
    return ['items' => $st->fetchAll(), 'total' => $total, 'page' => max(1, $page),
            'per' => $per, 'pages' => (int)ceil($total / $per)];
}

/** リピーター判定。同じメールで過去に注文があるか（おちゃのこネットの「リピーター表示」）。 */
function kv_is_repeater(string $email, int $exclude_id): bool
{
    if ($email === '') { return false; }
    $st = kv_db()->prepare('SELECT COUNT(*) FROM orders WHERE email=? AND id<>? AND cancelled=0');
    $st->execute([$email, $exclude_id]);
    return (int)$st->fetchColumn() > 0;
}

// ---- メール ----

function kv_mail_defaults(): array
{
    return [
        'order_received' => ['ご注文ありがとうございます（{code}）',
            "{name} 様\n\nご注文ありがとうございます。下記の内容で承りました。\n\n"
            . "受注番号: {code}\n合計: {total}円（税込）\n\n{items}\n\n{next}\n\n{shop}\n{email}\n"],
        'order_paid_card' => ['ご注文とお支払いを確認しました（{code}）',
            "{name} 様\n\nご注文と、クレジットカードでのお支払いを確認しました。ありがとうございます。\n\n"
            . "受注番号: {code}\nお支払い金額: {total}円（税込）\n\n{items}\n\n"
            . "【今後の流れ】\nメーカーに在庫と納期を確認し、納期をメールでお知らせします。\n\n{shop}\n{email}\n"],
        'flag_paid' => ['ご入金を確認しました（{code}）',
            "{name} 様\n\nご入金を確認しました。ありがとうございます。\n"
            . "準備ができ次第、発送します。\n\n受注番号: {code}\n合計: {total}円\n\n{shop}\n"],
        'flag_shipped' => ['商品を発送しました（{code}）',
            "{name} 様\n\n商品を発送しました。\n\n受注番号: {code}\n{tracking}\n\n{shop}\n"],
        'flag_received' => ['ご注文を承りました（{code}）',
            "{name} 様\n\nご注文を承りました。\n\n受注番号: {code}\n\n{shop}\n"],
        'flag_other' => ['ご連絡（{code}）', "{name} 様\n\n受注番号 {code} についてご連絡します。\n\n{shop}\n"],
        // 在庫・納期を確かめたあとの案内（2026-11-17 追加）。納期はメール作成画面で書き換えてから送る
        'stock_ok' => ['納期とお振込先のご案内（{code}）',
            "{name} 様\n\nメーカーに確認したところ、納期は次のとおりです。\n\n受注番号: {code}\n{items}\n\n"
            . "納期: ご入金を確認してから、〇営業日程度\n\n"
            . "納期がかかりますので、ご入金の前であれば、キャンセルしていただいても差し支えございません。\n"
            . "この納期でよろしければ、下記へお振込みをお願いいたします。\n\n{bank_account}\n金額: {total}円（税込・送料無料）\n"
            . "※振込手数料は、お客様のご負担でお願いいたします。\n\n"
            . "ご入金を確認しましたら、メーカーに手配し、発送の予定をあらためてお知らせいたします。\n\n{shop}\n{email}\n"],
        'stripe_invoice' => ['お支払いのご案内（{code}）',
            "{name} 様\n\nご注文いただいた商品の在庫・納期・送料を確認しました。\n"
            . "下のページから、クレジットカードでお支払いください（お支払い期限は7日後です）。\n\n"
            . "{pay_url}\n\n受注番号: {code}\nお支払い金額: {total}円（税込）\n\n{items}\n\n"
            . "お支払いを確認したら、あらためてメールでお知らせします。\n\n{shop}\n{email}\n"],
    ];
}

/** テンプレートの区分（おちゃのこネットのメールマネジメントの4区分）。 */
function kv_mail_default_kinds(): array
{
    return ['order_received' => 'received', 'order_paid_card' => 'received', 'stock_ok' => 'received', 'flag_received' => 'received',
            'flag_paid' => 'paid', 'flag_shipped' => 'shipped', 'flag_other' => 'other', 'stripe_invoice' => 'other'];
}

/** 全テンプレート（標準＋自分で足したもの）: key => ['label','kind']。 */
function kv_mail_templates_all(): array
{
    kv_order_schema();
    $out = [];
    $kinds = kv_mail_default_kinds();
    foreach (kv_mail_default_labels() as $k => $l) { $out[$k] = ['label' => $l, 'kind' => $kinds[$k] ?? 'other']; }
    foreach (kv_db()->query('SELECT key,label,kind FROM mail_templates') as $r) {
        if (isset($out[$r['key']])) {
            if ($r['label']) { $out[$r['key']]['label'] = $r['label']; }
            if ($r['kind'])  { $out[$r['key']]['kind'] = $r['kind']; }
        } elseif (strpos((string)$r['key'], 'custom_') === 0) {
            $out[$r['key']] = ['label' => $r['label'] ?: $r['key'], 'kind' => $r['kind'] ?: 'other'];
        }
    }
    return $out;
}

function kv_mail_labels(): array
{
    return array_map(fn($t) => $t['label'], kv_mail_templates_all());
}

function kv_mail_kind(string $key): string
{
    return kv_mail_templates_all()[$key]['kind'] ?? 'other';
}

/** 件名の先頭に必ず [店名] を付ける（2026-11-17 ユーザー指示「メールのタイトルには [X-Direct] って必ず入れて」）。 */
function kv_mail_subject(string $s): string
{
    $tag = '[' . kv_shop('name') . ']';
    $s = trim($s);
    return kv_shop('name') === '' || strpos($s, $tag) !== false ? $s : $tag . ' ' . $s;
}

/** 受注ごとに、どの区分のメールを送ったか（受注一覧のメールアイコンの青いチェック）。 */
function kv_order_mail_kinds(int $oid): array
{
    kv_order_schema();
    $st = kv_db()->prepare('SELECT DISTINCT kind FROM order_mails WHERE order_id=? AND ok=1 AND kind IS NOT NULL');
    $st->execute([$oid]);
    return array_fill_keys(array_column($st->fetchAll(), 'kind'), true);
}

/** 標準テンプレートの名前。 */
function kv_mail_default_labels(): array
{
    return ['order_received' => 'ご注文受付（注文時に自動）', 'order_paid_card' => 'カード払いのご注文受付（決済後に自動）',
            'stock_ok' => '納期とお振込先のご案内', 'flag_received' => '受注の完了', 'flag_paid' => '入金の確認',
            'flag_shipped' => '発送のご連絡', 'flag_other' => 'その他のご連絡（自由に書く）', 'stripe_invoice' => 'カード払いのお支払いページの案内'];
}

/** 管理画面で書いたメールを、そのままの件名・本文で送る（Cc 可）。送った全文を order_mails に残す。 */
function kv_mail_send_text(int $oid, string $to, string $cc, string $subj, string $body, string $kind = 'other'): bool
{
    kv_order_schema();
    $subj = kv_mail_subject($subj);
    $from = kv_shop('email');
    $h = "From: " . mb_encode_mimeheader(kv_shop('name')) . " <$from>\r\n"
       . "Reply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = kv_mail($to, mb_encode_mimeheader($subj), $body, $h, $cc);
    kv_db()->prepare('INSERT INTO order_mails (order_id,at,to_addr,cc,subject,body,ok,kind) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$oid, date('Y-m-d H:i:s'), $to, $cc, $subj, $body, $ok ? 1 : 0, $kind]);
    kv_log($oid, 'mail', ($ok ? '送信' : '送信できず') . ': ' . $subj . ' → ' . $to . ($cc !== '' ? '（Cc ' . $cc . '）' : ''));
    return (bool)$ok;
}

function kv_order_mails(int $oid): array
{
    kv_order_schema();
    $st = kv_db()->prepare('SELECT * FROM order_mails WHERE order_id=? ORDER BY id DESC');
    $st->execute([$oid]);
    return $st->fetchAll();
}

function kv_mail_template(string $key): array
{
    kv_order_schema();
    $st = kv_db()->prepare('SELECT subject,body FROM mail_templates WHERE key=?');
    $st->execute([$key]);
    $r = $st->fetch();
    if ($r) { return [$r['subject'], $r['body']]; }
    $d = kv_mail_defaults();
    return $d[$key] ?? ['', ''];
}

function kv_mail_render(int $oid, string $key): array
{
    [$subj, $body] = kv_mail_template($key);
    return kv_mail_render_text($oid, $subj, $body);
}

/** 埋め込みタグ（{name} など）を受注の内容に置き換える。まとめて送るときは、1通ずつここを通す。 */
function kv_mail_render_text(int $oid, string $subj, string $body): array
{
    $o = kv_order($oid);
    $items = '';
    foreach (kv_order_items($oid) as $it) {
        $items .= sprintf("%s%s × %d = %s円\n", $it['name'],
            (($mdl = kv_model_display($it['model_number'], $it['name'])) !== '' && mb_stripos($it['name'], $mdl) !== 0) ? ' (' . $mdl . ')' : '',
            (int)$it['qty'], number_format((int)$it['amount']));
    }
    $items .= sprintf("\n小計 %s円 / 消費税 %s円 / 合計 %s円",
        number_format((int)$o['subtotal']), number_format((int)$o['tax']), number_format((int)$o['total']));
    $map = [
        '{code}' => $o['code'], '{name}' => $o['name'] ?: 'お客様',
        '{total}' => number_format((int)$o['total']), '{items}' => $items,
        '{bank}' => $o['payment'] === 'bank' ? "【お振込先】\n" . kv_shop('bank')
                    : "【お支払い】\nクレジットカード（ご注文時のお支払い画面）。お支払いを確認したら、あらためてお知らせします。",
        // 注文受付メール用。振込先は書かず、在庫・納期の確認後に案内することだけ伝える
        '{next}' => $o['payment'] === 'bank'
                    ? "【今後の流れ】\nメーカーに在庫と納期を確認しています。確認ができしだい、納期とお振込先をメールでお知らせします。\n"
                      . "お振込みは、その連絡をお待ちいただいてからお願いいたします。"
                    : "【お支払い】\nクレジットカード（ご注文時のお支払い画面）。お支払いを確認したら、あらためてお知らせします。",
        '{pay_url}' => $o['payment'] === 'stripe' && function_exists('kv_stripe_pay_url') ? kv_stripe_pay_url($o) : '',
        '{tracking}' => $o['tracking'] ? '送り状番号: ' . $o['tracking'] : '',
        // 口座そのもの（kv_config.php の shop.bank_account）。{bank} は注文時の案内文なので別にする
        '{bank_account}' => "【お振込先】\n" . (kv_shop('bank_account') !== '' ? kv_shop('bank_account') : kv_shop('bank')),
        '{shop}' => kv_shop('name') . '（' . kv_shop('company') . '）',
        '{email}' => kv_shop('email'),
    ];
    $body = strtr($body, $map);
    // 設定の mail_footer（任意）を、お客様へのメールすべての末尾に付ける（会社の他事業の案内など）
    $footer = trim(kv_shop('mail_footer'));
    if ($footer !== '') { $body = rtrim($body) . "\n\n" . $footer . "\n"; }
    return [strtr($subj, $map), $body];
}

/**
 * メール送信。**失敗しても注文は壊さない**（送れなかったことをログに残す）。
 * kv_mail()（設定に KV_SMTP があれば SMTP、無ければ mail()）で送る。
 */
function kv_mail_send(int $oid, string $key): bool
{
    $o = kv_order($oid);
    if (!$o || !$o['email']) { return false; }
    [$subj, $body] = kv_mail_render($oid, $key);
    if ($subj === '') { return false; }
    $subj = kv_mail_subject($subj);
    $from = kv_shop('email');
    $h = "From: " . mb_encode_mimeheader(kv_shop('name')) . " <$from>\r\n"
       . "Reply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = kv_mail($o['email'], mb_encode_mimeheader($subj), $body, $h);
    kv_log($oid, 'mail', ($ok ? '送信' : '送信できず') . ': ' . $key . ' → ' . $o['email']);
    kv_db()->prepare('INSERT INTO order_mails (order_id,at,to_addr,cc,subject,body,ok,kind) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$oid, date('Y-m-d H:i:s'), $o['email'], '', $subj, $body, $ok ? 1 : 0, kv_mail_kind($key)]);
    if (in_array($key, ['order_received', 'order_paid_card'], true) && $from !== '') {
        // 店にも同じ内容を送る（新しい注文に気づけるように）。カード払いは決済が終わってから
        $note = ($key === 'order_paid_card' ? "新しい注文が入り、カードでのお支払いも確認しました。" : "新しい注文が入りました。")
              . "お客様には下の内容でメールを送っています（送信: " . ($ok ? '成功' : '失敗') . "）。\n"
              . "お客様: " . $o['name'] . ' ' . ($o['company'] ?? '') . " / " . $o['email'] . " / " . ($o['tel'] ?? '') . "\n"
              . "発注用の社内型番: " . implode(' / ', array_map(static fn($it) => $it['model_number'] . ' ×' . (int)$it['qty'], kv_order_items($oid))) . "\n"
              . (!empty($GLOBALS['kv_risk_note']) ? "カード決済の確認: " . $GLOBALS['kv_risk_note'] . "\n" : '')
              . "------------------------------------\n";
        $ok2 = kv_mail($from, mb_encode_mimeheader('【新規注文】' . $subj), $note . $body, $h);
        kv_log($oid, 'mail', ($ok2 ? '送信' : '送信できず') . ': 店への通知 → ' . $from);
    }
    return (bool)$ok;
}
