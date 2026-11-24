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

/** Stripe のエラー。$err に Stripe の error（code・decline_code・payment_intent など）をそのまま持つ。 */
class KvStripeError extends RuntimeException
{
    public array $err = [];
}

/** Stripe API を呼ぶ。失敗したら KvStripeError（RuntimeException。Stripe のエラー文をそのまま入れる）。 */
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
        $e = new KvStripeError('Stripe のエラー: ' . ($j['error']['message'] ?? ('HTTP ' . $code)));
        $e->err = is_array($j['error'] ?? null) ? $j['error'] : [];
        throw $e;
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
    if ((int)($o['discount'] ?? 0) > 0) { $lines[] = ['値引き', -(int)$o['discount']]; }
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

/*
 * カードを預かる（2026-11-24 ユーザー指示「カードを預かる設定にして」）。
 * 決済画面（Checkout）で Customer を作り、payment_intent_data.setup_future_usage=off_session でカードを Customer に保存する。
 * カード番号は Stripe が保管し、店は customer（cus_）と payment_method（pm_）の ID だけを受注に持つ。
 * これで、仮売上の期限が切れたあとや送料の追加で金額が上がったときに、受注画面から店側で決済し直せる（kv_stripe_reauth）。
 * ゼウスでカードを預かっていたときと同じ運用。決済画面の「お支払い」ボタンの横に、保管と店側での決済があることを出す。
 */
const KV_STRIPE_SAVE_NOTE = 'ご注文の時点では仮売上（お支払い枠の確保）とし、在庫と納期を確かめてから売上を確定します。'
    . 'カード情報は決済代行会社の Stripe が保管し、当店はカード番号を持ちません。'
    . '送料の追加などで金額を訂正するときや、手配に時間がかかり仮売上の期限が切れたときは、'
    . '金額をご連絡したうえで、このカードで当店からお支払いを処理する場合があります。';

