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
    kv_head('ショッピングカート', '', '/cart', ['noindex' => true]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ ショッピングカート</nav>'
       . '<h1>ショッピングカート</h1>';
    kv_steps(0);
    if (!$r['lines']) {
        echo '<div class="note">カートは空です。<br>'
           . 'カートに商品が入らない場合は、クッキーの設定が有効になっていない可能性があります。'
           . 'ブラウザの設定をご確認ください。</div>'
           . '<p><a class="btn" href="' . kv_url('') . '">買い物を続ける</a></p></div>';
        kv_footer();
        return;
    }
    // 列はおちゃのこネットと同じ: 商品写真 / 商品名 / 販売価格 / 数量 / 小計 / 削除
    echo '<form method="post" action="' . kv_url('cart') . '">'
       . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<div class="scroll"><table class="t"><tr><th>商品写真</th><th>商品名</th>'
       . '<th>販売価格</th><th>数量</th><th>小計</th><th>削除</th></tr>';
    foreach ($r['lines'] as $l) {
        $p = $l['product'];
        $img = kv_img($p['image_url']);
        echo '<tr><td style="width:96px">'
           . ($img ? '<a href="' . kv_url('product/' . (int)$p['id']) . '">'
                   . '<img src="' . kv_e($img) . '" alt="" style="width:80px;height:80px;object-fit:contain"></a>' : '')
           . '</td>'
           . '<td><a href="' . kv_url('product/' . (int)$p['id']) . '">' . kv_e($p['name']) . '</a>'
           . ($p['model_number'] ? '<div class="src">[' . kv_e($p['model_number']) . ']</div>' : '') . '</td>'
           . '<td style="white-space:nowrap">' . number_format($l['unit']) . '円</td>'
           . '<td><input type="number" name="qty_of[' . (int)$p['id'] . ']" value="' . $l['qty']
           . '" min="0" max="99" style="width:70px;padding:7px;border:2px solid #cfdae4;border-radius:8px"></td>'
           . '<td style="white-space:nowrap">' . number_format($l['amount']) . '円</td>'
           . '<td><button class="btn gray" type="submit" name="remove" value="' . (int)$p['id'] . '">削除</button></td></tr>';
    }
    echo '</table></div>'
       . '<p><button class="btn ghost" type="submit">更新</button></p></form>';
    // 金額欄。おちゃのこネットは「商品合計 ○円（税別）（税込：○円）」の形
    echo '<div class="panel" style="max-width:460px;margin-left:auto">'
       . '<table class="t"><tr><th>商品合計</th><td>' . number_format($r['subtotal']) . '円 <span class="src">(税別)</span></td></tr>'
       . '<tr><th>消費税</th><td>' . number_format($r['tax']) . '円</td></tr>'
       . '<tr><th>税込合計</th><td><b style="font-size:18px">' . number_format($r['total']) . '円</b></td></tr></table>'
       . '<p class="src">' . kv_e(kv_shop('ship_note')) . '</p>'
       . '<p style="text-align:right"><a class="btn" href="' . kv_url('cart/customer') . '">レジに進む</a></p>'
       . '</div></div>';
    kv_footer();
}

/**
 * STEP 1 購入者。**おちゃのこネットと同じ項目・同じ並び**にする。
 * （www.exdirect.net/cart/customer?nonmember_register=1 の入力欄を実測して合わせた）
 *   お名前 / フリガナ / 会社名 / 部署名 / 郵便番号 / 都道府県 / 住所1 / 住所2 / 住所3
 *   / メール / 電話 / FAX / DMの可否
 */
function kv_checkout_fields(): array
{
    return [
        'name'        => ['label' => 'お名前', 'required' => true],
        'name_kana'   => ['label' => 'フリガナ'],
        'company'     => ['label' => '会社名', 'hint' => '※法人の方のみ'],
        'department'  => ['label' => '部署名', 'hint' => '※法人の方のみ'],
        'zip'         => ['label' => '郵便番号', 'required' => true, 'hint' => '例: 460-0008'],
        'pref'        => ['label' => '都道府県', 'required' => true, 'type' => 'pref'],
        'address1'    => ['label' => '市区町村', 'required' => true],
        'address2'    => ['label' => '番地', 'required' => true],
        'address3'    => ['label' => 'ビル名・マンション名など'],
        'email'       => ['label' => 'メールアドレス', 'required' => true, 'type' => 'email'],
        'tel'         => ['label' => '電話番号', 'required' => true, 'type' => 'tel'],
        'fax'         => ['label' => 'FAX番号'],
    ];
}

