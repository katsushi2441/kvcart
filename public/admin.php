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

require __DIR__ . '/kv_shop.php';
require __DIR__ . '/kv_session.php';
require __DIR__ . '/kv_lib.php';
require __DIR__ . '/kv_ui.php';
require __DIR__ . '/kv_order.php';
require __DIR__ . '/kv_stripe.php';
require __DIR__ . '/kv_cart.php';
require __DIR__ . '/kv_admin_catalog.php';

kv_session();
kv_admin_guard();

$act = (string)($_GET['a'] ?? 'orders');
if ($act === 'csv') { kv_admin_csv(); exit; }
if ($act === 'pcsv') { kv_admin_pcsv(); exit; }
if ($act === 'ccsv') { kv_admin_ccsv(); exit; }

switch ($act) {
    case 'order':  kv_admin_order(); break;
    case 'new':    kv_admin_new(); break;
    case 'mail':   kv_admin_mail_templates(); break;
    case 'compose': kv_admin_compose(); break;
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
    $t = [
        'orders' => '受注一覧', 'new' => '代理入力', 'products' => '商品', 'price' => '価格の一括改定', 'pimport' => '商品CSV取り込み',
        'makers' => 'メーカー', 'categories' => 'カテゴリ', 'customers' => '顧客', 'stats' => '売上', 'plog' => '商品の変更履歴',
        'mail' => 'メールテンプレート',
    ];
    echo '<div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">';
    foreach ($t as $k => $label) {
        $cls = $k === $now ? 'btn' : 'btn gray';
        echo '<a class="' . $cls . '" href="?a=' . $k . '">' . kv_e($label) . '</a>';
    }
    echo '<a class="btn gray" href="' . kv_url('manual') . '" target="_blank" rel="noopener">運営マニュアル</a>'
       . '<a class="btn gray" href="' . kv_url('') . '" target="_blank" rel="noopener">店を見る</a>'
       . '<a class="btn gray" href="?logout=1">ログアウト</a></div>';
}

// ---------------- 受注一覧 ----------------

