<?php
/**
 * 仕入先への発注書（2026-11-18）。
 *
 * 受注明細の「発注書」から、仕入先ごとに発注書を作り、PDF を付けてメールで送る。送った記録は受注に残る。
 * - 仕入先は型番の頭の記号（SWA-401082S なら SWA）で決まる。管理画面「仕入先」で、記号・会社名・担当者・メールを登録する
 * - 発注No は「仕入先の記号＋受注番号」（例 SWA023783）。受注番号は6桁の連番
 * - 仕入れ値（税抜下代）は、原価表の API があれば自動で引く（kv_config.php の shop.cost_api_url / cost_api_token。
 *   kdbagent 互換: POST cmd=select&table=<表>&where[code]=<型番>、ヘッダー X-KDBA-Token）。無ければ画面で入れる
 * - 見た目は X-Direct の Excel の注文書（宛先・発注者・お送り先・コメント・明細・回答欄）に合わせた A4 縦の PDF
 */
declare(strict_types=1);

function kv_purchase_schema(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $db = kv_db();
    $db->exec('CREATE TABLE IF NOT EXISTS suppliers (code TEXT PRIMARY KEY, name TEXT, contact TEXT, email TEXT, cc TEXT, tel TEXT, note TEXT, updated_at TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS purchase_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, supplier TEXT, po_no TEXT,
        created_at TEXT, sent_at TEXT, sent_to TEXT, cc TEXT, subject TEXT, body TEXT, lines TEXT, comment TEXT, total INTEGER, ok INTEGER)');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_po_order ON purchase_orders(order_id)');
}

/** 型番の頭の記号（SWA-401082S → SWA）。記号が無ければ '' */
function kv_supplier_code_of(?string $model): string
{
    // TMT15-… や OYA16-… のように記号の後ろに数字が付くものも同じ仕入先（英字の部分だけを見る）
    return preg_match('/^([A-Z]{2,5})\d{0,2}-./', (string)$model, $m) ? $m[1] : '';
}

function kv_supplier(string $code): ?array
{
    kv_purchase_schema();
    $st = kv_db()->prepare('SELECT * FROM suppliers WHERE code=?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

/** 発注No（仕入先の記号＋受注番号。移行前の「日付-英数字」は日付を外す） */
function kv_po_no(string $supplier, string $orderCode): string
{
    return $supplier . (strpos($orderCode, '-') !== false ? substr($orderCode, strrpos($orderCode, '-') + 1) : $orderCode);
}

/** 原価表 API から型番ごとの仕入れ値（税抜）を引く。設定が無い・見つからないものは入らない */
function kv_cost_lookup(array $models): array
{
    $url = trim(kv_shop('cost_api_url')); $tok = trim(kv_shop('cost_api_token'));
    if ($url === '' || $tok === '' || !function_exists('curl_init')) { return []; }
    $table = trim(kv_shop('cost_api_table')) ?: 'estimates';
    $out = [];
    foreach (array_unique(array_filter($models)) as $m) {
        $ch = curl_init($url . (strpos($url, '?') === false ? '?' : '&') . 'api=1');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['X-KDBA-Token: ' . $tok],
            CURLOPT_POSTFIELDS => http_build_query(['cmd' => 'select', 'table' => $table, 'where' => ['code' => $m], 'limit' => 1])]);
        $j = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $row = $j['rows'][0] ?? null;
        if ($row && is_numeric($row['cost'] ?? null)) { $out[$m] = (int)$row['cost']; }
    }
    return $out;
}

/** 受注の明細を仕入先ごとに分ける: 記号 => [明細...] */
function kv_po_groups(int $oid): array
{
    $g = [];
    foreach (kv_order_items($oid) as $it) { $g[kv_supplier_code_of($it['model_number']) ?: '（記号なし）'][] = $it; }
    return $g;
}

