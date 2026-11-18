<?php
/**
 * Kurage Vibe-Cart — Stripe（カード払いの請求書）。2026-11-15
 *
 * **注文の時点ではカードを引き落とさない。** この店は注文後に在庫・納期・送料を確かめて
 * 金額を訂正することがあるので、確定した金額で Stripe の請求書（Invoicing）を作り、
 * お支払いページのリンクをメールで送る。お客様はリンクからカード等で支払う。
 *
 *   注文（payment=stripe） → 管理画面「カード払いの請求書を送る」 → 請求書を作って確定
 *   → 店からお支払いリンクをメール → 支払い → webhook（invoice.paid）で「入金」チェックが自動で付く
 *
 * - SDK を使わず HTTPS で直接呼ぶ（レンタルサーバーに FTP で置くだけで動かすため。composer 不要）。
 * - 鍵は**制限付きキー（rk_）**を使う。必要な権限: Customers・Invoices・Invoice Items の書き込み。
 * - 支払い方法の種類（payment_method_types）は**指定しない**。Stripe のダッシュボードで選んだものが出る。
 * - 消費税は店の計算（10%）をそのまま明細の1行にする。Stripe Tax（automatic_tax）は使わない
 *   （国内の店で、税額は店が計算済み。二重に掛けないため）。
 */
declare(strict_types=1);

const KV_STRIPE_API = 'https://api.stripe.com/v1';
const KV_STRIPE_VERSION = '2026-10-28.endive';