function kv_admin_orders(): void
{
    // 一括操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok() && !empty($_POST['bulk'])) {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $flag = (string)$_POST['bulk_flag'];
        $send = !empty($_POST['bulk_mail']);
        foreach ($ids as $id) {
            if ($flag === 'cancel') {
                kv_db()->prepare('UPDATE orders SET cancelled=1 WHERE id=?')->execute([$id]);
                kv_log($id, 'cancel', 'キャンセルしました');
            } elseif ($flag === 'hide') {
                kv_db()->prepare('UPDATE orders SET hidden=1 WHERE id=?')->execute([$id]);
                kv_log($id, 'hide', '一覧から外しました');
            } elseif (isset(KV_FLAGS[$flag])) {
                kv_order_flag($id, $flag, true, $send);
            }
        }
        header('Location: ?' . $_SERVER['QUERY_STRING']);
        exit;
    }
    $f = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''),
        'cancelled' => (string)($_GET['cancelled'] ?? ''),
        'show_hidden' => !empty($_GET['show_hidden']),
    ];
    foreach (array_keys(KV_FLAGS) as $k) { $f['f_' . $k] = (string)($_GET['f_' . $k] ?? ''); }
    $r = kv_orders($f, max(1, (int)($_GET['p'] ?? 1)));

    kv_head('受注一覧', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:1200px">';
    kv_admin_nav('orders');
    echo '<h1>受注一覧</h1>';
    // 絞り込み
    echo '<form class="panel" method="get"><input type="hidden" name="a" value="orders">'
       . '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">'
       . '<div><label class="src">検索（受注番号・氏名・会社・メール・電話・管理メモ）</label>'
       . '<input name="q" value="' . kv_e($f['q']) . '" style="min-width:260px;padding:8px;border:2px solid #cfdae4;border-radius:8px"></div>'
       . '<div><label class="src">期間</label><input type="date" name="from" value="' . kv_e($f['from'])
       . '" style="padding:8px;border:2px solid #cfdae4;border-radius:8px"> 〜 '
       . '<input type="date" name="to" value="' . kv_e($f['to']) . '" style="padding:8px;border:2px solid #cfdae4;border-radius:8px"></div>';
    foreach (KV_FLAGS as $k => $label) {
        echo '<div><label class="src">' . $label . '</label><select name="f_' . $k
           . '" style="padding:8px;border:2px solid #cfdae4;border-radius:8px">'
           . '<option value="">すべて</option>'
           . '<option value="1"' . ($f['f_' . $k] === '1' ? ' selected' : '') . '>完了</option>'
           . '<option value="0"' . ($f['f_' . $k] === '0' ? ' selected' : '') . '>未</option></select></div>';
    }
    echo '<div><button class="btn" type="submit">絞り込む</button></div>'
       . '<div><a class="btn gray" href="?a=csv&' . kv_e(http_build_query($_GET)) . '">CSVで落とす</a></div>'
       . '</div></form>';

    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<div class="scroll"><table class="t"><tr><th></th><th>受注番号</th><th>受注日時</th>'
       . '<th>お客様</th><th>合計</th>';
    foreach (KV_FLAGS as $label) { echo '<th>' . $label . '</th>'; }
    echo '<th>支払</th><th></th></tr>';
    foreach ($r['items'] as $o) {
        $rep = kv_is_repeater((string)$o['email'], (int)$o['id']);
        echo '<tr' . ((int)$o['cancelled'] === 1 ? ' style="background:#fdf6f6"' : '') . '>'
           . '<td><input type="checkbox" name="ids[]" value="' . (int)$o['id'] . '"></td>'
           . '<td><a href="?a=order&id=' . (int)$o['id'] . '">' . kv_e($o['code']) . '</a>'
           . ((int)$o['cancelled'] === 1 ? '<div class="src" style="color:#b3261e">キャンセル</div>' : '') . '</td>'
           . '<td class="src">' . kv_e(substr((string)$o['created_at'], 0, 16)) . '</td>'
           . '<td>' . kv_e($o['name']) . ($o['company'] ? '<div class="src">' . kv_e($o['company']) . '</div>' : '')
           . ($rep ? '<div class="src" style="color:#0a7d75">リピーター</div>' : '') . '</td>'
           . '<td>' . number_format((int)$o['total']) . '円</td>';
        foreach (array_keys(KV_FLAGS) as $k) {
            echo '<td style="text-align:center">' . ((int)$o['f_' . $k] === 1 ? '✓' : '—') . '</td>';
        }
        echo '<td class="src">' . ($o['payment'] === 'stripe' ? 'カード' : '振込') . '</td>'
           . '<td><a class="btn gray" href="?a=order&id=' . (int)$o['id'] . '">明細</a></td></tr>';
    }
    echo '</table></div>';
    echo '<div class="panel" style="margin-top:14px"><b>選択した受注をまとめて処理</b><br>'
       . '<select name="bulk_flag" style="padding:8px;border:2px solid #cfdae4;border-radius:8px">';
    foreach (KV_FLAGS as $k => $label) { echo '<option value="' . $k . '">' . $label . 'を完了にする</option>'; }
    echo '<option value="cancel">キャンセルにする</option><option value="hide">一覧から外す</option></select> '
       . '<label class="src"><input type="checkbox" name="bulk_mail" value="1"> メールも送る</label> '
       . '<button class="btn" type="submit" name="bulk" value="1">実行</button></div></form>';
    kv_pager($r, '?a=orders&' . http_build_query(array_diff_key($_GET, ['p' => 1])));
    echo '</div>';
    kv_footer();
}

