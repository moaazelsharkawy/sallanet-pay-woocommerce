<?php
$state_file = '/tmp/wpt/mock/state.json';
$st = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : ['tx'=>[], 'log'=>[]];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$st['log'][] = ['path'=>$path, 'body'=>$body, 'origin'=>$_SERVER['HTTP_ORIGIN'] ?? null];
header('Content-Type: application/json');
$out = function($code, $d) use (&$st, $state_file) { file_put_contents($state_file, json_encode($st)); http_response_code($code); echo json_encode($d); exit; };
if ($path === 'get-pi-price') $out(200, ['price'=>0.5]);
if ($key !== 'test_key') $out(401, ['error'=>'Invalid or expired API key']);
if ($path === 'extapi-create-checkout') {
  $id = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffff));
  $st['tx'][$id] = ['amount'=>$body['amount'], 'currency'=>$body['currency'], 'status'=>'pending', 'success_url'=>$body['success_url']];
  $out(200, ['success'=>true, 'transaction_id'=>$id, 'checkout_url'=>"https://mall.salla-shop.com/api-checkout/$id"]);
}
$id = $body['transaction_id'] ?? '';
if (!isset($st['tx'][$id])) $out(404, ['error'=>'Transaction not found']);
if ($path === 'extapi-transaction-status') { $t=$st['tx'][$id]; $out(200, ['success'=>true,'transaction'=>['id'=>$id,'status'=>$t['status'],'amount'=>(float)$t['amount'],'currency'=>$t['currency']]]); }
if ($path === 'extapi-refund') { $st['tx'][$id]['refunds'][] = $body['amount'] ?? 'full'; $out(200, ['success'=>true]); }
if ($path === 'extapi-confirm') { $st['tx'][$id]['status']='confirmed'; $out(200, ['success'=>true]); }
$out(404, ['error'=>'no route']);