/** 決済画面でカードを預かるための指定。受注にお客様（cus_）があればそれに紐づけ、無ければ Customer を作る。 */
function kv_stripe_save_card_params(string $customer = ''): array
{
    return ($customer !== '' ? ['customer' => $customer] : ['customer_creation' => 'always'])
         + ['custom_text' => ['submit' => ['message' => KV_STRIPE_SAVE_NOTE]]];
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
    // 値引きは Checkout の明細に負の金額を書けないので、1回限りのクーポンにする
    $disc = (int)($o['discount'] ?? 0);
    $discounts = [];
    if ($disc > 0) {
        $cp = kv_stripe_call('POST', '/coupons', ['amount_off' => $disc, 'currency' => 'jpy', 'duration' => 'once',
                             'name' => '値引き（' . $o['code'] . '）', 'max_redemptions' => 1], 'kv-cp-' . $o['code'] . '-' . $disc);
        $discounts = [['coupon' => $cp['id']]];
        $sum -= $disc;
    }
    if ($sum !== (int)$o['total']) {
        throw new RuntimeException('明細の合計（' . $sum . '円）が受注の合計（' . (int)$o['total'] . '円）と合いません');
    }
    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'www.exdirect.net') . KV_BASE;
    $letters = '';
    for ($i = 0; $i < 8; $i++) { $letters .= chr(97 + random_int(0, 25)); }
    $s = kv_stripe_call('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'line_items' => $lines,
        'discounts' => $discounts,
        'client_reference_id' => (string)$oid,
        'metadata' => ['kv_order_id' => (string)$oid, 'kv_order' => $o['code']],
        'payment_intent_data' => ['metadata' => ['kv_order_id' => (string)$oid, 'kv_order' => $o['code']],
                                  'description' => kv_shop('name') . ' ご注文 ' . $o['code'],
                                  // 仮売上（与信）にして、在庫を確かめてから受注画面で確定する（2026-11-19 ユーザー指示）
                                  'capture_method' => 'manual',
                                  // カードを預かる（2026-11-24）。あとで店側から off_session で決済し直せるようにする
                                  'setup_future_usage' => 'off_session'],
        // 本人認証（3D セキュア）を毎回求める（2026-11-17 ユーザー承認）。不正カードの負担をカード会社側に移す
        'payment_method_options' => ['card' => ['request_three_d_secure' => 'any']],
        'success_url' => $base . '/cart/complete',
        'cancel_url' => $base . '/cart/complete?canceled=1',
        'integration_identifier' => 'kvcart-checkout-' . $letters,
    ] + ((string)($o['stripe_customer'] ?? '') !== '' ? [] : ['customer_email' => $o['email']])
      + kv_stripe_save_card_params((string)($o['stripe_customer'] ?? '')), 'kv-cs-' . $o['code'] . '-' . bin2hex(random_bytes(4)));
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
                // 仮売上（capture_method=manual）は payment_status が unpaid のまま完了する
                if (($o['auth_status'] ?? '') === '' && ($pi = kv_stripe_authorized($obj, (int)$o['total']))) {
                    $GLOBALS['kv_risk_note'] = kv_stripe_risk_note($oid, (string)$pi['id']);
                    kv_auth_record($oid, $pi);
                    kv_mail_send($oid, 'order_paid_card');
                    return 'authorized';
                }
                kv_log($oid, 'stripe', '決済の手続きは済みましたが、入金はまだです: ' . $obj['id']);
                return 'completed but unpaid';
            }
            if ((int)$o['f_paid'] === 1) { return 'already paid'; }
            kv_log($oid, 'stripe', 'カードでのお支払いを確認しました: ' . $obj['id'] . ' / ' . ($obj['payment_intent'] ?? '')
                   . ' ' . number_format((int)($obj['amount_total'] ?? 0)) . '円');
            // 注文時のカード決済は、ここで初めて受付メールを送る（ご注文とお支払いの確認を1通で）。店にも通知する
            $GLOBALS['kv_risk_note'] = kv_stripe_risk_note($oid, (string)($obj['payment_intent'] ?? ''));
            kv_stripe_save_card_of_pi($oid, (string)($obj['payment_intent'] ?? ''));
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
 * カードの仮売上（2026-11-19）。決済画面は capture_method=manual で作るので、お客様が払い終えても
 * PaymentIntent は requires_capture（仮売上）のまま。受注画面の「売上を確定」で capture、「仮売上を取り消す」か
 * 受注のキャンセルで cancel する。仮売上の期限は決済ごとに違う（capture_before）ので、受注一覧に何日目かと期限を出す。
 */

/** 決済画面が終わり、仮売上になっていれば PaymentIntent を返す（金額 $expect と一致するときだけ）。 */
function kv_stripe_authorized(array $session, int $expect): ?array
{
    if (($session['status'] ?? '') !== 'complete' || empty($session['payment_intent'])) { return null; }
    $pi = kv_stripe_call('GET', '/payment_intents/' . rawurlencode(is_array($session['payment_intent']) ? $session['payment_intent']['id'] : (string)$session['payment_intent']),
                         ['expand' => ['latest_charge']]);
    if (($pi['status'] ?? '') !== 'requires_capture' || (int)($pi['amount_capturable'] ?? -1) !== $expect) { return null; }
    return $pi;
}

/**
 * PaymentIntent（latest_charge を expand 済みでも ID のままでもよい）から、預かったカードと仮売上の期限を取り出す。
 * 返り値: ['customer' => cus_…, 'pm' => pm_…, 'capture_before' => 'Y-m-d H:i:s'（無ければ ''）]
 * 仮売上の期限はカードの種類・カード会社で違う（Visa の 023786 は30日）ので、決済ごとに
 * latest_charge.payment_method_details.card.capture_before を見る（2026-11-24）。
 */
