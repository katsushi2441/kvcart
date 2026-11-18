<?php
/**
 * Kurage Vibe-Cart — 管理画面（受注管理。商品・メーカー・カテゴリ・顧客・売上は kv_admin_catalog.php）。
 *
 * おちゃのこネットの受注管理に合わせる:
 *   - 受注一覧：期間・処理状況・購入者・管理メモで絞り込み、リピーター表示
 *   - 受注明細：確認・変更・キャンセル、管理メモ、送り状番号
 *   - **「受注・入金・発送・その他」を個別にチェック**（単一ステータスにしない）
 *   - チェックとメール送信が連動（送るかどうかは毎回選べる）
 *   - 一括操作（複数選択して一括チェック／一括メール）
 *   - 代理入力（電話注文を管理側から登録）
 *   - 受注CSVダウンロード
 *
 * ログインは kv_config.php の $KV_ADMIN（password_hash の値）。
 */
declare(strict_types=1);

// 管理画面であることの印。kv_head / kv_footer がお店の見た目ではなく管理画面の見た目を出す
define('KV_ADMIN', true);

require __DIR__ . '/kv_shop.php';
require __DIR__ . '/kv_session.php';
require __DIR__ . '/kv_lib.php';
require __DIR__ . '/kv_ui.php';
require __DIR__ . '/kv_order.php';
require __DIR__ . '/kv_stripe.php';
require __DIR__ . '/kv_cart.php';
require __DIR__ . '/kv_admin_catalog.php';
require __DIR__ . '/kv_admin_ops.php';
require __DIR__ . '/kv_admin_stock.php';   // 在庫管理   // お問い合わせ受付一覧・ブラックリスト・帳票
require __DIR__ . '/kv_purchase.php';   // 仕入先への発注書（2026-11-18）
require __DIR__ . '/kv_pages.php';   // 運営マニュアルを管理画面の中で出すため

kv_session();
kv_admin_guard();
kv_ops_schema();   // ブラックリスト・お問い合わせの表と、受注の ip・bl_note 列を用意する

$act = (string)($_GET['a'] ?? 'orders');
if ($act === 'csv') { kv_admin_csv(); exit; }
if ($act === 'pcsv') { kv_admin_pcsv(); exit; }
if ($act === 'ccsv') { kv_admin_ccsv(); exit; }
if ($act === 'icsv') { kv_admin_icsv(); exit; }
if ($act === 'doc')  { kv_admin_doc(); exit; }
if ($act === 'popdf') { kv_admin_popdf(); exit; }
if ($act === 'slcsv') { kv_admin_slcsv(); exit; }

switch ($act) {
    case 'order':  kv_admin_order(); break;
    case 'new':    kv_admin_new(); break;
    case 'mail':   kv_admin_mail_templates(); break;
    case 'compose': kv_admin_compose(); break;
    case 'bcompose': kv_admin_bcompose(); break;
    case 'inquiries': kv_admin_inquiries(); break;
    case 'inquiry':   kv_admin_inquiry(); break;
    case 'blacklist': kv_admin_blacklist(); break;
    case 'stock':     kv_admin_stock(); break;
    case 'po':        kv_admin_po(); break;          // 発注書（kv_purchase.php）
    case 'suppliers': kv_admin_suppliers(); break;
    case 'stocklog':  kv_admin_stocklog(); break;
    case 'stockset':  kv_admin_stockset(); break;
    case 'restock':   kv_admin_restock(); break;
    case 'manual':   kv_page_manual(); break;   // 管理画面の見た目で出る（KV_ADMIN）
    // 商品・メーカー・カテゴリ・顧客・売上（kv_admin_catalog.php・2026-11-17）
    case 'products':   kv_admin_products(); break;
    case 'product':    kv_admin_product(); break;
    case 'price':      kv_admin_price(); break;
    case 'pimport':    kv_admin_pimport(); break;
    case 'plog':       kv_admin_plog(); break;
    case 'makers':     kv_admin_makers(); break;
    case 'categories': kv_admin_categories(); break;
    case 'customers':  kv_admin_customers(); break;
    case 'stats':      kv_admin_stats(); break;
    default:       kv_admin_orders(); break;
}

// ---------------- 認証 ----------------

function kv_admin_guard(): void
{
    $cfg = kv_cfg()['admin'];
    if (!empty($_GET['logout'])) { unset($_SESSION['kv_admin']); header('Location: ?'); exit; }
    if (!empty($_SESSION['kv_admin'])) { return; }
    $err = '';
    // 総当たり対策（2026-11-15）: 同じIPから15分に5回まちがえたら、15分は受け付けない
    $lockf = __DIR__ . '/kv_data/admin_login_fail.json';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $fails = is_file($lockf) ? (json_decode((string)file_get_contents($lockf), true) ?: []) : [];
    $now = time();
    foreach ($fails as $k => $ts) { $fails[$k] = array_values(array_filter((array)$ts, fn($t) => $t > $now - 900)); if (!$fails[$k]) unset($fails[$k]); }
    $locked = count($fails[$ip] ?? []) >= 5;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw']) && ($locked || !kv_csrf_ok())) {
        $err = $locked ? 'ログインの失敗が続いたため、しばらく受け付けません。15分ほどおいてからお試しください。'
                       : '画面の有効期限が切れました。もう一度入力してください。';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
        if ($cfg['hash'] === '') {
            $err = 'kv_config.php に管理パスワードが設定されていません。';
        } elseif (($_POST['user'] ?? '') === $cfg['user'] && password_verify((string)$_POST['pw'], $cfg['hash'])) {
            session_regenerate_id(true);
            $_SESSION['kv_admin'] = true;
            header('Location: ?');
            exit;
        } else {
            $err = 'ユーザー名かパスワードが違います。';
            $fails[$ip][] = $now;
            @file_put_contents($lockf, json_encode($fails), LOCK_EX);
            usleep(400000);   // 総当たりを少し鈍らせる
        }
    }
    kv_head('管理画面', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:420px"><h1>管理画面</h1>';
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    echo '<form method="post" class="panel">'
       . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<label class="src">ユーザー名</label>'
       . '<input name="user" style="width:100%;padding:9px;border:2px solid #cfdae4;border-radius:8px">'
       . '<label class="src" style="display:block;margin-top:10px">パスワード</label>'
       . '<input type="password" name="pw" style="width:100%;padding:9px;border:2px solid #cfdae4;border-radius:8px">'
       . '<p><button class="btn" type="submit">ログイン</button></p></form></div>';
    kv_footer();
    exit;
}

function kv_admin_nav(string $now): void
{
    // おちゃのこネットの管理画面と同じく、仕事ごとにまとめる（受注管理・商品管理・設定）
    $groups = [
        '受注管理' => ['orders' => '受注一覧と明細', 'new' => '代理入力', 'inquiries' => 'お問い合わせ受付一覧', 'customers' => '顧客', 'stats' => '売上'],
        '商品管理' => ['products' => '商品', 'price' => '価格の一括改定', 'pimport' => '商品CSV取り込み', 'makers' => 'メーカー',
                       'categories' => 'カテゴリ', 'plog' => '商品の変更履歴'],
        '在庫管理' => ['stock' => '在庫一括設定', 'stocklog' => '在庫数変更履歴', 'restock' => '再入荷お知らせ登録一覧', 'stockset' => '在庫表示・通知設定'],
        '設定' => ['suppliers' => '仕入先', 'mail' => 'メールテンプレート設定', 'blacklist' => 'ブラックリスト設定', 'manual' => '運営マニュアル'],
    ];
    echo '</div><nav class="admnav"><div class="inner">';
    foreach ($groups as $g => $items) {
        echo '<div class="grp"><span class="gl">' . kv_e($g) . '</span>';
        foreach ($items as $k => $label) { echo '<a' . ($k === $now ? ' class="now"' : '') . ' href="?a=' . $k . '">' . kv_e($label) . '</a>'; }
        echo '</div>';
    }
    echo '</div></nav><div class="wrap" style="max-width:1320px">';
}

// ---------------- 受注一覧 ----------------

