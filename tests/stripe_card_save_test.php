<?php
declare(strict_types=1);
/*
 * カードを預かる・預かったカードで再決済する（2026-11-24）の試験。
 * **テスト用の鍵（sk_test）を入れた kv_config.php と複製DBを置いた作業フォルダで回す。本番の DB・本番の鍵では回さない。**
 * 決済画面（Checkout）でのカード入力は tests/stripe_card_save_e2e.py（本物のブラウザ）がやり、このスクリプトを呼ぶ。
 *
 *   php stripe_card_save_test.php unit                 … 残り日数の札（ネットにつながない）
 *   php stripe_card_save_test.php create               … 決済待ちと決済画面を作り、{"pid":…,"url":…} を出す
 *   php stripe_card_save_test.php finish <pid> ok      … 注文にして、カードの保存・金額を上げた再決済・確定を確かめる
 *   php stripe_card_save_test.php finish <pid> authreq … 注文にして、再決済で authentication_required の案内になるかを確かめる
 */
require __DIR__ . '/kv_shop.php'; require __DIR__ . '/kv_lib.php'; require __DIR__ . '/kv_order.php'; require __DIR__ . '/kv_stripe.php';
if (strpos(kv_cfg()['stripe']['secret'], '_test_') === false && ($argv[1] ?? '') !== 'unit') { fwrite(STDERR, "テスト用の鍵（sk_test）でしか回さない\n"); exit(2); }
kv_order_schema(); $db = kv_db();
$ok = 0; $ng = 0;
function check(string $n, bool $c, string $extra = '') { global $ok, $ng; echo ($c ? 'OK ' : 'NG ') . $n . ($extra !== '' ? '  ' . $extra : '') . "\n"; $c ? $ok++ : $ng++; }
function done() { global $ok, $ng; echo "OK $ok / NG $ng\n"; exit($ng ? 1 : 0); }
$mode = $argv[1] ?? '';

if ($mode === 'unit') {
    $base = ['id' => 0, 'auth_status' => 'authorized', 'auth_pi' => '', 'auth_at' => date('Y-m-d H:i:s', strtotime('-4 days'))];
    $far = $base + ['auth_capture_before' => date('Y-m-d H:i:s', strtotime('+26 days'))];
    $b = kv_auth_badge($far);
    check('期限が26日先なら「あと26日」で赤くない', strpos($b, 'あと26日') !== false && strpos($b, '#b3261e') === false, strip_tags($b));
    check('5日目でも期限が先なら赤くない（7日決め打ちをやめた）', strpos($b, '仮売上 5日目') !== false && strpos($b, '#fde8e7') === false);
    $near = $base + ['auth_capture_before' => date('Y-m-d H:i:s', strtotime('+2 days'))];
    check('期限まで2日なら赤', strpos(kv_auth_badge($near), '#b3261e') !== false, strip_tags(kv_auth_badge($near)));
    $past = $base + ['auth_capture_before' => date('Y-m-d H:i:s', strtotime('-1 hour'))];
    check('期限を過ぎたら「期限切れ」', strpos(kv_auth_badge($past), '期限切れ') !== false);
    $none = ['id' => 0, 'auth_status' => 'authorized', 'auth_pi' => '', 'auth_at' => date('Y-m-d H:i:s', strtotime('-6 days')), 'auth_capture_before' => ''];
    check('期限が読めないときは7日を目安に6日目から赤', strpos(kv_auth_badge($none), '#b3261e') !== false && strpos(kv_auth_badge($none), '7日を目安') !== false);
    check('決済画面の同意の文は 1200 字以内', mb_strlen(KV_STRIPE_SAVE_NOTE) <= 1200, (string)mb_strlen(KV_STRIPE_SAVE_NOTE) . '字');
    $p = kv_stripe_save_card_params();
    check('新しいお客様は Customer を作る（customer_creation=always）', ($p['customer_creation'] ?? '') === 'always' && !isset($p['customer']));
    $p = kv_stripe_save_card_params('cus_X');
    check('預かったお客様は customer に紐づける', ($p['customer'] ?? '') === 'cus_X' && !isset($p['customer_creation']));
    done();
}

