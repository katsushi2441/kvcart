<?php
/**
 * Kurage Vibe-Cart — 運営の機能（2026-11-17・おちゃのこネットの機能に合わせて追加）
 *
 *   - ブラックリスト（おちゃのこネットの「ブラックリスト設定」と同じ）
 *       メールアドレス（ドメインだけでも可）・名前・電話番号・住所・IPアドレス・メモを登録。
 *       注文やお問い合わせの購入者情報と一致したら、店への受注確認メールに書き、受注一覧・お問い合わせ受付一覧に印を出す。
 *       おちゃのこネットと同じく、購入そのものは止めない（誤登録でお客様を締め出さないため）。
 *   - お問い合わせ受付一覧（おちゃのこネットの同名の画面と同じ）
 *       お問い合わせフォームの内容を保存し、管理画面で対応状況・メモ・返信・送信履歴を扱う。
 *   - 帳票（納品書・請求書・領収書）の PDF（おちゃのこオフィスの帳票印刷と同じ役目）
 *       請求書・領収書は適格請求書の記載事項（登録番号・税率ごとの対価と消費税額）を入れる。
 */
declare(strict_types=1);

require_once __DIR__ . '/kv_pdf.php';

function kv_ops_schema(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    kv_order_schema();
    kv_db()->exec("
    CREATE TABLE IF NOT EXISTS blacklist (
      id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT,
      email TEXT, name TEXT, tel TEXT, address TEXT, ip TEXT, memo TEXT
    );
    CREATE TABLE IF NOT EXISTS inquiries (
      id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL,
      name TEXT, email TEXT, tel TEXT, body TEXT, product_id INTEGER, ip TEXT,
      status TEXT DEFAULT 'new', memo TEXT, bl_note TEXT
    );
    CREATE TABLE IF NOT EXISTS inquiry_mails (
      id INTEGER PRIMARY KEY AUTOINCREMENT, inquiry_id INTEGER NOT NULL, at TEXT NOT NULL,
      to_addr TEXT, cc TEXT, subject TEXT, body TEXT, ok INTEGER
    );
    ");
    $has = fn(string $c) => in_array($c, array_column(kv_db()->query('PRAGMA table_info(orders)')->fetchAll(), 'name'), true);
    if (!$has('ip'))      { kv_db()->exec('ALTER TABLE orders ADD COLUMN ip TEXT'); }
    if (!$has('bl_note')) { kv_db()->exec('ALTER TABLE orders ADD COLUMN bl_note TEXT'); }
}

// ---------------- ブラックリスト ----------------

/** 比べるための形にそろえる（全角半角・大小・空白・ハイフンの違いを無くす）。 */
function kv_bl_norm(string $s): string
{
    return preg_replace('/[\s\-‐－ー―・]/u', '', mb_strtolower(mb_convert_kana($s, 'asKV', 'UTF-8'), 'UTF-8'));
}

/**
 * 購入者・お問い合わせの情報がブラックリストに当たるか。当たった理由を1行ずつ返す（無ければ空）。
 * $p = ['email','name','tel','address','ip']
 */
function kv_blacklist_match(array $p): array
{
    kv_ops_schema();
    $email = mb_strtolower(trim((string)($p['email'] ?? '')));
    $dom = ($i = strrpos($email, '@')) !== false ? substr($email, $i + 1) : '';
    $name = kv_bl_norm((string)($p['name'] ?? ''));
    $tel = preg_replace('/\D/', '', mb_convert_kana((string)($p['tel'] ?? ''), 'n'));
    $addr = kv_bl_norm((string)($p['address'] ?? ''));
    $ip = trim((string)($p['ip'] ?? ''));
    $hit = [];
    foreach (kv_db()->query('SELECT * FROM blacklist') as $b) {
        $why = [];
        $be = mb_strtolower(trim((string)$b['email']));
        if ($be !== '' && $email !== '' && (strpos($be, '@') !== false ? $be === $email : ltrim($be, '@') === $dom)) { $why[] = 'メール ' . $be; }
        if (trim((string)$b['name']) !== '' && $name !== '' && kv_bl_norm((string)$b['name']) === $name) { $why[] = '名前 ' . $b['name']; }
        $bt = preg_replace('/\D/', '', mb_convert_kana((string)$b['tel'], 'n'));
        if ($bt !== '' && $tel !== '' && $bt === $tel) { $why[] = '電話 ' . $b['tel']; }
        $ba = kv_bl_norm((string)$b['address']);
        if ($ba !== '' && $addr !== '' && strpos($addr, $ba) !== false) { $why[] = '住所 ' . $b['address']; }
        if (trim((string)$b['ip']) !== '' && $ip !== '' && trim((string)$b['ip']) === $ip) { $why[] = 'IP ' . $b['ip']; }
        if ($why) { $hit[] = '#' . $b['id'] . ' ' . implode('・', $why) . ($b['memo'] ? '（' . $b['memo'] . '）' : ''); }
    }
    return $hit;
}

/** ブラックリストを変えたら、これまでの受注・お問い合わせも照合し直す（登録より前の注文にも印を出す）。 */
function kv_blacklist_recheck(): void
{
    kv_ops_schema();
    $up = kv_db()->prepare('UPDATE orders SET bl_note=? WHERE id=?');
    foreach (kv_db()->query('SELECT id,email,name,tel,address,ship_address,ip FROM orders')->fetchAll() as $o) {
        $hit = kv_blacklist_match(['email' => $o['email'], 'name' => $o['name'], 'tel' => $o['tel'], 'address' => $o['address'] . ' ' . $o['ship_address'], 'ip' => $o['ip']]);
        $up->execute([$hit ? implode(' / ', $hit) : null, $o['id']]);
    }
    $up = kv_db()->prepare('UPDATE inquiries SET bl_note=? WHERE id=?');
    foreach (kv_db()->query('SELECT id,email,name,tel,ip FROM inquiries')->fetchAll() as $q) {
        $hit = kv_blacklist_match(['email' => $q['email'], 'name' => $q['name'], 'tel' => $q['tel'], 'ip' => $q['ip']]);
        $up->execute([$hit ? implode(' / ', $hit) : null, $q['id']]);
    }
}

/** 注文を受けた直後に呼ぶ。IP を記録し、ブラックリストに当たれば bl_note に理由を残す。 */
function kv_order_after_create(int $oid): void
{
    kv_ops_schema();
    $o = kv_order($oid);
    if (!$o) { return; }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hit = kv_blacklist_match(['email' => $o['email'], 'name' => $o['name'], 'tel' => $o['tel'],
                               'address' => ($o['address'] ?? '') . ' ' . ($o['ship_address'] ?? ''), 'ip' => $ip]);
    kv_db()->prepare('UPDATE orders SET ip=?, bl_note=? WHERE id=?')->execute([$ip, $hit ? implode(' / ', $hit) : null, $oid]);
    if ($hit) { kv_log($oid, 'blacklist', 'ブラックリストに一致: ' . implode(' / ', $hit)); }
}

// ---------------- お問い合わせ ----------------

/** お問い合わせフォームの内容を保存する。ブラックリストに当たれば印を残す。 */
function kv_inquiry_save(array $in, int $pid = 0, string $spam = ''): int
{
    kv_ops_schema();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hit = kv_blacklist_match(['email' => $in['email'] ?? '', 'name' => $in['name'] ?? '', 'tel' => $in['tel'] ?? '', 'ip' => $ip]);
    kv_db()->prepare('INSERT INTO inquiries (created_at,name,email,tel,body,product_id,ip,status,bl_note) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([date('Y-m-d H:i:s'), $in['name'] ?? '', $in['email'] ?? '', $in['tel'] ?? '', $in['body'] ?? '', $pid ?: null, $ip, $spam !== '' ? 'spam' : 'new',
                      $hit ? implode(' / ', $hit) : null]);
    $id = (int)kv_db()->lastInsertId();
    if ($spam !== '') { kv_db()->prepare('UPDATE inquiries SET memo=? WHERE id=?')->execute(['スパム判定: ' . $spam, $id]); }
    return $id;
}