function kv_admin_orders(): void
{
    // おちゃのこネットの「受注一覧と明細」に合わせる（おちゃのこネットの Web マニュアルを読んで合わせた・2026-11-17）:
    //   - 右端の「メールマネジメント」に 受注・入金・発送・その他 の4区分。上段＝状態アイコン（押すと完了／取り消し）、
    //     下段＝メールアイコン（押すとその区分のテンプレートを読み込んだメール送信画面。送ると青いチェック）
    //   - 左のチェックで複数選んで「〇完了にする」「メールを一括送信」「非表示にする」「取り出す」
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $back = '?' . preg_replace('/(^|&)(done|mailed)=[^&]*/', '', (string)$_SERVER['QUERY_STRING']);
        if (!empty($_POST['toggle'])) {
            [$id, $k] = explode(':', (string)$_POST['toggle'] . ':');
            $o = kv_order((int)$id);
            if ($o && isset(KV_FLAGS[$k])) { kv_order_flag((int)$id, $k, (int)$o['f_' . $k] !== 1, false); }
            header('Location: ' . $back); exit;
        }
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'mail' && $ids) { header('Location: ?a=bcompose&ids=' . implode(',', $ids) . '&back=' . rawurlencode($back)); exit; }
        foreach ($ids as $id) {
            if (isset(KV_FLAGS[$op])) { kv_order_flag($id, $op, true, false); }
            elseif ($op === 'hide')   { kv_db()->prepare('UPDATE orders SET hidden=1 WHERE id=?')->execute([$id]); kv_log($id, 'hide', '非表示にしました'); }
            elseif ($op === 'unhide') { kv_db()->prepare('UPDATE orders SET hidden=0 WHERE id=?')->execute([$id]); kv_log($id, 'hide', '取り出しました'); }
            elseif ($op === 'cancel' && !(int)(kv_order($id)['cancelled'] ?? 1)) {
                kv_db()->prepare('UPDATE orders SET cancelled=1 WHERE id=?')->execute([$id]); kv_log($id, 'cancel', 'キャンセルしました');
                kv_stock_apply_order($id, +1, '受注キャンセル');
            }
        }
        header('Location: ' . $back . '&done=' . count($ids)); exit;
    }
    $f = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''),
        // キャンセルした受注は、指定がなければ出さない（2026-11-18 ユーザー指示）。「すべて」は all
        'cancelled' => isset($_GET['cancelled']) ? (string)$_GET['cancelled'] : '0',
        'show_hidden' => !empty($_GET['show_hidden']),
    ];
    foreach (array_keys(KV_FLAGS) as $k) { $f['f_' . $k] = (string)($_GET['f_' . $k] ?? ''); }
    $per = (int)($_GET['per'] ?? 30); if (!in_array($per, [20, 30, 50, 100], true)) { $per = 30; }
    $r = kv_orders_ex($f + ['amin' => $_GET['amin'] ?? '', 'amax' => $_GET['amax'] ?? '', 'pay' => $_GET['pay'] ?? '',
                            'memo' => !empty($_GET['memo']), 'hidden_only' => !empty($_GET['hidden_only'])], max(1, (int)($_GET['p'] ?? 1)), $per);

    kv_head('受注一覧と明細', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:1280px">';
    kv_admin_nav('orders');
    echo '<style>.mm td.mmc{padding:3px 2px;text-align:center;background:#fffaf0;border-left:1px solid #f0e2c4}'
       . '.mmb{display:inline-flex;align-items:center;justify-content:center;width:34px;height:26px;border:1px solid #cfd8e0;border-radius:5px;background:#f4f6f8;color:#8a97a3;font-size:14px;cursor:pointer;text-decoration:none;line-height:1}'
       . '.mmb.on{background:#e8473f;border-color:#c6332c;color:#fff}.mmb.ml{background:#fff}.mmb.ml.sent{background:#2f8fd0;border-color:#1f6fa8;color:#fff}'
       . '.mm th.mmh{font-size:11px;padding:2px;background:#fff1d6;text-align:center}.qd a{margin-right:4px}</style>';
    echo '<h1>受注一覧と明細</h1><p><a class="btn gray" href="?a=new">＋ 新しく受注情報を登録する</a></p>';
    if (!empty($_GET['done'])) { echo '<div class="note">' . (int)$_GET['done'] . '件を更新しました。</div>'; }
    if (isset($_GET['mailed'])) { echo '<div class="note">メールを ' . (int)$_GET['mailed'] . '通送りました。</div>'; }
    $sel = 'padding:7px;border:2px solid #cfdae4;border-radius:8px';
    // 受注検索
    $d = fn($t) => date('Y-m-d', $t);
    $quick = ['今日' => [$d(time()), $d(time())], '昨日' => [$d(strtotime('-1 day')), $d(strtotime('-1 day'))],
              '今月' => [date('Y-m-01'), $d(time())], '前月' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))], '全て' => ['', '']];
    echo '<form class="panel" method="get"><input type="hidden" name="a" value="orders"><b>受注検索</b>'
       . '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-top:6px">'
       . '<div><label class="src">キーワード（受注番号・名前・会社・メール・電話・住所・管理用メモ・商品名・型番）</label>'
       . '<input name="q" value="' . kv_e($f['q']) . '" style="min-width:280px;' . $sel . '"></div>'
       . '<div><label class="src">受注日時 <span class="qd">';
    foreach ($quick as $lb => [$a1, $a2]) {
        echo '<a href="?' . kv_e(http_build_query(array_merge(array_diff_key($_GET, ['p' => 1, 'done' => 1, 'mailed' => 1]), ['a' => 'orders', 'from' => $a1, 'to' => $a2]))) . '">' . $lb . '</a>';
    }
    echo '</span></label><input type="date" name="from" value="' . kv_e($f['from']) . '" style="' . $sel . '"> 〜 '
       . '<input type="date" name="to" value="' . kv_e($f['to']) . '" style="' . $sel . '"></div>'
       . '<div><label class="src">合計金額</label><input name="amin" value="' . kv_e($_GET['amin'] ?? '') . '" style="width:90px;' . $sel . '" inputmode="numeric"> 〜 '
       . '<input name="amax" value="' . kv_e($_GET['amax'] ?? '') . '" style="width:90px;' . $sel . '" inputmode="numeric"></div>';
    foreach (KV_FLAGS as $k => $label) {
        echo '<div><label class="src">' . $label . '</label><select name="f_' . $k . '" style="' . $sel . '">'
           . '<option value="">----</option>'
           . '<option value="0"' . ($f['f_' . $k] === '0' ? ' selected' : '') . '>未完了</option>'
           . '<option value="1"' . ($f['f_' . $k] === '1' ? ' selected' : '') . '>完了</option></select></div>';
    }
    echo '<div><label class="src">キャンセル</label><select name="cancelled" style="' . $sel . '">'
       . '<option value="0"' . ($f['cancelled'] === '0' ? ' selected' : '') . '>キャンセル以外</option><option value="1"' . ($f['cancelled'] === '1' ? ' selected' : '') . '>キャンセルのみ</option>'
       . '<option value="all"' . (!in_array($f['cancelled'], ['0', '1'], true) ? ' selected' : '') . '>すべて</option></select></div>'
       . '<div><label class="src">支払い方法</label><select name="pay" style="' . $sel . '"><option value="">全て</option>'
       . '<option value="bank"' . (($_GET['pay'] ?? '') === 'bank' ? ' selected' : '') . '>銀行振込</option><option value="stripe"' . (($_GET['pay'] ?? '') === 'stripe' ? ' selected' : '') . '>クレジットカード</option></select></div>'
       . '<div><label class="src"><input type="checkbox" name="memo" value="1"' . (!empty($_GET['memo']) ? ' checked' : '') . '> 管理用メモに記入のある受注</label><br>'
       . '<label class="src"><input type="checkbox" name="hidden_only" value="1"' . (!empty($_GET['hidden_only']) ? ' checked' : '') . '> 非表示になっている受注</label></div>'
       . '<div><label class="src">表示件数</label><select name="per" style="' . $sel . '">';
    foreach ([20, 30, 50, 100] as $n) { echo '<option' . ($n === $per ? ' selected' : '') . '>' . $n . '</option>'; }
    echo '</select></div><div><button class="btn" type="submit">絞り込む</button> <a class="btn gray" href="?a=orders">絞り込み解除</a></div>'
       . '<div><a class="btn gray" href="?a=csv&' . kv_e(http_build_query($_GET)) . '">CSVで落とす</a></div></div></form>';

    $csrf = '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
    echo '<p class="src">' . number_format($r['total']) . '件</p>'
       . '<form method="post" id="olist">' . $csrf
       . '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0"><span class="src">チェックした受注を：</span>'
       . '<button class="btn gray" name="op" value="received" onclick="return confirm(\'受注完了にします\')">受注完了</button>'
       . '<button class="btn gray" name="op" value="shipped" onclick="return confirm(\'発送完了にします\')">発送完了</button>'
       . '<button class="btn gray" name="op" value="paid" onclick="return confirm(\'入金完了にします\')">入金完了</button>'
       . '<button class="btn gray" name="op" value="other" onclick="return confirm(\'その他完了にします\')">その他完了</button>'
       . '<button class="btn" name="op" value="mail">✉ メールを一括送信</button>'
       . (!empty($_GET['hidden_only']) ? '<button class="btn gray" name="op" value="unhide">取り出す</button>' : '<button class="btn gray" name="op" value="hide" onclick="return confirm(\'非表示にします\')">非表示にする</button>')
       . '</div><div class="scroll"><table class="t mm"><tr><th rowspan="2"><input type="checkbox" onclick="for(const c of document.querySelectorAll(\'.oid\'))c.checked=this.checked"></th>'
       . '<th rowspan="2">受注日時</th><th rowspan="2">受注番号</th><th rowspan="2">購入者</th><th rowspan="2">合計金額</th><th rowspan="2">支払</th><th rowspan="2">詳細</th>'
       . '<th colspan="4" style="text-align:center;background:#fff1d6">メールマネジメント</th></tr><tr>';
    // 入金のあとに発送するので、受注・入金・発送・その他の順（2026-11-18 ユーザー指示。おちゃのこネットの並びからは変えた）
    $order4 = ['received' => '受注', 'paid' => '入金', 'shipped' => '発送', 'other' => 'その他'];
    foreach ($order4 as $lb) { echo '<th class="mmh">' . $lb . '</th>'; }
    echo '</tr>';
    $cnt = kv_db()->prepare('SELECT COUNT(*) FROM orders WHERE lower(email)=lower(?) AND cancelled=0');
    $poSent = kv_po_sent_counts(array_map(fn($x) => (int)$x['id'], $r['items']));
    foreach ($r['items'] as $o) {
        $id = (int)$o['id'];
        $cnt->execute([(string)$o['email']]); $n = (int)$cnt->fetchColumn();
        $sent = kv_order_mail_kinds($id);
        echo '<tr' . ((int)$o['cancelled'] === 1 ? ' style="background:#fdf6f6"' : '') . '>'
           . '<td rowspan="2"><input class="oid" type="checkbox" name="ids[]" value="' . $id . '"></td>'
           . '<td rowspan="2" class="src" style="white-space:nowrap">' . kv_e(substr((string)$o['created_at'], 5, 11)) . '</td>'
           . '<td rowspan="2"><a href="?a=order&id=' . $id . '">' . kv_e($o['code']) . '</a>' . ((int)$o['cancelled'] === 1 ? '<div class="src" style="color:#b3261e">キャンセル</div>' : '')
           . (!empty($poSent[$id]) ? '<div><span class="tag" style="background:#e8f0fe;color:#1a4d8f" title="発注書を送った数">発注済</span></div>' : '') . '</td>'
           . '<td rowspan="2">' . ($n >= 2 ? '<span class="tag" style="background:#e9f7ef;color:#14632f" title="購入回数">' . $n . '回</span> ' : '')
           . (!empty($o['bl_note']) ? '<span class="tag red" title="' . kv_e($o['bl_note']) . '">ブラックリスト</span> ' : '')
           . '<a href="?a=order&id=' . $id . '">' . kv_e($o['name']) . '</a>' . ($o['company'] ? '<div class="src">' . kv_e($o['company']) . '</div>' : '') . '</td>'
           . '<td rowspan="2" style="text-align:right;white-space:nowrap">' . number_format((int)$o['total']) . '円</td>'
           . '<td rowspan="2" class="src">' . ($o['payment'] === 'stripe' ? 'カード' : '振込') . kv_auth_badge($o) . '</td>'
           . '<td rowspan="2"><a class="btn gray" href="?a=order&id=' . $id . '">明細</a></td>';
        foreach (array_keys($order4) as $k) {
            $on = (int)$o['f_' . $k] === 1;
            echo '<td class="mmc"><button type="submit" form="olist" class="mmb' . ($on ? ' on' : '') . '" name="toggle" value="' . $id . ':' . $k . '" title="'
               . $order4[$k] . ($on ? '完了（' . kv_e(substr((string)$o[$k . '_at'], 5, 11)) . '）押すと取り消し' : '：押すと完了にする') . '">' . ($on ? '✓' : '□') . '</button></td>';
        }
        echo '</tr><tr' . ((int)$o['cancelled'] === 1 ? ' style="background:#fdf6f6"' : '') . '>';
        foreach (array_keys($order4) as $k) {
            $tpl = ['received' => ($o['payment'] === 'bank' ? 'stock_ok' : 'flag_received'), 'shipped' => 'flag_shipped', 'paid' => 'flag_paid', 'other' => 'flag_other'][$k];
            echo '<td class="mmc"><a class="mmb ml' . (!empty($sent[$k]) ? ' sent' : '') . '" href="?a=compose&id=' . $id . '&tpl=' . $tpl . '&back=' . rawurlencode('?' . (string)$_SERVER['QUERY_STRING'])
               . '" title="' . $order4[$k] . 'のメールを書く' . (!empty($sent[$k]) ? '（送信済み）' : '') . '">' . (!empty($sent[$k]) ? '✓' : '✉') . '</a></td>';
        }
        echo '</tr>';
    }
    echo '</table></div></form>'
       . '<p class="src">メールマネジメントの上段は処理状況（赤＝完了。押すと切り替え）、下段はメール（押すとメールを書く画面。送ると青いチェック）。</p>';
    kv_pager($r, '?' . http_build_query(array_diff_key($_GET, ['p' => 1, 'done' => 1, 'mailed' => 1])));
    echo '</div>';
    kv_footer();
}

