<?php
/**
 * 管理画面：お問い合わせ受付一覧・ブラックリスト設定・帳票（PDF）（2026-11-17・おちゃのこネットの同名の画面に合わせる）
 *
 *   ?a=inquiries        お問い合わせ受付一覧（名前/E-mail・対応状況・日付で検索、CSV、一括で対応状況を変える）
 *   ?a=inquiry&id=      お問い合わせの詳細（対応状況・メモ・メール送信・送信履歴）
 *   ?a=icsv             お問い合わせのCSV
 *   ?a=blacklist        ブラックリスト設定（登録・検索・編集・削除）
 *   ?a=doc&id=&type=    納品書・請求書・領収書の PDF（type=delivery|invoice|receipt。atena・tadashi・date・note を渡せる）
 */
declare(strict_types=1);

// ---------------- 帳票 ----------------

function kv_admin_doc(): void
{
    $id = (int)($_GET['id'] ?? 0); $type = (string)($_GET['type'] ?? 'invoice');
    if (!kv_order($id) || !isset(KV_DOC_TYPES[$type])) { http_response_code(404); echo '受注が見つかりません'; return; }
    $pdf = kv_doc_pdf($id, $type, ['atena' => $_GET['atena'] ?? '', 'tadashi' => $_GET['tadashi'] ?? '', 'date' => $_GET['date'] ?? '', 'note' => $_GET['note'] ?? '']);
    kv_log($id, 'doc', KV_DOC_TYPES[$type] . 'を出しました' . (!empty($_GET['atena']) ? '（宛名 ' . $_GET['atena'] . '）' : ''));
    header('Content-Type: application/pdf');
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode(kv_doc_filename($id, $type)));
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
}

/** 受注明細とメール送信画面に出す、帳票の欄。 */
function kv_admin_doc_panel(array $o): string
{
    $id = (int)$o['id'];
    $in = 'padding:6px;border:2px solid #cfdae4;border-radius:8px';
    $h = '<div class="panel"><b>帳票（PDF）</b><p class="src">請求書・領収書には登録番号と税率ごとの消費税額が入ります（適格請求書）。</p><div style="display:flex;gap:8px;flex-wrap:wrap">';
    foreach (['delivery', 'invoice'] as $t) { $h .= '<a class="btn gray" target="_blank" rel="noopener" href="?a=doc&id=' . $id . '&type=' . $t . '">' . KV_DOC_TYPES[$t] . '</a>'; }
    $h .= '</div><form method="get" target="_blank" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:10px">'
        . '<input type="hidden" name="a" value="doc"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="type" value="receipt">'
        . '<span class="src">領収書：宛名</span><input name="atena" value="' . kv_e($o['company'] ?: $o['name']) . '" style="' . $in . ';width:220px">'
        . '<span class="src">但し</span><input name="tadashi" value="商品代として" style="' . $in . ';width:160px">'
        . '<span class="src">発行日</span><input type="date" name="date" value="' . date('Y-m-d') . '" style="' . $in . '">'
        . '<button class="btn gray" type="submit">領収書</button></form></div>';
    return $h;
}

// ---------------- お問い合わせ ----------------

