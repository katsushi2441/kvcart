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

/**
 * 商品のオプション（おちゃのこネットの「オプション」）。products.variations にAPIの形のまま入っている:
 *   {"options":{"option1":{"label":"吐出口","values":["左方向吐出タイプ(L)","右方向吐出タイプ"]}},"data":[組み合わせ別の価格・在庫]}
 * 返り値: [['label'=>..., 'values'=>[...]], ...]（option1, option2… の順）
 */
function kv_variations(array $p): array
{
    $v = json_decode((string)($p['variations'] ?? ''), true);
    $out = [];
    foreach (($v['options'] ?? []) as $k => $o) {
        if (!empty($o['values'])) { $out[(int)preg_replace('/\D/', '', $k)] = ['label' => (string)$o['label'], 'values' => array_values($o['values'])]; }
    }
    ksort($out);
    return array_values($out);
}

/** 選んだオプション（各項目の番号）から、表示用の文字列と、組み合わせ別の価格（無ければ null）を返す。不正なら null。 */
function kv_variation_pick(array $p, array $idx): ?array
{
    $opts = kv_variations($p);
    if (!$opts) { return $idx ? null : ['text' => '', 'price' => null, 'values' => []]; }
    if (count($idx) !== count($opts)) { return null; }
    $vals = []; $parts = [];
    foreach ($opts as $i => $o) {
        $j = (int)$idx[$i];
        if (!isset($o['values'][$j])) { return null; }
        $vals[] = $o['values'][$j];
        $parts[] = $o['label'] . '：' . $o['values'][$j];
    }
    $price = null;
    $v = json_decode((string)($p['variations'] ?? ''), true);
    foreach (($v['data'] ?? []) as $d) {
        $ok = true;
        foreach ($vals as $i => $val) { if ((string)($d['option' . ($i + 1)] ?? '') !== $val) { $ok = false; break; } }
        if ($ok) {
            if (empty($d['stock_unlimited']) && $d['stock'] !== null && (int)$d['stock'] <= 0) { return null; }  // 品切れの組み合わせ
            if ($d['price'] !== null) { $price = (int)$d['price']; }
            break;
        }
    }
    return ['text' => implode('／', $parts), 'price' => $price, 'values' => $vals];
}

/** カートのキー。オプションの無い商品は "商品ID"、ある商品は "商品ID|0-1"（各項目で選んだ番号）。 */
function kv_cart_key_parse(string $key): array
{
    $a = explode('|', $key, 2);
    return [(int)$a[0], isset($a[1]) && $a[1] !== '' ? array_map('intval', explode('-', $a[1])) : []];
}

