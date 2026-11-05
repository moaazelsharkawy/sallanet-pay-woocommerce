<?php
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? "PASS " : "FAIL ") . $m . "\n"; if (!$c) $fails++; }
function hook($payload, $secret = 'whsec_test') {
  $raw = json_encode($payload);
  $r = new WP_REST_Request('POST', '/sallanet-pay/v1/webhook');
  $r->set_body($raw); $r->set_header('Content-Type', 'application/json');
  $r->set_header('X-Salla-Signature', hash_hmac('sha256', $raw, $secret));
  return rest_do_request($r);
}
function mkorder() {
  $o = wc_create_order(); $o->add_product(wc_get_product(10), 2); $o->set_currency('USD');
  $o->set_payment_method('sallanet_pay'); $o->calculate_totals(); $o->save(); return $o;
}
function mock() { return json_decode(file_get_contents('/tmp/wpt/mock/state.json'), true); }
$gw = WC()->payment_gateways()->payment_gateways()['sallanet_pay'];
check($gw && $gw->is_available(), 'gateway available');

// 1. checkout + webhook
$o = mkorder();
check(abs($o->get_total() - 20.74) < 0.001, 'order total 20.74');
$res = $gw->process_payment($o->get_id());
check($res['result'] === 'success' && str_starts_with($res['redirect'], 'https://mall.salla-shop.com/api-checkout/'), 'redirect to checkout');
$o = wc_get_order($o->get_id()); $tx = $o->get_meta('_sallanet_tx_id');
check($o->get_meta('_sallanet_coin_amount') === '41.4800000', 'coins = 20.74 / 0.5 (' . $o->get_meta('_sallanet_coin_amount') . ')');
$m = mock(); $last = end($m['log']);
check($last['body']['currency'] === 'pi' && $last['body']['amount'] == 41.48 && $last['origin'] === null, 'API got amount 41.48 pi, no origin');
check(str_contains($m['tx'][$tx]['success_url'], 'order-received'), 'success_url is order received page');

$bad = hook(['event'=>'payment.success','transaction_id'=>$tx,'status'=>'escrow','amount'=>41.48,'currency'=>'pi','delivery_id'=>'d1'], 'wrong');
check($bad->get_status() === 401, 'bad signature rejected 401');
check(!wc_get_order($o->get_id())->is_paid(), 'still unpaid after bad signature');

$mis = hook(['event'=>'payment.success','transaction_id'=>$tx,'status'=>'escrow','amount'=>1,'currency'=>'pi','delivery_id'=>'d0']);
check($mis->get_status() === 200 && !wc_get_order($o->get_id())->is_paid(), 'amount mismatch not paid');

$ok = hook(['event'=>'payment.success','transaction_id'=>$tx,'status'=>'escrow','amount'=>41.48,'currency'=>'pi','delivery_id'=>'d1']);
$o = wc_get_order($o->get_id());
check($ok->get_status() === 200 && $o->is_paid() && $o->has_status('processing'), 'paid via webhook -> processing');
$dup = hook(['event'=>'payment.success','transaction_id'=>$tx,'status'=>'escrow','amount'=>41.48,'currency'=>'pi','delivery_id'=>'d1']);
check(!empty($dup->get_data()['duplicate']), 'duplicate delivery skipped');
check(hook(['event'=>'webhook.test'])->get_status() === 200, 'webhook.test ok');
check(empty(hook(['event'=>'payment.success','transaction_id'=>'nope','status'=>'escrow','amount'=>1,'currency'=>'pi'])->get_data()['error']), 'unknown tx ignored with 200');