function kv_admin_inquiries(): void
{
    kv_ops_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok() && isset(KV_INQ_STATUS[$_POST['status'] ?? ''])) {
        $st = kv_db()->prepare('UPDATE inquiries SET status=? WHERE id=?');
        foreach (array_map('intval', (array)($_POST['ids'] ?? [])) as $i) { $st->execute([$_POST['status'], $i]); }
        header('Location: ?' . preg_replace('/(^|&)done=\d+/', '', (string)$_SERVER['QUERY_STRING']) . '&done=1'); exit;
    }
    [$w, $a] = kv_admin_inq_filter($_GET);
    $per = 30; $page = max(1, (int)($_GET['p'] ?? 1));
    $st = kv_db()->prepare("SELECT COUNT(*) FROM inquiries WHERE $w"); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = kv_db()->prepare("SELECT i.*, (SELECT COUNT(*) FROM inquiry_mails m WHERE m.inquiry_id=i.id AND m.ok=1) sent FROM inquiries i WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($a);
    kv_head('お問い合わせ受付一覧', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap">'; kv_admin_nav('inquiries');
    echo '<h1>お問い合わせ受付一覧</h1>';
    if (!empty($_GET['done'])) { echo '<div class="note">更新しました。</div>'; }
    $sel = 'padding:7px;border:2px solid #cfdae4;border-radius:8px';
    echo '<form method="get" class="panel" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><input type="hidden" name="a" value="inquiries">'
       . '<div><label class="src">名前/E-mail/内容</label><input name="q" value="' . kv_e($_GET['q'] ?? '') . '" style="' . $sel . ';min-width:240px"></div>'
       . '<div><label class="src">対応状況</label><select name="status" style="' . $sel . '"><option value="">すべて</option>';
    foreach (KV_INQ_STATUS as $k => $lb) { echo '<option value="' . $k . '"' . (($_GET['status'] ?? '') === $k ? ' selected' : '') . '>' . $lb . '</option>'; }
    echo '</select></div><div><label class="src">日付</label><input type="date" name="from" value="' . kv_e($_GET['from'] ?? '') . '" style="' . $sel . '"> 〜 '
       . '<input type="date" name="to" value="' . kv_e($_GET['to'] ?? '') . '" style="' . $sel . '"></div>'
       . '<div><button class="btn" type="submit">絞り込む</button> <a class="btn gray" href="?a=icsv&' . kv_e(http_build_query(array_diff_key($_GET, ['a' => 1]))) . '">CSVで落とす</a></div></form>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><p class="src">' . $total . '件　チェックしたものを '
       . '<select name="status" style="' . $sel . '">';
    foreach (KV_INQ_STATUS as $k => $lb) { echo '<option value="' . $k . '">' . $lb . '</option>'; }
    echo '</select> に <button class="btn gray" type="submit">する</button></p><div class="scroll"><table class="t"><tr><th></th><th>日時</th><th>お名前・メール</th><th>お問い合わせ内容</th><th>対応状況</th><th>メール</th><th></th></tr>';
    foreach ($st->fetchAll() as $r) {
        echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int)$r['id'] . '"></td><td class="src" style="white-space:nowrap">' . kv_e(substr((string)$r['created_at'], 5, 11)) . '</td>'
           . '<td>' . ($r['bl_note'] ? '<span class="tag red" title="' . kv_e($r['bl_note']) . '">ブラックリスト</span> ' : '') . kv_e($r['name']) . '<div class="src">' . kv_e($r['email']) . '</div></td>'
           . '<td style="font-size:13px;max-width:460px">' . kv_e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$r['body']), 0, 120, '…')) . '</td>'
           . '<td><span class="tag ' . (['new' => 'red', 'doing' => 'or', 'done' => 'gr', 'spam' => ''][$r['status']] ?? '') . '">' . kv_e(KV_INQ_STATUS[$r['status']] ?? $r['status']) . '</span></td>'
           . '<td class="src">' . ((int)$r['sent'] ? '送信済：' . (int)$r['sent'] . '通' : '—') . '</td>'
           . '<td style="white-space:nowrap"><a class="btn" href="?a=inquiry&id=' . (int)$r['id'] . '#reply">メール送信</a> <a class="btn gray" href="?a=inquiry&id=' . (int)$r['id'] . '">詳細</a></td></tr>';
    }
    echo '</table></div></form>';
    kv_pager(['page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], '?' . http_build_query(array_diff_key($_GET, ['p' => 1, 'done' => 1])));
    echo '</div>'; kv_footer();
}

function kv_admin_inq_filter(array $g): array
{
    $w = ['1=1']; $a = [];
    if (!empty($g['q'])) { $w[] = '(name LIKE ? OR email LIKE ? OR body LIKE ? OR tel LIKE ?)'; array_push($a, ...array_fill(0, 4, '%' . $g['q'] . '%')); }
    if (!empty($g['status'])) { $w[] = 'status=?'; $a[] = (string)$g['status']; }
    else { $w[] = "status<>'spam'"; }   // スパム判定は「対応状況：スパム判定」を選んだときだけ出す（おちゃのこネットの非表示と同じ考え）
    if (!empty($g['from'])) { $w[] = 'created_at>=?'; $a[] = $g['from'] . ' 00:00:00'; }
    if (!empty($g['to'])) { $w[] = 'created_at<=?'; $a[] = $g['to'] . ' 23:59:59'; }
    return [implode(' AND ', $w), $a];
}

function kv_admin_icsv(): void
{
    kv_ops_schema();
    [$w, $a] = kv_admin_inq_filter($_GET);
    $st = kv_db()->prepare("SELECT id,created_at,name,email,tel,status,body,memo FROM inquiries WHERE $w ORDER BY id DESC"); $st->execute($a);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inquiries_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['番号', '日時', 'お名前', 'メール', '電話', '対応状況', 'お問い合わせ内容', '管理メモ']);
    while ($r = $st->fetch()) { $r['status'] = KV_INQ_STATUS[$r['status']] ?? $r['status']; fputcsv($out, array_values($r)); }
    fclose($out);
}