function kv_stripe_card_of(array $pi): array
{
    $ch = $pi['latest_charge'] ?? null;
    if (is_string($ch) && $ch !== '') {
        try { $ch = kv_stripe_call('GET', '/charges/' . rawurlencode($ch)); } catch (RuntimeException $e) { $ch = null; }
    }
    $cb = is_array($ch) ? (int)($ch['payment_method_details']['card']['capture_before'] ?? 0) : 0;
    $id = fn($v) => is_array($v) ? (string)($v['id'] ?? '') : (string)$v;
    return ['customer' => $id($pi['customer'] ?? ''), 'pm' => $id($pi['payment_method'] ?? ''),
            'capture_before' => $cb > 0 ? date('Y-m-d H:i:s', $cb) : ''];
}

/** 預かったカード（customer・payment_method）を受注に保存する。空のときは上書きしない。 */
function kv_stripe_save_card(int $oid, array $card): void
{
    if ($card['customer'] === '' || $card['pm'] === '') { return; }
    kv_db()->prepare('UPDATE orders SET stripe_customer=?, stripe_pm=? WHERE id=?')->execute([$card['customer'], $card['pm'], $oid]);
    kv_log($oid, 'stripe', 'カードを預かりました（Stripe が保管）: ' . $card['customer'] . ' / ' . $card['pm']);
}

/** 受注に仮売上を記録する。預かったカードと仮売上の期限（capture_before）も保存する。 */
function kv_auth_record(int $oid, array $pi): void
{
    $card = kv_stripe_card_of($pi);
    kv_db()->prepare("UPDATE orders SET auth_status='authorized', auth_pi=?, auth_amount=?, auth_at=?, auth_done_at=NULL, auth_capture_before=? WHERE id=?")
           ->execute([(string)$pi['id'], (int)$pi['amount_capturable'], date('Y-m-d H:i:s'), $card['capture_before'], $oid]);
    kv_log($oid, 'stripe', 'カードの仮売上（与信）を確認しました: ' . $pi['id'] . ' ' . number_format((int)$pi['amount_capturable']) . '円'
           . ($card['capture_before'] !== '' ? '（確定の期限 ' . substr($card['capture_before'], 0, 16) . '）' : ''));
    kv_stripe_save_card($oid, $card);
}

/** 仮売上から何日目か（仮売上の当日を1日目）。仮売上中でなければ 0。 */
function kv_auth_day(array $o): int
{
    if (($o['auth_status'] ?? '') !== 'authorized' || empty($o['auth_at'])) { return 0; }
    return (int)floor((strtotime(date('Y-m-d')) - strtotime(substr((string)$o['auth_at'], 0, 10))) / 86400) + 1;
}

/**
 * 仮売上の確定の期限（'Y-m-d H:i:s'）。受注に無ければ（2026-11-24 より前の仮売上）Stripe から読んで保存する（GET だけ）。
 * 読めなければ ''。
 */
function kv_auth_capture_before(array $o): string
{
    $cb = (string)($o['auth_capture_before'] ?? '');
    if ($cb !== '' || ($o['auth_status'] ?? '') !== 'authorized' || empty($o['auth_pi']) || !kv_stripe_ready()) { return $cb; }
    // 1画面で何度も呼ばれる（残り日数と札）。2回目に '' を返すと「期限切れ」と出たので、読んだ値を覚えて返す（2026-11-24）
    static $seen = [];
    if (array_key_exists($o['id'], $seen)) { return $seen[$o['id']]; }
    $seen[$o['id']] = '';
    try {
        $card = kv_stripe_card_of(kv_stripe_call('GET', '/payment_intents/' . rawurlencode((string)$o['auth_pi']), ['expand' => ['latest_charge']]));
    } catch (RuntimeException $e) { return ''; }
    if ($card['capture_before'] !== '') {
        kv_db()->prepare('UPDATE orders SET auth_capture_before=? WHERE id=?')->execute([$card['capture_before'], (int)$o['id']]);
    }
    return $seen[$o['id']] = $card['capture_before'];
}