/** 受注の検索（おちゃのこネットの受注検索に合わせて、合計金額・支払い方法・管理用メモ・商品名・非表示も）。 */
function kv_orders_ex(array $f, int $page, int $per): array
{
    kv_order_schema();
    $w = [!empty($f['hidden_only']) ? 'hidden=1' : 'hidden=0']; $a = [];
    foreach (array_keys(KV_FLAGS) as $k) { if (($f['f_' . $k] ?? '') !== '') { $w[] = "f_$k=?"; $a[] = (int)$f['f_' . $k]; } }
    if (!empty($f['from'])) { $w[] = 'created_at>=?'; $a[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))   { $w[] = 'created_at<=?'; $a[] = $f['to'] . ' 23:59:59'; }
    if ((string)$f['amin'] !== '') { $w[] = 'total>=?'; $a[] = (int)$f['amin']; }
    if ((string)$f['amax'] !== '') { $w[] = 'total<=?'; $a[] = (int)$f['amax']; }
    if (!empty($f['pay'])) { $w[] = 'payment=?'; $a[] = (string)$f['pay']; }
    if (!empty($f['memo'])) { $w[] = "COALESCE(admin_memo,'')<>''"; }
    if (in_array((string)($f['cancelled'] ?? ''), ['0', '1'], true)) { $w[] = 'cancelled=?'; $a[] = (int)$f['cancelled']; }
    if (!empty($f['q'])) {
        $w[] = '(code LIKE ? OR name LIKE ? OR company LIKE ? OR email LIKE ? OR tel LIKE ? OR address LIKE ? OR ship_address LIKE ? OR admin_memo LIKE ?'
             . ' OR id IN (SELECT order_id FROM order_items WHERE name LIKE ? OR model_number LIKE ?))';
        for ($i = 0; $i < 10; $i++) { $a[] = '%' . $f['q'] . '%'; }
    }
    $sql = 'FROM orders WHERE ' . implode(' AND ', $w);
    $st = kv_db()->prepare('SELECT COUNT(*) ' . $sql); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = kv_db()->prepare('SELECT * ' . $sql . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ((max(1, $page) - 1) * $per)); $st->execute($a);
    return ['items' => $st->fetchAll(), 'total' => $total, 'page' => max(1, $page), 'per' => $per, 'pages' => (int)ceil($total / $per)];
}