/** お送り先（お届け先が別ならそちら） */
function kv_po_ship_to(array $o): array
{
    if (($o['ship_to'] ?? 'same') === 'other') {
        return ['name' => (string)$o['ship_name'], 'company' => '', 'zip' => (string)$o['ship_zip'], 'address' => (string)$o['ship_address'], 'tel' => (string)$o['ship_tel']];
    }
    return ['name' => (string)$o['name'], 'company' => (string)$o['company'], 'zip' => (string)$o['zip'], 'address' => (string)$o['address'], 'tel' => (string)$o['tel']];
}

function kv_po_zip(string $z): string { $z = preg_replace('/\D/', '', $z); return strlen($z) === 7 ? substr($z, 0, 3) . '-' . substr($z, 3) : $z; }

/** 発注書の PDF。$lines = [['name','qty','cost'(税抜)]...] */
function kv_po_pdf(array $o, array $sup, string $poNo, array $lines, string $comment, string $date): string
{
    // PDF の字体に無い記号は豆腐になる（■◆× を実測）。見出しは【】、× は全角の ｘ にする
    $fix = fn($t) => strtr((string)$t, ['×' => 'ｘ', '■' => '', '◆' => '', '●' => '・']);
    $T = fn($x, $y, $sz, $t) => kv_pdf_text($x, $y, $sz, $fix($t)); $R = 'kv_pdf_text_right'; $L = 'kv_pdf_line'; $F = 'kv_pdf_rect_fill';
    $lines = array_map(fn($l) => ['name' => $fix($l['name'])] + $l, $lines); $comment = $fix($comment);
    $box = fn($x, $y, $w, $h, $lw = 0.6) => $L($x, $y, $x + $w, $y, $lw) . $L($x, $y + $h, $x + $w, $y + $h, $lw) . $L($x, $y, $x, $y + $h, $lw) . $L($x + $w, $y, $x + $w, $y + $h, $lw);
    $c = '';
    // 宛先・発注者
    $c .= $T(40, 800, 12, 'To ' . ($sup['name'] ?? '') . ' 御中');
    if (trim((string)($sup['contact'] ?? '')) !== '') { $c .= $T(52, 784, 10.5, $sup['contact'] . '　様'); }
    $c .= $R(555, 800, 10.5, kv_shop('company')) . $R(555, 786, 9, kv_shop('email'));
    $c .= $T(250, 752, 20, '注 文 書');
    $c .= $T(40, 728, 9.5, '下記の内容でご発注いたしますので、出荷の手配などよろしくお願いいたします。') . $L(40, 720, 555, 720, 1);
    // 送信日・発注No
    $c .= $F(40, 690, 70, 20, 0.85) . $T(46, 696, 9, '送信日') . $box(40, 690, 230, 20) . $T(118, 696, 9.5, $date);
    $c .= $F(40, 668, 70, 20, 0.85) . $T(46, 674, 9, '発注No') . $box(40, 668, 230, 20) . $T(118, 674, 11, $poNo);
    // 発注者（ショップ）
    $c .= $T(40, 650, 9.5, '【発注者（ショップ）】');
    $rows = [['会社名', kv_shop('company')], ['ショップ名', kv_shop('name')], ['担当者', kv_shop('po_staff')],
             ['住所', (kv_shop('zip') !== '' ? '〒' . kv_shop('zip') . ' ' : '') . kv_shop('address')], ['電話', kv_shop('tel')], ['メール', kv_shop('email')]];
    $y = 630;
    foreach ($rows as [$k, $v]) {
        $ls = kv_pdf_wrap((string)$v, 8.5, 150, 2);   // 住所は2行まで
        $h = 18 + 11 * (count($ls) - 1);
        $c .= $F(40, $y - $h + 13, 70, $h, 0.85) . $T(46, $y, 8.5, $k) . $box(40, $y - $h + 13, 230, $h, 0.4);
        foreach ($ls as $i => $ln) { $c .= $T(115, $y - 11 * $i, 8.5, $ln); }
        $y -= $h;
    }
    // お送り先
    $st = kv_po_ship_to($o);
    $c .= $T(300, 712 - 10, 9.5, '【お送り先】') . $box(300, 520 + 20, 255, 150, 1.2);
    $y = 670;
    foreach ([['会社名', $st['company']], ['お名前', $st['name'] !== '' ? $st['name'] . ' 様' : ''], ['郵便番号', kv_po_zip($st['zip'])], ['ご住所', $st['address']], ['電話番号', $st['tel']]] as [$k, $v]) {
        if ($v === '') { continue; }
        $ls = kv_pdf_wrap((string)$v, 9.5, 175, 3);
        $c .= $T(308, $y, 8.5, $k);
        foreach ($ls as $i => $ln) { $c .= $T(365, $y - 13 * $i, 9.5, $ln); }
        $y -= 13 * count($ls) + 7;
    }
    // コメント（発注者の表の高さに合わせて下げる）
    $cy = min(510, $y - 10);
    $c .= $T(40, $cy, 9.5, '＊コメント欄');
    $cl = kv_pdf_wrap($comment, 9, 500, 5);
    $ch = max(30, 13 * count($cl) + 10);
    $c .= $box(40, $cy - 10 - $ch, 515, $ch, 0.8);
    foreach ($cl as $i => $ln) { $c .= $T(48, $cy - 24 - 13 * $i, 9, $ln); }
    // 明細
    $y = $cy - 10 - $ch - 26;
    $cols = [[40, 70, '発注日'], [110, 62, '発注No'], [172, 198, '商品コード・商品名・規格'], [370, 50, '税抜下代'], [420, 50, '税込下代'], [470, 30, '数量'], [500, 55, '小計（税込）']];
    foreach ($cols as [$x, $w, $t]) { $c .= $F($x, $y - 5, $w, 18, 0.85) . $box($x, $y - 5, $w, 18, 0.4) . $T($x + 4, $y, 8, $t); }
    $sum = 0; $y -= 18;
    foreach ($lines as $l) {
        $nm = kv_pdf_wrap((string)$l['name'], 8, 192, 3);
        $h = max(20, 11 * count($nm) + 8);
        $in = (int)round((int)$l['cost'] * 1.1); $sub = $in * (int)$l['qty']; $sum += $sub;
        foreach ($cols as [$x, $w]) { $c .= $box($x, $y - $h + 13, $w, $h, 0.4); }
        $c .= $T(44, $y, 8, $date) . $T(114, $y, 8, $poNo);
        foreach ($nm as $i => $ln) { $c .= $T(176, $y - 11 * $i, 8, $ln); }
        $c .= $R(416, $y, 8.5, number_format((int)$l['cost'])) . $R(466, $y, 8.5, number_format($in)) . $R(496, $y, 8.5, (string)(int)$l['qty']) . $R(551, $y, 8.5, number_format($sub));
        $y -= $h;
    }
    $c .= $F(470, $y - 5, 30, 18, 0.85) . $box(470, $y - 5, 85, 18, 0.6) . $T(474, $y, 8.5, '合計') . $R(551, $y, 9.5, '￥' . number_format($sum));
    // 回答欄
    $y -= 40;
    $c .= $T(40, $y, 9.5, '【' . ($sup['name'] ?? '') . ' 回答欄】出荷のご連絡はメールでお願いいたします');
    $y -= 20;
    foreach ([[40, 129, '発送日'], [169, 129, '配送予定日'], [298, 128, '配送便'], [426, 129, '送り状No']] as [$x, $w, $t]) {
        $c .= $F($x, $y - 5, $w, 18, 0.85) . $box($x, $y - 5, $w, 18, 0.4) . $T($x + 4, $y, 8.5, $t) . $box($x, $y - 35, $w, 30, 0.4);
    }
    return kv_pdf_document([$c]);
}