/** 仮売上の確定の期限まで残り何日か（今日が期限の日なら 0、過ぎていれば負）。期限が分からなければ null。 */
function kv_auth_left(array $o): ?int
{
    $cb = kv_auth_capture_before($o);
    if ($cb === '') { return null; }
    return (int)floor((strtotime(substr($cb, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
}

/**
 * 受注一覧・詳細に出す仮売上の札。期限は決済ごとの capture_before（Visa なら30日など）で、残り3日から赤。
 * 期限が読めないときだけ、一般的な7日を目安にする（6日目から赤）。
 */
function kv_auth_badge(array $o): string
{
    $st = (string)($o['auth_status'] ?? '');
    if ($st === '') { return ''; }
    if ($st === 'authorized') {
        $d = kv_auth_day($o);
        $left = kv_auth_left($o);
        $cb = kv_auth_capture_before($o);
        if ($left !== null && $cb !== '' && strtotime($cb) < time()) {
            return '<div><span class="tag" style="background:#fde8e7;color:#b3261e" title="確定の期限 ' . kv_e(substr($cb, 0, 16))
                 . ' を過ぎました">仮売上の期限切れ</span></div>';
        }
        $red = $left !== null ? $left <= 3 : $d >= 6;
        $tip = '仮売上 ' . substr((string)$o['auth_at'], 0, 16) . '・'
             . ($left !== null ? '確定の期限 ' . substr($cb, 0, 16) : '期限を読めないので7日を目安にしています');
        return '<div><span class="tag" style="background:' . ($red ? '#fde8e7;color:#b3261e' : '#fff4e0;color:#8a5300') . '" title="' . kv_e($tip) . '">仮売上 ' . $d . '日目'
             . ($left !== null ? '・期限 ' . kv_e(date('n/j', strtotime($cb))) . '（あと' . $left . '日）' : '') . '</span></div>';
    }
    $lab = ['captured' => ['売上確定', '#e9f7ef;color:#14632f'], 'canceled' => ['仮売上取消', '#eef0f2;color:#52636b'], 'expired' => ['仮売上切れ', '#fde8e7;color:#b3261e']][$st] ?? [$st, '#eef0f2;color:#52636b'];
    return '<div><span class="tag" style="background:' . $lab[1] . '">' . $lab[0] . '</span></div>';
}

/**
 * 売上確定の日時（受注一覧の合計の下に出す）。
 * - カードで仮売上を確定した注文: 確定した日時
 * - 銀行振込: 入金を確認した日時（入金を確かめて手配した日が売上確定日・2026-11-19 ユーザー指示）
 * - 仮売上の仕組みより前（2026-11-19 まで）の注文時に確定していたカード注文: 入金の日時
 * 仮売上中・取消・期限切れ、入金前は ''。
 */
function kv_capture_at(array $o): string
{
    $st = (string)($o['auth_status'] ?? '');
    if ($st === 'captured') { return (string)($o['auth_done_at'] ?? ''); }
    if ($st !== '') { return ''; }
    return (int)($o['f_paid'] ?? 0) === 1 ? (string)($o['paid_at'] ?? '') : '';
}

/** 売上を確定する。金額は今の受注の合計（値引きで減っていれば減らして確定。仮売上の額を超える増額はできない）。 */
function kv_stripe_capture(int $oid): void
{
    $o = kv_order($oid);
    if (!$o || ($o['auth_status'] ?? '') !== 'authorized') { throw new RuntimeException('仮売上中ではありません'); }
    $amt = (int)$o['total'];
    if ($amt > (int)$o['auth_amount']) {
        throw new RuntimeException('受注の合計（' . number_format($amt) . '円）が仮売上の額（' . number_format((int)$o['auth_amount']) . '円）を超えています。超える分は確定できません');
    }
    $pi = kv_stripe_call('POST', '/payment_intents/' . rawurlencode((string)$o['auth_pi']) . '/capture', ['amount_to_capture' => $amt], 'kv-cap-' . $o['auth_pi'] . '-' . $amt);   // 再決済で仮売上が変わるので PaymentIntent ごとの鍵
    if (($pi['status'] ?? '') !== 'succeeded') { throw new RuntimeException('確定できませんでした（' . ($pi['status'] ?? '?') . '）'); }
    kv_db()->prepare("UPDATE orders SET auth_status='captured', auth_done_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $oid]);
    kv_log($oid, 'stripe', '売上を確定しました: ' . $o['auth_pi'] . ' ' . number_format($amt) . '円');
    kv_order_flag($oid, 'paid', true, false);
}

/** 仮売上を取り消す（手数料はかからない）。 */
function kv_stripe_cancel_auth(int $oid, string $why = ''): void
{
    $o = kv_order($oid);
    if (!$o || ($o['auth_status'] ?? '') !== 'authorized') { throw new RuntimeException('仮売上中ではありません'); }
    $pi = kv_stripe_call('POST', '/payment_intents/' . rawurlencode((string)$o['auth_pi']) . '/cancel', ['cancellation_reason' => 'requested_by_customer'], 'kv-cxl-' . $o['auth_pi']);
    if (($pi['status'] ?? '') !== 'canceled') { throw new RuntimeException('取り消せませんでした（' . ($pi['status'] ?? '?') . '）'); }
    kv_db()->prepare("UPDATE orders SET auth_status='canceled', auth_done_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $oid]);
    kv_log($oid, 'stripe', '仮売上を取り消しました' . ($why !== '' ? '（' . $why . '）' : '') . ': ' . $o['auth_pi']);
}

/** 即時売上の決済（仮売上でないもの）でも、預かったカードを受注に保存する。取れなくても決済の処理は止めない。 */
function kv_stripe_save_card_of_pi(int $oid, string $pi): void
{
    if ($pi === '') { return; }
    try { kv_stripe_save_card($oid, kv_stripe_card_of(kv_stripe_call('GET', '/payment_intents/' . rawurlencode($pi), ['expand' => ['latest_charge']]))); }
    catch (Throwable $e) { kv_log($oid, 'stripe', '預かったカードを記録できませんでした: ' . $e->getMessage()); }
}

/** 預かったカードの表示（例: 「visa 4242（12/2030）」）。読めなければ ''。 */
function kv_stripe_card_label(array $o): string
{
    $pm = (string)($o['stripe_pm'] ?? '');
    if ($pm === '') { return ''; }
    try { $p = kv_stripe_call('GET', '/payment_methods/' . rawurlencode($pm)); } catch (RuntimeException $e) { return ''; }
    $c = $p['card'] ?? [];
    if (!$c) { return (string)($p['type'] ?? ''); }
    return (string)($c['brand'] ?? '') . ' ' . (string)($c['last4'] ?? '') . '（' . (int)($c['exp_month'] ?? 0) . '/' . (int)($c['exp_year'] ?? 0) . '）';
}

/**
 * 預かったカードで、店側から決済し直す（2026-11-24）。仮売上（capture_method=manual）で取り、あとは今までどおり
 * 受注画面の「売上を確定」で確定する。お客様はその場にいないので off_session で決済する。
 *   $amount: 決済する金額（円）。確定は受注の合計で行うので、ふつうは送料などを保存したあとの受注の合計。
 *   $old:    前の仮売上が残っているとき 'after'（新しい仮売上が取れてから取り消す・おすすめ）/ 'before'（先に取り消す。
 *            カードの利用枠に二重に取る余裕がないとき）
 * 返り値: ['ok' => bool, 'code' => Stripe のエラーコード（authentication_required など）, 'msg' => 画面に出す文]
 * 3D セキュアを求められた（authentication_required）ときは、お客様に支払いページ（請求書）を送る流れに切り替える。
 */
function kv_stripe_reauth(int $oid, int $amount, string $old = 'after'): array
{
    $o = kv_order($oid);
    if (!$o) { throw new RuntimeException('受注が見つかりません'); }
    if ((string)($o['stripe_customer'] ?? '') === '' || (string)($o['stripe_pm'] ?? '') === '') {
        throw new RuntimeException('この受注にはカードを預かっていません（2026-11-24 より前の決済か、カード以外の決済です）');
    }
    if (($o['auth_status'] ?? '') === 'captured' || (int)$o['f_paid'] === 1) { throw new RuntimeException('この受注は売上を確定済み（入金済み）です'); }
    if ($amount <= 0) { throw new RuntimeException('金額を入れてください'); }

    // 前の仮売上がまだ Stripe で生きているか確かめる（期限が過ぎていれば Stripe が取り消している）
    $prev = '';
    if (($o['auth_status'] ?? '') === 'authorized' && (string)$o['auth_pi'] !== '') {
        $pp = kv_stripe_call('GET', '/payment_intents/' . rawurlencode((string)$o['auth_pi']));
        if (($pp['status'] ?? '') === 'requires_capture') {
            $prev = (string)$pp['id'];
        } else {
            kv_db()->prepare("UPDATE orders SET auth_status='expired', auth_done_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $oid]);
            kv_log($oid, 'stripe', '前の仮売上は Stripe で ' . ($pp['status'] ?? '?') . ' になっていました（期限切れ）: ' . $o['auth_pi']);
        }
    }
    if ($prev !== '' && $old === 'before') { kv_stripe_cancel_auth($oid, '再決済の前に'); }

    try {
        $pi = kv_stripe_call('POST', '/payment_intents', [
            'amount' => $amount,
            'currency' => 'jpy',
            'customer' => (string)$o['stripe_customer'],
            'payment_method' => (string)$o['stripe_pm'],
            'off_session' => 'true',
            'confirm' => 'true',
            'capture_method' => 'manual',
            // お客様がいないのでリダイレクトの要る決済方法は使わない（return_url が要らない）
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'description' => kv_shop('name') . ' ご注文 ' . $o['code'] . '（再決済）',
            'metadata' => ['kv_order_id' => (string)$oid, 'kv_order' => $o['code'], 'kv_reauth' => '1'],
            'expand' => ['latest_charge'],
        ], 'kv-reauth-' . $o['code'] . '-' . $amount . '-' . bin2hex(random_bytes(4)));
    } catch (KvStripeError $e) {
        $code = (string)($e->err['code'] ?? '');
        $dec = (string)($e->err['decline_code'] ?? '');
        $piId = (string)($e->err['payment_intent']['id'] ?? '');
        kv_log($oid, 'stripe', '再決済できませんでした（' . number_format($amount) . '円・' . ($code ?: '?') . ($dec !== '' ? '/' . $dec : '') . '）: '
               . $e->getMessage() . ($piId !== '' ? ' ' . $piId : '')
               . ($prev !== '' && $old === 'after' ? '。前の仮売上（' . $prev . '）はそのまま残しています' : ''));
        $msg = $code === 'authentication_required'
            ? 'カード会社が本人認証（3D セキュア）を求めたため、店側からは決済できませんでした。'
              . 'お客様に支払いページを送ってください（下の「カード払いの請求書を送る」）。お客様が本人認証をして支払います。'
            : 'カードで決済できませんでした（' . ($code ?: '不明') . ($dec !== '' ? '・' . $dec : '') . '）。' . $e->getMessage()
              . '　お客様に支払いページ（請求書）を送るか、別の方法でのお支払いをご相談ください。';
        return ['ok' => false, 'code' => $code, 'msg' => $msg];
    }
    if (($pi['status'] ?? '') !== 'requires_capture') {
        kv_log($oid, 'stripe', '再決済が仮売上になりませんでした（' . ($pi['status'] ?? '?') . '）: ' . ($pi['id'] ?? ''));
        if (($pi['status'] ?? '') === 'requires_action') {
            try { kv_stripe_call('POST', '/payment_intents/' . rawurlencode((string)$pi['id']) . '/cancel'); } catch (RuntimeException $e) {}
            return ['ok' => false, 'code' => 'authentication_required',
                    'msg' => 'カード会社が本人認証（3D セキュア）を求めました。お客様に支払いページを送ってください（下の「カード払いの請求書を送る」）。'];
        }
        return ['ok' => false, 'code' => (string)($pi['status'] ?? ''), 'msg' => '再決済が仮売上になりませんでした（' . ($pi['status'] ?? '?') . '）。Stripe のダッシュボードで確かめてください。'];
    }
    if ($prev !== '' && $old !== 'before') {
        try { kv_stripe_call('POST', '/payment_intents/' . rawurlencode($prev) . '/cancel', ['cancellation_reason' => 'duplicate'], 'kv-cxl-' . $prev); }
        catch (RuntimeException $e) { kv_log($oid, 'stripe', '前の仮売上を取り消せませんでした（Stripe で取り消してください）: ' . $prev . ' ' . $e->getMessage()); }
        kv_log($oid, 'stripe', '新しい仮売上が取れたので、前の仮売上を取り消しました: ' . $prev);
    }
    $card = kv_stripe_card_of($pi);
    kv_db()->prepare("UPDATE orders SET auth_status='authorized', auth_pi=?, auth_amount=?, auth_at=?, auth_done_at=NULL, auth_capture_before=? WHERE id=?")
           ->execute([(string)$pi['id'], (int)$pi['amount_capturable'], date('Y-m-d H:i:s'), $card['capture_before'], $oid]);
    kv_log($oid, 'stripe', '預かったカードで再決済し、仮売上にしました: ' . $pi['id'] . ' ' . number_format((int)$pi['amount_capturable']) . '円'
           . ($card['capture_before'] !== '' ? '（確定の期限 ' . substr($card['capture_before'], 0, 16) . '）' : ''));
    return ['ok' => true, 'code' => '', 'msg' => number_format((int)$pi['amount_capturable']) . '円で仮売上を取りました。在庫と納期を確かめたら「売上を確定」を押してください。'];
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
        // 仮売上（与信）にして、在庫を確かめてから受注画面で確定する（2026-11-19 ユーザー指示「注文時は仮売上」）。
        // 欠品で取り消すときに Stripe の手数料がかからない（確定後の返金では手数料が戻らない）
        'payment_intent_data' => ['metadata' => ['kv_pending_id' => (string)$pid], 'description' => kv_shop('name') . ' ご注文',
                                  'capture_method' => 'manual',
                                  // カードを預かる（2026-11-24）。あとで店側から off_session で決済し直せるようにする
                                  'setup_future_usage' => 'off_session'],
        'payment_method_options' => ['card' => ['request_three_d_secure' => 'any']],
        'success_url' => $base . '/cart/complete?pc=' . $token,
        'cancel_url' => $base . '/cart/confirm?canceled=1',
        'integration_identifier' => 'kvcart-checkout-' . $letters,
    ] + kv_stripe_save_card_params(), 'kv-pc-' . $token);
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
    $paid = in_array($s['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
    $auth = $paid ? null : kv_stripe_authorized($s, (int)$pc['total']);   // 仮売上（2026-11-19〜の標準）
    if (!$paid && !$auth) { return 0; }
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
    kv_log($oid, 'stripe', ($auth ? 'カードの仮売上を確認してから' : 'カードでのお支払いを確認してから') . '注文を登録しました: ' . $pc['session_id'] . ' / ' . ($s['payment_intent'] ?? '')
           . ' ' . number_format((int)$s['amount_total']) . '円');
    $GLOBALS['kv_risk_note'] = kv_stripe_risk_note($oid, (string)($s['payment_intent'] ?? ''));
    if ($auth) {
        kv_auth_record($oid, $auth);   // 入金チェックは「売上を確定」で付く
    } else {
        kv_stripe_save_card_of_pi($oid, (string)($s['payment_intent'] ?? ''));
        kv_order_flag($oid, 'paid', true, false);
    }
    kv_mail_send($oid, 'order_paid_card');
    return $oid;
}
