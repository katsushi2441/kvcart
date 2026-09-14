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
    ");
}

function kv_order_create(array $cart, array $post): int
{
    kv_order_schema();
    $db = kv_db();
    $now = date('Y-m-d H:i:s');
    $code = date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $db->beginTransaction();
    $st = $db->prepare('INSERT INTO orders (code,created_at,name,company,email,tel,zip,address,note,'
        . 'payment,subtotal,tax,total,f_received,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $st->execute([$code, $now,
        trim((string)($post['name'] ?? '')), trim((string)($post['company'] ?? '')),
        trim((string)($post['email'] ?? '')), trim((string)($post['tel'] ?? '')),
        trim((string)($post['zip'] ?? '')), trim((string)($post['address'] ?? '')),
        trim((string)($post['note'] ?? '')),
        ($post['payment'] ?? 'bank') === 'stripe' && kv_stripe_ready() ? 'stripe' : 'bank',
        $cart['subtotal'], $cart['tax'], $cart['total'], $now]);
    $oid = (int)$db->lastInsertId();
    $si = $db->prepare('INSERT INTO order_items (order_id,product_id,name,model_number,unit,qty,amount,tax)'
                       . ' VALUES (?,?,?,?,?,?,?,?)');
    foreach ($cart['lines'] as $l) {
        $p = $l['product'];
        $si->execute([$oid, (int)$p['id'], $p['name'], $p['model_number'],
                      $l['unit'], $l['qty'], $l['amount'], $l['tax']]);
    }
    kv_log($oid, 'created', '注文を受け付けました');
    $db->commit();
    kv_mail_send($oid, 'order_received');
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
            . "受注番号: {code}\n合計: {total}円（税込）\n\n{items}\n\n{bank}\n\n{shop}\n{email}\n"],
        'flag_paid' => ['ご入金を確認しました（{code}）',
            "{name} 様\n\nご入金を確認しました。ありがとうございます。\n"
            . "準備ができ次第、発送します。\n\n受注番号: {code}\n合計: {total}円\n\n{shop}\n"],
        'flag_shipped' => ['商品を発送しました（{code}）',
            "{name} 様\n\n商品を발送しました。\n\n受注番号: {code}\n{tracking}\n\n{shop}\n"],
        'flag_received' => ['ご注文を承りました（{code}）',
            "{name} 様\n\nご注文を承りました。\n\n受注番号: {code}\n\n{shop}\n"],
        'flag_other' => ['ご連絡（{code}）', "{name} 様\n\n受注番号 {code} についてご連絡します。\n\n{shop}\n"],
    ];
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
    $o = kv_order($oid);
    [$subj, $body] = kv_mail_template($key);
    $items = '';
    foreach (kv_order_items($oid) as $it) {
        $items .= sprintf("%s%s × %d = %s円\n", $it['name'],
            $it['model_number'] ? ' (' . $it['model_number'] . ')' : '',
            (int)$it['qty'], number_format((int)$it['amount']));
    }
    $items .= sprintf("\n小計 %s円 / 消費税 %s円 / 合計 %s円",
        number_format((int)$o['subtotal']), number_format((int)$o['tax']), number_format((int)$o['total']));
    $map = [
        '{code}' => $o['code'], '{name}' => $o['name'] ?: 'お客様',
        '{total}' => number_format((int)$o['total']), '{items}' => $items,
        '{bank}' => $o['payment'] === 'bank' ? "【お振込先】\n" . kv_shop('bank') : '',
        '{tracking}' => $o['tracking'] ? '送り状番号: ' . $o['tracking'] : '',
        '{shop}' => kv_shop('name') . '（' . kv_shop('company') . '）',
        '{email}' => kv_shop('email'),
    ];
    return [strtr($subj, $map), strtr($body, $map)];
}

/**
 * メール送信。**失敗しても注文は壊さない**（送れなかったことをログに残す）。
 * レンタルサーバーの mail() を使う。SMTP が要るなら後で差し替える。
 */
function kv_mail_send(int $oid, string $key): bool
{
    $o = kv_order($oid);
    if (!$o || !$o['email']) { return false; }
    [$subj, $body] = kv_mail_render($oid, $key);
    if ($subj === '') { return false; }
    $from = kv_shop('email');
    $h = "From: " . mb_encode_mimeheader(kv_shop('name')) . " <$from>\r\n"
       . "Reply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($o['email'], mb_encode_mimeheader($subj), $body, $h);
    kv_log($oid, 'mail', ($ok ? '送信' : '送信できず') . ': ' . $key . ' → ' . $o['email']);
    return (bool)$ok;
}