const KV_INQ_STATUS = ['new' => '未対応', 'doing' => '対応中', 'done' => '対応済み', 'spam' => 'スパム判定'];

// ---------------- フォームのスパム対策（exbridge.jp/contact.php と同じ6段・2026-11-17） ----------------
// 2026-11-16 に kvcart へ切り替えてから「Test」「ハワイ語の営業」「英語のリード獲得業者」が届いた（CSRF しか無かった）。
//   1) CSRF（kv_csrf）  2) ハニーポット  3) 表示から2秒未満の送信  4) 同じIPから1時間に5件まで
//   5) 名前にURL → 保存だけして通知しない  6) 日本語が無い → 【スパム判定】を付けて通知（海外の本物の相談を取りこぼさない）

/** フォームを出すときに呼ぶ。表示の時刻を覚え、ハニーポットの欄を返す（人には見えない）。 */
function kv_form_guard_issue(string $key): string
{
    kv_session();
    $_SESSION['kv_form_time_' . $key] = time();
    return '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">'
         . '<label>この欄は空のままにしてください<input type="text" name="hp_memo" value="" tabindex="-1" autocomplete="off"></label></div>';
}

/**
 * 送信を受けたときに呼ぶ。[静かに捨てる(bool), スパムの理由('' なら人), お客様に見せるエラー('' なら無し)]
 * 静かに捨てるものは、送信者には通常どおり「受け付けました」を見せる（ボットに判定の手がかりを与えない）。
 */