function kv_admin_inquiry(): void
{
    kv_ops_schema();
    $id = (int)($_REQUEST['id'] ?? 0);
    $st = kv_db()->prepare('SELECT * FROM inquiries WHERE id=?'); $st->execute([$id]); $q = $st->fetch();
    if (!$q) { http_response_code(404); echo 'お問い合わせが見つかりません'; return; }
    $err = ''; $step = 'edit';
    $quote = implode("\n", array_map(fn($l) => '> ' . $l, preg_split('/\R/u', rtrim((string)$q['body']))));
    $to = (string)$q['email']; $cc = kv_shop('email');
    $subj = 'Re: お問い合わせ（' . kv_shop('name') . '）';
    $body = $q['name'] . " 様\n\n" . kv_shop('name') . " です。\nお問い合わせいただき、ありがとうございます。\n\n\n\n" . kv_shop('name') . '（' . kv_shop('company') . "）\n" . kv_shop('email') . "\n"
          . (trim(kv_shop('mail_footer')) !== '' ? "\n" . trim(kv_shop('mail_footer')) . "\n" : '')
          . "\n" . date('Y/m/d H:i', strtotime((string)$q['created_at'])) . " のお問い合わせ:\n" . $quote . "\n";
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        if (isset($_POST['save'])) {
            kv_db()->prepare('UPDATE inquiries SET status=?, memo=? WHERE id=?')->execute([isset(KV_INQ_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : $q['status'], (string)($_POST['memo'] ?? ''), $id]);
            header('Location: ?a=inquiry&id=' . $id . '&saved=1'); exit;
        }
        $to = trim((string)$_POST['to']); $cc = trim((string)$_POST['cc']); $subj = trim((string)$_POST['subject']); $body = str_replace("\r\n", "\n", (string)$_POST['body']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || ($cc !== '' && !filter_var($cc, FILTER_VALIDATE_EMAIL))) { $err = '宛先・Cc を確かめてください。'; }
        elseif ($subj === '' || trim($body) === '') { $err = '件名と本文を入れてください。'; }
        elseif (($_POST['step'] ?? '') === 'send') {
            $s = kv_mail_subject($subj);
            $from = kv_shop('email');
            $h = 'From: ' . mb_encode_mimeheader(kv_shop('name')) . " <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $ok = kv_mail($to, mb_encode_mimeheader($s), $body, $h, $cc);
            kv_db()->prepare('INSERT INTO inquiry_mails (inquiry_id,at,to_addr,cc,subject,body,ok) VALUES (?,?,?,?,?,?,?)')->execute([$id, date('Y-m-d H:i:s'), $to, $cc, $s, $body, $ok ? 1 : 0]);
            if ($ok && $q['status'] === 'new') { kv_db()->prepare("UPDATE inquiries SET status='doing' WHERE id=?")->execute([$id]); }
            header('Location: ?a=inquiry&id=' . $id . '&mailed=' . ($ok ? 1 : 0)); exit;
        } else { $step = 'confirm'; }
    }
    kv_head('お問い合わせ ' . $id, '', '/admin', ['noindex' => true]);
    echo '<div class="wrap">'; kv_admin_nav('inquiries');
    echo '<nav class="crumb"><a href="?a=inquiries">お問い合わせ受付一覧</a> › ' . $id . '</nav><h1>お問い合わせ（' . kv_e($q['name']) . ' 様）</h1>';
    if (!empty($_GET['saved'])) { echo '<div class="note">保存しました。</div>'; }
    if (isset($_GET['mailed'])) { echo $_GET['mailed'] === '1' ? '<div class="note">メールを送りました。</div>' : '<div class="alert">メールを送れませんでした。</div>'; }
    if ($q['bl_note']) { echo '<div class="alert"><b>ブラックリストに一致：</b>' . kv_e($q['bl_note']) . '</div>'; }
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    echo '<div class="panel"><table class="ftbl" style="width:100%"><tr><th>日時</th><td>' . kv_e($q['created_at']) . '</td></tr><tr><th>お名前</th><td>' . kv_e($q['name']) . '</td></tr>'
       . '<tr><th>メール</th><td>' . kv_e($q['email']) . '</td></tr><tr><th>電話</th><td>' . kv_e($q['tel']) . '</td></tr>'
       . ($q['product_id'] ? '<tr><th>商品</th><td><a href="?a=product&id=' . (int)$q['product_id'] . '">' . (int)$q['product_id'] . '</a></td></tr>' : '')
       . '<tr><th>IP</th><td class="src">' . kv_e($q['ip']) . '　<a href="?a=blacklist&email=' . rawurlencode((string)$q['email']) . '&name=' . rawurlencode((string)$q['name']) . '&tel=' . rawurlencode((string)$q['tel']) . '&ip=' . rawurlencode((string)$q['ip']) . '">ブラックリストに登録</a></td></tr>'
       . '<tr><th>お問い合わせ内容</th><td><pre style="white-space:pre-wrap;margin:0;font-family:inherit">' . kv_e($q['body']) . '</pre></td></tr></table>'
       . '<form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="id" value="' . $id . '">'
       . '<span class="src">対応状況</span> <select name="status" style="padding:6px;border:2px solid #cfdae4;border-radius:8px">';
    foreach (KV_INQ_STATUS as $k => $lb) { echo '<option value="' . $k . '"' . ($q['status'] === $k ? ' selected' : '') . '>' . $lb . '</option>'; }
    echo '</select><label class="src" style="display:block;margin-top:8px">管理メモ</label><textarea name="memo" rows="2" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px">' . kv_e($q['memo']) . '</textarea>'
       . '<p><button class="btn gray" name="save" value="1">保存する</button></p></form></div>';
    $in = 'width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px;box-sizing:border-box';
    $hidden = '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="id" value="' . $id . '">';
    echo '<div class="panel" id="reply"><b>メール送信</b>';
    if ($step === 'confirm') {
        echo '<p>宛先 ' . kv_e($to) . '　Cc ' . kv_e($cc ?: 'なし') . '<br>件名 <b>' . kv_e(kv_mail_subject($subj)) . '</b></p>'
           . '<pre style="white-space:pre-wrap;font-size:13px;background:#f7f9fb;border:1px solid #e3e9ee;border-radius:8px;padding:10px">' . kv_e($body) . '</pre>'
           . '<form method="post">' . $hidden;
        foreach (['to' => $to, 'cc' => $cc, 'subject' => $subj, 'body' => $body] as $k => $v) { echo '<input type="hidden" name="' . $k . '" value="' . kv_e($v) . '">'; }
        echo '<button class="btn" name="step" value="send">送信する</button> <button class="btn gray" name="step" value="edit">戻って直す</button></form></div>';
    } else {
        echo '<form method="post">' . $hidden
           . '<label class="src">宛先</label><input name="to" value="' . kv_e($to) . '" style="' . $in . '">'
           . '<label class="src" style="display:block;margin-top:6px">Cc（店の控え）</label><input name="cc" value="' . kv_e($cc) . '" style="' . $in . '">'
           . '<label class="src" style="display:block;margin-top:6px">件名（先頭に [' . kv_e(kv_shop('name')) . '] が自動で付きます）</label><input name="subject" value="' . kv_e($subj) . '" style="' . $in . '">'
           . '<label class="src" style="display:block;margin-top:6px">本文</label><textarea name="body" rows="18" style="' . $in . ';font-family:ui-monospace,monospace;font-size:13px">' . kv_e($body) . '</textarea>'
           . '<p><button class="btn" name="step" value="confirm">内容を確認する</button></p></form></div>';
    }
    $ms = kv_db()->prepare('SELECT * FROM inquiry_mails WHERE inquiry_id=? ORDER BY id DESC'); $ms->execute([$id]);
    $rows = $ms->fetchAll();
    if ($rows) {
        echo '<div class="panel"><b>送信履歴</b>';
        foreach ($rows as $m) {
            echo '<details style="margin-top:8px"><summary>' . kv_e(substr((string)$m['at'], 0, 16)) . '　' . kv_e($m['subject']) . ((int)$m['ok'] ? '' : ' <b style="color:#b42318">（送信できず）</b>') . '</summary>'
               . '<p class="src">宛先 ' . kv_e($m['to_addr']) . ($m['cc'] ? '　Cc ' . kv_e($m['cc']) : '') . '</p><pre style="white-space:pre-wrap;font-size:13px;background:#f7f9fb;border:1px solid #e3e9ee;border-radius:8px;padding:10px">' . kv_e($m['body']) . '</pre></details>';
        }
        echo '</div>';
    }
    echo '</div>'; kv_footer();
}

