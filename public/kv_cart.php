<?php
/**
 * Kurage Vibe-Cart — カートと注文。
 *
 * カートはセッションに置く（会員機能は作らない。おちゃのこネットの /member-login は
 * 過去データを引き継がない方針なので、ゲスト購入だけにする）。
 * 注文は SQLite の orders / order_items に入れ、受注管理から扱う。
 *
 * 決済は **銀行振込を既定**にする。Stripe は鍵が入ったときだけボタンを出す（kbilling と同じ）。
 * 押せないボタンを置かないのは、問い合わせを増やさないため。
 */
declare(strict_types=1);

function kv_cart_get(): array
{
    kv_session();
    return $_SESSION['cart'] ?? [];
}

function kv_cart_set(array $c): void
{
    kv_session();
    $_SESSION['cart'] = $c;
}

/** カートの中身を商品情報つきで返す。価格は**都度DBから引く**（セッションの値を信じない）。 */
function kv_cart_lines(): array
{
    $c = kv_cart_get();
    if (!$c) { return ['lines' => [], 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'count' => 0]; }
    $ids = array_map('intval', array_keys($c));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = kv_db()->prepare("SELECT * FROM products WHERE id IN ($in) AND hidden=0");
    $st->execute($ids);
    $lines = [];
    $sub = 0;
    $tax = 0;
    foreach ($st->fetchAll() as $p) {
        $qty = max(1, (int)$c[(string)$p['id']]);
        $unit = (int)$p['price'];
        $rate = ((int)($p['tax_reduce'] ?? 0) === 1) ? 0.08 : 0.10;
        $amt = $unit * $qty;
        $t = (int)floor($amt * $rate);
        $lines[] = ['product' => $p, 'qty' => $qty, 'unit' => $unit, 'amount' => $amt, 'tax' => $t];
        $sub += $amt;
        $tax += $t;
    }
    return ['lines' => $lines, 'subtotal' => $sub, 'tax' => $tax, 'total' => $sub + $tax,
            'count' => array_sum(array_map(fn($l) => $l['qty'], $lines))];
}

function kv_page_cart(): void
{
    kv_session();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $c = kv_cart_get();
        if (!empty($_POST['add'])) {
            $id = (string)(int)$_POST['add'];
            $c[$id] = min(99, (int)($c[$id] ?? 0) + max(1, (int)($_POST['qty'] ?? 1)));
        }
        if (isset($_POST['qty_of']) && is_array($_POST['qty_of'])) {
            foreach ($_POST['qty_of'] as $id => $q) {
                $id = (string)(int)$id;
                $q = (int)$q;
                if ($q <= 0) { unset($c[$id]); } else { $c[$id] = min(99, $q); }
            }
        }
        if (!empty($_POST['remove'])) { unset($c[(string)(int)$_POST['remove']]); }
        kv_cart_set($c);
        header('Location: ' . kv_url('cart'));
        return;
    }
    $r = kv_cart_lines();
    kv_head('カート', '', '/cart', ['noindex' => true]);
    echo '<div class="wrap"><h1>カート</h1>';
    if (!$r['lines']) {
        echo '<div class="note">カートは空です。</div><p><a class="btn" href="' . kv_url('') . '">商品を探す</a></p>';
        echo '</div>'; kv_footer(); return;
    }
    echo '<form method="post" action="' . kv_url('cart') . '">'
       . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<div class="scroll"><table class="t"><tr><th>商品</th><th>単価（税抜）</th><th>数量</th><th>小計</th><th></th></tr>';
    foreach ($r['lines'] as $l) {
        $p = $l['product'];
        echo '<tr><td><a href="' . kv_url('product/' . (int)$p['id']) . '">' . kv_e($p['name']) . '</a>'
           . ($p['model_number'] ? '<div class="src">' . kv_e($p['model_number']) . '</div>' : '') . '</td>'
           . '<td>' . number_format($l['unit']) . '円</td>'
           . '<td><input type="number" name="qty_of[' . (int)$p['id'] . ']" value="' . $l['qty']
           . '" min="0" max="99" style="width:66px;padding:6px;border:2px solid #cfdae4;border-radius:8px"></td>'
           . '<td>' . number_format($l['amount']) . '円</td>'
           . '<td><button class="btn gray" type="submit" name="remove" value="' . (int)$p['id'] . '">削除</button></td></tr>';
    }
    echo '</table></div>'
       . '<p><button class="btn ghost" type="submit">数量を更新</button></p></form>'
       . '<div class="panel"><table class="t">'
       . '<tr><th>小計（税抜）</th><td>' . number_format($r['subtotal']) . '円</td></tr>'
       . '<tr><th>消費税</th><td>' . number_format($r['tax']) . '円</td></tr>'
       . '<tr><th>合計（税込）</th><td><b>' . number_format($r['total']) . '円</b></td></tr></table>'
       . '<p class="src">' . kv_e(kv_shop('ship_note')) . '</p>'
       . '<p><a class="btn" href="' . kv_url('checkout') . '">お客様情報の入力へ</a></p></div></div>';
    kv_footer();
}

