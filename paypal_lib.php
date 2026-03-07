<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function gc_paypal_runtime_settings(): array {
  try {
    $pdo = db();
    return gc_payment_settings($pdo)['paypal'];
  } catch (Throwable $e) {
    return [
      'enabled' => false,
      'client_id' => defined('PAYPAL_CLIENT_ID') ? (string)PAYPAL_CLIENT_ID : '',
      'secret' => defined('PAYPAL_SECRET') ? (string)PAYPAL_SECRET : '',
      'sandbox' => (defined('PAYPAL_SANDBOX') && PAYPAL_SANDBOX),
      'configured' => ((defined('PAYPAL_CLIENT_ID') ? (string)PAYPAL_CLIENT_ID : '') !== '' && (defined('PAYPAL_SECRET') ? (string)PAYPAL_SECRET : '') !== ''),
    ];
  }
}

function paypal_base(){
  $cfg = gc_paypal_runtime_settings();
  return !empty($cfg['sandbox']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
}
function paypal_token(){
  $cfg = gc_paypal_runtime_settings();
  $clientId = trim((string)($cfg['client_id'] ?? ''));
  $secret = trim((string)($cfg['secret'] ?? ''));
  if($clientId === '' || $secret === ''){
    throw new Exception('PayPal keys not set in Payment Gateways.');
  }
  $ch=curl_init(paypal_base().'/v1/oauth2/token');
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_USERPWD=>$clientId.':'.$secret,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>'grant_type=client_credentials',
    CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Language: en_US']
  ]);
  $res=curl_exec($ch);
  if($res===false) throw new Exception(curl_error($ch));
  $data=json_decode($res,true);
  return $data['access_token'] ?? null;
}
function paypal_create_order($amount,$currency,$returnUrl,$cancelUrl){
  $token=paypal_token();
  $payload=[
    'intent'=>'CAPTURE',
    'purchase_units'=>[['amount'=>['currency_code'=>$currency,'value'=>number_format($amount,2,'.','')]]],
    'application_context'=>[
      'return_url'=>$returnUrl,
      'cancel_url'=>$cancelUrl
    ]
  ];
  $ch=curl_init(paypal_base().'/v2/checkout/orders');
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]
  ]);
  $res=curl_exec($ch);
  if($res===false) throw new Exception(curl_error($ch));
  return json_decode($res,true);
}
function paypal_capture($orderId){
  $token=paypal_token();
  $ch=curl_init(paypal_base().'/v2/checkout/orders/'.$orderId.'/capture');
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]
  ]);
  $res=curl_exec($ch);
  if($res===false) throw new Exception(curl_error($ch));
  return json_decode($res,true);
}
