<?php
/** Wave T static gate: no database driver required. */
$root=dirname(__DIR__);
$checks=[];
$assert=function(bool $ok,string $name)use(&$checks){$checks[]=['name'=>$name,'ok'=>$ok];if(!$ok){fwrite(STDERR,"FAIL: {$name}\n");exit(1);}};

$sql=file_get_contents($root.'/migrations/v26_payment_settlement.sql');
$mysql=file_get_contents($root.'/migrations/v26_payment_settlement_mysql.sql');
$domain=file_get_contents($root.'/app/Domain/GatewayPaymentSettlement.php');
$order=file_get_contents($root.'/app/Domain/PaymentOrder.php');
$bootstrap=file_get_contents($root.'/app/Core/Bootstrap.php');
$api=file_get_contents($root.'/app/Api/Controllers/BillingApiController.php');
$routes=file_get_contents($root.'/app/Api/Routes/api.php');

$assert(str_contains($sql,'UNIQUE(user_id, idempotency_key)'),'sqlite unique user/idempotency');
$assert(str_contains($sql,'UNIQUE(provider,event_key)'),'sqlite unique provider event');
$assert(str_contains($sql,'uq_payment_orders_provider_reference'),'sqlite unique provider reference');
$assert(str_contains($mysql,'uq_payment_orders_user_idem'),'mysql unique user/idempotency');
$assert(str_contains($mysql,'uq_payment_events_provider_key'),'mysql unique provider event');
$assert(str_contains($domain,'$expected-$amount'),'server amount comparison');
$assert(str_contains($domain,"status='paid'"),'atomic paid state');
$assert(str_contains($domain,"UPDATE subscriptions SET status='expired'"),'single active subscription transition');
$assert(str_contains($domain,'Referral::processFirstPurchase'),'referral atomicity');
$assert(str_contains($domain,'FOR UPDATE'),'mysql row lock');
$assert(str_contains($domain,'amount_mismatch'),'amount mismatch rejection');
$assert(str_contains($domain,'duplicate_provider_reference'),'provider reference replay protection');
$assert(str_contains($domain,'eventKey'),'event idempotency');
$assert(str_contains($order,'PaymentPricing::quote'),'server-side quote');
$assert(str_contains($api,"PaymentOrder::createSubscription"),'API creates immutable order');
$assert(str_contains($api,"PaymentProviderRegistry::adapter"),'API uses provider boundary');
$assert(str_contains($api,"getConfig('app.url'"),'return URL is server-controlled');
$assert(str_contains($routes,"/payments/online"),'online payment API route');
$assert(str_contains($bootstrap,"'v26_payment_settlement'"),'migration registered');

echo 'WAVE_T_STATIC_GATE: PASS '.count($checks).'/'.count($checks)."\n";
