<?php
/**
 * Kurage Vibe-Cart — 会員（2026-11-22 ユーザー指示「リピーターがいる。住所を毎回入れるのは面倒」）。
 *
 * **パスワードを使わない。** メールアドレスを入れると、ログイン用のリンクをメールで送る。
 *   - おちゃのこネットのパスワードは取り出せないので、移行した会員も最初からそのまま使える。
 *   - 業務のお客様は年に数回しか買わないので、パスワードを忘れてゲスト購入になるのを避ける。
 *   - パスワードを預からないので、漏れたときの害が小さい。
 * リンクは 60 分・1 回だけ。メールのウイルス検査などがリンクを先に開いても使い切らないよう、
 * 開いた画面で「ログインする」を押したときに使う（GET では使わない）。
 * ログインの状態はセッションに置く（kv_session.php で 365 日）。
 *
 * テーブル
 *   members            会員（メールアドレスで1人。おちゃのこの会員ID ocnk_id を残す）
 *   member_addresses   お届け先の帳面
 *   member_past_orders おちゃのこネット時代の注文（マイページの履歴と「もう一度買う」用）
 *   member_tokens      ログイン用リンク（中身はハッシュだけ保存）
 *   member_login_log   リンクを送った記録（送りすぎを止める）
 * orders.member_id    kvcart の注文と会員をつなぐ
 *
 * 移行: scripts/build_member_import.py（おちゃのこの登録者CSV＋受注API → JSON）→ scripts/import_members.php
 */
declare(strict_types=1);

const KV_MEMBER_TOKEN_MIN = 60;          // ログイン用リンクの有効時間（分）
const KV_MEMBER_MAIL_PER_HOUR = 3;       // 同じメールアドレスへ1時間に送る上限
const KV_MEMBER_IP_PER_HOUR = 10;        // 同じIPから1時間に受け付ける上限

