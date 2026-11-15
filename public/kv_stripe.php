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
        $desc = $it['name'] . ($it['model_number'] ? ' [' . $it['model_number'] . ']' : '')
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
function kv_stripe_handle(array $ev): string
{
    $obj = $ev['data']['object'] ?? [];
    $oid = (int)($obj['metadata']['kv_order_id'] ?? 0);
    $o = $oid ? kv_order($oid) : null;
    if (!$o) { return 'ignored: no order'; }
    // 作り直して取り消した古い請求書のイベントは無視する
    if (($obj['id'] ?? '') !== (string)$o['stripe_id']) { return 'ignored: old invoice'; }
    switch ($ev['type'] ?? '') {
        case 'invoice.paid':
            if ((int)$o['f_paid'] === 1) { return 'already paid'; }
            kv_log($oid, 'stripe', 'カードでのお支払いを確認しました: ' . $obj['id']
                   . ' ' . number_format((int)($obj['amount_paid'] ?? 0)) . '円');
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