/** Stripe API を呼ぶ。失敗したら RuntimeException（Stripe のエラー文をそのまま入れる）。 */
function kv_stripe_call(string $method, string $path, array $params = [], string $idem = ''): array
{
    $secret = kv_cfg()['stripe']['secret'];
    if ($secret === '') { throw new RuntimeException('Stripe の鍵が設定されていません'); }
    $url = KV_STRIPE_API . $path;
    $body = http_build_query($params, '', '&');
    if ($method === 'GET' && $body !== '') { $url .= '?' . $body; }
    $h = ['Authorization: Bearer ' . $secret, 'Stripe-Version: ' . KV_STRIPE_VERSION];
    if ($idem !== '') { $h[] = 'Idempotency-Key: ' . $idem; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($method !== 'GET') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { throw new RuntimeException('Stripe に接続できません: ' . $err); }
    $j = json_decode((string)$res, true) ?: [];
    if ($code >= 400) {
        throw new RuntimeException('Stripe のエラー: ' . ($j['error']['message'] ?? ('HTTP ' . $code)));
    }
    return $j;
}

/** 受注に紐づく請求書（無ければ null）。 */
function kv_stripe_invoice_of(array $o): ?array
{
    $id = (string)($o['stripe_id'] ?? '');
    if (strpos($id, 'in_') !== 0) { return null; }
    try { return kv_stripe_call('GET', '/invoices/' . rawurlencode($id)); }
    catch (RuntimeException $e) { return null; }
}

/**
 * 受注から請求書を作って確定し、お支払いリンクをメールで送る。
 * すでに未払いの請求書があり、金額が同じならそれを使い回す（作り直さない）。
 * 金額が変わっていたら古い請求書を無効にして作り直す。
 */
function kv_stripe_send_invoice(int $oid): array
{
    $o = kv_order($oid);
    if (!$o) { throw new RuntimeException('受注が見つかりません'); }
    if ((int)$o['f_paid'] === 1) { throw new RuntimeException('この受注は入金済みです'); }
    if (trim((string)$o['email']) === '') { throw new RuntimeException('お客様のメールアドレスがありません'); }

    $old = kv_stripe_invoice_of($o);
    if ($old && $old['status'] === 'paid') { throw new RuntimeException('この請求書は支払い済みです'); }
    if ($old && $old['status'] === 'open' && (int)$old['amount_due'] === (int)$o['total']) {
        kv_mail_send($oid, 'stripe_invoice');
        kv_log($oid, 'stripe', '同じ金額の請求書のお支払いリンクを再送しました: ' . $old['id']);
        return $old;
    }
    if ($old && in_array($old['status'], ['open', 'draft'], true)) {
        $old['status'] === 'open'
            ? kv_stripe_call('POST', '/invoices/' . rawurlencode($old['id']) . '/void')
            : kv_stripe_call('DELETE', '/invoices/' . rawurlencode($old['id']));
        kv_log($oid, 'stripe', '金額が変わったので前の請求書を取り消しました: ' . $old['id']);
    }

    // 明細（税別の商品・消費税・送料）。合計が受注の合計と一致しなければ作らない
    $lines = [];
    foreach (kv_order_items($oid) as $it) {
        $desc = $it['name'] . (($mdl = kv_model_display($it['model_number'], $it['name'])) !== '' && mb_stripos($it['name'], $mdl) === false ? ' [' . $mdl . ']' : '')
              . ' ' . number_format((int)$it['unit']) . '円 × ' . (int)$it['qty'];
        $lines[] = [mb_strimwidth($desc, 0, 480, '…'), (int)$it['amount']];
    }
    if ((int)$o['tax'] > 0)      { $lines[] = ['消費税（10%）', (int)$o['tax']]; }
    if ((int)$o['shipping'] > 0) { $lines[] = ['送料（税込）', (int)$o['shipping']]; }
    $sum = array_sum(array_column($lines, 1));
    if ($sum !== (int)$o['total']) {
        throw new RuntimeException('明細の合計（' . $sum . '円）が受注の合計（' . (int)$o['total'] . '円）と合いません');
    }

    $key = 'kv-' . $o['code'] . '-' . $sum . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $cus = kv_stripe_call('POST', '/customers', [
        'email' => $o['email'],
        'name' => trim(($o['company'] ? $o['company'] . ' ' : '') . $o['name']),
        'metadata' => ['kv_order' => $o['code']],
    ], $key . '-cus');
    $inv = kv_stripe_call('POST', '/invoices', [
        'customer' => $cus['id'],
        'collection_method' => 'send_invoice',
        'days_until_due' => 7,
        'currency' => 'jpy',
        'pending_invoice_items_behavior' => 'exclude',
        'description' => kv_shop('name') . ' ご注文 ' . $o['code'],
        'metadata' => ['kv_order_id' => (string)$oid, 'kv_order' => $o['code']],
        'payment_settings' => ['payment_method_options' => ['card' => ['request_three_d_secure' => 'any']]],
    ], $key . '-inv');
    // 請求書の明細は後から足したものが上に出るので、逆順に足して「商品 → 消費税 → 送料」の順に見せる
    foreach (array_reverse($lines, true) as $i => [$desc, $amount]) {
        kv_stripe_call('POST', '/invoiceitems', [
            'customer' => $cus['id'], 'invoice' => $inv['id'],
            'currency' => 'jpy', 'amount' => $amount, 'description' => $desc,
        ], $key . '-item' . $i);
    }
    $inv = kv_stripe_call('POST', '/invoices/' . rawurlencode($inv['id']) . '/finalize', [], $key . '-fin');
    if ((int)$inv['amount_due'] !== (int)$o['total']) {
        throw new RuntimeException('Stripe の請求額（' . $inv['amount_due'] . '円）が受注の合計と合いません');
    }
    kv_db()->prepare('UPDATE orders SET stripe_id=? WHERE id=?')->execute([$inv['id'], $oid]);
    kv_log($oid, 'stripe', '請求書を作りました: ' . $inv['id'] . ' ' . number_format((int)$inv['amount_due']) . '円');
    kv_mail_send($oid, 'stripe_invoice');
    return $inv;
}

/**
 * 注文の確定と同時にカードで払ってもらう（Stripe Checkout）。2026-11-16 ユーザー指示で請求書方式から切り替え。
 * キャンセル・減額は Stripe のダッシュボードで返金（全額・一部）、増額は kv_stripe_send_invoice() で差額を請求する。
 * 支払い方法の種類は指定しない（ダッシュボードの設定に任せる）。入金の反映は webhook だけで行う。
 */
function kv_stripe_checkout(int $oid): array
{
    $o = kv_order($oid);
    if (!$o) { throw new RuntimeException('受注が見つかりません'); }
    if ((int)$o['f_paid'] === 1) { throw new RuntimeException('この受注はお支払い済みです'); }
    $lines = [];
    foreach (kv_order_items($oid) as $it) {
        $lines[] = ['price_data' => ['currency' => 'jpy', 'unit_amount' => (int)$it['unit'],
                    'product_data' => ['name' => mb_strimwidth($it['name'] . (($mdl = kv_model_display($it['model_number'], $it['name'])) !== '' && mb_stripos($it['name'], $mdl) === false ? ' [' . $mdl . ']' : ''), 0, 240, '…')]],
                    'quantity' => (int)$it['qty']];
    }
    if ((int)$o['tax'] > 0) {
        $lines[] = ['price_data' => ['currency' => 'jpy', 'unit_amount' => (int)$o['tax'], 'product_data' => ['name' => '消費税（10%）']], 'quantity' => 1];
    }
    if ((int)$o['shipping'] > 0) {
        $lines[] = ['price_data' => ['currency' => 'jpy', 'unit_amount' => (int)$o['shipping'], 'product_data' => ['name' => '送料（税込）']], 'quantity' => 1];
    }
    $sum = 0;
    foreach ($lines as $l) { $sum += $l['price_data']['unit_amount'] * $l['quantity']; }
    if ($sum !== (int)$o['total']) {
        throw new RuntimeException('明細の合計（' . $sum . '円）が受注の合計（' . (int)$o['total'] . '円）と合いません');
    }
    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'www.exdirect.net') . KV_BASE;
    $letters = '';
    for ($i = 0; $i < 8; $i++) { $letters .= chr(97 + random_int(0, 25)); }
    $s = kv_stripe_call('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'line_items' => $lines,
        'customer_email' => $o['email'],
        'client_reference_id' => (string)$oid,
        'metadata' => ['kv_order_id' => (string)$oid, 'kv_order' => $o['code']],
        'payment_intent_data' => ['metadata' => ['kv_order_id' => (string)$oid, 'kv_order' => $o['code']],
                                  'description' => kv_shop('name') . ' ご注文 ' . $o['code']],
        // 本人認証（3D セキュア）を毎回求める（2026-11-17 ユーザー承認）。不正カードの負担をカード会社側に移す
        'payment_method_options' => ['card' => ['request_three_d_secure' => 'any']],
        'success_url' => $base . '/cart/complete',
        'cancel_url' => $base . '/cart/complete?canceled=1',
        'integration_identifier' => 'kvcart-checkout-' . $letters,
    ], 'kv-cs-' . $o['code'] . '-' . bin2hex(random_bytes(4)));
    kv_db()->prepare('UPDATE orders SET stripe_id=? WHERE id=?')->execute([$s['id'], $oid]);
    kv_log($oid, 'stripe', 'カード決済の画面を作りました: ' . $s['id'] . ' ' . number_format((int)$o['total']) . '円');
    return $s;
}