function kv_member_schema(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    kv_order_schema();
    kv_db()->exec("
    CREATE TABLE IF NOT EXISTS members (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL UNIQUE COLLATE NOCASE,
      name TEXT, name_kana TEXT, company TEXT, department TEXT,
      zip TEXT, pref TEXT, address1 TEXT, address2 TEXT, address3 TEXT,
      tel TEXT, fax TEXT, dm INTEGER DEFAULT 0,
      source TEXT,                 -- ocnk（おちゃのこから移行） / kvcart（この店で登録）
      ocnk_id INTEGER,             -- おちゃのこネットの会員ID
      ocnk_sales_count INTEGER DEFAULT 0, ocnk_sales_total INTEGER DEFAULT 0, ocnk_last_order TEXT,
      created_at TEXT NOT NULL, updated_at TEXT, last_login_at TEXT
    );
    CREATE TABLE IF NOT EXISTS member_addresses (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      member_id INTEGER NOT NULL,
      name TEXT, company TEXT, zip TEXT, pref TEXT, address1 TEXT, address2 TEXT, address3 TEXT, tel TEXT,
      akey TEXT,                   -- 同じ届け先を二重に入れないための照合キー
      created_at TEXT NOT NULL, last_used_at TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_maddr_member ON member_addresses(member_id);
    CREATE TABLE IF NOT EXISTS member_past_orders (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL COLLATE NOCASE,
      ocnk_order_id INTEGER UNIQUE,
      ordered_at TEXT, total INTEGER, items TEXT,   -- items は [{product_id,name,model_number,qty,price,variations}] の JSON
      ship_name TEXT, ship_address TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_mpast_email ON member_past_orders(email);
    CREATE TABLE IF NOT EXISTS member_tokens (
      hash TEXT PRIMARY KEY, member_id INTEGER NOT NULL,
      created_at TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT, next TEXT
    );
    CREATE TABLE IF NOT EXISTS member_login_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, ip TEXT, email TEXT, sent INTEGER
    );
    ");
    $has = fn(string $t, string $c) => in_array($c, array_column(kv_db()->query("PRAGMA table_info($t)")->fetchAll(), 'name'), true);
    // リンクを頼んだときのカート（別のブラウザ・端末でリンクを開いてもカートを引き継ぐため・2026-11-22）
    if (!$has('member_tokens', 'cart')) { kv_db()->exec('ALTER TABLE member_tokens ADD COLUMN cart TEXT'); }
    if (!$has('orders', 'member_id')) {
        kv_db()->exec('ALTER TABLE orders ADD COLUMN member_id INTEGER');
        kv_db()->exec('CREATE INDEX IF NOT EXISTS ix_orders_member ON orders(member_id)');
    }
}

function kv_member_email(string $e): string
{
    return mb_strtolower(trim(mb_convert_kana($e, 'a', 'UTF-8')), 'UTF-8');
}

function kv_member_by_id(int $id): ?array
{
    kv_member_schema();
    $st = kv_db()->prepare('SELECT * FROM members WHERE id=?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function kv_member_by_email(string $email): ?array
{
    kv_member_schema();
    $st = kv_db()->prepare('SELECT * FROM members WHERE email=?');
    $st->execute([kv_member_email($email)]);
    return $st->fetch() ?: null;
}

/** ログイン中の会員。ログインしていなければ null。 */
function kv_member_current(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['member_id'])) { return null; }
    static $m = false;
    if ($m === false) {
        $m = kv_member_by_id((int)$_SESSION['member_id']);
        if (!$m) { unset($_SESSION['member_id']); }
    }
    return $m ?: null;
}

/** 会員の情報を、レジの入力欄（kv_checkout_fields の名前）の形で返す。 */
function kv_member_checkout_values(array $m): array
{
    return ['name' => (string)$m['name'], 'name_kana' => (string)$m['name_kana'], 'company' => (string)$m['company'],
            'department' => (string)$m['department'], 'zip' => (string)$m['zip'], 'pref' => (string)$m['pref'],
            'address1' => (string)$m['address1'], 'address2' => (string)$m['address2'], 'address3' => (string)$m['address3'],
            'email' => (string)$m['email'], 'tel' => (string)$m['tel'], 'fax' => (string)$m['fax'],
            'dm' => (int)$m['dm'] === 1 ? '1' : '0'];
}

function kv_member_addresses(int $mid): array
{
    kv_member_schema();
    $st = kv_db()->prepare('SELECT * FROM member_addresses WHERE member_id=? ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC LIMIT 30');
    $st->execute([$mid]);
    return $st->fetchAll();
}

function kv_member_address_text(array $a): string
{
    return trim(($a['zip'] ? '〒' . $a['zip'] . ' ' : '') . $a['pref'] . $a['address1'] . $a['address2'] . ' ' . $a['address3']);
}

/** 届け先を帳面に入れる（同じ届け先があれば使った日だけ更新）。 */
function kv_member_address_save(int $mid, array $a): void
{
    kv_member_schema();
    $f = fn($k) => trim((string)($a[$k] ?? ''));
    if ($f('address1') === '' && $f('address2') === '') { return; }
    $key = kv_norm(preg_replace('/[\s\-－ー‐]/u', '', $f('zip') . $f('pref') . $f('address1') . $f('address2') . $f('address3') . '|' . $f('name')));
    $db = kv_db();
    $now = date('Y-m-d H:i:s');
    $st = $db->prepare('SELECT id FROM member_addresses WHERE member_id=? AND akey=?');
    $st->execute([$mid, $key]);
    if ($id = (int)$st->fetchColumn()) {
        $db->prepare('UPDATE member_addresses SET last_used_at=? WHERE id=?')->execute([$now, $id]);
        return;
    }
    $db->prepare('INSERT INTO member_addresses (member_id,name,company,zip,pref,address1,address2,address3,tel,akey,created_at,last_used_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$mid, $f('name'), $f('company'), $f('zip'), $f('pref'), $f('address1'), $f('address2'), $f('address3'), $f('tel'), $key, $now, $now]);
}

/** レジの入力から会員の情報を書き換える（メールアドレスは変えない）。 */
function kv_member_update_profile(int $mid, array $p): void
{
    $f = fn($k) => trim((string)($p[$k] ?? ''));
    kv_db()->prepare('UPDATE members SET name=?,name_kana=?,company=?,department=?,zip=?,pref=?,address1=?,address2=?,address3=?,tel=?,fax=?,dm=?,updated_at=? WHERE id=?')
        ->execute([$f('name'), $f('name_kana'), $f('company'), $f('department'), $f('zip'), $f('pref'), $f('address1'), $f('address2'), $f('address3'),
                   $f('tel'), $f('fax'), (string)($p['dm'] ?? '0') === '1' ? 1 : 0, date('Y-m-d H:i:s'), $mid]);
}

function kv_member_create(array $p, string $source = 'kvcart'): int
{
    kv_member_schema();
    $f = fn($k) => trim((string)($p[$k] ?? ''));
    $now = date('Y-m-d H:i:s');
    kv_db()->prepare('INSERT INTO members (email,name,name_kana,company,department,zip,pref,address1,address2,address3,tel,fax,dm,source,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([kv_member_email($f('email')), $f('name'), $f('name_kana'), $f('company'), $f('department'), $f('zip'), $f('pref'),
                   $f('address1'), $f('address2'), $f('address3'), $f('tel'), $f('fax'), (string)($p['dm'] ?? '0') === '1' ? 1 : 0, $source, $now, $now]);
    return (int)kv_db()->lastInsertId();
}

/**
 * 注文ができたあと（kv_order_create から呼ぶ）。**失敗しても注文は壊さない。**
 *   - ログイン中（_member_id）: 注文を会員につなぎ、「保存する」なら会員の情報を更新、別の届け先を帳面に入れる。
 *   - ログインしていないが同じメールの会員がいる: 注文をつなぐだけ（会員の情報は書き換えない）。
 *   - 「会員登録する」にチェック: 新しく会員を作る。
 */
function kv_member_after_order(int $oid, array $post): void
{
    try {
        kv_member_schema();
        $email = kv_member_email((string)($post['email'] ?? ''));
        if ($email === '') { return; }
        $m = !empty($post['_member_id']) ? kv_member_by_id((int)$post['_member_id']) : null;
        $mine = (bool)$m;   // 本人がログインして注文した
        $save = !empty($post['_member_save']);
        if (!$m) {
            $m = kv_member_by_email($email);
            if (!$m && $save) {
                $m = kv_member_by_id(kv_member_create($post));
                $mine = true;
                kv_log($oid, 'member', '会員登録しました');
            }
        }
        if (!$m) { return; }
        kv_db()->prepare('UPDATE orders SET member_id=? WHERE id=?')->execute([(int)$m['id'], $oid]);
        if (!$mine) { return; }
        if ($save) { kv_member_update_profile((int)$m['id'], $post); }
        if (($post['ship_to'] ?? 'same') === 'other' && !empty($post['_addr_id'])) {
            kv_db()->prepare('UPDATE member_addresses SET last_used_at=? WHERE id=? AND member_id=?')
                   ->execute([date('Y-m-d H:i:s'), (int)$post['_addr_id'], (int)$m['id']]);
        } elseif (($post['ship_to'] ?? 'same') === 'other' && !empty($post['_addr_save'])) {
            kv_member_address_save((int)$m['id'], ['name' => $post['s_name'] ?? '', 'company' => $post['s_company'] ?? '', 'zip' => $post['s_zip'] ?? '',
                'pref' => $post['s_pref'] ?? '', 'address1' => $post['s_address1'] ?? '', 'address2' => $post['s_address2'] ?? '',
                'address3' => $post['s_address3'] ?? '', 'tel' => $post['s_tel'] ?? '']);
        }
    } catch (Throwable $e) {
        error_log('kvcart member after order: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 * ログイン（メールのリンク）
 * ------------------------------------------------------------------ */

function kv_member_safe_next(string $n): string
{
    return in_array($n, ['cart/customer', 'member'], true) ? $n : 'member';
}

/** ログイン用リンクを送る。会員がいなくても、送りすぎでも、画面には同じ案内を出す（登録の有無を知られないため）。 */
function kv_member_send_link(string $email, string $next): void
{
    kv_member_schema();
    $db = kv_db();
    $email = kv_member_email($email);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hour = date('Y-m-d H:i:s', time() - 3600);
    $st = $db->prepare('SELECT COUNT(*) FROM member_login_log WHERE ip=? AND at>?'); $st->execute([$ip, $hour]);
    $by_ip = (int)$st->fetchColumn();
    $st = $db->prepare('SELECT COUNT(*) FROM member_login_log WHERE email=? AND sent=1 AND at>?'); $st->execute([$email, $hour]);
    $by_mail = (int)$st->fetchColumn();
    $m = filter_var($email, FILTER_VALIDATE_EMAIL) ? kv_member_by_email($email) : null;
    $send = $m && $by_ip < KV_MEMBER_IP_PER_HOUR && $by_mail < KV_MEMBER_MAIL_PER_HOUR;
    $db->prepare('INSERT INTO member_login_log (at,ip,email,sent) VALUES (?,?,?,?)')->execute([date('Y-m-d H:i:s'), $ip, $email, $send ? 1 : 0]);
    $db->exec("DELETE FROM member_login_log WHERE at < '" . date('Y-m-d H:i:s', time() - 30 * 86400) . "'");
    $db->exec("DELETE FROM member_tokens WHERE expires_at < '" . date('Y-m-d H:i:s', time() - 86400) . "'");
    if (!$send) { return; }
    $token = bin2hex(random_bytes(24));
    $cart = kv_cart_get();
    $db->prepare('INSERT INTO member_tokens (hash,member_id,created_at,expires_at,next,cart) VALUES (?,?,?,?,?,?)')
       ->execute([hash('sha256', $token), (int)$m['id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + KV_MEMBER_TOKEN_MIN * 60), $next,
                  $cart ? json_encode($cart) : null]);
    $url = kv_abs(kv_url('member/auth')) . '?t=' . $token;
    $shop = kv_shop('name');
    $body = ($m['name'] ? $m['name'] . " 様\n\n" : '')
          . $shop . '（' . kv_shop('company') . "）です。\n"
          . "ログイン用のリンクをお送りします。\n"
          . "下のリンクを開いて「ログインする」を押すと、ご登録のご住所などを呼び出してご注文いただけます。\n\n"
          . $url . "\n\n"
          . "・このリンクは" . KV_MEMBER_TOKEN_MIN . "分間、1回だけ使えます。\n"
          . "・パスワードは必要ありません。次回も、メールアドレスを入れていただければ同じようにログインできます。\n"
          . "・お心当たりが無い場合は、このメールを破棄してください。ログインはされません。\n";
    $footer = trim(kv_shop('mail_footer'));
    if ($footer !== '') { $body = rtrim($body) . "\n\n" . $footer . "\n"; }
    $from = kv_shop('email');
    $h = "From: " . mb_encode_mimeheader($shop) . " <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    // **店の CC は付けない。** リンクを持っている人がそのお客様としてログインできるため（X-Direct の他のメールは info@ に CC する）
    $ok = kv_mail($m['email'], mb_encode_mimeheader('【' . $shop . '】ログイン用のリンク'), $body, $h);
    if (!$ok) { error_log('kvcart member: ログインのメールを送れませんでした member=' . $m['id']); }
}

function kv_page_member_login(): void
{
    kv_session();
    $next = kv_member_safe_next((string)($_POST['next'] ?? ($_GET['next'] ?? 'member')));
    if (kv_member_current()) { header('Location: ' . kv_url($next)); return; }
    $sent = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $sent = kv_member_email((string)($_POST['email'] ?? ''));
        kv_member_send_link($sent, $next);
    }
    kv_head('ログイン', '', '/member/login', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:760px"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ ログイン</nav>'
       . '<h1>ログイン</h1>';
    if ($sent !== '') {
        echo '<div class="note"><b>' . kv_e($sent) . '</b> へ、ログイン用のリンクをお送りしました。<br>'
           . 'メールのリンクを開いて「ログインする」を押してください（' . KV_MEMBER_TOKEN_MIN . '分間有効）。<br>'
           . '<span class="src">数分たっても届かないときは、迷惑メールのフォルダをご確認ください。'
           . 'ご登録の無いメールアドレスには送られません。その場合は、そのままご購入いただけます（ご購入のときに会員登録もできます）。</span></div>'
           . '<p><a class="btn gray" href="' . kv_url($next === 'cart/customer' ? 'cart/customer' : '') . '">' . ($next === 'cart/customer' ? 'お客様情報の入力に戻る' : 'トップへ') . '</a></p></div>';
        kv_footer();
        return;
    }
    echo kv_member_login_form($next, true) . '</div>';
    kv_footer();
}

/** メールアドレスを入れてログイン用リンクを受け取る欄。レジの最初とログイン画面で使う。 */
function kv_member_login_form(string $next, bool $full = false): string
{
    return '<div class="panel">'
       . '<p style="margin-top:0"><b>以前ご注文いただいた方・会員の方</b><br>'
       . 'メールアドレスを入れると、<b>ログイン用のリンク</b>をメールでお送りします。ログインすると、ご登録のご住所・お届け先・これまでのご注文を呼び出せます。'
       . '<span class="src">パスワードは必要ありません。' . (kv_is_xdirect() ? 'おちゃのこネットの頃に会員登録された方も、同じメールアドレスでお使いいただけます。' : '') . '</span></p>'
       . '<form method="post" action="' . kv_url('member/login') . '" style="display:flex;gap:8px;flex-wrap:wrap">'
       . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="next" value="' . kv_e($next) . '">'
       . '<input type="email" name="email" required placeholder="メールアドレス" autocomplete="email" style="flex:1;min-width:220px;padding:9px;border:2px solid #cfdae4;border-radius:8px">'
       . '<button class="btn" type="submit">ログイン用のリンクを受け取る</button></form>'
       . ($full ? '<p class="src" style="margin-bottom:0">初めての方は、会員登録をしなくてもご購入いただけます。ご購入のときに「会員登録する」を選ぶと、次回からメールアドレスだけでご住所を呼び出せます。</p>' : '')
       . '</div>';
}

/** メールのリンクを開いたところ。押して初めてリンクを使う。 */
function kv_page_member_auth(): void
{
    kv_session();
    kv_member_schema();
    $token = (string)($_POST['t'] ?? ($_GET['t'] ?? ''));
    $row = null;
    if (preg_match('/^[0-9a-f]{48}$/', $token)) {
        $st = kv_db()->prepare('SELECT * FROM member_tokens WHERE hash=?');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch() ?: null;
    }
    $valid = $row && !$row['used_at'] && $row['expires_at'] >= date('Y-m-d H:i:s');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid && kv_csrf_ok()) {
        $u = kv_db()->prepare('UPDATE member_tokens SET used_at=? WHERE hash=? AND used_at IS NULL');
        $u->execute([date('Y-m-d H:i:s'), $row['hash']]);
        if ($u->rowCount() === 1) {
            session_regenerate_id(true);
            $_SESSION['member_id'] = (int)$row['member_id'];
            unset($_SESSION['checkout']);   // 前の入力ではなく、会員の情報から始める
            // リンクを頼んだときのカートを、開いたブラウザのカートに足す。同じブラウザなら同じ中身なので、
            // 数量は足し算せず商品ごとに多いほうを取る（2倍にしない）。価格・在庫はカートを表示するときに DB から引き直す
            $saved = json_decode((string)($row['cart'] ?? ''), true);
            if (is_array($saved) && $saved) {
                $c = kv_cart_get();
                foreach ($saved as $k => $q) {
                    if (!preg_match('/^\d+(\|\d+(-\d+)*)?$/', (string)$k)) { continue; }
                    $c[(string)$k] = min(99, max((int)($c[(string)$k] ?? 0), (int)$q));
                }
                kv_cart_set($c);
            }
            kv_db()->prepare('UPDATE members SET last_login_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$row['member_id']]);
            header('Location: ' . kv_url(kv_member_safe_next((string)$row['next'])));
            return;
        }
        $valid = false;
    }
    kv_head('ログイン', '', '/member/auth', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:760px"><h1>ログイン</h1>';
    if ($valid) {
        echo '<div class="panel"><p style="margin-top:0">下のボタンを押すとログインします。</p>'
           . '<form method="post" action="' . kv_url('member/auth') . '"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
           . '<input type="hidden" name="t" value="' . kv_e($token) . '"><button class="btn" type="submit">ログインする</button></form></div>';
    } else {
        echo '<div class="alert">このリンクは使えません。有効期限（' . KV_MEMBER_TOKEN_MIN . '分）が過ぎたか、すでに使われています。<br>'
           . 'お手数ですが、もう一度メールアドレスを入れてリンクを受け取ってください。</div>'
           . kv_member_login_form('member');
    }
    echo '</div>';
    kv_footer();
}

/* ------------------------------------------------------------------
 * マイページ
 * ------------------------------------------------------------------ */

/** 会員の注文（この店の注文＋おちゃのこ時代の注文）を新しい順に。 */
function kv_member_orders(array $m, int $limit = 50): array
{
    kv_member_schema();
    $db = kv_db();
    $out = [];
    $st = $db->prepare('SELECT * FROM orders WHERE (member_id=? OR lower(email)=?) AND cancelled=0 AND hidden=0 ORDER BY created_at DESC LIMIT ' . $limit);
    $st->execute([(int)$m['id'], kv_member_email((string)$m['email'])]);
    foreach ($st->fetchAll() as $o) {
        $items = [];
        foreach (kv_order_items((int)$o['id']) as $it) {
            $items[] = ['product_id' => (int)$it['product_id'], 'name' => (string)$it['name'], 'qty' => (int)$it['qty'],
                        'opt' => (bool)preg_match('/（.+：.+）$/u', (string)$it['name'])];
        }
        $out[] = ['src' => 'kv', 'id' => (int)$o['id'], 'code' => (string)$o['code'], 'at' => (string)$o['created_at'],
                  'total' => (int)$o['total'], 'items' => $items, 'ship' => (string)($o['ship_to'] === 'other' ? $o['ship_address'] : '')];
    }
    $st = $db->prepare('SELECT * FROM member_past_orders WHERE email=? ORDER BY ordered_at DESC LIMIT ' . $limit);
    $st->execute([kv_member_email((string)$m['email'])]);
    foreach ($st->fetchAll() as $o) {
        $items = [];
        foreach ((json_decode((string)$o['items'], true) ?: []) as $it) {
            $items[] = ['product_id' => (int)($it['product_id'] ?? 0), 'name' => (string)($it['name'] ?? ''), 'qty' => (int)($it['qty'] ?? 1),
                        'opt' => !empty($it['variations'])];
        }
        $out[] = ['src' => 'ocnk', 'id' => (int)$o['id'], 'code' => '', 'at' => (string)$o['ordered_at'],
                  'total' => (int)$o['total'], 'items' => $items, 'ship' => (string)$o['ship_address']];
    }
    usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_slice($out, 0, $limit);
}

/** カートに入れられる商品か（オプションのある商品は、選んでもらうため商品ページへ）。 */
function kv_member_can_add(int $pid): ?array
{
    $p = $pid ? kv_product($pid) : null;
    if (!$p || (int)($p['hidden'] ?? 0) === 1) { return null; }
    if (kv_price_incl($p) === null || kv_inquiry_only($p) || !kv_stock_orderable($p) || kv_variations($p)) { return null; }
    return $p;
}

function kv_page_member(): void
{
    kv_session();
    $m = kv_member_current();
    if (!$m) { header('Location: ' . kv_url('member/login')); return; }
    $mid = (int)$m['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'logout') {
            unset($_SESSION['member_id'], $_SESSION['checkout']);
            session_regenerate_id(true);
            header('Location: ' . kv_url(''));
            return;
        }
        if ($act === 'profile') {
            $err = kv_checkout_validate(['email' => $m['email']] + $_POST);
            if ($err) { $_SESSION['member_flash'] = implode(' / ', $err); }
            else { kv_member_update_profile($mid, $_POST); $_SESSION['member_flash'] = 'ご登録の情報を更新しました。'; }
        }
        if ($act === 'addr_delete') {
            kv_db()->prepare('DELETE FROM member_addresses WHERE id=? AND member_id=?')->execute([(int)$_POST['id'], $mid]);
            $_SESSION['member_flash'] = 'お届け先を削除しました。';
        }
        if ($act === 'reorder') {
            // その注文の商品を、いまカートに入れられるものだけまとめて入れる
            $o = null;
            foreach (kv_member_orders($m, 200) as $x) {
                if ($x['src'] === (string)$_POST['src'] && $x['id'] === (int)$_POST['id']) { $o = $x; break; }
            }
            $c = kv_cart_get(); $n = 0; $skip = [];
            foreach (($o['items'] ?? []) as $it) {
                $p = $it['opt'] ? null : kv_member_can_add($it['product_id']);
                if (!$p) { $skip[] = $it['name']; continue; }
                $k = (string)$p['id'];
                $c[$k] = min(99, (int)($c[$k] ?? 0) + max(1, $it['qty']));
                $n++;
            }
            kv_cart_set($c);
            $_SESSION['member_flash'] = $n . '点をカートに入れました。'
                . ($skip ? ' 次の商品は、オプションの選択が必要か、いまはご注文いただけないため入れていません: ' . implode('、', array_slice($skip, 0, 5)) . (count($skip) > 5 ? ' ほか' : '') : '');
            header('Location: ' . kv_url($n ? 'cart' : 'member'));
            return;
        }
        if ($act === 'withdraw' && !empty($_POST['confirm'])) {
            $db = kv_db();
            $db->beginTransaction();
            $db->prepare('DELETE FROM member_addresses WHERE member_id=?')->execute([$mid]);
            $db->prepare('DELETE FROM member_past_orders WHERE email=?')->execute([kv_member_email((string)$m['email'])]);
            $db->prepare('DELETE FROM member_tokens WHERE member_id=?')->execute([$mid]);
            $db->prepare('UPDATE orders SET member_id=NULL WHERE member_id=?')->execute([$mid]);
            $db->prepare('DELETE FROM members WHERE id=?')->execute([$mid]);
            $db->commit();
            unset($_SESSION['member_id'], $_SESSION['checkout']);
            session_regenerate_id(true);
            kv_head('会員登録を削除しました', '', '/member', ['noindex' => true]);
            echo '<div class="wrap"><h1>会員登録を削除しました</h1><div class="panel">ご登録の情報とお届け先を削除しました。'
               . 'ご注文の記録は、法令に基づき保存が必要なため当店で保管します。これまでのご利用ありがとうございました。</div>'
               . '<p><a class="btn" href="' . kv_url('') . '">トップへ</a></p></div>';
            kv_footer();
            return;
        }
        header('Location: ' . kv_url('member'));
        return;
    }
    $csrf = '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
    kv_head('マイページ', '', '/member', ['noindex' => true]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ マイページ</nav>'
       . '<h1>マイページ</h1>';
    if (!empty($_SESSION['member_flash'])) { echo '<div class="note">' . kv_e($_SESSION['member_flash']) . '</div>'; unset($_SESSION['member_flash']); }
    echo '<div class="panel" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between">'
       . '<div><b>' . kv_e($m['name'] ?: $m['email']) . ' 様</b>' . ($m['company'] ? '（' . kv_e($m['company']) . '）' : '')
       . '<div class="src">' . kv_e($m['email']) . '</div></div>'
       . '<div style="display:flex;gap:8px;flex-wrap:wrap">'
       . (kv_cart_get() ? '<a class="btn" href="' . kv_url('cart') . '">カートを見る</a>' : '<a class="btn" href="' . kv_url('') . '">買い物をする</a>')
       . '<form method="post">' . $csrf . '<input type="hidden" name="act" value="logout"><button class="btn gray" type="submit">ログアウト</button></form></div></div>';

    // これまでに買った商品（消耗品の買い足し用）
    $orders = kv_member_orders($m, 200);
    $bought = [];
    foreach ($orders as $o) {
        foreach ($o['items'] as $it) {
            $pid = $it['product_id'];
            if ($pid && !isset($bought[$pid])) { $bought[$pid] = ['at' => $o['at'], 'qty' => $it['qty'], 'name' => $it['name']]; }
        }
    }
    echo '<h2>これまでにご購入の商品</h2>';
    if (!$bought) {
        echo '<p class="src">まだご購入の記録がありません。</p>';
    } else {
        // スマホで「カートに入れる」が横にはみ出さないよう、表ではなくカードで並べる
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">';
        $shown = 0;
        foreach ($bought as $pid => $b) {
            if ($shown++ >= 60) { break; }
            $p = kv_product((int)$pid);
            $add = $p ? kv_member_can_add((int)$pid) : null;
            $price = $p ? kv_price_incl($p) : null;
            $img = $p ? kv_img($p['image_url'] ?? '') : '';
            echo '<div class="panel" style="margin:0;display:flex;flex-direction:column;gap:8px">'
               . '<div style="display:flex;gap:10px;align-items:flex-start">'
               . ($img ? '<img src="' . kv_e($img) . '" alt="" style="width:64px;height:64px;object-fit:contain;flex:0 0 auto">' : '')
               . '<div style="min-width:0">' . ($p ? '<a href="' . kv_url('product/' . (int)$pid) . '">' . kv_e($p['name']) . '</a>' : kv_e($b['name']) . ' <span class="src">（取り扱い終了）</span>')
               . '<div class="src">前回 ' . kv_e(substr($b['at'], 0, 10)) . '・' . (int)$b['qty'] . '点'
               . ($price !== null && $p && !kv_inquiry_only($p) ? '　いまの価格 <b>' . number_format($price) . '円</b>（税込）' : '') . '</div></div></div>';
            if ($add) {
                echo '<form method="post" action="' . kv_url('cart') . '" style="display:flex;gap:6px;margin-top:auto">' . $csrf
                   . '<input type="hidden" name="add" value="' . (int)$pid . '">'
                   . '<input type="number" name="qty" value="' . max(1, (int)$b['qty']) . '" min="1" max="99" aria-label="数量" style="width:70px;padding:7px;border:2px solid #cfdae4;border-radius:8px">'
                   . '<button class="btn" type="submit" style="flex:1">カートに入れる</button></form>';
            } elseif ($p) {
                echo '<a class="btn ghost" style="margin-top:auto;text-align:center" href="' . kv_url('product/' . (int)$pid) . '">商品ページで選ぶ</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    // ご注文の履歴
    echo '<h2>ご注文の履歴</h2>';
    if (!$orders) { echo '<p class="src">まだご注文の記録がありません。</p>'; }
    foreach (array_slice($orders, 0, 30) as $o) {
        echo '<details class="panel" style="margin-bottom:8px"><summary style="cursor:pointer"><b>' . kv_e(substr($o['at'], 0, 10)) . '</b>　'
           . ($o['code'] !== '' ? '受注番号 ' . kv_e($o['code']) . '　' : '')
           . number_format($o['total']) . '円（税込）　<span class="src">' . kv_e(mb_strimwidth($o['items'][0]['name'] ?? '', 0, 40, '…'))
           . (count($o['items']) > 1 ? ' ほか' . (count($o['items']) - 1) . '点' : '') . '</span></summary>'
           . '<ul style="margin:8px 0 8px 18px">';
        foreach ($o['items'] as $it) { echo '<li>' . kv_e($it['name']) . ' × ' . (int)$it['qty'] . '</li>'; }
        echo '</ul>' . ($o['ship'] !== '' ? '<div class="src">お届け先: ' . kv_e($o['ship']) . '</div>' : '')
           . '<form method="post" style="margin-top:8px">' . $csrf . '<input type="hidden" name="act" value="reorder">'
           . '<input type="hidden" name="src" value="' . $o['src'] . '"><input type="hidden" name="id" value="' . $o['id'] . '">'
           . '<button class="btn ghost" type="submit">この注文と同じ商品をカートに入れる</button></form></details>';
    }

    // お届け先
    echo '<h2>お届け先</h2>';
    $addrs = kv_member_addresses($mid);
    if (!$addrs) {
        echo '<p class="src">ご注文のとき「別の住所へ届ける」を選ぶと、ここに保存され、次回から選べるようになります。</p>';
    } else {
        echo '<div class="scroll"><table class="t"><tr><th>お届け先</th><th>電話番号</th><th></th></tr>';
        foreach ($addrs as $a) {
            echo '<tr><td>' . kv_e(trim($a['company'] . ' ' . $a['name'])) . ' 様<div class="src">' . kv_e(kv_member_address_text($a)) . '</div></td>'
               . '<td>' . kv_e($a['tel']) . '</td><td><form method="post">' . $csrf . '<input type="hidden" name="act" value="addr_delete">'
               . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><button class="btn gray" type="submit">削除</button></form></td></tr>';
        }
        echo '</table></div>';
    }

    // ご登録の情報
    echo '<h2>ご登録の情報</h2><form method="post">' . $csrf . '<input type="hidden" name="act" value="profile"><table class="formtbl">';
    $v = kv_member_checkout_values($m);
    foreach (kv_checkout_fields() as $k => $f) {
        echo '<tr><th>' . kv_e($f['label']) . (!empty($f['required']) ? '<span class="req">必須</span>' : '') . '</th><td>';
        if ($k === 'email') {
            echo kv_e($m['email']) . '<div class="hint">メールアドレスを変えるときは、お問い合わせください。</div>';
        } elseif (($f['type'] ?? '') === 'pref') {
            echo '<select name="pref"><option value="">選択してください</option>';
            foreach (kv_prefs() as $pf) { echo '<option' . ($v['pref'] === $pf ? ' selected' : '') . '>' . kv_e($pf) . '</option>'; }
            echo '</select>';
        } else {
            echo '<input type="' . ($f['type'] ?? 'text') . '" name="' . $k . '" value="' . kv_e($v[$k] ?? '') . '">';
        }
        echo '</td></tr>';
    }
    echo '<tr><th>メールマガジン</th><td><label><input type="radio" name="dm" value="1"' . ($v['dm'] === '1' ? ' checked' : '') . '> 受け取る</label>　'
       . '<label><input type="radio" name="dm" value="0"' . ($v['dm'] === '0' ? ' checked' : '') . '> 受け取らない</label></td></tr>'
       . '</table><p><button class="btn" type="submit">更新する</button></p></form>';

    echo '<details style="margin-top:30px"><summary class="src" style="cursor:pointer">会員登録を削除する</summary><div class="panel" style="margin-top:8px">'
       . 'ご登録の情報・お届け先・これまでのご注文の一覧を削除します。削除したあとも、会員登録をせずにご購入いただけます。'
       . '<form method="post" style="margin-top:8px">' . $csrf . '<input type="hidden" name="act" value="withdraw">'
       . '<label><input type="checkbox" name="confirm" value="1" required> 削除してよい</label> '
       . '<button class="btn gray" type="submit">会員登録を削除する</button></form></div></details>';
    echo '</div>';
    kv_footer();
}

/* ------------------------------------------------------------------
 * 管理画面: 会員
 * ------------------------------------------------------------------ */

function kv_admin_members(): void
{
    kv_member_schema();
    $db = kv_db();
    $q = trim((string)($_GET['q'] ?? ''));
    $w = '1=1'; $a = [];
    if ($q !== '') { $w = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR tel LIKE ? OR address1 LIKE ? OR name_kana LIKE ?)'; $a = array_fill(0, 6, '%' . $q . '%'); }
    $per = 50; $page = max(1, (int)($_GET['p'] ?? 1));
    $st = $db->prepare("SELECT COUNT(*) FROM members WHERE $w"); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT m.*, (SELECT COUNT(*) FROM orders o WHERE o.member_id=m.id AND o.cancelled=0) kv_n,"
        . " (SELECT COUNT(*) FROM member_addresses x WHERE x.member_id=m.id) addr_n"
        . " FROM members m WHERE $w ORDER BY COALESCE(m.last_login_at, m.updated_at, m.created_at) DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($a);
    $sum = $db->query("SELECT COUNT(*) n, SUM(source='ocnk') ocnk, SUM(source='kvcart') kv, SUM(last_login_at IS NOT NULL) login FROM members")->fetch();
    kv_admin_page('会員', 'members');
    echo '<p>会員 <b>' . number_format((int)$sum['n']) . '</b>人（おちゃのこネットから移行 ' . number_format((int)$sum['ocnk']) . '人・この店で登録 '
       . number_format((int)$sum['kv']) . '人）。ログインしたことがある会員 ' . number_format((int)$sum['login']) . '人。</p>'
       . '<p class="src">お客様はパスワードを使わず、メールアドレスに届くリンクでログインします。会員がログインして注文すると、受注と会員がつながります。</p>';
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="members"><input class="in" name="q" value="' . kv_e($q) . '" placeholder="名前・フリガナ・会社・メール・電話・住所">'
       . '<button class="btn" type="submit">探す</button></form>';
    echo '<div class="scroll"><table class="t"><tr><th>お名前・会社</th><th>メール・電話</th><th>住所</th><th>おちゃのこ時代</th><th>この店の注文</th><th>届け先</th><th>最終ログイン</th></tr>';
    foreach ($st->fetchAll() as $m) {
        echo '<tr><td>' . kv_e($m['name']) . ($m['company'] ? '<div class="src">' . kv_e($m['company']) . '</div>' : '')
           . ($m['source'] === 'ocnk' ? ' <span class="tag">移行</span>' : ' <span class="tag gr">新規</span>') . '</td>'
           . '<td>' . kv_e($m['email']) . '<div class="src">' . kv_e($m['tel']) . '</div></td>'
           . '<td class="src">' . kv_e(mb_strimwidth($m['pref'] . $m['address1'] . $m['address2'], 0, 36, '…')) . '</td>'
           . '<td class="src" style="white-space:nowrap">' . ((int)$m['ocnk_sales_count'] ? (int)$m['ocnk_sales_count'] . '回・' . kv_e(substr((string)$m['ocnk_last_order'], 0, 10)) : '—') . '</td>'
           . '<td style="text-align:right">' . ((int)$m['kv_n'] ? '<a href="?a=orders&q=' . kv_e(rawurlencode((string)$m['email'])) . '">' . (int)$m['kv_n'] . '回</a>' : '—') . '</td>'
           . '<td style="text-align:right">' . (int)$m['addr_n'] . '</td>'
           . '<td class="src" style="white-space:nowrap">' . kv_e(substr((string)$m['last_login_at'], 0, 16) ?: '—') . '</td></tr>';
    }
    echo '</table></div>';
    kv_pager(['page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], '?a=members&q=' . rawurlencode($q));
    kv_admin_end();
}
