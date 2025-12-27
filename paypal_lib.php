<?php
require_once __DIR__ . '/config.php';

function paypal_base(){
  return PAYPAL_SANDBOX ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
}
function paypal_token(){
  if(!PAYPAL_CLIENT_ID || !PAYPAL_SECRET){
    throw new Exception("PayPal keys not set in config.php");
  }
  $ch=curl_init(paypal_base()."/v1/oauth2/token");
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_USERPWD=>PAYPAL_CLIENT_ID.":".PAYPAL_SECRET,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>"grant_type=client_credentials",
    CURLOPT_HTTPHEADER=>["Accept: application/json","Accept-Language: en_US"]
  ]);
  $res=curl_exec($ch);
  if($res===false) throw new Exception(curl_error($ch));
  $data=json_decode($res,true);
  return $data['access_token'] ?? null;
}
function paypal_create_order($amount,$currency,$returnUrl,$cancelUrl){
  $token=paypal_token();
  $payload=[
    "intent"=>"CAPTURE",
    "purchase_units"=>[["amount"=>["currency_code"=>$currency,"value"=>number_format($amount,2,'.','')]]],
    "application_context"=>[
      "return_url"=>$returnUrl,
      "cancel_url"=>$cancelUrl
    ]
  ];
  $ch=curl_init(paypal_base()."/v2/checkout/orders");
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer ".$token]
  ]);
  $res=curl_exec($ch);
  if($res===false) throw new Exception(curl_error($ch));
  return json_decode($res,true);
}
function paypal_capture($orderId){
  $token=paypal_token();
  $ch=curl_init(paypal_base()."/v2/checkout/orders/".$orderId."/capture");
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer ".$token]
  ]);
  $res=curl_exec($ch);
  if($res===false) throw new Exception(curl_error($ch));
  return json_decode($res,true);
}