/**
 * メールの一括送信（おちゃのこネットの「メールマネジメントの一括送信」と同じ）。
 * テンプレートを読み込み、件名・本文を直して、埋め込みタグ（{name} など）は1通ずつ各受注の内容に置き換えて送る。
 * 「〇完了にする」にチェックすると、送ったあと処理状況も完了にする。内容を確認する → 送信する の2段。
 */
function kv_admin_bcompose(): void
{
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_REQUEST['ids'] ?? ''))), fn($i) => $i > 0 && kv_order($i)));
    $back = (string)($_REQUEST['back'] ?? '?a=orders');
    if (strpos($back, '?') !== 0) { $back = '?a=orders'; }
    $all = kv_mail_templates_all();
    $tpl = (string)($_REQUEST['tpl'] ?? 'flag_other');
    if (!isset($all[$tpl])) { $tpl = 'flag_other'; }
    [$subj, $body] = kv_mail_template($tpl);
    $kind = $all[$tpl]['kind'];
    $cc = kv_shop('email'); $done = $kind; $err = '';
    $step = 'edit';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $subj = (string)$_POST['subject']; $body = str_replace("\r\n", "\n", (string)$_POST['body']);
        $cc = trim((string)$_POST['cc']); $kind = (string)($_POST['kind'] ?? $kind); $done = (string)($_POST['done'] ?? '');
        if (trim($subj) === '' || trim($body) === '') { $err = '件名と本文を入れてください。'; }
        elseif (strpos($body, '〇') !== false && empty($_POST['force'])) { $err = '本文に「〇」が残っています（納期などの書き換え忘れ）。直すか「〇のまま送る」にチェックしてください。'; }
        elseif (($_POST['step'] ?? '') === 'send') {
            $n = 0;
            foreach ($ids as $id) {
                $o = kv_order($id);
                if (!$o || !$o['email']) { continue; }
                [$s1, $b1] = kv_mail_render_text($id, $subj, $body);
                if (kv_mail_send_text($id, (string)$o['email'], $cc, $s1, $b1, $kind)) { $n++; }
                if (isset(KV_FLAGS[$done]) && (int)$o['f_' . $done] !== 1) { kv_order_flag($id, $done, true, false); }
            }
            header('Location: ' . $back . '&mailed=' . $n); exit;
        } else { $step = 'confirm'; }
    }
    kv_head('メール一括送信', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:960px">';
    kv_admin_nav('orders');
    echo '<nav class="crumb"><a href="' . kv_e($back) . '">受注一覧と明細</a> › メール一括送信</nav><h1>メール一括送信（' . count($ids) . '件）</h1>';
    if (!$ids) { echo '<p>受注が選ばれていません。<a href="' . kv_e($back) . '">戻る</a></p></div>'; kv_footer(); return; }
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    $to = array_map(fn($i) => kv_order($i), $ids);
    echo '<p class="src">宛先：' . kv_e(implode('、', array_map(fn($o) => $o['code'] . ' ' . $o['name'], $to))) . '</p>';
    $hidden = '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="ids" value="' . kv_e(implode(',', $ids)) . '">'
            . '<input type="hidden" name="back" value="' . kv_e($back) . '"><input type="hidden" name="tpl" value="' . kv_e($tpl) . '">';
    if ($step === 'confirm') {
        [$ps, $pb] = kv_mail_render_text($ids[0], $subj, $body);
        echo '<div class="panel"><b>内容の確認</b>（1件目 ' . kv_e($to[0]['code']) . ' に差し込んだ例。ほかの受注にもそれぞれの内容が入ります）'
           . '<p>件名：<b>' . kv_e(kv_mail_subject($ps)) . '</b></p><p class="src">Cc：' . kv_e($cc ?: 'なし') . '　送ったあと：' . kv_e(KV_FLAGS[$done] ?? '') . (isset(KV_FLAGS[$done]) ? 'を完了にする' : '処理状況は変えない') . '</p>'
           . '<pre style="white-space:pre-wrap;font-size:13px;background:#f7f9fb;border:1px solid #e3e9ee;border-radius:8px;padding:10px">' . kv_e($pb) . '</pre>'
           . '<form method="post">' . $hidden;
        foreach (['subject' => $subj, 'body' => $body, 'cc' => $cc, 'kind' => $kind, 'done' => $done, 'force' => $_POST['force'] ?? ''] as $k => $v) { echo '<input type="hidden" name="' . $k . '" value="' . kv_e($v) . '">'; }
        echo '<button class="btn" name="step" value="send">' . count($ids) . '件に送信する</button> <button class="btn gray" name="step" value="edit">戻って直す</button></form></div></div>';
        kv_footer(); return;
    }
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="bcompose"><input type="hidden" name="ids" value="' . kv_e(implode(',', $ids)) . '"><input type="hidden" name="back" value="' . kv_e($back) . '">'
       . '<span class="src">メールテンプレート：</span><select name="tpl" style="padding:7px;border:2px solid #cfdae4;border-radius:8px">';
    foreach ($all as $k => $t) { echo '<option value="' . $k . '"' . ($k === $tpl ? ' selected' : '') . '>' . kv_e(KV_FLAGS[$t['kind']] ?? '') . '：' . kv_e($t['label']) . '</option>'; }
    $in = 'width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px;box-sizing:border-box';
    echo '</select> <button class="btn gray" type="submit">読み込む</button></form>'
       . '<form method="post" class="panel">' . $hidden . '<input type="hidden" name="kind" value="' . kv_e($kind) . '">'
       . '<label class="src">Cc（店の控え。要らなければ空に）</label><input name="cc" value="' . kv_e($cc) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">件名（先頭に [' . kv_e(kv_shop('name')) . '] が自動で付きます）</label><input name="subject" value="' . kv_e($subj) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">本文（{name} {code} {items} {total} などの埋め込みタグは、受注ごとの内容に置き換わります）</label>'
       . '<textarea name="body" rows="20" style="' . $in . ';font-family:ui-monospace,monospace;font-size:13px">' . kv_e($body) . '</textarea>'
       . '<p>状態（メールマネジメント）：<select name="done" style="padding:6px;border:2px solid #cfdae4;border-radius:8px"><option value="">変えない</option>';
    foreach (KV_FLAGS as $k => $lb) { echo '<option value="' . $k . '"' . ($k === $done ? ' selected' : '') . '>' . $lb . '完了にする</option>'; }
    echo '</select>' . ($err && strpos($err, '〇') !== false ? ' <label class="src"><input type="checkbox" name="force" value="1"> 〇のまま送る</label>' : '') . '</p>'
       . '<p><button class="btn" type="submit" name="step" value="confirm">内容を確認する</button> <a class="btn gray" href="' . kv_e($back) . '">戻る</a></p></form></div>';
    kv_footer();
}