/** 発注書のメール本文（既定） */
function kv_po_mail_body(array $o, array $sup, string $poNo, array $lines, string $comment): string
{
    $st = kv_po_ship_to($o);
    $b = (trim((string)($sup['contact'] ?? '')) !== '' ? $sup['contact'] . '　様' : ($sup['name'] ?? '') . '　御中') . "\n\n"
       . "お世話になります。\n" . kv_shop('company') . (kv_shop('po_staff') !== '' ? '　' . kv_shop('po_staff') : '') . "です。\n\n"
       . "下記を注文いたします。注文書（PDF）を添付しましたので、出荷の手配をお願いいたします。\n"
       . "出荷のご連絡（発送日・配送便・送り状No）は、このメールへの返信でお願いいたします。\n\n"
       . '発注No：' . $poNo . "\n";
    foreach ($lines as $l) { $b .= '商品：' . $l['name'] . '　数量：' . (int)$l['qty'] . "\n"; }
    $b .= "お送り先：\n　" . trim($st['company'] . '　' . $st['name'] . ' 様') . "\n　〒" . kv_po_zip($st['zip']) . ' ' . $st['address'] . "\n" . ($st['tel'] !== '' ? '　電話 ' . $st['tel'] . "\n" : '');
    if (trim($comment) !== '') { $b .= "\n" . trim($comment) . "\n"; }
    $b .= "\nよろしくお願いいたします。\n";
    if (trim(kv_shop('po_signature')) !== '') { $b .= "\n" . str_replace('\n', "\n", kv_shop('po_signature')) . "\n"; }
    return $b;
}