function kv_checkout_validate(array $post): array
{
    $err = [];
    foreach (kv_checkout_fields() as $k => $m) {
        $v = trim((string)($post[$k] ?? ''));
        if (!empty($m['required']) && $v === '') { $err[$k] = $m['label'] . 'を入力してください'; }
        if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $err[$k] = 'メールアドレスの形式が正しくありません';
        }
        if ($k === 'zip' && $v !== '' && !preg_match('/^[0-9]{3}-?[0-9]{4}$/', $v)) {
            $err[$k] = '郵便番号は 460-0008 の形で入力してください';
        }
    }
    return $err;
}

/** STEP 1: 購入者の入力 */
function kv_page_customer(): void
{
    kv_session();
    $r = kv_cart_lines();
    if (!$r['lines']) { header('Location: ' . kv_url('cart')); return; }
    $err = [];
    $in = $_SESSION['checkout'] ?? [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $in = $_POST;
        $err = kv_checkout_validate($_POST);
        if (!$err) {
            $_SESSION['checkout'] = $_POST;
            header('Location: ' . kv_url('cart/delivery'));
            return;
        }
    }
    kv_head('お客様情報の入力', '', '/cart/customer', ['noindex' => true]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ '
       . '<a href="' . kv_url('cart') . '">ショッピングカート</a> ｜ お客様情報の入力</nav>'
       . '<h1>ショッピングカート</h1>';
    kv_steps(1);
    echo '<h2>お客様情報の入力</h2>';
    if ($err) { echo '<div class="alert">' . implode('<br>', array_map('kv_e', $err)) . '</div>'; }
    echo '<p class="src"><span class="req">必須</span> のマークのついている項目は必ずご記入ください。</p>'
       . '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<table class="formtbl">';
    foreach (kv_checkout_fields() as $k => $m) {
        $v = kv_e($in[$k] ?? '');
        echo '<tr><th>' . kv_e($m['label'])
           . (!empty($m['required']) ? '<span class="req">必須</span>' : '') . '</th><td>';
        if (($m['type'] ?? '') === 'pref') {
            echo '<select name="' . $k . '"><option value="">選択してください</option>';
            foreach (kv_prefs() as $pf) {
                echo '<option' . ($v === kv_e($pf) ? ' selected' : '') . '>' . kv_e($pf) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="' . ($m['type'] ?? 'text') . '" name="' . $k . '" value="' . $v . '">';
        }
        if (!empty($m['hint'])) { echo '<div class="hint">' . kv_e($m['hint']) . '</div>'; }
        echo '</td></tr>';
    }
    $dm = $in['dm'] ?? '1';
    echo '<tr><th>メールマガジン</th><td>'
       . '<label><input type="radio" name="dm" value="1"' . ($dm === '1' ? ' checked' : '') . '> 受け取る</label>　'
       . '<label><input type="radio" name="dm" value="0"' . ($dm === '0' ? ' checked' : '') . '> 受け取らない</label>'
       . '</td></tr></table>'
       . '<p style="margin-top:16px"><a class="btn gray" href="' . kv_url('cart') . '">カートに戻る</a> '
       . '<button class="btn" type="submit">次へ進む</button></p></form></div>';
    kv_footer();
}

/** STEP 2: お届け先・お支払い */
function kv_page_delivery(): void
{
    kv_session();
    $r = kv_cart_lines();
    $c = $_SESSION['checkout'] ?? [];
    if (!$r['lines']) { header('Location: ' . kv_url('cart')); return; }
    if (!$c) { header('Location: ' . kv_url('cart/customer')); return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $_SESSION['checkout'] = array_merge($c, [
            'ship_to' => (string)($_POST['ship_to'] ?? 'same'),
            'payment' => (string)($_POST['payment'] ?? 'bank'),
            'note' => (string)($_POST['note'] ?? ''),
        ] + array_intersect_key($_POST, array_flip(
            ['s_name', 's_zip', 's_pref', 's_address1', 's_address2', 's_address3', 's_tel'])));
        header('Location: ' . kv_url('cart/confirm'));
        return;
    }
    kv_head('お届け先・お支払い', '', '/cart/delivery', ['noindex' => true]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ '
       . '<a href="' . kv_url('cart') . '">ショッピングカート</a> ｜ お届け先・お支払い</nav>'
       . '<h1>ショッピングカート</h1>';
    kv_steps(2);
    echo '<form method="post"><input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<h2>お届け先</h2><div class="panel">'
       . '<label><input type="radio" name="ship_to" value="same" checked> ご購入者さまと同じ住所へ届ける</label><br>'
       . '<div class="src" style="margin:6px 0 12px 24px">'
       . kv_e(($c['zip'] ?? '') . ' ' . ($c['pref'] ?? '') . ($c['address1'] ?? '')
              . ($c['address2'] ?? '') . ' ' . ($c['address3'] ?? '')) . '<br>'
       . kv_e($c['name'] ?? '') . ' 様</div>'
       . '<label><input type="radio" name="ship_to" value="other"> 別の住所へ届ける</label>'
       . '<table class="formtbl" style="margin-top:10px">'
       . '<tr><th>お届け先 お名前</th><td><input type="text" name="s_name"></td></tr>'
       . '<tr><th>郵便番号</th><td><input type="text" name="s_zip"></td></tr>'
       . '<tr><th>都道府県</th><td><select name="s_pref"><option value="">選択してください</option>';
    foreach (kv_prefs() as $pf) { echo '<option>' . kv_e($pf) . '</option>'; }
    echo '</select></td></tr>'
       . '<tr><th>市区町村</th><td><input type="text" name="s_address1"></td></tr>'
       . '<tr><th>番地</th><td><input type="text" name="s_address2"></td></tr>'
       . '<tr><th>ビル名など</th><td><input type="text" name="s_address3"></td></tr>'
       . '<tr><th>電話番号</th><td><input type="text" name="s_tel"></td></tr></table></div>';
    echo '<h2>お支払い方法</h2><div class="panel">'
       . '<label><input type="radio" name="payment" value="bank" checked> <b>銀行振込</b>（前払い）</label>'
       . '<div class="src" style="margin:4px 0 10px 24px">ご入金を確認してから発送します。振込手数料はお客様のご負担でお願いします。</div>';
    if (kv_stripe_ready()) {
        echo '<label><input type="radio" name="payment" value="stripe"> <b>クレジットカード</b></label>'
           . '<div class="src" style="margin:4px 0 0 24px">VISA / Mastercard / JCB / American Express</div>';
    } else {
        echo '<div class="src">クレジットカード決済は準備中です。</div>';
    }
    echo '</div><h2>備考</h2><div class="panel">'
       . '<textarea name="note" rows="4" style="width:100%;padding:9px;border:2px solid #cfdae4;border-radius:8px" '
       . 'placeholder="納期のご希望、見積書・納品書・請求書のご依頼、領収書の宛名・但書きなど"></textarea></div>'
       . '<p><a class="btn gray" href="' . kv_url('cart/customer') . '">戻る</a> '
       . '<button class="btn" type="submit">確認画面へ</button></p></form></div>';
    kv_footer();
}

/** STEP 3: 確認 */
function kv_page_confirm(): void
{
    kv_session();
    $r = kv_cart_lines();
    $c = $_SESSION['checkout'] ?? [];
    if (!$r['lines']) { header('Location: ' . kv_url('cart')); return; }
    if (!$c || empty($c['payment'])) { header('Location: ' . kv_url('cart/delivery')); return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $oid = kv_order_create($r, $c);
        kv_cart_set([]);
        unset($_SESSION['checkout']);
        $_SESSION['last_order'] = $oid;
        header('Location: ' . kv_url('cart/complete'));
        return;
    }
    kv_head('ご注文内容の確認', '', '/cart/confirm', ['noindex' => true]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ '
       . '<a href="' . kv_url('cart') . '">ショッピングカート</a> ｜ ご注文内容の確認</nav>'
       . '<h1>ショッピングカート</h1>';
    kv_steps(3);
    echo '<div class="note">まだご注文は確定していません。内容をご確認のうえ、'
       . '一番下の「注文する」を押してください。</div>';
    echo '<h2>ご注文商品</h2><div class="scroll"><table class="t">'
       . '<tr><th>商品名</th><th>販売価格</th><th>数量</th><th>小計</th></tr>';
    foreach ($r['lines'] as $l) {
        $p = $l['product'];
        echo '<tr><td>' . kv_e($p['name'])
           . ($p['model_number'] ? '<div class="src">[' . kv_e($p['model_number']) . ']</div>' : '') . '</td>'
           . '<td>' . number_format($l['unit']) . '円</td><td>' . $l['qty'] . '</td>'
           . '<td>' . number_format($l['amount']) . '円</td></tr>';
    }
    echo '<tr><th colspan="3">商品合計（税別）</th><td>' . number_format($r['subtotal']) . '円</td></tr>'
       . '<tr><th colspan="3">消費税</th><td>' . number_format($r['tax']) . '円</td></tr>'
       . '<tr><th colspan="3">税込合計</th><td><b>' . number_format($r['total']) . '円</b></td></tr>'
       . '</table></div>';
    $addr = ($c['zip'] ?? '') . ' ' . ($c['pref'] ?? '') . ($c['address1'] ?? '')
          . ($c['address2'] ?? '') . ' ' . ($c['address3'] ?? '');
    echo '<h2>ご購入者</h2><table class="formtbl">'
       . '<tr><th>お名前</th><td>' . kv_e($c['name'] ?? '') . '　' . kv_e($c['name_kana'] ?? '') . '</td></tr>'
       . ($c['company'] ?? '' ? '<tr><th>会社名</th><td>' . kv_e($c['company']) . ' ' . kv_e($c['department'] ?? '') . '</td></tr>' : '')
       . '<tr><th>ご住所</th><td>' . kv_e($addr) . '</td></tr>'
       . '<tr><th>メールアドレス</th><td>' . kv_e($c['email'] ?? '') . '</td></tr>'
       . '<tr><th>電話番号</th><td>' . kv_e($c['tel'] ?? '') . '</td></tr>'
       . '</table>';
    echo '<h2>お届け先</h2><table class="formtbl"><tr><th>お届け先</th><td>'
       . (($c['ship_to'] ?? 'same') === 'same' ? 'ご購入者さまと同じ'
          : kv_e(($c['s_zip'] ?? '') . ' ' . ($c['s_pref'] ?? '') . ($c['s_address1'] ?? '')
                 . ($c['s_address2'] ?? '') . ' ' . ($c['s_address3'] ?? '') . ' ' . ($c['s_name'] ?? '') . ' 様'))
       . '</td></tr></table>';
    echo '<h2>お支払い方法</h2><table class="formtbl"><tr><th>お支払い方法</th><td>'
       . (($c['payment'] ?? 'bank') === 'stripe' ? 'クレジットカード' : '銀行振込（前払い）') . '</td></tr>'
       . (trim((string)($c['note'] ?? '')) !== ''
          ? '<tr><th>備考</th><td>' . nl2br(kv_e($c['note'])) . '</td></tr>' : '')
       . '</table>';
    // 注文を確定する直前に、規約とポリシーへの導線を必ず出す
    echo '<form method="post" style="margin-top:22px">'
       . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
       . '<p class="note">「注文する」を押すと、'
       . '<a href="' . kv_url('terms') . '" target="_blank" rel="noopener">ご利用規約</a>と'
       . '<a href="' . kv_url('privacy') . '" target="_blank" rel="noopener">プライバシーポリシー</a>'
       . 'に同意のうえご注文いただいたものとします。<br>'
       . 'ご注文後、在庫と納期を確認し、振込先とあわせてメールでご連絡します。'
       . '送料が必要な地域・商品の場合は、金額を訂正してご連絡します。</p>'
       . '<p><a class="btn gray" href="' . kv_url('cart/delivery') . '">戻る</a> '
       . '<button class="btn" type="submit">注文する</button></p></form></div>';
    kv_footer();
}

/** STEP 4: 完了 */
function kv_page_complete(): void
{
    kv_session();
    $oid = $_SESSION['last_order'] ?? null;
    kv_head('ご注文ありがとうございます', '', '/cart/complete', ['noindex' => true]);
    echo '<div class="wrap"><nav class="crumb"><a href="' . kv_url('') . '">ホーム</a> ｜ ご注文完了</nav>'
       . '<h1>ショッピングカート</h1>';
    kv_steps(4);
    echo '<h2>ご注文ありがとうございます</h2>';
    if ($oid) {
        $o = kv_order((int)$oid);
        echo '<div class="panel"><table class="formtbl">'
           . '<tr><th>受注番号</th><td><b>' . kv_e($o['code']) . '</b></td></tr>'
           . '<tr><th>ご注文金額</th><td><b>' . number_format((int)$o['total']) . '円</b>（税込）</td></tr>'
           . '<tr><th>お支払い方法</th><td>' . ($o['payment'] === 'stripe' ? 'クレジットカード' : '銀行振込（前払い）') . '</td></tr>'
           . '</table>'
           . '<p class="src">ご注文確認のメールを ' . kv_e($o['email']) . ' へお送りしました。'
           . '届かないときは迷惑メールフォルダをご確認のうえ、お問い合わせください。</p></div>';
        if ($o['payment'] === 'bank') {
            echo '<h2>お振込先</h2><div class="panel">' . nl2br(kv_e(kv_shop('bank')))
               . '<p class="src" style="margin-top:10px">ご入金を確認したら発送します。'
               . '振込手数料はお客様のご負担でお願いします。</p></div>';
        }
    }
    echo '<p style="margin-top:20px"><a class="btn" href="' . kv_url('') . '">買い物を続ける</a></p></div>';
    kv_footer();
}