// ---------------- 受注明細 ----------------

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
                $_POST['tracking'], $ship, (int)$o['subtotal'] + (int)$o['tax'] + $ship, $id]);
            kv_log($id, 'edit', '受注内容を変更しました');
        } elseif (isset($_POST['flag'])) {
            // メールは「編集して送る／そのまま送る／送らない」から選ぶ（おちゃのこネットと同じく、送る前に直せる・2026-11-17）
            $mode = (string)($_POST['send_mail'] ?? '');
            $on = (string)$_POST['on'] === '1';
            kv_order_flag($id, (string)$_POST['flag'], $on, $on && $mode === '1');
            if ($on && $mode === 'edit') { header('Location: ?a=compose&id=' . $id . '&tpl=flag_' . rawurlencode((string)$_POST['flag'])); exit; }
        } elseif (isset($_POST['cancel'])) {
            kv_db()->prepare('UPDATE orders SET cancelled=? WHERE id=?')
                   ->execute([(string)$_POST['cancel'] === '1' ? 1 : 0, $id]);
            kv_log($id, 'cancel', (string)$_POST['cancel'] === '1' ? 'キャンセルしました' : 'キャンセルを取り消しました');
        } elseif (isset($_POST['sendmail'])) {
            kv_mail_send($id, (string)$_POST['sendmail']);
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

    // カード払いの請求書（Stripe）。注文後に在庫・送料を確かめてから、確定した金額で送る
    if ($o['payment'] === 'stripe' && kv_stripe_ready()) {
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
    echo '<tr><th colspan="4">小計（税抜）</th><td>' . number_format((int)$o['subtotal']) . '円</td></tr>'
       . '<tr><th colspan="4">消費税</th><td>' . number_format((int)$o['tax']) . '円</td></tr>'
       . '<tr><th colspan="4">送料</th><td>' . number_format((int)$o['shipping']) . '円</td></tr>'
       . '<tr><th colspan="4">合計（税込）</th><td><b>' . number_format((int)$o['total']) . '円</b></td></tr>'
       . '</table></div></div>';

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
    $id = (int)($_GET['id'] ?? 0);
    $o = kv_order($id);
    if (!$o) { http_response_code(404); echo '受注が見つかりません'; return; }
    $tpl = (string)($_GET['tpl'] ?? 'flag_other');
    if (!isset(kv_mail_defaults()[$tpl])) { $tpl = 'flag_other'; }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $to = trim((string)($_POST['to'] ?? '')); $cc = trim((string)($_POST['cc'] ?? ''));
        $subj = trim((string)($_POST['subject'] ?? '')); $body = str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''));
        $valid = fn(string $s) => $s === '' || array_reduce(array_map('trim', explode(',', $s)), fn($c, $a) => $c && filter_var($a, FILTER_VALIDATE_EMAIL), true);
        if ($to === '' || !$valid($to) || !$valid($cc)) { $err = '宛先・Cc のメールアドレスを確かめてください（複数はカンマ区切り）。'; }
        elseif ($subj === '' || trim($body) === '') { $err = '件名と本文を入れてください。'; }
        elseif (strpos($body, '〇') !== false && empty($_POST['force'])) { $err = '本文に「〇」が残っています（納期などの書き換え忘れ）。直すか、このまま送るなら「〇のまま送る」にチェックしてください。'; }
        else {
            $ok = kv_mail_send_text($id, $to, $cc, $subj, $body);
            header('Location: ?a=order&id=' . $id . '&mailed=' . ($ok ? '1' : '0'));
            exit;
        }
    } else {
        [$subj, $body] = kv_mail_render($id, $tpl);
        $to = (string)$o['email']; $cc = kv_shop('email');
    }
    kv_head('メールを送る', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:900px">';
    kv_admin_nav('orders');
    echo '<nav class="crumb"><a href="?a=orders">受注一覧</a> › <a href="?a=order&id=' . $id . '">' . kv_e($o['code']) . '</a> › メールを送る</nav><h1>メールを送る</h1>';
    if ($err) { echo '<div class="alert">' . kv_e($err) . '</div>'; }
    echo '<form method="get" class="filters"><input type="hidden" name="a" value="compose"><input type="hidden" name="id" value="' . $id . '">'
       . '<span class="src">テンプレート：</span><select name="tpl" style="padding:7px;border:2px solid #cfdae4;border-radius:8px">';
    foreach (kv_mail_labels() as $k => $label) { echo '<option value="' . $k . '"' . ($k === $tpl ? ' selected' : '') . '>' . kv_e($label) . '</option>'; }
    echo '</select> <button class="btn gray" type="submit">このテンプレートで作り直す</button></form>';
    $in = 'width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px;box-sizing:border-box';
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<label class="src">宛先</label><input name="to" value="' . kv_e($to) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">Cc（店の控え。要らなければ空に）</label><input name="cc" value="' . kv_e($cc) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">件名</label><input name="subject" value="' . kv_e($subj) . '" style="' . $in . '">'
       . '<label class="src" style="display:block;margin-top:8px">本文</label><textarea name="body" rows="24" style="' . $in . ';font-family:ui-monospace,monospace;font-size:13px">' . kv_e($body) . '</textarea>'
       . ($err && strpos($err, '〇') !== false ? '<label class="src"><input type="checkbox" name="force" value="1"> 〇のまま送る</label>' : '')
       . '<p><button class="btn" type="submit" onclick="return confirm(\'この内容で送信します。よろしいですか？\')">送信する</button> '
       . '<a class="btn gray" href="?a=order&id=' . $id . '">送らずに戻る</a></p></form></div>';
    kv_footer();
}