// ---------------- 管理画面 ----------------

/** 受注明細に出す「発注書」の欄 */
function kv_admin_po_panel(array $o): string
{
    kv_purchase_schema();
    $id = (int)$o['id'];
    $h = '<div class="panel"><b>発注書（仕入先への注文）</b><p class="src">仕入先ごとに発注書を作り、PDF を付けてメールで送ります。仕入先は型番の頭の記号で決まります（<a href="?a=suppliers">仕入先の登録</a>）。</p>';
    foreach (kv_po_groups($id) as $code => $items) {
        $sup = kv_supplier((string)$code);
        $h .= '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0"><span class="tag">' . kv_e((string)$code) . '</span> '
            . ($sup ? kv_e($sup['name']) . ($sup['email'] ? '' : ' <span class="src" style="color:#b3261e">（メール未登録）</span>') : '<span class="src" style="color:#b3261e">仕入先が未登録です</span>')
            . ' <span class="src">' . count($items) . '品</span>'
            . ' <a class="btn" href="?a=po&id=' . $id . '&sup=' . rawurlencode((string)$code) . '">発注書を作る</a></div>';
    }
    $st = kv_db()->prepare('SELECT * FROM purchase_orders WHERE order_id=? ORDER BY id DESC');
    $st->execute([$id]);
    $sent = $st->fetchAll();
    if ($sent) {
        $h .= '<table class="t" style="margin-top:8px"><tr><th>送信日時</th><th>発注No</th><th>宛先</th><th>合計（税込）</th><th></th></tr>';
        foreach ($sent as $p) {
            $h .= '<tr><td class="src">' . kv_e((string)$p['sent_at']) . '</td><td>' . kv_e($p['po_no']) . '</td><td class="src">' . kv_e($p['sent_to']) . '</td><td>'
                . number_format((int)$p['total']) . '円</td><td>' . ((int)$p['ok'] ? '' : '<span class="tag red">送信できず</span> ')
                . '<a class="btn gray" target="_blank" rel="noopener" href="?a=popdf&po=' . (int)$p['id'] . '">PDF</a></td></tr>';
        }
        $h .= '</table>';
    }
    return $h . '</div>';
}

/** 受注一覧に出す「発注済」の印（受注ID => 送った件数） */
function kv_po_sent_counts(array $ids): array
{
    kv_purchase_schema();
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $st = kv_db()->query('SELECT order_id, COUNT(*) n FROM purchase_orders WHERE ok=1 AND order_id IN (' . implode(',', $ids) . ') GROUP BY order_id');
    $out = [];
    foreach ($st as $r) { $out[(int)$r['order_id']] = (int)$r['n']; }
    return $out;
}