/** お支払いページの URL（メールの {pay_url}）。 */
function kv_stripe_pay_url(array $o): string
{
    $inv = kv_stripe_invoice_of($o);
    return $inv['hosted_invoice_url'] ?? '';
}

/** webhook の署名を確かめる（Stripe-Signature: t=…,v1=…）。5分より古いものは受けない。 */
function kv_stripe_verify(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    if ($secret === '' || $header === '') { return false; }
    $t = 0; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') { $t = (int)$v; }
        if ($k === 'v1') { $sigs[] = $v; }
    }
    if ($t === 0 || !$sigs || abs(time() - $t) > $tolerance) { return false; }
    $expect = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) { if (hash_equals($expect, $s)) { return true; } }
    return false;
}

/** webhook のイベントを処理する。入金の反映はここだけで行う（お客様の戻りページに頼らない）。 */
/**
 * 決済の危険度を注文の記録に残す（2026-11-17 ユーザー承認）。Radar の危険度・カードの発行国・本人認証の結果。
 * 取れなくても決済の処理は止めない。返り値は店への通知に添える1行。
 */
function kv_stripe_risk_note(int $oid, string $pi): string
{
    if ($pi === '') { return ''; }
    try {
        $p = kv_stripe_call('GET', '/payment_intents/' . rawurlencode($pi), ['expand' => ['latest_charge']]);
        $ch = is_array($p['latest_charge'] ?? null) ? $p['latest_charge'] : [];
        $out = $ch['outcome'] ?? [];
        $card = $ch['payment_method_details']['card'] ?? [];
        $tds = $card['three_d_secure']['result'] ?? '';
        $level = (string)($out['risk_level'] ?? '');
        $ja = ['normal' => '通常', 'elevated' => '高め（要確認）', 'highest' => '最も高い（発送前に必ず確認）'][$level] ?? ($level !== '' ? $level : '不明');
        $line = '不正の危険度: ' . $ja
              . ' / カード発行国: ' . ((string)($card['country'] ?? '') ?: '不明')
              . ' / 本人認証: ' . ($tds === 'authenticated' ? '済み' : ($tds !== '' ? $tds : 'なし'))
              . ' / CVC: ' . ((string)($card['checks']['cvc_check'] ?? '') ?: '不明');
        kv_log($oid, 'stripe', $line);
        return $line;
    } catch (Throwable $e) {
        kv_log($oid, 'stripe', '危険度を取れませんでした: ' . $e->getMessage());
        return '';
    }
}