/** カートの中身を商品情報つきで返す。価格は**都度DBから引く**（セッションの値を信じない）。 */
function kv_cart_lines(): array
{
    $c = kv_cart_get();
    if (!$c) { return ['lines' => [], 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'count' => 0]; }
    $ids = array_values(array_unique(array_map(fn($k) => kv_cart_key_parse((string)$k)[0], array_keys($c))));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = kv_db()->prepare("SELECT * FROM products WHERE id IN ($in) AND hidden=0");
    $st->execute($ids);
    $byid = [];
    foreach ($st->fetchAll() as $p) { $byid[(int)$p['id']] = $p; }
    $lines = [];
    $sub = 0;
    $tax = 0;
    foreach ($c as $key => $q) {
        [$pid, $idx] = kv_cart_key_parse((string)$key);
        $p = $byid[$pid] ?? null;
        if (!$p) { continue; }
        $pick = kv_variation_pick($p, $idx);
        if ($pick === null) { continue; }   // 選択が足りない・品切れの組み合わせは入れない
        if (kv_price_incl($p) === null || kv_inquiry_only($p)) { continue; }   // 問い合わせ扱い（販売中止品など）はカートに残さない
        $qty = max(1, (int)$q);
        $unit = $pick['price'] ?? (int)$p['price'];
        $rate = ((int)($p['tax_reduce'] ?? 0) === 1) ? 0.08 : 0.10;
        $amt = $unit * $qty;
        $t = (int)floor($amt * $rate);
        $lines[] = ['product' => $p, 'qty' => $qty, 'unit' => $unit, 'amount' => $amt, 'tax' => $t,
                    'key' => (string)$key, 'opt' => $pick['text']];
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
        $okkey = fn($k) => (bool)preg_match('/^\d+(\|\d+(-\d+)*)?$/', (string)$k);
        if (!empty($_POST['add'])) {
            $pid = (int)$_POST['add'];
            $idx = array_map('intval', array_values((array)($_POST['opt'] ?? [])));
            $p = kv_product($pid);
            if ($p && (kv_price_incl($p) === null || kv_inquiry_only($p))) {   // 価格の無い商品・問い合わせ扱いの商品はカートに入れない
                header('Location: ' . kv_url('product/' . $pid));
                return;
            }
            if ($p && !kv_stock_orderable($p)) {   // 在庫切れで注文を受け付けない設定（kv_stock.php）
                header('Location: ' . kv_url('product/' . $pid));
                return;
            }
            if ($p && kv_variation_pick($p, $idx) === null) {
                // オプションを選ばずに送られた（または品切れの組み合わせ）。商品ページへ戻す
                header('Location: ' . kv_url('product/' . $pid) . '?need_option=1');
                return;
            }
            $key = $idx ? $pid . '|' . implode('-', $idx) : (string)$pid;
            $c[$key] = min(99, (int)($c[$key] ?? 0) + max(1, (int)($_POST['qty'] ?? 1)));
            if ($p && kv_stock_tracked($p) && !(int)kv_stock_settings()['backorder']) { $c[$key] = min($c[$key], max(1, (int)$p['stock'])); }
        }
        if (isset($_POST['qty_of']) && is_array($_POST['qty_of'])) {
            foreach ($_POST['qty_of'] as $key => $q) {
                if (!$okkey($key)) { continue; }
                $q = (int)$q;
                if ($q <= 0) { unset($c[$key]); } else { $c[$key] = min(99, $q); }
            }
        }
        if (!empty($_POST['remove']) && $okkey($_POST['remove'])) { unset($c[(string)$_POST['remove']]); }
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
           . ($l['opt'] ? '<div><b>' . kv_e($l['opt']) . '</b></div>' : '')
           . (($mdl = kv_model_display($p['model_number'], $p['name'])) !== '' ? '<div class="src">[' . kv_e($mdl) . ']</div>' : '') . '</td>'
           . '<td style="white-space:nowrap">' . number_format($l['unit']) . '円</td>'
           . '<td><input type="number" name="qty_of[' . kv_e($l['key']) . ']" value="' . $l['qty']
           . '" min="0" max="99" style="width:70px;padding:7px;border:2px solid #cfdae4;border-radius:8px"></td>'
           . '<td style="white-space:nowrap">' . number_format($l['amount']) . '円</td>'
           . '<td><button class="btn gray" type="submit" name="remove" value="' . kv_e($l['key']) . '">削除</button></td></tr>';
    }
    echo '</table></div>'
       . '<p><button class="btn ghost" type="submit">更新</button></p></form>';
    // 金額欄。おちゃのこネットは「商品合計 ○円（税別）（税込：○円）」の形
    echo '<div class="panel" style="max-width:460px;margin-left:auto">'
       . '<table class="t"><tr><th>商品合計</th><td>' . number_format($r['subtotal']) . '円 <span class="src">(税別)</span></td></tr>'
       . '<tr><th>消費税</th><td>' . number_format($r['tax']) . '円</td></tr>'
       . '<tr><th>税込合計</th><td><b style="font-size:18px">' . number_format($r['total']) . '円</b></td></tr></table>'
       . '<p class="src">' . kv_e(kv_shop('ship_note')) . '</p>'
       . kv_heavy_note()
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
    // メーカー・商品ごとのお支払い方法の指定（kv_data/payment_rules.json・2026-11-20）
    $pay = kv_cart_payment_methods($r);
    if (!kv_stripe_ready()) { $pay['methods'] = array_values(array_diff($pay['methods'], ['stripe'])) ?: ['bank']; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        $_SESSION['checkout'] = array_merge($c, [
            'ship_to' => (string)($_POST['ship_to'] ?? 'same'),
            'payment' => in_array((string)($_POST['payment'] ?? ''), $pay['methods'], true) ? (string)$_POST['payment'] : $pay['methods'][0],
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
    echo '<h2>お支払い方法</h2><div class="panel">';
    $first = $pay['methods'][0];
    if ($pay['restricted'] && count($pay['methods']) < 2) {
        echo '<div class="note" style="margin-bottom:10px">次の商品は、お支払いを<b>' . ($first === 'bank' ? '銀行振込' : 'クレジットカード') . '</b>のみでお受けしています。<br>'
           . kv_e(implode('、', array_slice($pay['restricted'], 0, 5))) . (count($pay['restricted']) > 5 ? ' ほか' : '') . '</div>';
    }
    if (in_array('bank', $pay['methods'], true)) {
        echo '<label><input type="radio" name="payment" value="bank"' . ($first === 'bank' ? ' checked' : '') . '> <b>銀行振込</b>（前払い）</label>'
           . '<div class="src" style="margin:4px 0 10px 24px">ご入金を確認してから発送します。振込手数料はお客様のご負担でお願いします。</div>';
    }
    if (in_array('stripe', $pay['methods'], true)) {
        echo '<label><input type="radio" name="payment" value="stripe"' . ($first === 'stripe' ? ' checked' : '') . '> <b>クレジットカード</b></label>'
           . '<div class="src" style="margin:4px 0 0 24px">「注文する」を押すと、カードのお支払い画面（Stripe）に進みます。'
           . 'カードは、ご注文の時点ではお支払い枠を確保する仮売上とし、在庫と納期を確かめて商品を手配してから売上を確定します。' . 'メーカーの欠品などで納期が長くかかる場合は、ご注文のあと納期をメールでお知らせし、ご了承をいただいてから手配します。ご了承いただけない場合はキャンセルできます（カードは仮売上を取り消すので、ご請求はありません）。</div>';
    } elseif (!kv_stripe_ready()) {
        echo '<div class="src">クレジットカード決済は準備中です。</div>';
    }
    echo '</div><h2>備考</h2><div class="panel">'
       . '<textarea name="note" rows="4" style="width:100%;padding:9px;border:2px solid #cfdae4;border-radius:8px" '
       . 'placeholder="納期のご希望、見積書・納品書・請求書のご依頼、領収書の宛名・但書きなど"></textarea></div>'
       . '<p><a class="btn gray" href="' . kv_url('cart/customer') . '">戻る</a> '
       . '<button class="btn" type="submit">確認画面へ</button></p></form></div>';
    kv_footer();
}

/**
 * カートの中の、納期が長い商品（メーカーの納期設定 maker_leadtime.json がある商品）。[[商品名, 納期の説明], ...]
 * 確認画面で、納期の了承とカード決済がご注文時に確定することを示すため（2026-11-18 ユーザー指示）。
 */
function kv_cart_leadtime_lines(array $r): array
{
    $out = [];
    foreach ($r['lines'] as $l) {
        $p = $l['product'];
        if (function_exists('kv_maker_leadtime') && ($lt = kv_maker_leadtime($p['maker_id'] ?? 0)) && trim((string)($lt['note'] ?? '')) !== '') {
            $out[] = [(string)$p['name'], (string)$lt['note']];
        }
    }
    return $out;
}

/** STEP 3: 確認 */
function kv_page_confirm(): void
{
    kv_session();
    $r = kv_cart_lines();
    $c = $_SESSION['checkout'] ?? [];
    if (!$r['lines']) { header('Location: ' . kv_url('cart')); return; }
    if (!$c || empty($c['payment'])) { header('Location: ' . kv_url('cart/delivery')); return; }
    if (!in_array($c['payment'], kv_cart_payment_methods($r)['methods'], true)) { header('Location: ' . kv_url('cart/delivery')); return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!kv_csrf_ok()) { http_response_code(400); echo '不正なリクエストです'; return; }
        // カード払いは、ここでは注文を作らない。決済が終わってから注文にする（2026-11-18 ユーザー指示）。
        // カートと入力は決済が終わるまで残す（決済をやめても確認画面からやり直せる）
        if (($c['payment'] ?? '') === 'stripe' && kv_stripe_ready()) {
            try {
                $s = kv_stripe_checkout_pending($r, $c);
                header('Location: ' . $s['url'], true, 303);   // Stripe の決済画面へ
            } catch (Throwable $e) {
                error_log('kvcart checkout: ' . $e->getMessage());
                header('Location: ' . kv_url('cart/confirm') . '?payerror=1');
            }
            return;
        }
        $oid = kv_order_create($r, $c);
        kv_cart_set([]);
        unset($_SESSION['checkout']);
        $_SESSION['last_order'] = $oid;
        $o = kv_order($oid);
        if ($o['payment'] === 'stripe' && function_exists('kv_stripe_checkout')) {
            try {
                $s = kv_stripe_checkout($oid);
                header('Location: ' . $s['url'], true, 303);   // Stripe の決済画面へ
                return;
            } catch (RuntimeException $e) {
                kv_log($oid, 'stripe', '決済画面を作れませんでした: ' . $e->getMessage());
                $_SESSION['pay_error'] = 1;
            }
        }
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
    if (!empty($_GET['canceled'])) {
        echo '<div class="alert">カードのお支払いが完了していないため、ご注文はまだ確定していません。'
           . '内容をご確認のうえ、もう一度「注文する」を押してください。銀行振込に変えることもできます。</div>';
    } elseif (!empty($_GET['payerror'])) {
        echo '<div class="alert">カード決済の画面を開けませんでした。お手数ですが、もう一度お試しいただくか、銀行振込をお選びください。</div>';
    }
    echo '<h2>ご注文商品</h2><div class="scroll"><table class="t">'
       . '<tr><th>商品名</th><th>販売価格</th><th>数量</th><th>小計</th></tr>';
    foreach ($r['lines'] as $l) {
        $p = $l['product'];
        echo '<tr><td>' . kv_e($p['name'])
           . ($l['opt'] ? '<div><b>' . kv_e($l['opt']) . '</b></div>' : '')
           . (($mdl = kv_model_display($p['model_number'], $p['name'])) !== '' ? '<div class="src">[' . kv_e($mdl) . ']</div>' : '') . '</td>'
           . '<td>' . number_format($l['unit']) . '円</td><td>' . $l['qty'] . '</td>'
           . '<td>' . number_format($l['amount']) . '円</td></tr>';
    }
    echo '<tr><th colspan="3">商品合計（税別）</th><td>' . number_format($r['subtotal']) . '円</td></tr>'
       . '<tr><th colspan="3">消費税</th><td>' . number_format($r['tax']) . '円</td></tr>'
       . '<tr><th colspan="3">税込合計</th><td><b>' . number_format($r['total']) . '円</b></td></tr>'
       . '</table></div>' . kv_heavy_note();
    $ltl = kv_cart_leadtime_lines($r);
    $isCard = ($c['payment'] ?? 'bank') === 'stripe';
    // 納期とお支払いの確定（全商品）。直送なのでメーカーの欠品で遅れることがある
    echo '<div class="alert"><b>納期とお支払いの確定について</b>';
    if ($ltl) {
        echo '<ul style="margin:6px 0 6px 18px">';
        foreach ($ltl as [$nm, $note]) { echo '<li>' . kv_e($nm) . '<br><b>納期：' . kv_e($note) . '</b></li>'; }
        echo '</ul>';
    }
    echo '<p style="margin:6px 0">商品はメーカー・仕入先からの直送です。メーカーの欠品などで納期が長くかかる場合は、ご注文のあと納期をメールでお知らせし、ご了承をいただいてから手配します。ご了承いただけない場合はキャンセルできます' . ($isCard ? '（カードは仮売上を取り消すので、ご請求はありません）' : '') . '。</p>'
       . ($isCard
          ? 'クレジットカードは、<b>ご注文の時点ではお支払い枠を確保する仮売上</b>とし、在庫と納期を確かめて商品を手配してから売上を確定します。手配できない場合は仮売上を取り消すので、ご請求はありません。'
          : 'ご入金を確認してからメーカーに手配します。')
       . '</div>';
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
       . (($c['payment'] ?? 'bank') === 'stripe'
          ? '「注文する」を押すと、カードのお支払い画面に進みます。お支払いが済んだ時点で、ご注文と決済が確定します。ご注文後、在庫と納期を確認してご連絡します。'
          : 'ご注文後、在庫と納期を確認し、振込先とあわせてメールでご連絡します。')
       . '送料が必要な地域・商品の場合は、金額を訂正してご連絡します。</p>'
       . '<p><a class="btn gray" href="' . kv_url('cart/delivery') . '">戻る</a> '
       . '<button class="btn" type="submit">注文する</button></p></form></div>';
    kv_footer();
}

/** カード決済をやり直す（決済画面で戻った・失敗したとき）。直前の注文だけ。 */
function kv_page_pay(): void
{
    kv_session();
    $oid = (int)($_SESSION['last_order'] ?? 0);
    $o = $oid ? kv_order($oid) : null;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kv_csrf_ok() || !$o || $o['payment'] !== 'stripe' || (int)$o['f_paid'] === 1) {
        header('Location: ' . kv_url('cart/complete'));
        return;
    }
    try {
        $s = kv_stripe_checkout($oid);
        header('Location: ' . $s['url'], true, 303);
    } catch (RuntimeException $e) {
        kv_log($oid, 'stripe', '決済画面を作れませんでした: ' . $e->getMessage());
        $_SESSION['pay_error'] = 1;
        header('Location: ' . kv_url('cart/complete'));
    }
}

/** STEP 4: 完了 */
function kv_page_complete(): void
{
    kv_session();
    // カード決済から戻ってきたとき: 支払い済みを確かめて注文にする（webhook が先に作っていればその注文）
    if (!empty($_GET['pc']) && preg_match('/^[0-9a-f]{24}$/', (string)$_GET['pc'])) {
        kv_pending_schema();
        $st = kv_db()->prepare('SELECT id FROM pending_checkouts WHERE token=?');
        $st->execute([(string)$_GET['pc']]);
        $pid = (int)$st->fetchColumn();
        $made = 0;
        if ($pid) {
            for ($i = 0; $i < 3 && !$made; $i++) {
                try { $made = kv_pending_finalize($pid); } catch (Throwable $e) { error_log('kvcart complete: ' . $e->getMessage()); }
                if (!$made) { usleep(800000); }
            }
        }
        if ($made) {
            $_SESSION['last_order'] = $made;
            kv_cart_set([]);
            unset($_SESSION['checkout']);
        } else {
            kv_head('お支払いを確認しています', '', '/cart/complete', ['noindex' => true]);
            echo '<div class="wrap"><h1>お支払いを確認しています</h1><div class="panel">カードのお支払いの確認に時間がかかっています。'
               . '確認できしだい、ご注文の受付メールをお送りします。しばらくたっても届かないときは、お手数ですがお問い合わせください。</div></div>';
            kv_footer();
            return;
        }
    }
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
        // Google 広告の購入の計測（shop.google_ads_id と shop.google_ads_purchase_label があるとき）。
        // 金額は税込の注文金額、transaction_id は受注番号（再読み込みしても Google 側で1件に数える）
        $aw = trim((string)kv_shop('google_ads_id')); $lb = trim((string)kv_shop('google_ads_purchase_label'));
        if ($aw !== '' && $lb !== '' && preg_match('/^AW-\d+$/', $aw)) {
            echo '<script>if(window.gtag){gtag("event","conversion",' . json_encode(['send_to' => $aw . '/' . $lb,
                 'value' => (int)$o['total'], 'currency' => 'JPY', 'transaction_id' => (string)$o['code']], JSON_UNESCAPED_SLASHES) . ');}</script>';
        }
        if ($o['payment'] === 'stripe') {
            $o = kv_order((int)$oid);   // webhook で入金が付いていれば反映
            echo '<h2>カードでのお支払い</h2><div class="panel">';
            if ((int)$o['f_paid'] === 1) {
                echo '<b>お支払いを確認しました。</b>ありがとうございます。';
            } elseif (!empty($_GET['canceled']) || !empty($_SESSION['pay_error'])) {
                echo '<b>カードのお支払いが完了していません。</b>ご注文はお受けしています。'
                   . '下のボタンから、もう一度お支払いください。'
                   . '<form method="post" action="' . kv_url('cart/pay') . '" style="margin-top:10px">'
                   . '<input type="hidden" name="csrf" value="' . kv_e(kv_csrf()) . '">'
                   . '<button class="btn" type="submit">カードで支払う</button></form>';
            } else {
                echo 'カードのお支払いを確認しています。確認できしだい、' . kv_e($o['email']) . ' へメールでお知らせします。';
            }
            unset($_SESSION['pay_error']);
            echo '</div>';
        }
        if ($o['payment'] === 'bank') {
            echo '<h2>お支払いについて</h2><div class="panel">'
               . 'メーカーに在庫と納期を確認しています。確認ができしだい、納期とお振込先をメールでお知らせします。<br>'
               . 'お振込みは、その連絡をお待ちいただいてからお願いいたします。'
               . '<p class="src" style="margin-top:10px">ご入金を確認したら発送します。'
               . '振込手数料はお客様のご負担でお願いします。</p></div>';
        }
    }
    echo '<p style="margin-top:20px"><a class="btn" href="' . kv_url('') . '">買い物を続ける</a></p></div>';
    kv_footer();
}