/** 送った発注書の PDF をもう一度出す */
function kv_admin_popdf(): void
{
    kv_purchase_schema();
    $st = kv_db()->prepare('SELECT * FROM purchase_orders WHERE id=?');
    $st->execute([(int)($_GET['po'] ?? 0)]);
    $p = $st->fetch();
    $o = $p ? kv_order((int)$p['order_id']) : null;
    if (!$p || !$o) { http_response_code(404); echo 'not found'; return; }
    $sup = kv_supplier((string)$p['supplier']) ?? ['name' => $p['supplier']];
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . date('Ymd', strtotime((string)$p['created_at'])) . '_' . $p['po_no'] . '.pdf"');
    echo kv_po_pdf($o, $sup, (string)$p['po_no'], json_decode((string)$p['lines'], true) ?: [], (string)$p['comment'], date('Y/m/d', strtotime((string)$p['created_at'])));
}

/** 発注書を作る・見る・送る */
function kv_admin_po(): void
{
    kv_purchase_schema();
    $id = (int)($_GET['id'] ?? 0); $code = (string)($_GET['sup'] ?? '');
    $o = kv_order($id);
    $groups = $o ? kv_po_groups($id) : [];
    if (!$o || !isset($groups[$code])) { http_response_code(404); kv_head('発注書', '', '/admin'); echo '<p>受注か仕入先が見つかりません</p>'; kv_footer(); return; }
    $sup = kv_supplier($code) ?? ['code' => $code, 'name' => $code, 'contact' => '', 'email' => '', 'cc' => ''];
    $items = $groups[$code];
    $poNo = kv_po_no($code, (string)$o['code']);
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok();
    // 明細（名前・数量・仕入れ値）。最初は原価表から自動で引く
    $lines = [];
    if ($isPost) {
        foreach ((array)($_POST['name'] ?? []) as $i => $nm) {
            $lines[] = ['name' => trim((string)$nm), 'qty' => max(1, (int)($_POST['qty'][$i] ?? 1)), 'cost' => (int)preg_replace('/\D/', '', (string)($_POST['cost'][$i] ?? '0'))];
        }
        $comment = (string)($_POST['comment'] ?? '');
    } else {
        $costs = kv_cost_lookup(array_map(fn($it) => (string)$it['model_number'], $items));
        foreach ($items as $it) {
            $nm = trim(preg_replace('/\s+/u', ' ', preg_replace('/【[^】]*】|\(SEIWA\)|（[A-Z]+）/u', '', (string)$it['name'])));
            $lines[] = ['name' => $nm, 'qty' => (int)$it['qty'], 'cost' => $costs[(string)$it['model_number']] ?? 0, 'auto' => isset($costs[(string)$it['model_number']])];
        }
        $comment = "価格が変わっていたら、手配保留にして、ご連絡ください。" . (trim((string)$o['note']) !== '' ? "\nお客様より：" . trim((string)$o['note']) : '');
    }
    $date = date('Y/m/d');
    $act = (string)($_POST['do'] ?? '');
    if ($isPost && $act === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . date('Ymd') . '_' . $poNo . '.pdf"');
        echo kv_po_pdf($o, $sup, $poNo, $lines, $comment, $date);
        return;
    }
    $to = $isPost ? trim((string)($_POST['to'] ?? '')) : (string)($sup['email'] ?? '');
    $cc = $isPost ? trim((string)($_POST['cc'] ?? '')) : trim((string)($sup['cc'] ?? '') . ((string)($sup['cc'] ?? '') !== '' ? ',' : '') . kv_shop('email'), ',');
    $subj = $isPost ? trim((string)($_POST['subject'] ?? '')) : '【注文】' . $lines[0]['name'] . (count($lines) > 1 ? ' ほか' . (count($lines) - 1) . '点' : '') . '（発注No ' . $poNo . '）';
    $body = $isPost ? (string)($_POST['body'] ?? '') : kv_po_mail_body($o, $sup, $poNo, $lines, $comment);
    $msg = '';
    if ($isPost && $act === 'send') {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $msg = '宛先のメールアドレスを確かめてください'; }
        elseif (array_filter($lines, fn($l) => (int)$l['cost'] <= 0)) { $msg = '仕入れ値（税抜下代）が0円の行があります'; }
        else {
            $pdf = kv_po_pdf($o, $sup, $poNo, $lines, $comment, $date);
            $from = kv_shop('email');
            $hd = 'From: ' . mb_encode_mimeheader(kv_shop('company') ?: kv_shop('name')) . " <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $ok = kv_mail($to, mb_encode_mimeheader($subj), $body, $hd, $cc, [date('Ymd') . '_' . $poNo . '.pdf' => $pdf]);
            $total = array_sum(array_map(fn($l) => (int)round((int)$l['cost'] * 1.1) * (int)$l['qty'], $lines));
            kv_db()->prepare('INSERT INTO purchase_orders (order_id,supplier,po_no,created_at,sent_at,sent_to,cc,subject,body,lines,comment,total,ok) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$id, $code, $poNo, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $to, $cc, $subj, $body, json_encode($lines, JSON_UNESCAPED_UNICODE), $comment, $total, $ok ? 1 : 0]);
            kv_log($id, 'po', ($ok ? '発注書を送りました: ' : '発注書を送れませんでした: ') . $poNo . ' → ' . $to . '（' . number_format($total) . '円）');
            header('Location: ?a=order&id=' . $id . '&po_sent=' . ($ok ? 1 : 0));
            return;
        }
    }
    $in = 'padding:6px;border:2px solid #cfdae4;border-radius:8px';
    kv_head('発注書 ' . $poNo, '', '/admin');
    kv_admin_nav('orders');
    echo '<h1>発注書 ' . kv_e($poNo) . '</h1><p><a href="?a=order&id=' . $id . '">← 受注 ' . kv_e($o['code']) . ' に戻る</a></p>';
    if ($msg !== '') { echo '<div class="alert">' . kv_e($msg) . '</div>'; }
    if (!kv_supplier($code)) { echo '<div class="alert">仕入先「' . kv_e($code) . '」が未登録です。<a href="?a=suppliers&edit=' . rawurlencode($code) . '">仕入先を登録</a>すると、宛先と担当者が入ります。</div>'; }
    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<div class="panel"><b>宛先</b>：' . kv_e($sup['name']) . ' 御中' . (trim((string)($sup['contact'] ?? '')) !== '' ? '　' . kv_e($sup['contact']) . ' 様' : '') . '</div>'
       . '<div class="panel"><b>明細</b>' . (kv_shop('cost_api_url') !== '' ? '<span class="src">　仕入れ値は原価表から自動で入れています（★）。違っていたら直してください。</span>' : '')
       . '<table class="t"><tr><th>商品</th><th>数量</th><th>税抜下代</th></tr>';
    foreach ($lines as $i => $l) {
        echo '<tr><td><input name="name[]" value="' . kv_e($l['name']) . '" style="' . $in . ';width:100%;min-width:320px"></td>'
           . '<td><input name="qty[]" value="' . (int)$l['qty'] . '" style="' . $in . ';width:60px"></td>'
           . '<td><input name="cost[]" value="' . ((int)$l['cost'] ?: '') . '" style="' . $in . ';width:100px" placeholder="円"> ' . (!empty($l['auto']) ? '★' : '') . '</td></tr>';
    }
    echo '</table><p class="src">コメント欄（注文書に載ります）</p><textarea name="comment" rows="3" style="' . $in . ';width:100%">' . kv_e($comment) . '</textarea>'
       . '<p><button class="btn gray" type="submit" name="do" value="pdf" formtarget="_blank">PDFを見る</button></p></div>'
       . '<div class="panel"><b>メール</b>（PDF を付けて送ります）'
       . '<p>宛先 <input name="to" value="' . kv_e($to) . '" style="' . $in . ';width:300px">　Cc <input name="cc" value="' . kv_e($cc) . '" style="' . $in . ';width:300px"></p>'
       . '<p>件名 <input name="subject" value="' . kv_e($subj) . '" style="' . $in . ';width:80%"></p>'
       . '<textarea name="body" rows="18" style="' . $in . ';width:100%;font-family:monospace">' . kv_e($body) . '</textarea>'
       . '<p class="src">明細やコメントを直したときは、本文の該当部分も直してください（本文は自動では作り直しません）。</p>'
       . '<p><button class="btn" type="submit" name="do" value="send" onclick="return confirm(\'この内容で仕入先に送りますか？\')">発注書を送る</button></p></div></form>';
    kv_footer();
}