function kv_admin_order(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $o = kv_order($id);
    if (!$o) { http_response_code(404); echo '受注が見つかりません'; return; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        if (isset($_POST['save'])) {
            $st = kv_db()->prepare('UPDATE orders SET name=?,company=?,email=?,tel=?,zip=?,address=?,'
                . 'note=?,admin_memo=?,tracking=?,shipping=?,total=? WHERE id=?');
            $ship = (int)($_POST['shipping'] ?? 0);
            $st->execute([$_POST['name'], $_POST['company'], $_POST['email'], $_POST['tel'],
                $_POST['zip'], $_POST['address'], $_POST['note'], $_POST['admin_memo'],
                $_POST['tracking'], $ship, (int)$o['subtotal'] - (int)($o['discount'] ?? 0) + (int)$o['tax'] + $ship, $id]);
            kv_log($id, 'edit', '受注内容を変更しました');
        } elseif (isset($_POST['discount_save'])) {
            try { kv_order_set_discount($id, (int)preg_replace('/[^0-9]/', '', (string)($_POST['discount'] ?? '0'))); }
            catch (RuntimeException $e) { kv_log($id, 'edit', '値引きを設定できませんでした: ' . $e->getMessage()); }
        } elseif (isset($_POST['flag'])) {
            // メールは「編集して送る／そのまま送る／送らない」から選ぶ（おちゃのこネットと同じく、送る前に直せる・2026-11-17）
            $mode = (string)($_POST['send_mail'] ?? '');
            $on = (string)$_POST['on'] === '1';
            kv_order_flag($id, (string)$_POST['flag'], $on, $on && $mode === '1');
            if ($on && $mode === 'edit') { header('Location: ?a=compose&id=' . $id . '&tpl=flag_' . rawurlencode((string)$_POST['flag'])); exit; }
        } elseif (isset($_POST['cancel'])) {
            kv_db()->prepare('UPDATE orders SET cancelled=? WHERE id=?')
                   ->execute([(string)$_POST['cancel'] === '1' ? 1 : 0, $id]);
            // 在庫を戻す／取り消しなら減らし直す（kv_stock.php）
            if ((string)$_POST['cancel'] === '1' && !(int)$o['cancelled']) { kv_stock_apply_order($id, +1, '受注キャンセル'); }
            // カードの仮売上が残っていれば取り消す（手数料はかからない・2026-11-19）
            if ((string)$_POST['cancel'] === '1' && ($o['auth_status'] ?? '') === 'authorized') {
                try { kv_stripe_cancel_auth($id, '受注キャンセル'); }
                catch (RuntimeException $e) { kv_log($id, 'stripe', '仮売上を取り消せませんでした: ' . $e->getMessage()); }
            }
            if ((string)$_POST['cancel'] !== '1' && (int)$o['cancelled']) { kv_stock_apply_order($id, -1, '受注キャンセルの取り消し'); }
            kv_log($id, 'cancel', (string)$_POST['cancel'] === '1' ? 'キャンセルしました' : 'キャンセルを取り消しました');
        } elseif (isset($_POST['sendmail'])) {
            kv_mail_send($id, (string)$_POST['sendmail']);
        } elseif (isset($_POST['auth_capture'])) {
            try { kv_stripe_capture($id); }
            catch (RuntimeException $e) { kv_log($id, 'stripe', '売上を確定できませんでした: ' . $e->getMessage()); }
        } elseif (isset($_POST['auth_cancel'])) {
            try { kv_stripe_cancel_auth($id, '受注画面から'); }
            catch (RuntimeException $e) { kv_log($id, 'stripe', '仮売上を取り消せませんでした: ' . $e->getMessage()); }
        } elseif (isset($_POST['stripe_invoice'])) {
            try { kv_stripe_send_invoice($id); }
            catch (RuntimeException $e) { kv_log($id, 'stripe', '請求書を送れませんでした: ' . $e->getMessage()); }
        }
        header('Location: ?a=order&id=' . $id);
        exit;
    }

    $items = kv_order_items($id);
    kv_head('受注 ' . $o['code'], '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:1100px">';
    kv_admin_nav('orders');
    echo '<nav class="crumb"><a href="?a=orders">受注一覧</a> › ' . kv_e($o['code']) . '</nav>'
       . '<h1>受注 ' . kv_e($o['code']) . '</h1>';
    if (isset($_GET['mailed'])) {
        echo $_GET['mailed'] === '1' ? '<div class="note">メールを送りました。下の「送ったメール」に全文が残っています。</div>'
                                     : '<div class="alert">メールを送れませんでした。メールの設定（kv_config.php の KV_SMTP）を確かめてください。</div>';
    }
    if (!empty($o['bl_note'])) { echo '<div class="alert"><b>ブラックリストに一致：</b>' . kv_e($o['bl_note']) . '</div>'; }
    echo '<p class="src">IP ' . kv_e($o['ip'] ?? '') . '　<a href="?a=blacklist&email=' . rawurlencode((string)$o['email']) . '&name=' . rawurlencode((string)$o['name'])
       . '&tel=' . rawurlencode((string)$o['tel']) . '&ip=' . rawurlencode((string)($o['ip'] ?? '')) . '">この購入者をブラックリストに登録</a></p>';
    if (isset($_GET['po_sent'])) { echo (int)$_GET['po_sent'] ? '<div class="note">発注書を送りました。</div>' : '<div class="alert">発注書を送れませんでした。宛先とメールの設定を確かめてください。</div>'; }
    echo kv_admin_doc_panel($o);
    echo kv_admin_po_panel($o);
    if ((int)$o['cancelled'] === 1) { echo '<div class="alert">この受注はキャンセルされています。</div>'; }

    // 4系統チェック
    echo '<div class="panel"><b>処理状況</b>'
       . '<p class="src">おちゃのこネットと同じく、受注・入金・発送・その他を別々に管理します。</p>'
       . '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    foreach (KV_FLAGS as $k => $label) {
        $on = (int)$o['f_' . $k] === 1;
        $at = $o[$k . '_at'] ?? '';
        echo '<form method="post" style="background:' . ($on ? '#eaf7f5' : '#f5f8f9')
           . ';border:1px solid ' . ($on ? '#a8ded8' : '#e3e9ec') . ';border-radius:10px;padding:10px 12px">'
           . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
           . '<input type="hidden" name="flag" value="' . $k . '">'
           . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">'
           . '<div><b>' . $label . '</b> ' . ($on ? '✓ 完了' : '未') . '</div>'
           . ($at ? '<div class="src">' . kv_e(substr((string)$at, 0, 16)) . '</div>' : '')
           . (!$on ? '<select name="send_mail" class="src" style="padding:4px;border:1px solid #cfdae4;border-radius:6px;margin:4px 0">'
                   . '<option value="edit">メールを編集して送る</option><option value="1">メールをそのまま送る</option><option value="">メールは送らない</option></select><br>' : '')
           . '<button class="btn' . ($on ? ' gray' : '') . '" type="submit">'
           . ($on ? '取り消す' : '完了にする') . '</button></form>';
    }
    echo '</div></div>';

    // カードの仮売上（2026-11-19）。在庫を確かめてから「売上を確定」、手配できなければ「仮売上を取り消す」
    if (($o['auth_status'] ?? '') !== '') {
        $stName = ['authorized' => '仮売上中', 'captured' => '売上確定', 'canceled' => '仮売上を取消', 'expired' => '仮売上の期限切れ'][$o['auth_status']] ?? $o['auth_status'];
        echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
           . '<b>カードの仮売上（Stripe）</b> ' . kv_auth_badge($o)
           . '<p>' . kv_e($stName) . '　仮売上 ' . number_format((int)$o['auth_amount']) . '円（' . kv_e(substr((string)$o['auth_at'], 0, 16)) . '）'
           . (!empty($o['auth_done_at']) ? '　→ ' . kv_e(substr((string)$o['auth_done_at'], 0, 16)) : '') . '</p>';
        if ($o['auth_status'] === 'authorized') {
            $amt = (int)$o['total'];
            echo '<p class="src">在庫と納期を確かめたら「売上を確定」を押してください。今の受注の合計（' . number_format($amt) . '円）で確定します。'
               . '値引きなどで減らした場合は減らした額で確定し、残りは取り消されます（仮売上の額を超える増額はできません）。'
               . 'カードの仮売上はおおむね7日で切れるので、それまでに確定してください。手配できない場合は「仮売上を取り消す」（手数料はかかりません）。</p>'
               . '<button class="btn" type="submit" name="auth_capture" value="1" onclick="return confirm(\'' . number_format($amt) . '円で売上を確定します\')"'
               . ($amt > (int)$o['auth_amount'] ? ' disabled title="仮売上の額を超えています"' : '') . '>売上を確定（' . number_format($amt) . '円）</button> '
               . '<button class="btn gray" type="submit" name="auth_cancel" value="1" onclick="return confirm(\'仮売上を取り消します。お客様への請求は無くなります\')">仮売上を取り消す</button>';
        }
        echo '</form>';
    }

    // カード払いの請求書（Stripe）。注文後に在庫・送料を確かめてから、確定した金額で送る
    if ($o['payment'] === 'stripe' && kv_stripe_ready() && ($o['auth_status'] ?? '') === '') {
        $inv = kv_stripe_invoice_of($o);
        echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
           . '<b>カード払いの請求書（Stripe）</b>';
        if ($inv) {
            $st = ['draft' => '下書き', 'open' => '未払い', 'paid' => '支払い済み', 'void' => '取り消し', 'uncollectible' => '回収不能'];
            echo '<p>' . kv_e($inv['id']) . '　' . number_format((int)$inv['amount_due']) . '円　<b>'
               . kv_e($st[$inv['status']] ?? $inv['status']) . '</b>'
               . (!empty($inv['hosted_invoice_url']) ? '　<a href="' . kv_e($inv['hosted_invoice_url']) . '" target="_blank" rel="noopener">お支払いページ</a>' : '')
               . '</p>';
        } else {
            echo '<p class="src">まだ請求書を送っていません。在庫・納期・送料を確かめ、送料を保存してから送ってください。</p>';
        }
        if ((int)$o['f_paid'] !== 1 && (!$inv || $inv['status'] !== 'paid')) {
            echo '<p class="src">合計 ' . number_format((int)$o['total']) . '円（税込）で請求書を作り、お支払いリンクをメールで送ります。'
               . '金額を変えたあとに押すと、前の請求書を取り消して作り直します。</p>'
               . '<button class="btn" type="submit" name="stripe_invoice" value="1">'
               . ($inv ? '請求書を送り直す' : 'カード払いの請求書を送る') . '</button>';
        }
        echo '</form>';
    }

    // 明細
    echo '<div class="panel"><b>ご注文内容</b><div class="scroll"><table class="t">'
       . '<tr><th>商品</th><th>型番</th><th>単価</th><th>数量</th><th>小計</th></tr>';
    foreach ($items as $it) {
        echo '<tr><td>' . ($it['product_id'] ? '<a href="' . kv_url('product/' . (int)$it['product_id'])
             . '" target="_blank" rel="noopener">' . kv_e($it['name']) . '</a>' : kv_e($it['name'])) . '</td>'
           . '<td class="src">' . kv_e($it['model_number']) . '</td>'
           . '<td>' . number_format((int)$it['unit']) . '円</td>'
           . '<td>' . (int)$it['qty'] . '</td>'
           . '<td>' . number_format((int)$it['amount']) . '円</td></tr>';
    }
    $disc = (int)($o['discount'] ?? 0);
    echo '<tr><th colspan="4">小計（税抜）</th><td>' . number_format((int)$o['subtotal']) . '円</td></tr>'
       . ($disc > 0 ? '<tr><th colspan="4">値引き（税抜）</th><td>-' . number_format($disc) . '円</td></tr>' : '')
       . '<tr><th colspan="4">消費税</th><td>' . number_format((int)$o['tax']) . '円</td></tr>'
       . '<tr><th colspan="4">送料</th><td>' . number_format((int)$o['shipping']) . '円</td></tr>'
       . '<tr><th colspan="4">合計（税込）</th><td><b>' . number_format((int)$o['total']) . '円</b></td></tr>'
       . '</table></div>'
       // 値引き（見積書どおりの金額にする・2026-11-18）。税別で入れると消費税と合計を計算し直す
       . '<form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:10px">'
       . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<span class="src">値引き（税抜）</span><input name="discount" inputmode="numeric" value="' . $disc . '" style="padding:6px;border:2px solid #cfdae4;border-radius:8px;width:140px">'
       . '<span class="src">円</span><button class="btn gray" type="submit" name="discount_save" value="1">値引きを反映</button>'
       . '<span class="src">消費税と合計を計算し直します。見積書の「値引き」と同じ税抜の金額を入れてください。</span></form></div>';

    // お客様情報の編集
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<b>お客様情報・管理情報</b><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:0 14px">';
    foreach ([['name','お名前'],['company','会社名'],['email','メール'],['tel','電話'],
              ['zip','郵便番号'],['address','住所'],['tracking','送り状番号']] as [$k,$label]) {
        echo '<div><label class="src">' . $label . '</label>'
           . '<input name="' . $k . '" value="' . kv_e($o[$k]) . '" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px"></div>';
    }
    echo '<div><label class="src">送料（税込・円）</label><input name="shipping" type="number" value="'
       . (int)$o['shipping'] . '" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px"></div>';
    echo '</div><label class="src" style="display:block;margin-top:10px">お客様のご要望</label>'
       . '<textarea name="note" rows="2" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px">' . kv_e($o['note']) . '</textarea>'
       . '<label class="src" style="display:block;margin-top:10px">管理メモ（お客様には見えません・検索できます）</label>'
       . '<textarea name="admin_memo" rows="3" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px">' . kv_e($o['admin_memo']) . '</textarea>'
       . '<p><button class="btn" type="submit" name="save" value="1">保存する</button> '
       . '<button class="btn gray" type="submit" name="cancel" value="' . ((int)$o['cancelled'] === 1 ? '0' : '1') . '">'
       . ((int)$o['cancelled'] === 1 ? 'キャンセルを取り消す' : 'この受注をキャンセルする') . '</button></p></form>';

    // メール送信
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<b>メールを送る</b><p class="src">宛先 ' . kv_e($o['email']) . '。テンプレートを選び「編集して送る」で、件名・本文を直してから送れます。</p>'
       . '<select name="tpl" style="padding:8px;border:2px solid #cfdae4;border-radius:8px">';
    foreach (kv_mail_labels() as $k => $label) { echo '<option value="' . $k . '"' . ($k === 'stock_ok' ? ' selected' : '') . '>' . kv_e($label) . '</option>'; }
    echo '</select> <button class="btn" type="submit" formmethod="get" formaction="?" name="a" value="compose">編集して送る</button>'
       . '<input type="hidden" name="id" value="' . (int)$id . '"></form>';

    // 送ったメール（全文）
    $mails = kv_order_mails($id);
    if ($mails) {
        echo '<div class="panel"><b>送ったメール</b>';
        foreach ($mails as $m) {
            echo '<details style="margin-top:8px"><summary>' . kv_e(substr((string)$m['at'], 0, 16)) . '　' . kv_e($m['subject'])
               . ((int)$m['ok'] ? '' : ' <b style="color:#b42318">（送信できず）</b>') . '</summary>'
               . '<p class="src">宛先 ' . kv_e($m['to_addr']) . ($m['cc'] ? '　Cc ' . kv_e($m['cc']) : '') . '</p>'
               . '<pre style="white-space:pre-wrap;font-size:13px;background:#f7f9fb;border:1px solid #e3e9ee;border-radius:8px;padding:10px">' . kv_e($m['body']) . '</pre></details>';
        }
        echo '</div>';
    }

    // 履歴
    echo '<div class="panel"><b>履歴</b><div class="scroll"><table class="t">'
       . '<tr><th>日時</th><th>種類</th><th>内容</th></tr>';
    foreach (kv_order_logs($id) as $l) {
        echo '<tr><td class="src">' . kv_e(substr((string)$l['at'], 0, 16)) . '</td>'
           . '<td class="src">' . kv_e($l['kind']) . '</td><td>' . kv_e($l['detail']) . '</td></tr>';
    }
    echo '</table></div></div></div>';
    kv_footer();
}

