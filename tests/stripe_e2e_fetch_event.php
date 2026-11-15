<?php
require __DIR__ . '/kv_shop.php'; require __DIR__ . '/kv_lib.php'; require __DIR__ . '/kv_order.php'; require __DIR__ . '/kv_stripe.php';
[$inv] = explode(' ', trim(file_get_contents(__DIR__.'/inv.txt')));
for ($i = 0; $i < 10; $i++) {
  $evs = kv_stripe_call('GET', '/events', ['type' => 'invoice.paid', 'limit' => 10]);
  foreach ($evs['data'] as $e) { if ($e['data']['object']['id'] === $inv) { echo json_encode($e, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; } }
  sleep(2);
}
fwrite(STDERR, "event not found\n"); exit(1);