/** 仕入先の登録 */
function kv_admin_suppliers(): void
{
    kv_purchase_schema();
    $db = kv_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if (!empty($_POST['delete'])) {
            $db->prepare('DELETE FROM suppliers WHERE code=?')->execute([$code]);
        } elseif (preg_match('/^[A-Z]{2,5}$/', $code)) {
            $db->prepare('INSERT INTO suppliers (code,name,contact,email,cc,tel,note,updated_at) VALUES (?,?,?,?,?,?,?,?)
                          ON CONFLICT(code) DO UPDATE SET name=excluded.name, contact=excluded.contact, email=excluded.email, cc=excluded.cc, tel=excluded.tel, note=excluded.note, updated_at=excluded.updated_at')
               ->execute([$code, trim((string)$_POST['name']), trim((string)$_POST['contact']), trim((string)$_POST['email']), trim((string)$_POST['cc']),
                          trim((string)$_POST['tel']), trim((string)$_POST['note']), date('Y-m-d H:i:s')]);
        }
        header('Location: ?a=suppliers'); return;
    }
    $in = 'padding:6px;border:2px solid #cfdae4;border-radius:8px';
    kv_head('仕入先', '', '/admin');
    kv_admin_nav('suppliers');
    echo '<h1>仕入先</h1><p class="src">型番の頭の記号（SWA-401082S なら SWA）で仕入先が決まります。発注書の宛先・担当者・メールに使います。</p>';
    $edit = kv_supplier((string)($_GET['edit'] ?? '')) ?? ['code' => (string)($_GET['edit'] ?? ''), 'name' => '', 'contact' => '', 'email' => '', 'cc' => '', 'tel' => '', 'note' => ''];
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><b>' . ($edit['name'] !== '' ? '仕入先を直す' : '仕入先を足す') . '</b>'
       . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">';
    foreach (['code' => '記号', 'name' => '会社名', 'contact' => '担当者', 'email' => 'メール', 'cc' => 'Cc（カンマ区切り）', 'tel' => '電話', 'note' => 'メモ'] as $k => $lb) {
        echo '<label class="src">' . $lb . '<br><input name="' . $k . '" value="' . kv_e((string)$edit[$k]) . '" style="' . $in . ';width:' . ($k === 'code' ? '80' : '200') . 'px"></label>';
    }
    echo '</div><p><button class="btn" type="submit">保存</button></p></form>';
    $have = [];
    echo '<table class="t"><tr><th>記号</th><th>会社名</th><th>担当者</th><th>メール</th><th>Cc</th><th>商品数</th><th></th></tr>';
    $cnt = [];
    foreach ($db->query("SELECT model_number FROM products WHERE hidden=0") as $r) { $k = kv_supplier_code_of($r['model_number']); if ($k !== '') { $cnt[$k] = ($cnt[$k] ?? 0) + 1; } }
    foreach ($db->query('SELECT * FROM suppliers ORDER BY code') as $s) {
        $have[$s['code']] = 1;
        echo '<tr><td><b>' . kv_e($s['code']) . '</b></td><td>' . kv_e($s['name']) . '</td><td>' . kv_e($s['contact']) . '</td><td>' . kv_e($s['email']) . '</td><td class="src">' . kv_e($s['cc'])
           . '</td><td>' . number_format($cnt[$s['code']] ?? 0) . '</td><td><a class="btn gray" href="?a=suppliers&edit=' . rawurlencode($s['code']) . '">直す</a></td></tr>';
    }
    echo '</table>';
    $miss = array_diff_key($cnt, $have); arsort($miss);
    if ($miss) {
        echo '<h2>まだ登録していない記号</h2><div class="makers">';
        foreach ($miss as $k => $n) { echo '<a href="?a=suppliers&edit=' . rawurlencode((string)$k) . '">' . kv_e((string)$k) . ' <span class="src">' . number_format($n) . '</span></a>'; }
        echo '</div>';
    }
    kv_footer();
}