// ---------------- ブラックリスト ----------------

function kv_admin_blacklist(): void
{
    kv_ops_schema();
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        if (!empty($_POST['delete'])) { kv_db()->prepare('DELETE FROM blacklist WHERE id=?')->execute([(int)$_POST['delete']]); kv_blacklist_recheck(); header('Location: ?a=blacklist&saved=1'); exit; }
        $v = []; foreach (['email', 'name', 'tel', 'address', 'ip', 'memo'] as $k) { $v[$k] = trim((string)($_POST[$k] ?? '')); }
        if ($v['email'] === '' && $v['name'] === '' && $v['tel'] === '' && $v['address'] === '' && $v['ip'] === '') { $err = 'メールアドレス・名前・電話番号・住所・IPアドレスのどれかを入れてください。'; }
        else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) { kv_db()->prepare('UPDATE blacklist SET email=?,name=?,tel=?,address=?,ip=?,memo=? WHERE id=?')->execute(array_merge(array_values($v), [$id])); }
            else { kv_db()->prepare('INSERT INTO blacklist (created_at,email,name,tel,address,ip,memo) VALUES (?,?,?,?,?,?,?)')->execute(array_merge([date('Y-m-d H:i:s')], array_values($v))); }
            kv_blacklist_recheck();
            header('Location: ?a=blacklist&saved=1'); exit;
        }
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $st = $q === '' ? kv_db()->query('SELECT * FROM blacklist ORDER BY id DESC')
                    : kv_db()->prepare('SELECT * FROM blacklist WHERE email LIKE ? OR name LIKE ? OR tel LIKE ? OR address LIKE ? OR ip LIKE ? OR memo LIKE ? ORDER BY id DESC');
    if ($q !== '') { $st->execute(array_fill(0, 6, '%' . $q . '%')); }
    kv_head('ブラックリスト設定', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap">'; kv_admin_nav('blacklist');
    echo '<h1>ブラックリスト設定</h1>';
    if (!empty($_GET['saved'])) { echo '<div class="note">保存しました。</div>'; }
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    echo '<p class="src">登録したメールアドレス（@の後ろのドメインだけでも可。例：example.com）・名前・電話番号・住所・IPアドレスのどれかが、注文やお問い合わせと一致すると、'
       . '店への受注確認メールに「ブラックリストに一致」と書き、受注一覧とお問い合わせ受付一覧に印が出ます。おちゃのこネットと同じく、購入そのものは止めません（在庫確認や入金の前に、こちらで判断してください）。</p>';
    $in = 'padding:6px;border:2px solid #cfdae4;border-radius:8px';
    $form = function (array $b) use ($in): string {
        $f = '<form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="id" value="' . (int)($b['id'] ?? 0) . '">';
        foreach (['email' => 'メール/ドメイン', 'name' => '名前', 'tel' => '電話番号', 'address' => '住所（一部で可）', 'ip' => 'IPアドレス', 'memo' => 'メモ'] as $k => $ph) {
            $f .= '<input name="' . $k . '" value="' . kv_e($b[$k] ?? '') . '" placeholder="' . $ph . '" style="' . $in . ';width:' . ($k === 'memo' || $k === 'address' ? 200 : 150) . 'px">';
        }
        return $f . '<button class="btn gray" type="submit">' . (!empty($b['id']) ? '保存' : '登録する') . '</button>'
             . (!empty($b['id']) ? '<button class="btn gray" name="delete" value="' . (int)$b['id'] . '" onclick="return confirm(\'削除します\')">削除</button>' : '') . '</form>';
    };
    echo '<div class="panel"><b>新しくブラックリストを登録する</b>' . $form(array_intersect_key($_GET, array_flip(['email', 'name', 'tel', 'address', 'ip']))) . '</div>';
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="blacklist"><input name="q" value="' . kv_e($q) . '" placeholder="検索" style="' . $in . '"><button class="btn gray">探す</button></form>';
    foreach ($st->fetchAll() as $b) { echo '<div class="panel"><span class="src">#' . (int)$b['id'] . '　登録 ' . kv_e(substr((string)$b['created_at'], 0, 10)) . '</span>' . $form($b) . '</div>'; }
    echo '</div>'; kv_footer();
}
