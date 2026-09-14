<?php
/** 設定の読み出し。kv_config.php が無くてもデモとして動くよう既定値を持つ。
 *
 * **kv_config.php はここで即座に読む（遅延にしない）。**
 * KV_BASE は kv_lib.php も使うので、後回しにすると kv_lib.php が先に
 * KV_BASE='' を define してしまい、/exdirect のようなサブパス配置で
 * ルーティングが全部 404 になる（2026-09-14 に実際に踏んだ）。
 */
declare(strict_types=1);

$KV_SHOP = $KV_STRIPE = $KV_ADMIN = [];
$__kv_cfg_file = __DIR__ . '/kv_config.php';
if (is_file($__kv_cfg_file)) { require $__kv_cfg_file; }
if (!defined('KV_BASE')) {
    define('KV_BASE', getenv('KV_BASE') !== false ? (string)getenv('KV_BASE') : '');
}

function kv_cfg(): array
{
    static $c = null;
    if ($c === null) {
        global $KV_SHOP, $KV_STRIPE, $KV_ADMIN;
        $KV_SHOP = $KV_SHOP ?: [];
        $KV_STRIPE = $KV_STRIPE ?: [];
        $KV_ADMIN = $KV_ADMIN ?: [];
        $c = [
            'shop' => $KV_SHOP + [
                'name' => 'X-Direct', 'tagline' => '仕事に役立つアイテムを直送・格安で',
                'company' => '株式会社エクスブリッジ', 'email' => 'info@exbridge.jp',
                'tel' => '', 'address' => '',
                'bank' => '振込先はご注文後にメールでご案内します。',
                'ship_note' => '送料は商品ページの表記に従います。',
            ],
            'stripe' => $KV_STRIPE + ['publishable' => '', 'secret' => '', 'webhook' => ''],
            'admin'  => $KV_ADMIN + ['user' => 'admin', 'hash' => ''],
        ];
    }
    return $c;
}

function kv_shop(string $k): string { return (string)(kv_cfg()['shop'][$k] ?? ''); }

/** Stripe が使えるか。鍵が無ければ決済ボタンを出さない（押せないボタンを置かない）。 */
function kv_stripe_ready(): bool
{
    $s = kv_cfg()['stripe'];
    return $s['publishable'] !== '' && $s['secret'] !== '';
}
