<?php
/**
 * Stripe の webhook の受け口（2026-11-15）。
 * Stripe のダッシュボード → Webhook で、この URL（https://www.exdirect.net/stripe_webhook.php）を登録し、
 * イベント invoice.paid / invoice.payment_failed / invoice.voided を選ぶ。
 * 表示される署名シークレット（whsec_…）を kv_config.php の $KV_STRIPE['webhook'] に入れる。
 */
declare(strict_types=1);

require __DIR__ . '/kv_shop.php';
require __DIR__ . '/kv_lib.php';
require __DIR__ . '/kv_order.php';
require __DIR__ . '/kv_stripe.php';
// 期限切れの案内メール（kv_pending_abandon_mail・2026-11-28）が、正本のURL（kv_ui.php）・カート（kv_cart.php）・会員の値引き（kv_member.php）を使う
require_once __DIR__ . '/kv_session.php';
require_once __DIR__ . '/kv_ui.php';
require_once __DIR__ . '/kv_cart.php';
if (is_file(__DIR__ . '/kv_member.php')) { require_once __DIR__ . '/kv_member.php'; }

header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'POST only'; exit; }

$payload = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (!kv_stripe_verify($payload, $sig, kv_cfg()['stripe']['webhook'])) {
    http_response_code(400);
    echo 'bad signature';
    exit;
}
$ev = json_decode($payload, true);
if (!is_array($ev)) { http_response_code(400); echo 'bad json'; exit; }
try {
    echo kv_stripe_handle($ev);
} catch (Throwable $e) {
    // 失敗を 500 で返すと Stripe が再送してくれる
    http_response_code(500);
    error_log('kvcart stripe webhook: ' . $e->getMessage());
    echo 'error';
}