// ---------------- 代理入力 ----------------

function kv_admin_new(): void
{
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $lines = [];
        $sub = $tax = 0;
        foreach ((array)($_POST['pid'] ?? []) as $i => $pid) {
            $pid = (int)$pid;
            $qty = max(0, (int)($_POST['qty'][$i] ?? 0));
            if ($pid <= 0 || $qty <= 0) { continue; }
            $p = kv_product($pid);
            if (!$p) { continue; }
            $unit = (int)$p['price'];
            $amt = $unit * $qty;
            $t = (int)floor($amt * (((int)$p['tax_reduce'] === 1) ? 0.08 : 0.10));
            $lines[] = ['product' => $p, 'qty' => $qty, 'unit' => $unit, 'amount' => $amt, 'tax' => $t];
            $sub += $amt; $tax += $t;
        }
        if (!$lines) {
            $msg = '商品を1つ以上入れてください（商品IDと数量）。';
        } else {
            $oid = kv_order_create(['lines' => $lines, 'subtotal' => $sub, 'tax' => $tax,
                                    'total' => $sub + $tax, 'count' => count($lines)], $_POST);
            kv_log($oid, 'proxy', '管理画面から代理入力しました');
            header('Location: ?a=order&id=' . $oid);
            exit;
        }
    }
    kv_head('代理入力', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:900px">';
    kv_admin_nav('new');
    echo '<h1>代理入力</h1><p class="src">電話・メールで受けた注文を、管理側から登録します。</p>';
    if ($msg) { echo '<div class="alert">' . kv_e($msg) . '</div>'; }
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<b>商品</b><p class="src">商品IDは商品ページのURL（/product/<b>11366</b>）の数字です。</p>';
    for ($i = 0; $i < 5; $i++) {
        echo '<div style="display:flex;gap:8px;margin-bottom:6px">'
           . '<input name="pid[]" placeholder="商品ID" style="width:140px;padding:8px;border:2px solid #cfdae4;border-radius:8px">'
           . '<input name="qty[]" type="number" min="0" placeholder="数量" style="width:100px;padding:8px;border:2px solid #cfdae4;border-radius:8px">'
           . '</div>';
    }
    echo '<b style="display:block;margin-top:14px">お客様</b>'
       . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:0 14px">';
    foreach (kv_checkout_fields() as $k => $m) {
        if (($m['type'] ?? '') === 'textarea') { continue; }
        echo '<div><label class="src">' . kv_e($m['label']) . '</label>'
           . '<input name="' . $k . '" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px"></div>';
    }
    echo '</div><input type="hidden" name="payment" value="bank">'
       . '<p><button class="btn" type="submit">この内容で受注を登録</button></p></form></div>';
    kv_footer();
}