function kv_page_checkout(): void
{
    kv_session();
    $r = kv_cart_lines();
    if (!$r['lines']) { header('Location: ' . kv_url('cart')); return; }
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $f = kv_checkout_fields();
        foreach ($f as $k => $meta) {
            $v = trim((string)($_POST[$k] ?? ''));
            if (!empty($meta['required']) && $v === '') { $err[$k] = $meta['label'] . 'を入力してください'; }
            if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $err[$k] = 'メールアドレスの形式が正しくありません';
            }
        }
        if (!$err) {
            $oid = kv_order_create($r, $_POST);
            kv_cart_set([]);
            $_SESSION['last_order'] = $oid;
            header('Location: ' . kv_url('thanks'));
            return;
        }
    }
    kv_head('お客様情報の入力', '', '/checkout', ['noindex' => true]);
    echo '<div class="wrap"><h1>お客様情報の入力</h1>';
    if ($err) { echo '<div class="alert">' . implode('<br>', array_map('kv_e', $err)) . '</div>'; }
    echo '<form method="post" class="panel"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">';
    foreach (kv_checkout_fields() as $k => $m) {
        $v = kv_e($_POST[$k] ?? '');
        echo '<label class="src" style="display:block;margin-top:10px">' . kv_e($m['label'])
           . (!empty($m['required']) ? ' <span style="color:#b3261e">必須</span>' : '') . '</label>';
        if (($m['type'] ?? '') === 'textarea') {
            echo '<textarea name="' . $k . '" rows="4" style="width:100%;padding:9px;border:2px solid #cfdae4;border-radius:8px">' . $v . '</textarea>';
        } else {
            echo '<input type="' . ($m['type'] ?? 'text') . '" name="' . $k . '" value="' . $v
               . '" style="width:100%;padding:9px;border:2px solid #cfdae4;border-radius:8px">';
        }
    }
    echo '<h2>お支払い方法</h2>'
       . '<label><input type="radio" name="payment" value="bank" checked> 銀行振込（前払い）</label>';
    if (kv_stripe_ready()) {
        echo '<br><label><input type="radio" name="payment" value="stripe"> クレジットカード</label>';
    } else {
        echo '<p class="src">クレジットカード決済は準備中です。</p>';
    }
    echo '<div class="note">合計 <b>' . number_format($r['total']) . '円</b>（税込・' . $r['count'] . '点）</div>'
       . '<p><button class="btn" type="submit">この内容で注文する</button></p></form></div>';
    kv_footer();
}

function kv_checkout_fields(): array
{
    return [
        'name'     => ['label' => 'お名前', 'required' => true],
        'company'  => ['label' => '会社名・部署名'],
        'email'    => ['label' => 'メールアドレス', 'required' => true, 'type' => 'email'],
        'tel'      => ['label' => '電話番号', 'required' => true, 'type' => 'tel'],
        'zip'      => ['label' => '郵便番号', 'required' => true],
        'address'  => ['label' => 'ご住所', 'required' => true],
        'note'     => ['label' => 'ご要望・納期のご希望など', 'type' => 'textarea'],
    ];
}

function kv_page_thanks(): void
{
    kv_session();
    $oid = $_SESSION['last_order'] ?? null;
    kv_head('ご注文ありがとうございます', '', '/thanks', ['noindex' => true]);
    echo '<div class="wrap"><h1>ご注文ありがとうございます</h1>';
    if ($oid) {
        $o = kv_order((int)$oid);
        echo '<div class="panel"><p>受注番号 <b>' . kv_e($o['code']) . '</b></p>'
           . '<p>合計 <b>' . number_format((int)$o['total']) . '円</b>（税込）</p>'
           . '<p class="src">確認のメールをお送りしました。届かないときはご連絡ください。</p></div>';
        if ($o['payment'] === 'bank') {
            echo '<h2>お振込先</h2><div class="panel">' . nl2br(kv_e(kv_shop('bank')))
               . '<p class="src" style="margin-top:10px">ご入金を確認したら発送します。</p></div>';
        }
    }
    echo '<p><a class="btn" href="' . kv_url('') . '">買い物を続ける</a></p></div>';
    kv_footer();
}