if ($mode === 'create') {
    $p1 = $db->query("SELECT * FROM products WHERE id=38978")->fetch();
    $line = ['product' => $p1, 'unit' => (int)$p1['price'], 'qty' => 1, 'amount' => (int)$p1['price'], 'tax' => intdiv((int)$p1['price'], 10), 'opt' => ''];
    $cart = ['lines' => [$line], 'subtotal' => $line['amount'], 'tax' => $line['tax'], 'total' => $line['amount'] + $line['tax']];
    $s = kv_stripe_checkout_pending($cart, ['name' => 'テスト 太郎', 'email' => 'test@example.com', 'company' => 'テスト株式会社', 'payment' => 'stripe']);
    $g = kv_stripe_call('GET', '/checkout/sessions/' . rawurlencode($s['id']));
    $pid = (int)$db->query("SELECT id FROM pending_checkouts WHERE session_id=" . $db->quote($s['id']))->fetchColumn();
    echo json_encode(['pid' => $pid, 'url' => $s['url'], 'session' => $s['id'], 'total' => $cart['total'],
                      'customer_creation' => $g['customer_creation'] ?? '', 'submit_text' => $g['custom_text']['submit']['message'] ?? '',
                      'customer_email' => $g['customer_email'] ?? '', 'payment_method_types' => $g['payment_method_types'] ?? []], JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

if ($mode === 'finish') {
    $pid = (int)($argv[2] ?? 0); $want = $argv[3] ?? 'ok';
    $oid = kv_pending_finalize($pid);
    check('決済のあと注文になる', $oid > 0, 'order ' . $oid);
    if (!$oid) { done(); }
    $o = kv_order($oid);
    check('仮売上になっている', $o['auth_status'] === 'authorized', (string)$o['auth_pi'] . ' ' . $o['auth_amount'] . '円');
    check('Customer の ID を保存', strpos((string)$o['stripe_customer'], 'cus_') === 0, (string)$o['stripe_customer']);
    check('PaymentMethod の ID を保存', strpos((string)$o['stripe_pm'], 'pm_') === 0, (string)$o['stripe_pm']);
    check('仮売上の期限（capture_before）を保存', (string)$o['auth_capture_before'] !== '', (string)$o['auth_capture_before']);
    $pi = kv_stripe_call('GET', '/payment_intents/' . rawurlencode((string)$o['auth_pi']));
    check('PaymentIntent は setup_future_usage=off_session', ($pi['setup_future_usage'] ?? '') === 'off_session');
    check('PaymentIntent は capture_method=manual のまま', in_array($pi['capture_method'] ?? '', ['manual'], true), (string)($pi['capture_method'] ?? ''));
    $pm = kv_stripe_call('GET', '/payment_methods/' . rawurlencode((string)$o['stripe_pm']));
    check('カードが Customer に保存されている', ($pm['customer'] ?? '') === $o['stripe_customer'], ($pm['card']['brand'] ?? '') . ' ' . ($pm['card']['last4'] ?? ''));
    $ch = kv_stripe_call('GET', '/charges/' . rawurlencode((string)$pi['latest_charge']));
    // テストカード 4242 は attempt_acknowledged、3184 は authenticated になる（どちらも 3D セキュアを求めた結果）
    check('本人認証（3D セキュア）を求めた決済', in_array($ch['payment_method_details']['card']['three_d_secure']['result'] ?? '', ['authenticated', 'attempt_acknowledged'], true),
          (string)($ch['payment_method_details']['card']['three_d_secure']['result'] ?? 'なし'));
    check('残り日数の札に期限が出る', strpos(kv_auth_badge($o), 'あと') !== false, strip_tags(kv_auth_badge($o)));
    $old = (string)$o['auth_pi'];

    // 送料を足して合計を上げる（受注画面で送料を保存したのと同じ計算）
    $db->prepare('UPDATE orders SET shipping=?, total=? WHERE id=?')->execute([1650, (int)$o['subtotal'] - (int)$o['discount'] + (int)$o['tax'] + 1650, $oid]);
    $o = kv_order($oid);
    $up = (int)$o['total'];
    check('増額した合計は、前の仮売上では確定できない', $up > (int)$o['auth_amount']);
    $r = kv_stripe_reauth($oid, $up, 'after');
    if ($want === 'authreq') {
        check('本人認証が要るカードは authentication_required', !$r['ok'] && $r['code'] === 'authentication_required', $r['msg']);
        $o = kv_order($oid);
        check('失敗しても前の仮売上は残る（after）', $o['auth_status'] === 'authorized' && $o['auth_pi'] === $old);
        $logs = array_column(kv_order_logs($oid), 'detail');
        check('order_logs（stripe）に失敗が残る', (bool)array_filter($logs, fn($d) => strpos($d, 'authentication_required') !== false));
        file_put_contents(__DIR__ . '/authreq_oid.txt', (string)$oid);   // 管理画面の案内の確認に使う（e2e が読む）
        done();
    }
    check('金額を上げた再決済が仮売上で取れる', $r['ok'], $r['msg']);
    $o = kv_order($oid);
    check('受注の仮売上が新しいものに替わる', $o['auth_pi'] !== $old && (int)$o['auth_amount'] === $up, $o['auth_pi'] . ' ' . $o['auth_amount'] . '円');
    check('再決済でも期限（capture_before）を保存', (string)$o['auth_capture_before'] !== '', (string)$o['auth_capture_before']);
    $p0 = kv_stripe_call('GET', '/payment_intents/' . rawurlencode($old));
    check('前の仮売上は取り消された', ($p0['status'] ?? '') === 'canceled');
    $p1 = kv_stripe_call('GET', '/payment_intents/' . rawurlencode((string)$o['auth_pi']));
    check('新しい仮売上は off_session・manual', ($p1['status'] ?? '') === 'requires_capture' && ($p1['capture_method'] ?? '') === 'manual');
    // 先に取り消す方（before）も試す
    $mid = (string)$o['auth_pi'];
    $r = kv_stripe_reauth($oid, $up, 'before');
    $o = kv_order($oid);
    $pm2 = kv_stripe_call('GET', '/payment_intents/' . rawurlencode($mid));
    check('先に取り消してから再決済（before）', $r['ok'] && ($pm2['status'] ?? '') === 'canceled' && $o['auth_pi'] !== $mid);
    // 既存の「売上を確定」がそのまま使える
    kv_stripe_capture($oid);
    $o = kv_order($oid);
    $pc = kv_stripe_call('GET', '/payment_intents/' . rawurlencode((string)$o['auth_pi']));
    check('既存の「売上を確定」で、上げた金額が確定する', $o['auth_status'] === 'captured' && (int)($pc['amount_received'] ?? 0) === $up && (int)$o['f_paid'] === 1,
          ($pc['status'] ?? '') . ' ' . ($pc['amount_received'] ?? '') . '円');
    $threw = false;
    try { kv_stripe_reauth($oid, $up); } catch (RuntimeException $e) { $threw = true; }
    check('確定済みの受注は再決済できない', $threw);
    // 前の仮売上が Stripe で切れている（取消済み）ときは「期限切れ」にしてから取り直す
    $db->prepare("UPDATE orders SET auth_status='authorized', auth_pi=?, f_paid=0 WHERE id=?")->execute([$old, $oid]);
    $r = kv_stripe_reauth($oid, $up);
    $logs = array_column(kv_order_logs($oid), 'detail');
    check('Stripe で切れた仮売上は期限切れとして扱い、取り直す', $r['ok'] && (bool)array_filter($logs, fn($d) => strpos($d, '期限切れ') !== false));
    kv_stripe_cancel_auth($oid, '試験の後片付け');
    foreach (array_reverse(kv_order_logs($oid)) as $l) { if ($l['kind'] === 'stripe') { echo '  log: ' . $l['detail'] . "\n"; } }
    done();
}
fwrite(STDERR, "使い方は先頭のコメントを見てください\n"); exit(2);