// 2. partial then full refund
$r = wc_create_refund(['order_id'=>$o->get_id(), 'amount'=>5.185, 'refund_payment'=>true]);
check(!is_wp_error($r), 'partial refund ok ' . (is_wp_error($r) ? $r->get_error_message() : ''));
$m = mock(); check(($m['tx'][$tx]['refunds'][0] ?? null) == 10.37, 'partial refund sent 10.37 pi (' . json_encode($m['tx'][$tx]['refunds'] ?? []) . ')');
$r = wc_create_refund(['order_id'=>$o->get_id(), 'amount'=>1, 'refund_payment'=>true]);
check(is_wp_error($r) && count(mock()['tx'][$tx]['refunds']) === 1, 'second refund blocked (one refund per tx)');
$o4 = mkorder(); $gw->process_payment($o4->get_id()); $tx4 = wc_get_order($o4->get_id())->get_meta('_sallanet_tx_id');
hook(['event'=>'payment.success','transaction_id'=>$tx4,'status'=>'escrow','amount'=>41.48,'currency'=>'pi','delivery_id'=>'f1']);
$r = wc_create_refund(['order_id'=>$o4->get_id(), 'amount'=>20.74, 'refund_payment'=>true]);
check(!is_wp_error($r) && mock()['tx'][$tx4]['refunds'][0] === 'full' && wc_get_order($o4->get_id())->has_status('refunded'), 'full refund sends no amount, order refunded');
hook(['event'=>'payment.refunded','transaction_id'=>$tx,'status'=>'refunded','amount'=>31.11,'currency'=>'pi','delivery_id'=>'d9']);
check(count(wc_get_order($o->get_id())->get_refunds()) === 1 && !wc_get_order($o->get_id())->has_status('refunded'), 'refund webhook does not double refund');

// 3. return page verification (no webhook) + release on complete
$o2 = mkorder(); $gw->process_payment($o2->get_id()); $o2 = wc_get_order($o2->get_id()); $tx2 = $o2->get_meta('_sallanet_tx_id');
$gw->verify_on_return($o2->get_id());
check(!wc_get_order($o2->get_id())->is_paid(), 'return page while pending stays unpaid');
$s = mock(); $s['tx'][$tx2]['status'] = 'escrow'; file_put_contents('/tmp/wpt/mock/state.json', json_encode($s));
$gw->verify_on_return($o2->get_id());
$o2 = wc_get_order($o2->get_id());
check($o2->is_paid(), 'return page marks paid when escrow');
$o2->update_status('completed');
check(mock()['tx'][$tx2]['status'] === 'confirmed', 'completed order released escrow');

// 4. external refund marks order refunded
$o3 = mkorder(); $gw->process_payment($o3->get_id()); $tx3 = wc_get_order($o3->get_id())->get_meta('_sallanet_tx_id');
hook(['event'=>'payment.success','transaction_id'=>$tx3,'status'=>'escrow','amount'=>41.48,'currency'=>'pi','delivery_id'=>'e1']);
hook(['event'=>'payment.refunded','transaction_id'=>$tx3,'status'=>'refunded','amount'=>41.48,'currency'=>'pi','delivery_id'=>'e2']);
check(wc_get_order($o3->get_id())->has_status('refunded'), 'external refund -> refunded');
$o5 = mkorder(); $gw->process_payment($o5->get_id()); $tx5 = wc_get_order($o5->get_id())->get_meta('_sallanet_tx_id');
hook(['event'=>'payment.success','transaction_id'=>$tx5,'status'=>'escrow','amount'=>41.48,'currency'=>'pi','delivery_id'=>'g1']);
hook(['event'=>'payment.refunded','transaction_id'=>$tx5,'status'=>'refunded','amount'=>10,'currency'=>'pi','delivery_id'=>'g2']);
check(wc_get_order($o5->get_id())->has_status('processing'), 'external partial refund only adds note');

// 5. direct / fixed pricing
$gw->update_option('pricing', 'fixed'); $gw->update_option('fixed_rate', '50'); 
check($gw->to_coin(100, 'EGP') == 2.0, 'fixed rate 100 EGP / 50 = 2 Pi');
$gw->update_option('pricing', 'direct');
check($gw->to_coin(3.3333333, 'PI') == 3.3333333 && $gw->to_coin(10, 'USD') == 0, 'direct pricing only for same coin');
$gw->update_option('pricing', 'usd_live');
check($gw->to_coin(10, 'EGP') == 0, 'usd_live refuses non USD');
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