// ---------------- メールテンプレート ----------------

/**
 * メールの作成・編集・送信（おちゃのこネットの「メール送信」と同じく、送る前に件名・本文を直せる・2026-11-17）。
 * テンプレートに受注の内容を差し込んだ状態から始め、宛先・Cc・件名・本文を直して送る。送った全文は受注明細に残る。
 */
function kv_admin_compose(): void
{
    // おちゃのこネットの「メールマネジメント」のメール送信画面と同じ流れ:
    // テンプレートを選んで「読み込む」→ 件名・本文を直す →「内容を確認する」→「送信する」。送るとその区分のメールアイコンに青いチェック。
    $id = (int)($_REQUEST['id'] ?? 0);
    $o = kv_order($id);
    if (!$o) { http_response_code(404); echo '受注が見つかりません'; return; }
    $all = kv_mail_templates_all();
    $tpl = (string)($_REQUEST['tpl'] ?? 'flag_other');
    if (!isset($all[$tpl])) { $tpl = 'flag_other'; }
    $back = (string)($_REQUEST['back'] ?? '');
    $back = strpos($back, '?') === 0 ? $back : '?a=order&id=' . $id;
    $kind = $all[$tpl]['kind'];
    $err = ''; $step = 'edit';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $to = trim((string)($_POST['to'] ?? '')); $cc = trim((string)($_POST['cc'] ?? ''));
        $subj = trim((string)($_POST['subject'] ?? '')); $body = str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''));
        $done = (string)($_POST['done'] ?? ''); $kind = (string)($_POST['kind'] ?? $kind);
        $valid = fn(string $s) => $s === '' || array_reduce(array_map('trim', explode(',', $s)), fn($c, $a) => $c && filter_var($a, FILTER_VALIDATE_EMAIL), true);
        if ($to === '' || !$valid($to) || !$valid($cc)) { $err = '宛先・Cc のメールアドレスを確かめてください（複数はカンマ区切り）。'; }
        elseif ($subj === '' || trim($body) === '') { $err = '件名と本文を入れてください。'; }
        elseif (strpos($body, '〇') !== false && empty($_POST['force'])) { $err = '本文に「〇」が残っています（納期などの書き換え忘れ）。直すか、このまま送るなら「〇のまま送る」にチェックしてください。'; }
        elseif (($_POST['step'] ?? '') === 'send') {
            $att = [];
            foreach (array_intersect((array)($_POST['doc'] ?? []), array_keys(KV_DOC_TYPES)) as $t) {
                $att[kv_doc_filename($id, $t)] = kv_doc_pdf($id, $t, ['atena' => $_POST['r_atena'] ?? '', 'tadashi' => $_POST['r_tadashi'] ?? '']);
            }
            $ok = kv_mail_send_text($id, $to, $cc, $subj, $body, $kind, $att);
            if ($ok && isset(KV_FLAGS[$done]) && (int)$o['f_' . $done] !== 1) { kv_order_flag($id, $done, true, false); }
            header('Location: ' . $back . (strpos($back, '?') !== false ? '&' : '?') . 'mailed=' . ($ok ? '1' : '0'));
            exit;
        } elseif (($_POST['step'] ?? '') === 'confirm') { $step = 'confirm'; }
    } else {
        [$subj, $body] = kv_mail_render($id, $tpl);
        $to = (string)$o['email']; $cc = kv_shop('email');
        $done = (int)($o['f_' . $kind] ?? 1) === 1 ? '' : $kind;   // その区分がまだ未完了なら、送ったら完了にする
    }
    kv_head('メール送信', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:900px">';
    kv_admin_nav('orders');
    echo '<nav class="crumb"><a href="?a=orders">受注一覧と明細</a> › <a href="?a=order&id=' . $id . '">' . kv_e($o['code']) . '</a> › メール送信</nav>'
       . '<h1>メール送信（' . kv_e(KV_FLAGS[$kind] ?? 'その他') . '）</h1>';
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    $hidden = '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="tpl" value="' . kv_e($tpl) . '"><input type="hidden" name="back" value="' . kv_e($back) . '"><input type="hidden" name="kind" value="' . kv_e($kind) . '">';
    if ($step === 'confirm') {
        echo '<div class="panel"><b>内容の確認</b><table class="ftbl" style="width:100%;margin-top:6px">'
           . '<tr><th>宛先</th><td>' . kv_e($o['name']) . '（' . kv_e($to) . '）</td></tr><tr><th>Cc</th><td>' . kv_e($cc ?: 'なし') . '</td></tr>'
           . '<tr><th>件名</th><td><b>' . kv_e(kv_mail_subject($subj)) . '</b></td></tr>'
           . '<tr><th>送ったあと</th><td>' . (isset(KV_FLAGS[$done]) ? kv_e(KV_FLAGS[$done]) . 'を完了にする' : '処理状況は変えない') . '</td></tr></table>'
           . '<pre style="white-space:pre-wrap;font-size:13px;background:#f7f9fb;border:1px solid #e3e9ee;border-radius:8px;padding:10px">' . kv_e($body) . '</pre>'
           . '<form method="post" action="?a=compose">' . $hidden;
        foreach (['to' => $to, 'cc' => $cc, 'subject' => $subj, 'body' => $body, 'done' => $done, 'force' => $_POST['force'] ?? '',
                  'r_atena' => $_POST['r_atena'] ?? '', 'r_tadashi' => $_POST['r_tadashi'] ?? ''] as $k => $v) { echo '<input type="hidden" name="' . $k . '" value="' . kv_e($v) . '">'; }
        foreach (array_intersect((array)($_POST['doc'] ?? []), array_keys(KV_DOC_TYPES)) as $t) { echo '<input type="hidden" name="doc[]" value="' . $t . '">'; }
        $docs = array_map(fn($t) => KV_DOC_TYPES[$t], array_intersect((array)($_POST['doc'] ?? []), array_keys(KV_DOC_TYPES)));
        if ($docs) { echo '<p class="src">添付：' . kv_e(implode('・', $docs)) . '（PDF）</p>'; }
        echo '<button class="btn" name="step" value="send">送信する</button> <button class="btn gray" name="step" value="edit">戻って直す</button></form></div></div>';
        kv_footer(); return;
    }
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="compose"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . kv_e($back) . '">'
       . '<span class="src">メールテンプレート：</span><select name="tpl" style="padding:7px;border:2px solid #cfdae4;border-radius:8px">';
    foreach ($all as $k => $t) { echo '<option value="' . $k . '"' . ($k === $tpl ? ' selected' : '') . '>' . kv_e(KV_FLAGS[$t['kind']] ?? '') . '：' . kv_e($t['label']) . '</option>'; }
    echo '</select> <button class="btn gray" type="submit">読み込む</button></form>';
    $in = 'width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px;box-sizing:border-box';
    echo '<form method="post" action="?a=compose" class="panel">' . $hidden
       . '<label class="src">宛先</label><input name="to" value="' . kv_e($to) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">Cc（店の控え。要らなければ空に）</label><input name="cc" value="' . kv_e($cc) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">件名（先頭に [' . kv_e(kv_shop('name')) . '] が自動で付きます）</label><input name="subject" value="' . kv_e($subj) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">本文</label><textarea name="body" rows="24" style="' . $in . ';font-family:ui-monospace,monospace;font-size:13px">' . kv_e($body) . '</textarea>'
       . '<p>添付（PDF）：' . implode(' ', array_map(fn($t) => '<label><input type="checkbox" name="doc[]" value="' . $t . '"' . (in_array($t, (array)($_POST['doc'] ?? []), true) ? ' checked' : '') . '> ' . KV_DOC_TYPES[$t] . '</label>', array_keys(KV_DOC_TYPES)))
       . '　<span class="src">領収書の宛名</span> <input name="r_atena" value="' . kv_e($_POST['r_atena'] ?? ($o['company'] ?: $o['name'])) . '" style="padding:5px;border:2px solid #cfdae4;border-radius:8px;width:180px">'
       . ' <span class="src">但し</span> <input name="r_tadashi" value="' . kv_e($_POST['r_tadashi'] ?? '商品代として') . '" style="padding:5px;border:2px solid #cfdae4;border-radius:8px;width:140px"></p>'
       . '<p>状態（メールマネジメント）：<select name="done" style="padding:6px;border:2px solid #cfdae4;border-radius:8px"><option value="">変えない</option>';
    foreach (KV_FLAGS as $k => $lb) { echo '<option value="' . $k . '"' . ($k === $done ? ' selected' : '') . '>' . $lb . '完了にする</option>'; }
    echo '</select>' . ($err && strpos($err, '〇') !== false ? ' <label class="src"><input type="checkbox" name="force" value="1"> 〇のまま送る</label>' : '') . '</p>'
       . '<p><button class="btn" type="submit" name="step" value="confirm">内容を確認する</button> '
       . '<a class="btn gray" href="' . kv_e($back) . '">送らずに戻る</a></p></form></div>';
    kv_footer();
}

