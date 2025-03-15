<?php

namespace App\Services\Gateway\tzsmmpay;

use App\Fund;
use Facades\App\Services\BasicService;

class Payment
{
    public static function prepareData($order, $gateway)
    {
        $val['pay_method'] = " ";
        $val['amount'] = round($order->final_amount, 2);
        $val['currency'] = $order->gateway_currency;
        $val['succes_url'] = route('success');
        $val['cancel_url'] = route('user.addFund');
        $val['cancel_url'] = route('ipn', ['code' => $gateway->code, 'trx' => $order->transaction]);
        $val['cus_email'] = optional($order->user)->email;
        $val['cus_name'] = optional($order->user)->firstname . optional($order->user)->lastname;
        $val['api_key'] = $gateway->parameters->api_key;
        $val['addi_info'] = "Payment";
        $val['redirect'] = "true";

        $send['url'] = 'https://tzsmmpay.com/api/payment/create';
        $send['method'] = 'get';
        $send['view'] = 'user.payment.redirect';
        $send['val'] = $val;

        return json_encode($send);
    }

    public static function ipn($request, $gateway, $order = null, $trx = null, $type = null)
    {
        $order = Fund::where('transaction', $request->order_id)->orderBy('id', 'DESC')->first();
        if ($order) {
            if ($request->currency == $order->gateway_currency && ($request->Amount == round($order->final_amount, 2)) && $order->status == 0) {
                BasicService::preparePaymentUpgradation($order);
            }
        }
    }
}