function kv_form_guard_check(string $key, string $name, string $text): array
{
    if (trim((string)($_POST['hp_memo'] ?? '')) !== '') { return [true, 'ボット（ハニーポット）', '']; }
    $t = (int)($_SESSION['kv_form_time_' . $key] ?? 0);
    if ($t > 0 && time() - $t < 2) { return [true, 'ボット（表示から2秒未満）', '']; }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $dir = KV_DATA . '/form_rate'; if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $f = $dir . '/' . md5($key . '|' . $ip) . '.json';
    $ts = array_values(array_filter((array)(is_file($f) ? json_decode((string)file_get_contents($f), true) : []), fn($x) => time() - (int)$x < 3600));
    if (count($ts) >= 5) { return [false, '', '送信回数が多すぎます。しばらく時間をおいてからお試しください。']; }
    $ts[] = time(); @file_put_contents($f, json_encode($ts), LOCK_EX);
    if (preg_match('#https?://|://|www\.#i', $name)) { return [true, 'ボット（名前にURL）', '']; }
    if (!preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $name . $text)) { return [false, 'ボット（日本語が無い）', '']; }
    return [false, '', ''];
}

// ---------------- 帳票（PDF） ----------------

const KV_DOC_TYPES = ['delivery' => '納品書', 'invoice' => '請求書', 'receipt' => '領収書'];

/**
 * 納品書・請求書・領収書の PDF。
 * $opt: atena（宛名・空なら会社名 御中 か お名前 様）, tadashi（領収書の但し書き）, date（発行日 Y-m-d）, note（備考）
 */