function kv_admin_mail_templates(): void
{
    // おちゃのこネットの「メールテンプレート設定」と同じく、テンプレートごとに名前と区分（受注・入金・発送・その他）を持ち、自分で増やせる。
    kv_order_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        if (!empty($_POST['delete']) && strpos((string)$_POST['delete'], 'custom_') === 0) {
            kv_db()->prepare('DELETE FROM mail_templates WHERE key=?')->execute([(string)$_POST['delete']]);
            header('Location: ?a=mail&saved=1'); exit;
        }
        if (!empty($_POST['new_label'])) {
            $key = 'custom_' . substr(bin2hex(random_bytes(4)), 0, 8);
            kv_db()->prepare('INSERT INTO mail_templates (key,subject,body,label,kind) VALUES (?,?,?,?,?)')
                   ->execute([$key, (string)$_POST['new_subject'], str_replace("\r\n", "\n", (string)$_POST['new_body']), trim((string)$_POST['new_label']),
                              isset(KV_FLAGS[$_POST['new_kind'] ?? '']) ? $_POST['new_kind'] : 'other']);
            header('Location: ?a=mail&saved=1#' . $key); exit;
        }
        $st = kv_db()->prepare('INSERT OR REPLACE INTO mail_templates (key,subject,body,label,kind) VALUES (?,?,?,?,?)');
        foreach ((array)($_POST['subject'] ?? []) as $k => $s) {
            $st->execute([$k, $s, str_replace("\r\n", "\n", (string)($_POST['body'][$k] ?? '')), trim((string)($_POST['label'][$k] ?? '')),
                          isset(KV_FLAGS[$_POST['kind'][$k] ?? '']) ? $_POST['kind'][$k] : 'other']);
        }
        header('Location: ?a=mail&saved=1');
        exit;
    }
    kv_head('メールテンプレート設定', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:900px">';
    kv_admin_nav('mail');
    echo '<h1>メールテンプレート設定</h1>';
    if (!empty($_GET['saved'])) { echo '<div class="note">保存しました。</div>'; }
    echo '<p class="src">埋め込みタグ: {code} 受注番号 / {name} お名前 / {total} 合計 / {items} 明細 / {bank} 注文時の振込の案内 / {bank_account} 振込先の口座 / {next} 今後の流れ / {tracking} 送り状番号 / {pay_url} カードのお支払いページ / {shop} 店名 / {email} 店のメール</p>'
       . '<p class="src">区分は、受注一覧のメールマネジメント（受注・入金・発送・その他）のどこに青いチェックが付くかです。件名の先頭には [' . kv_e(kv_shop('name')) . '] が自動で付きます。'
       . '1件ずつのメールは、受注一覧のメールアイコンから、送る前に件名・本文を直せます。</p>';
    $in = 'width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px;box-sizing:border-box';
    $kindsel = function (string $name, string $now): string {
        $h = '<select name="' . $name . '" style="padding:6px;border:2px solid #cfdae4;border-radius:8px">';
        foreach (KV_FLAGS as $k => $lb) { $h .= '<option value="' . $k . '"' . ($k === $now ? ' selected' : '') . '>' . $lb . '</option>'; }
        return $h . '</select>';
    };
    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
    foreach (kv_mail_templates_all() as $k => $t) {
        [$subj, $body] = kv_mail_template($k);
        $auto = in_array($k, ['order_received', 'order_paid_card'], true);
        echo '<div class="panel" id="' . kv_e($k) . '"><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
           . '<span class="src">名前</span><input name="label[' . $k . ']" value="' . kv_e($t['label']) . '" style="padding:6px;border:2px solid #cfdae4;border-radius:8px;min-width:260px">'
           . '<span class="src">区分</span>' . $kindsel('kind[' . $k . ']', $t['kind'])
           . ($auto ? ' <span class="tag">注文時に自動で送る</span>' : '')
           . (strpos($k, 'custom_') === 0 ? ' <button class="btn gray" name="delete" value="' . kv_e($k) . '" onclick="return confirm(\'このテンプレートを消します\')">削除</button>' : '') . '</div>'
           . '<label class="src" style="display:block;margin-top:8px">件名</label><input name="subject[' . $k . ']" value="' . kv_e($subj) . '" style="' . $in . '">'
           . '<label class="src" style="display:block;margin-top:8px">本文</label>'
           . '<textarea name="body[' . $k . ']" rows="8" style="' . $in . ';font-family:ui-monospace,monospace;font-size:13px">' . kv_e($body) . '</textarea></div>';
    }
    echo '<p><button class="btn" type="submit">保存する</button></p></form>';
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '"><b>テンプレートを追加する</b>'
       . '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px"><span class="src">名前</span><input name="new_label" required style="padding:6px;border:2px solid #cfdae4;border-radius:8px;min-width:260px" placeholder="例：納期遅れのお知らせ">'
       . '<span class="src">区分</span>' . $kindsel('new_kind', 'other') . '</div>'
       . '<label class="src" style="display:block;margin-top:8px">件名</label><input name="new_subject" value="ご連絡（{code}）" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">本文</label><textarea name="new_body" rows="8" style="' . $in . ';font-family:ui-monospace,monospace;font-size:13px">' . kv_e("{name} 様\n\n\n\n{shop}\n{email}\n") . '</textarea>'
       . '<p><button class="btn gray" type="submit">追加する</button></p></form></div>';
    kv_footer();
}

// ---------------- CSV ----------------

function kv_admin_csv(): void
{
    $f = ['q' => (string)($_GET['q'] ?? ''), 'from' => (string)($_GET['from'] ?? ''),
          'to' => (string)($_GET['to'] ?? ''), 'show_hidden' => true];
    foreach (array_keys(KV_FLAGS) as $k) { $f['f_' . $k] = (string)($_GET['f_' . $k] ?? ''); }
    $r = kv_orders($f, 1, 100000);
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="orders_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    $head = ['受注番号','受注日時','お名前','会社名','メール','電話','郵便番号','住所',
             '支払方法','小計','値引き','消費税','送料','合計','受注','入金','発送','その他',
             'キャンセル','送り状番号','管理メモ','商品'];
    $put = function (array $row) use ($out) {
        fputcsv($out, array_map(fn($v) => mb_convert_encoding((string)$v, 'SJIS-win', 'UTF-8'), $row));
    };
    $put($head);
    foreach ($r['items'] as $o) {
        $items = [];
        foreach (kv_order_items((int)$o['id']) as $it) {
            $items[] = $it['name'] . ' x' . (int)$it['qty'];
        }
        $put([$o['code'], $o['created_at'], $o['name'], $o['company'], $o['email'], $o['tel'],
              $o['zip'], $o['address'], $o['payment'] === 'stripe' ? 'カード' : '振込',
              $o['subtotal'], (int)($o['discount'] ?? 0), $o['tax'], $o['shipping'], $o['total'],
              (int)$o['f_received'] ? '済' : '', (int)$o['f_paid'] ? '済' : '',
              (int)$o['f_shipped'] ? '済' : '', (int)$o['f_other'] ? '済' : '',
              (int)$o['cancelled'] ? 'キャンセル' : '', $o['tracking'], $o['admin_memo'],
              implode(' / ', $items)]);
    }
    fclose($out);
}
