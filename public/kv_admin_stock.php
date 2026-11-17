<?php
/**
 * 管理画面：在庫管理（2026-11-17・おちゃのこネットの「在庫管理」と同じ並び）
 *   ?a=stock      在庫一括設定（在庫数を入力／加算・減算で入力、販売価格も。-99 で在庫数を管理しない）
 *   ?a=stocklog   在庫数変更履歴（日付・商品・受注番号・イベントで検索、CSV）
 *   ?a=stockset   在庫表示・通知設定と再入荷お知らせメール設定（担当者へテストメール）
 *   ?a=restock    再入荷お知らせ登録一覧（入荷済み一覧から送信・入荷待ち一覧・配信済み）
 */
declare(strict_types=1);

function kv_admin_stock(): void
{
    kv_stock_schema();
    $mode = ($_REQUEST['mode'] ?? 'set') === 'add' ? 'add' : 'set';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $n = 0;
        foreach ((array)($_POST['stock'] ?? []) as $id => $v) {
            $id = (int)$id; $p = kv_aproduct($id); if (!$p) { continue; }
            $v = trim(mb_convert_kana((string)$v, 'n'));
            $vals = [];
            if ($v !== '') {
                if ($mode === 'add') {
                    if (preg_match('/^[+\-]?\d+$/', $v) && kv_stock_tracked($p)) { $vals['stock'] = max(0, (int)$p['stock'] + (int)$v); }
                } elseif ($v === '-99') { $vals['stock_unlimited'] = 1; }
                elseif (ctype_digit($v)) { $vals['stock'] = (int)$v; if (!kv_stock_tracked($p)) { $vals['stock_unlimited'] = 0; } }
            }
            $pr = preg_replace('/[^\d]/', '', mb_convert_kana((string)($_POST['price'][$id] ?? ''), 'n'));
            if ($mode === 'set' && $pr !== '' && (int)$pr !== (int)$p['price']) { $vals['price'] = (int)$pr; }
            foreach ($vals as $k => $x) { if ((string)$p[$k] === (string)$x) { unset($vals[$k]); } }
            if ($vals) { kv_pupdate($id, $vals, '在庫一括設定'); $n++; }
        }
        header('Location: ?' . preg_replace('/(^|&)done=\d+/', '', (string)$_SERVER['QUERY_STRING']) . '&done=' . $n); exit;
    }
    $g = $_GET;
    [$where, $args] = kv_admin_pfilter(array_intersect_key($g, ['q' => 1, 'maker_id' => 1, 'category_id' => 1]));
    $line = (int)kv_stock_settings()['low_line'];
    $sf = (string)($g['st'] ?? 'tracked');
    $where .= ' AND hidden=0' . ['tracked' => ' AND COALESCE(stock_unlimited,0)=0', 'low' => " AND COALESCE(stock_unlimited,0)=0 AND stock>0 AND stock<=$line",
              'out' => ' AND COALESCE(stock_unlimited,0)=0 AND COALESCE(stock,0)<=0', 'unlimited' => ' AND stock_unlimited=1', 'all' => ''][$sf] ?? '';
    $per = 100; $page = max(1, (int)($g['p'] ?? 1));
    $st = kv_db()->prepare("SELECT COUNT(*) FROM products WHERE $where"); $st->execute($args); $total = (int)$st->fetchColumn();
    $st = kv_db()->prepare("SELECT id,name,model_number,price,stock,stock_unlimited FROM products WHERE $where ORDER BY COALESCE(stock_unlimited,0), stock, id LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $makers = []; foreach (kv_admin_makers_all() as $m) { $makers[$m['id']] = $m['name'] . '（' . $m['cnt'] . '）'; }
    kv_admin_page('在庫一括設定', 'stock');
    if (isset($g['done'])) { echo '<div class="note">' . (int)$g['done'] . '件を更新しました（在庫数変更履歴に残ります）。</div>'; }
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="stock"><input type="hidden" name="mode" value="' . $mode . '">'
       . '<input class="in" name="q" value="' . kv_e($g['q'] ?? '') . '" placeholder="商品名・型番・JAN">' . kv_sel('maker_id', $makers, $g['maker_id'] ?? '', 'メーカー（すべて）')
       . kv_sel('st', ['tracked' => '在庫数を管理する商品', 'low' => '在庫わずか（' . $line . '以下）', 'out' => '在庫切れ', 'unlimited' => '在庫数を管理しない商品', 'all' => 'すべて'], $sf)
       . '<button class="btn" type="submit">絞り込む</button></form>';
    $qs = fn($m) => '?' . http_build_query(array_merge(array_diff_key($g, ['done' => 1, 'p' => 1]), ['a' => 'stock', 'mode' => $m]));
    echo '<p>入力方法：' . ($mode === 'set' ? '<b>在庫数を入力</b>　<a href="' . kv_e($qs('add')) . '">加算／減算で入力</a>' : '<a href="' . kv_e($qs('set')) . '">在庫数を入力</a>　<b>加算／減算で入力</b>')
       . '<br><span class="src">' . ($mode === 'set' ? '在庫数は 0 以上の数字。「-99」で在庫数を管理しない（メーカー直送のお取り寄せ）。販売価格（税別）も変えられます。'
                                                  : '「+10」「-3」のように入れると、いまの在庫数に足し引きします（0 より下にはなりません）。在庫数を管理する商品だけ。') . '</span></p>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="mode" value="' . $mode . '">'
       . '<div class="scroll"><table class="t"><tr><th>ID</th><th>商品名・型番</th><th>いまの在庫</th><th>' . ($mode === 'set' ? '在庫数' : '増減') . '</th>' . ($mode === 'set' ? '<th>販売価格（税別）</th>' : '') . '</tr>';
    foreach ($st->fetchAll() as $p) {
        $cur = (int)$p['stock_unlimited'] ? '<span class="src">管理しない</span>' : ((int)$p['stock'] <= 0 ? '<span class="tag red">0</span>' : ((int)$p['stock'] <= $line ? '<span class="tag or">' . (int)$p['stock'] . '</span>' : (int)$p['stock']));
        echo '<tr><td>' . (int)$p['id'] . '</td><td><a href="?a=product&id=' . (int)$p['id'] . '">' . kv_e(mb_strimwidth((string)$p['name'], 0, 60, '…')) . '</a><div class="src">' . kv_e($p['model_number']) . '</div></td>'
           . '<td style="text-align:right">' . $cur . '</td>'
           . '<td><input class="in" name="stock[' . (int)$p['id'] . ']" value="' . ($mode === 'set' ? ((int)$p['stock_unlimited'] ? '-99' : (int)$p['stock']) : '') . '" style="width:90px" inputmode="numeric"></td>'
           . ($mode === 'set' ? '<td><input class="in" name="price[' . (int)$p['id'] . ']" value="' . (int)$p['price'] . '" style="width:120px" inputmode="numeric"></td>' : '') . '</tr>';
    }
    echo '</table></div><p><button class="btn" type="submit">設定処理を完了する</button></p></form>';
    kv_pager(['page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], '?' . http_build_query(array_diff_key($g, ['p' => 1, 'done' => 1])));
    kv_admin_end();
}

function kv_admin_stocklog_filter(array $g): array
{
    $w = ['1=1']; $a = [];
    if (!empty($g['from'])) { $w[] = 'at>=?'; $a[] = $g['from'] . ' 00:00:00'; }
    if (!empty($g['to'])) { $w[] = 'at<=?'; $a[] = $g['to'] . ' 23:59:59'; }
    if (!empty($g['pid'])) { $w[] = 'product_id=?'; $a[] = (int)$g['pid']; }
    if (!empty($g['q'])) { $w[] = 'name LIKE ?'; $a[] = '%' . $g['q'] . '%'; }
    if (!empty($g['code'])) { $w[] = 'order_code LIKE ?'; $a[] = '%' . $g['code'] . '%'; }
    if (!empty($g['event'])) { $w[] = 'event=?'; $a[] = (string)$g['event']; }
    $w[] = "at>=?"; $a[] = date('Y-m-d', strtotime('-1 year'));   // おちゃのこネットと同じく過去1年分
    return [implode(' AND ', $w), $a];
}

function kv_admin_stocklog(): void
{
    kv_stock_schema();
    [$w, $a] = kv_admin_stocklog_filter($_GET);
    $per = 100; $page = max(1, (int)($_GET['p'] ?? 1));
    $st = kv_db()->prepare("SELECT COUNT(*) FROM stock_log WHERE $w"); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = kv_db()->prepare("SELECT * FROM stock_log WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a);
    $events = array_column(kv_db()->query('SELECT DISTINCT event FROM stock_log ORDER BY event')->fetchAll(), 'event');
    kv_admin_page('在庫数変更履歴', 'stocklog');
    $g = $_GET;
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="stocklog">'
       . '<input type="date" class="in" name="from" value="' . kv_e($g['from'] ?? '') . '"> 〜 <input type="date" class="in" name="to" value="' . kv_e($g['to'] ?? '') . '">'
       . '<input class="in" name="pid" value="' . kv_e($g['pid'] ?? '') . '" placeholder="商品番号" style="width:100px"><input class="in" name="q" value="' . kv_e($g['q'] ?? '') . '" placeholder="商品名">'
       . '<input class="in" name="code" value="' . kv_e($g['code'] ?? '') . '" placeholder="受注番号" style="width:150px">'
       . kv_sel('event', array_combine($events, $events) ?: [], $g['event'] ?? '', 'イベント（すべて）')
       . '<button class="btn" type="submit">絞り込む</button><a class="btn gray" href="?a=slcsv&' . kv_e(http_build_query(array_diff_key($g, ['a' => 1]))) . '">CSVで落とす</a></form>';
    echo '<p class="src">過去1年分。' . $total . '件</p><div class="scroll"><table class="t"><tr><th>日時</th><th>商品</th><th>変更前</th><th>変更後</th><th>増減</th><th>イベント</th><th>受注番号</th></tr>';
    foreach ($st->fetchAll() as $r) {
        echo '<tr><td class="src" style="white-space:nowrap">' . kv_e($r['at']) . '</td><td><a href="?a=product&id=' . (int)$r['product_id'] . '">' . (int)$r['product_id'] . '</a> ' . kv_e(mb_strimwidth((string)$r['name'], 0, 50, '…')) . '</td>'
           . '<td style="text-align:right">' . kv_e((string)$r['before']) . '</td><td style="text-align:right">' . kv_e((string)$r['after']) . '</td>'
           . '<td style="text-align:right" class="' . ((int)$r['diff'] >= 0 ? 'up' : 'down') . '">' . ((int)$r['diff'] > 0 ? '+' : '') . (int)$r['diff'] . '</td>'
           . '<td class="src">' . kv_e($r['event']) . '</td><td class="src">' . kv_e($r['order_code']) . '</td></tr>';
    }
    echo '</table></div>';
    kv_pager(['page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], '?' . http_build_query(array_diff_key($g, ['p' => 1])));
    kv_admin_end();
}

function kv_admin_slcsv(): void
{
    kv_stock_schema();
    [$w, $a] = kv_admin_stocklog_filter($_GET);
    $st = kv_db()->prepare("SELECT at,product_id,name,before,after,diff,event,order_code FROM stock_log WHERE $w ORDER BY id DESC"); $st->execute($a);
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="stock_log_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['日時', '商品番号', '商品名', '変更前', '変更後', '増減', 'イベント', '受注番号']);
    while ($r = $st->fetch()) { fputcsv($out, array_values($r)); }
    fclose($out);
}

function kv_admin_stockset(): void
{
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        if (!empty($_POST['test'])) {
            $s = kv_stock_settings();
            $map = ['{product_name}' => '（テスト）サンプル商品', '{product_url}' => kv_site_url(''), '{shop}' => kv_shop('name') . '（' . kv_shop('company') . '）', '{email}' => kv_shop('email')];
            $h = 'From: ' . mb_encode_mimeheader(kv_shop('name')) . ' <' . kv_shop('email') . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $ok = kv_mail(kv_shop('email'), mb_encode_mimeheader(kv_mail_subject(strtr($s['restock_subject'], $map))), strtr($s['restock_body'], $map), $h);
            header('Location: ?a=stockset&test=' . ($ok ? 1 : 0)); exit;
        }
        kv_stock_save_settings($_POST);
        header('Location: ?a=stockset&saved=1'); exit;
    }
    $s = kv_stock_settings();
    kv_admin_page('在庫表示・通知設定', 'stockset');
    if (!empty($_GET['saved'])) { echo '<div class="note">設定しました。</div>'; }
    if (isset($_GET['test'])) { echo $_GET['test'] === '1' ? '<div class="note">担当者（' . kv_e(kv_shop('email')) . '）へテストメールを送りました。</div>' : '<div class="alert">テストメールを送れませんでした。</div>'; }
    $cb = fn($k, $lb) => '<label><input type="checkbox" name="' . $k . '" value="1"' . ((int)$s[$k] ? ' checked' : '') . '> ' . $lb . '</label>';
    $tx = fn($k, $w = 260) => '<input class="in" name="' . $k . '" value="' . kv_e($s[$k]) . '" style="width:' . $w . 'px">';
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><table class="ftbl">'
       . '<tr><th>在庫表示</th><td>' . $cb('show', '商品ページに在庫を表示する') . '</td></tr>'
       . '<tr><th>在庫数を管理しない商品</th><td>' . $tx('text_unlimited') . '<div class="src">メーカー直送のお取り寄せ品など（在庫一括設定で -99）</div></td></tr>'
       . '<tr><th>在庫のある商品</th><td>' . $cb('show_count', '在庫数を表示する') . '　ラベル ' . $tx('label', 90) . ' 単位 ' . $tx('unit', 60) . '<br>出さないときの文言 ' . $tx('text_in', 160) . '</td></tr>'
       . '<tr><th>在庫わずか</th><td>在庫が <input class="in" name="low_line" value="' . (int)$s['low_line'] . '" style="width:60px"> 個以下で ' . $tx('text_low', 160) . '</td></tr>'
       . '<tr><th>在庫切れ</th><td>' . $cb('backorder', '在庫切れでも注文を受け付ける（お取り寄せとして受け、納期をご連絡する）') . '<br>受け付けるときの文言 ' . $tx('text_out', 320)
       . '<br>受け付けないときの文言 ' . $tx('text_soldout', 160) . '<div class="src">受け付けないときは、カートの代わりに「再入荷お知らせ」の登録欄を出します。Google 向けの在庫状況も、受け付けるなら「取り寄せ（BackOrder）」、受け付けないなら「在庫切れ」にします。</div></td></tr>'
       . '<tr><th>在庫通知</th><td>' . $cb('notify', 'ショップでの注文で在庫わずか・在庫切れになったら、メールで知らせる') . '<br>送り先 ' . $tx('notify_email') . ' <span class="src">空なら ' . kv_e(kv_shop('email')) . '</span></td></tr>'
       . '<tr><th colspan="2" style="background:#e8eef3">再入荷お知らせメール設定</th></tr>'
       . '<tr><th>再入荷お知らせ機能</th><td>' . $cb('restock', '利用する（注文を受け付けない在庫切れの商品に、登録欄を出す）') . '</td></tr>'
       . '<tr><th>登録ボタン上部の注意書き</th><td>' . $tx('restock_note', 480) . '</td></tr>'
       . '<tr><th>メールの件名</th><td>' . $tx('restock_subject', 480) . '</td></tr>'
       . '<tr><th>メールの本文</th><td><textarea class="in" name="restock_body" rows="8">' . kv_e($s['restock_body']) . '</textarea><div class="src">埋め込みタグ: {product_name} 商品名 / {product_url} 商品ページ / {shop} 店名 / {email} 店のメール。件名の先頭には [' . kv_e(kv_shop('name')) . '] が付きます。</div></td></tr>'
       . '</table><p><button class="btn" type="submit">設定処理を完了する</button> <button class="btn gray" name="test" value="1" formnovalidate>担当者へテストメールを送信する</button></p></form>';
    kv_admin_end();
}

function kv_admin_restock(): void
{
    kv_stock_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $n = 0;
        if (!empty($_POST['send'])) { foreach (array_map('intval', (array)($_POST['pids'] ?? [])) as $pid) { $n += kv_restock_send($pid); } }
        if (!empty($_POST['del'])) { kv_db()->prepare('DELETE FROM restock_requests WHERE id=?')->execute([(int)$_POST['del']]); }
        header('Location: ?a=restock&sent=' . $n); exit;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $w = 'r.notified_at IS NULL' . ($q !== '' ? ' AND (r.email LIKE ? OR p.name LIKE ?)' : '');
    $args = $q !== '' ? ['%' . $q . '%', '%' . $q . '%'] : [];
    $sql = "SELECT p.id, p.name, p.stock, p.stock_unlimited, COUNT(r.id) n, MIN(r.created_at) first FROM restock_requests r JOIN products p ON p.id=r.product_id WHERE $w GROUP BY p.id ORDER BY first";
    $st = kv_db()->prepare($sql); $st->execute($args); $rows = $st->fetchAll();
    $ready = array_filter($rows, fn($r) => (int)$r['stock_unlimited'] === 1 || (int)$r['stock'] > 0);
    $wait = array_filter($rows, fn($r) => !((int)$r['stock_unlimited'] === 1 || (int)$r['stock'] > 0));
    kv_admin_page('再入荷お知らせ登録一覧', 'restock');
    if (isset($_GET['sent'])) { echo '<div class="note">再入荷お知らせを ' . (int)$_GET['sent'] . '通送りました。</div>'; }
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="restock"><input class="in" name="q" value="' . kv_e($q) . '" placeholder="商品名・メールアドレス"><button class="btn gray">探す</button></form>';
    $csrf = '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
    echo '<h2>入荷済み一覧（送れる商品）</h2><form method="post">' . $csrf . '<div class="scroll"><table class="t"><tr><th></th><th>商品</th><th>在庫</th><th>登録数</th><th>最初の登録</th></tr>';
    foreach ($ready as $r) { echo '<tr><td><input type="checkbox" name="pids[]" value="' . (int)$r['id'] . '" checked></td><td><a href="?a=product&id=' . (int)$r['id'] . '">' . kv_e(mb_strimwidth((string)$r['name'], 0, 60, '…')) . '</a></td><td>' . ((int)$r['stock_unlimited'] ? '管理しない' : (int)$r['stock']) . '</td><td>' . (int)$r['n'] . '件</td><td class="src">' . kv_e(substr((string)$r['first'], 0, 16)) . '</td></tr>'; }
    echo '</table></div>' . ($ready ? '<p><button class="btn" name="send" value="1" onclick="return confirm(\'チェックした商品の登録者に再入荷お知らせを送ります\')">✉ 再入荷お知らせを送信する</button></p>' : '<p class="src">送れる商品はありません。</p>') . '</form>';
    echo '<h2>入荷待ち一覧</h2><div class="scroll"><table class="t"><tr><th>商品</th><th>在庫</th><th>登録数</th><th>最初の登録</th></tr>';
    foreach ($wait as $r) { echo '<tr><td><a href="?a=product&id=' . (int)$r['id'] . '">' . kv_e(mb_strimwidth((string)$r['name'], 0, 60, '…')) . '</a></td><td>0</td><td>' . (int)$r['n'] . '件</td><td class="src">' . kv_e(substr((string)$r['first'], 0, 16)) . '</td></tr>'; }
    echo '</table></div><h2>登録者</h2><div class="scroll"><table class="t"><tr><th>登録日時</th><th>メールアドレス</th><th>商品</th><th>お知らせ</th><th></th></tr>';
    $st = kv_db()->prepare('SELECT r.*, p.name FROM restock_requests r LEFT JOIN products p ON p.id=r.product_id' . ($q !== '' ? ' WHERE r.email LIKE ? OR p.name LIKE ?' : '') . ' ORDER BY r.id DESC LIMIT 200');
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        echo '<tr><td class="src">' . kv_e(substr((string)$r['created_at'], 0, 16)) . '</td><td>' . kv_e($r['email']) . '</td><td>' . kv_e(mb_strimwidth((string)$r['name'], 0, 40, '…')) . '</td>'
           . '<td>' . ($r['notified_at'] ? '<span class="tag gr">送信済 ' . kv_e(substr((string)$r['notified_at'], 5, 11)) . '</span>' : '<span class="tag">待ち</span>') . '</td>'
           . '<td><form method="post" style="margin:0">' . $csrf . '<button class="btn gray" name="del" value="' . (int)$r['id'] . '" onclick="return confirm(\'この登録を消します\')">削除</button></form></td></tr>';
    }
    echo '</table></div>';
    kv_admin_end();
}