function kv_doc_pdf(int $oid, string $type, array $opt = []): string
{
    $o = kv_order($oid);
    if (!$o || !isset(KV_DOC_TYPES[$type])) { return ''; }
    $items = kv_order_items($oid);
    $title = KV_DOC_TYPES[$type];
    $date = !empty($opt['date']) && strtotime((string)$opt['date']) ? date('Y年n月j日', strtotime((string)$opt['date'])) : date('Y年n月j日');
    $atena = trim((string)($opt['atena'] ?? ''));
    if ($atena === '') { $atena = !empty($o['company']) ? $o['company'] . ' 御中' : $o['name'] . ' 様'; }
    elseif (!preg_match('/(様|御中|殿)$/u', $atena)) {
        // 会社・団体あては「御中」、人あては「様」
        $atena .= ($atena === trim((string)$o['company']) || preg_match('/(株式会社|有限会社|合同会社|（株）|\(株\)|㈱|法人|組合|大学|学校|病院|役所|役場|協会|工業|商事|商会|製作所|事務所)/u', $atena)) ? ' 御中' : ' 様';
    }
    $inv = trim(kv_shop('invoice_no'));
    $T = 'kv_pdf_text'; $R = 'kv_pdf_text_right'; $L = 'kv_pdf_line';
    $yen = fn($n) => '￥' . number_format((int)$n);   // 半角の ¥ は日本語フォントに無く豆腐になる（実測）。全角の ￥ を使う

    // 税率ごとの対価と消費税額（適格請求書の記載事項）。明細の tax/amount から 8%・10% を見分ける
    $by = [];
    foreach ($items as $it) {
        $rate = ((int)$it['amount'] > 0 && abs((int)$it['tax'] / (int)$it['amount'] - 0.08) < 0.01) ? 8 : 10;
        $by[$rate] = ($by[$rate] ?? 0) + (int)$it['amount'];
    }
    $disc = (int)($o['discount'] ?? 0);
    if ($disc > 0) { $by[10] = ($by[10] ?? 0) - $disc; }
    $ship = (int)($o['shipping'] ?? 0);
    if ($ship > 0) { $by[10] = ($by[10] ?? 0) + (int)round($ship / 1.1); }

    $head = function (int $page, int $pages) use ($T, $R, $L, $title, $date, $o, $atena, $inv, $yen, $type, $opt): string {
        $c = $T(255, 790, 22, $title);
        $c .= $R(555, 800, 9, '発行日 ' . $date) . $R(555, 787, 9, '受注番号 ' . $o['code']) . ($pages > 1 ? $R(555, 774, 8, $page . ' / ' . $pages) : '');
        if ($page > 1) { return $c . $T(40, 760, 11, $atena) . $L(40, 750, 555, 750, 0.5); }
        // 宛名が長いとき（長い法人名など）は、右の発行者の欄に重ならないよう文字を小さくする（2026-11-25）
        $asz = 14; while ($asz > 8 && kv_pdf_width($asz, $atena) > 290) { $asz -= 0.5; }
        $c .= $T(40, 735, $asz, $atena) . $L(40, 729, 300, 729, 0.8);
        // 発行者
        $y = 735;
        foreach ([[11, kv_shop('company')], [8.5, kv_shop('name') . '（' . preg_replace('#^https?://#', '', rtrim(defined('KV_ORIGIN') ? KV_ORIGIN : kv_site_url(''), '/')) . '）'], [8.5, (kv_shop('zip') !== '' ? '〒' . kv_shop('zip') . ' ' : '') . kv_shop('address')],
                  [8.5, kv_shop('email')], [8.5, $inv !== '' ? '登録番号 ' . $inv : '']] as [$sz, $tx]) {
            if ($tx !== '') { $c .= $T(340, $y, $sz, $tx); $y -= $sz + 5; }
        }
        if ($type === 'receipt') {
            $c .= kv_pdf_rect_fill(40, 618, 515, 44, 0.95) . $T(60, 633, 13, '金額') . $T(150, 630, 22, $yen($o['total']) . ' -');
            $c .= $T(360, 633, 10, '（税込）');
            $c .= $T(40, 595, 10.5, '但し ' . (trim((string)($opt['tadashi'] ?? '')) !== '' ? $opt['tadashi'] : '商品代として'));
            $c .= $T(40, 578, 10.5, '上記正に領収いたしました。');
            $c .= $T(40, 561, 9, 'お支払い方法: ' . ($o['payment'] === 'stripe' ? 'クレジットカード' : '銀行振込') . '　　電子発行のため収入印紙は不要です。');
            return $c;
        }
        $lead = $type === 'invoice' ? '下記のとおりご請求申し上げます。' : '下記のとおり納品いたします。';
        $c .= $T(40, 690, 10, $lead);
        $c .= kv_pdf_rect_fill(40, 650, 300, 30, 0.95) . $T(50, 660, 11, $type === 'invoice' ? 'ご請求金額（税込）' : '合計金額（税込）') . $R(330, 659, 14, $yen($o['total']));
        $c .= $T(40, 628, 9, 'ご注文日 ' . date('Y年n月j日', strtotime((string)$o['created_at'])));
        return $c;
    };
    // 明細
    $rows = [];
    foreach ($items as $it) {
        $nm = trim(preg_replace('/\s+/u', ' ', preg_replace('/【[^】]*】/u', '', (string)$it['name'])));
        $rate = ((int)$it['amount'] > 0 && abs((int)$it['tax'] / (int)$it['amount'] - 0.08) < 0.01) ? 8 : 10;
        $rows[] = [kv_pdf_wrap($nm . ($rate === 8 ? ' ※' : ''), 9, 285, 3), (int)$it['qty'], (int)$it['unit'], (int)$it['amount']];
    }
    if ($disc > 0) { $rows[] = [['値引き'], 1, -$disc, -$disc]; }
    if ($ship > 0) { $rows[] = [['送料'], 1, (int)round($ship / 1.1), (int)round($ship / 1.1)]; }
    $top1 = $type === 'receipt' ? 535 : 605; $topN = 735; $bottom = 200;
    $pages = []; $page = 1; $y = $top1; $c = '';
    $thead = function (float $y) use ($T, $R, $L): string {
        return kv_pdf_rect_fill(40, $y - 6, 515, 20, 0.9) . $T(46, $y, 9, '品名') . $R(395, $y, 9, '数量') . $R(470, $y, 9, '単価（税別）') . $R(550, $y, 9, '金額（税別）');
    };
    $c .= $thead($y); $y -= 22;
    foreach ($rows as [$lines, $qty, $unit, $amt]) {
        $h = 13 * count($lines) + 5;
        if ($y - $h < $bottom) { $pages[] = $c; $page++; $c = ''; $y = $topN; $c .= $thead($y); $y -= 22; }
        foreach ($lines as $i => $ln) { $c .= $T(46, $y - 13 * $i, 9, $ln); }
        $c .= $R(395, $y, 9, (string)$qty) . $R(470, $y, 9, number_format($unit)) . $R(550, $y, 9, number_format($amt));
        // 区切り線は、この行の最後の文字の少し下に引き、次の行はその下から書く
        // （以前は次の行の基準から +6 の位置に引いていて、商品名が折り返すと次の行の文字に線が重なった・2026-11-20 中村組の領収書）
        $lineY = $y - 13 * (count($lines) - 1) - 5;
        $c .= $L(40, $lineY, 555, $lineY, 0.3); $y = $lineY - 13;
    }
    // 合計（税率ごと）
    $y -= 8;
    $sum = [['小計（税別）', array_sum($by)]];
    foreach ([10, 8] as $r) { if (!empty($by[$r])) { $tax = $r === 10 ? (int)$o['tax'] - (int)round(($by[8] ?? 0) * 0.08) : (int)round($by[8] * 0.08); $sum[] = [$r . '%対象 ' . $yen($by[$r]) . '　消費税', $tax]; } }
    $sum[] = ['合計（税込）', (int)$o['total']];
    foreach ($sum as [$k, $v]) { $c .= $R(470, $y, 9.5, $k) . $R(550, $y, 9.5, $yen($v)); $y -= 15; }
    if (!empty($by[8])) { $c .= $T(46, $y, 8, '※は軽減税率（8%）対象'); $y -= 13; }
    // 振込先・備考
    $y -= 6;
    if ($type === 'invoice' && $o['payment'] === 'bank') {
        $c .= $T(46, $y, 9.5, 'お振込先'); $y -= 14;
        foreach (preg_split('/\R/u', kv_shop('bank_account') !== '' ? kv_shop('bank_account') : kv_shop('bank')) as $ln) { $c .= $T(56, $y, 9, $ln); $y -= 12; }
        $c .= $T(56, $y, 8.5, '振込手数料はお客様のご負担でお願いいたします。'); $y -= 14;
    }
    if (trim((string)($opt['note'] ?? '')) !== '') {
        $c .= $T(46, $y, 9.5, '備考'); $y -= 14;
        foreach (kv_pdf_wrap((string)$opt['note'], 9, 490, 6) as $ln) { $c .= $T(56, $y, 9, $ln); $y -= 12; }
    }
    $pages[] = $c;
    $n = count($pages);
    foreach ($pages as $i => $p) { $pages[$i] = $head($i + 1, $n) . $p; }
    return kv_pdf_document($pages);
}

function kv_doc_filename(int $oid, string $type): string
{
    $o = kv_order($oid);
    return (KV_DOC_TYPES[$type] ?? '帳票') . '_' . ($o['code'] ?? $oid) . '.pdf';
}