function kv_admin_mail_templates(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && kv_csrf_ok()) {
        $st = kv_db()->prepare('INSERT OR REPLACE INTO mail_templates (key,subject,body) VALUES (?,?,?)');
        foreach ((array)($_POST['subject'] ?? []) as $k => $s) {
            $st->execute([$k, $s, (string)($_POST['body'][$k] ?? '')]);
        }
        header('Location: ?a=mail&saved=1');
        exit;
    }
    kv_head('メールテンプレート', '', '/admin', ['noindex' => true]);
    echo '<div class="wrap" style="max-width:900px">';
    kv_admin_nav('mail');
    echo '<h1>メールテンプレート</h1>';
    if (!empty($_GET['saved'])) { echo '<div class="note">保存しました。</div>'; }
    echo '<p class="src">使える差し込み: {code} 受注番号 / {name} お名前 / {total} 合計 / {items} 明細 / {bank} 注文時の振込の案内 / {bank_account} 振込先の口座 / {next} 今後の流れ / {tracking} 送り状番号 / {pay_url} カードのお支払いページ / {shop} 店名 / {email} 店のメール</p>'
       . '<p class="src">ここで直すのは「ひな形」です。1件ずつのメールは、受注明細の「メールを送る」で、送る前に件名・本文を直せます。</p>'
       . '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
    foreach (kv_mail_defaults() as $k => $d) {
        [$subj, $body] = kv_mail_template($k);
        echo '<div class="panel"><b>' . kv_e(kv_mail_labels()[$k] ?? $k) . '</b> <span class="src">' . kv_e($k) . '</span>'
           . '<label class="src" style="display:block;margin-top:8px">件名</label>'
           . '<input name="subject[' . $k . ']" value="' . kv_e($subj) . '" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px">'
           . '<label class="src" style="display:block;margin-top:8px">本文</label>'
           . '<textarea name="body[' . $k . ']" rows="8" style="width:100%;padding:8px;border:2px solid #cfdae4;border-radius:8px;font-family:ui-monospace,monospace;font-size:13px">'
           . kv_e($body) . '</textarea></div>';
    }
    echo '<p><button class="btn" type="submit">保存する</button></p></form></div>';
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
             '支払方法','小計','消費税','送料','合計','受注','入金','発送','その他',
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
              $o['subtotal'], $o['tax'], $o['shipping'], $o['total'],
              (int)$o['f_received'] ? '済' : '', (int)$o['f_paid'] ? '済' : '',
              (int)$o['f_shipped'] ? '済' : '', (int)$o['f_other'] ? '済' : '',
              (int)$o['cancelled'] ? 'キャンセル' : '', $o['tracking'], $o['admin_memo'],
              implode(' / ', $items)]);
    }
    fclose($out);
}