function kv_stripe_handle(array $ev): string
{
    $obj = $ev['data']['object'] ?? [];
    // 決済が終わってから注文を登録する形（2026-11-18〜）の決済画面
    if (!empty($obj['metadata']['kv_pending_id'])) {
        if (in_array($ev['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $oid = kv_pending_finalize((int)$obj['metadata']['kv_pending_id'], $obj);
            return $oid ? 'paid: order ' . $oid : 'pending: not paid';
        }
        return 'ignored: pending ' . ($ev['type'] ?? '');
    }
    $oid = (int)($obj['metadata']['kv_order_id'] ?? 0);
    $o = $oid ? kv_order($oid) : null;
    if (!$o) { return 'ignored: no order'; }
    // 作り直して取り消した古い請求書・やり直す前の決済画面のイベントは無視する
    if (($obj['id'] ?? '') !== (string)$o['stripe_id']) { return 'ignored: old object'; }
    switch ($ev['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            // 後払い型の決済方法では completed の時点でまだ未払いのことがある。払い済みのときだけ入金にする
            if (!in_array($obj['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
                kv_log($oid, 'stripe', '決済の手続きは済みましたが、入金はまだです: ' . $obj['id']);
                return 'completed but unpaid';
            }
            if ((int)$o['f_paid'] === 1) { return 'already paid'; }
            kv_log($oid, 'stripe', 'カードでのお支払いを確認しました: ' . $obj['id'] . ' / ' . ($obj['payment_intent'] ?? '')
                   . ' ' . number_format((int)($obj['amount_total'] ?? 0)) . '円');
            // 注文時のカード決済は、ここで初めて受付メールを送る（ご注文とお支払いの確認を1通で）。店にも通知する
            $GLOBALS['kv_risk_note'] = kv_stripe_risk_note($oid, (string)($obj['payment_intent'] ?? ''));
            kv_order_flag($oid, 'paid', true, false);
            kv_mail_send($oid, 'order_paid_card');
            return 'paid';
        case 'checkout.session.async_payment_failed':
            kv_log($oid, 'stripe', 'お支払いが失敗しました: ' . $obj['id']);
            return 'failed logged';
        case 'invoice.paid':
            if ((int)$o['f_paid'] === 1) { return 'already paid'; }
            kv_log($oid, 'stripe', 'カードでのお支払いを確認しました: ' . $obj['id']
                   . ' ' . number_format((int)($obj['amount_paid'] ?? 0)) . '円');
            kv_stripe_risk_note($oid, is_string($obj['payment_intent'] ?? null) ? $obj['payment_intent'] : '');
            kv_order_flag($oid, 'paid', true, true);   // 「入金」チェック＋入金確認メール
            return 'paid';
        case 'invoice.payment_failed':
            kv_log($oid, 'stripe', 'お支払いが失敗しました: ' . $obj['id']);
            return 'failed logged';
        case 'invoice.voided':
            kv_log($oid, 'stripe', '請求書が取り消されました: ' . $obj['id']);
            return 'voided logged';
    }
    return 'ignored: ' . ($ev['type'] ?? '');
}

/*
 * カード払いは、決済が終わってから注文を登録する（2026-11-18 ユーザー指示）。
 * 「注文する」を押したら、カートとお客様の入力を pending_checkouts（決済待ち）に置いて Stripe の決済画面へ移す。
 * 決済の完了（webhook の checkout.session.completed か、お客様が完了ページに戻ったとき）で Stripe に支払い済みを
 * 確かめてから kv_order_create する。決済をやめた・失敗した注文は受注一覧に出ない。
 */
function kv_pending_schema(): void
{
    kv_db()->exec('CREATE TABLE IF NOT EXISTS pending_checkouts (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE,
        created_at TEXT, session_id TEXT, cart TEXT, post TEXT, total INTEGER, ip TEXT, status TEXT DEFAULT "open", order_id INTEGER)');
}

function kv_stripe_checkout_pending(array $cart, array $post): array
{
    kv_pending_schema();
    $lines = [];
    foreach ($cart['lines'] as $l) {
        $p = $l['product'];
        $nm = $p['name'] . (!empty($l['opt']) ? '（' . $l['opt'] . '）' : '');
        $mdl = kv_model_display($p['model_number'], $p['name']);
        if ($mdl !== '' && mb_stripos($nm, $mdl) === false) { $nm .= ' [' . $mdl . ']'; }
        $lines[] = ['price_data' => ['currency' => 'jpy', 'unit_amount' => (int)$l['unit'], 'product_data' => ['name' => mb_strimwidth($nm, 0, 240, '…')]],
                    'quantity' => (int)$l['qty']];
    }
    if ((int)$cart['tax'] > 0) {
        $lines[] = ['price_data' => ['currency' => 'jpy', 'unit_amount' => (int)$cart['tax'], 'product_data' => ['name' => '消費税']], 'quantity' => 1];
    }
    $sum = 0;
    foreach ($lines as $l) { $sum += $l['price_data']['unit_amount'] * $l['quantity']; }
    if ($sum !== (int)$cart['total']) {
        throw new RuntimeException('明細の合計（' . $sum . '円）がご注文の合計（' . (int)$cart['total'] . '円）と合いません');
    }
    $token = bin2hex(random_bytes(12));
    $db = kv_db();
    $db->prepare('INSERT INTO pending_checkouts (token,created_at,cart,post,total,ip) VALUES (?,?,?,?,?,?)')
       ->execute([$token, date('Y-m-d H:i:s'), json_encode($cart, JSON_UNESCAPED_UNICODE), json_encode($post, JSON_UNESCAPED_UNICODE),
                  (int)$cart['total'], (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    $pid = (int)$db->lastInsertId();
    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'www.exdirect.net') . KV_BASE;
    $letters = '';
    for ($i = 0; $i < 8; $i++) { $letters .= chr(97 + random_int(0, 25)); }
    $s = kv_stripe_call('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'line_items' => $lines,
        'customer_email' => (string)($post['email'] ?? ''),
        'client_reference_id' => 'p' . $pid,
        'metadata' => ['kv_pending_id' => (string)$pid],
        'payment_intent_data' => ['metadata' => ['kv_pending_id' => (string)$pid], 'description' => kv_shop('name') . ' ご注文'],
        'payment_method_options' => ['card' => ['request_three_d_secure' => 'any']],
        'success_url' => $base . '/cart/complete?pc=' . $token,
        'cancel_url' => $base . '/cart/confirm?canceled=1',
        'integration_identifier' => 'kvcart-checkout-' . $letters,
    ], 'kv-pc-' . $token);
    $db->prepare('UPDATE pending_checkouts SET session_id=? WHERE id=?')->execute([$s['id'], $pid]);
    return $s;
}

/**
 * 決済待ちを注文にする。Stripe に支払い済み・金額一致を確かめてから kv_order_create する。
 * webhook と完了ページの両方から呼ばれても注文は1件だけ（status を open→finalizing に取れた方だけが作る）。
 * 返り値: 注文の id（まだ払われていない・ほかが作成中なら 0）
 */
function kv_pending_finalize(int $pid, ?array $session = null): int
{
    kv_pending_schema();
    $db = kv_db();
    $get = function () use ($db, $pid) { $st = $db->prepare('SELECT * FROM pending_checkouts WHERE id=?'); $st->execute([$pid]); return $st->fetch() ?: null; };
    $pc = $get();
    if (!$pc) { return 0; }
    if ((int)$pc['order_id'] > 0) { return (int)$pc['order_id']; }
    if ((string)$pc['session_id'] === '') { return 0; }
    // 通知の中身は信じず、Stripe に問い合わせて確かめる
    $s = kv_stripe_call('GET', '/checkout/sessions/' . rawurlencode((string)$pc['session_id']));
    if (!in_array($s['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) { return 0; }
    if ((int)($s['amount_total'] ?? -1) !== (int)$pc['total']) {
        error_log('kvcart pending ' . $pid . ': 金額が合わない ' . ($s['amount_total'] ?? '') . ' / ' . $pc['total']);
        return 0;
    }
    $u = $db->prepare("UPDATE pending_checkouts SET status='finalizing' WHERE id=? AND status='open'");
    $u->execute([$pid]);
    if ($u->rowCount() !== 1) { $pc = $get(); return (int)($pc['order_id'] ?? 0); }
    $cart = json_decode((string)$pc['cart'], true) ?: [];
    $post = json_decode((string)$pc['post'], true) ?: [];
    $post['payment'] = 'stripe';
    $post['_card_paid'] = 1;   // 決済済みの確定なので、設定に関係なくカード払いの注文として作る（受付メールは下で order_paid_card）
    $ipBack = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = (string)$pc['ip'];   // ブラックリストの照合はお客様の IP で
    try {
        $oid = kv_order_create($cart, $post);
    } finally {
        if ($ipBack === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $ipBack; }
    }
    $db->prepare('UPDATE orders SET stripe_id=? WHERE id=?')->execute([(string)$pc['session_id'], $oid]);
    $db->prepare("UPDATE pending_checkouts SET status='done', order_id=? WHERE id=?")->execute([$oid, $pid]);
    kv_log($oid, 'stripe', 'カードでのお支払いを確認してから注文を登録しました: ' . $pc['session_id'] . ' / ' . ($s['payment_intent'] ?? '')
           . ' ' . number_format((int)$s['amount_total']) . '円');
    $GLOBALS['kv_risk_note'] = kv_stripe_risk_note($oid, (string)($s['payment_intent'] ?? ''));
    kv_order_flag($oid, 'paid', true, false);
    kv_mail_send($oid, 'order_paid_card');
    return $oid;
}
