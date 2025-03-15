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
        $val['success_url'] = route('success');
        $val['cancel_url'] = route('user.addFund');
        $val['callback_url'] = route('ipn', ['code' => $gateway->code, 'trx' => $order->transaction]);
        $val['cus_email'] = optional($order->user)->email;
        $val['cus_number'] = optional($order->user)->phone ?? '0170000';
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
        $validator = \Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'cus_name' => 'required',
            'cus_email' => 'required|email',
            'cus_number' => 'required',
            'trx_id' => 'required',
            'status' => 'required',
            'extra' => 'nullable|array',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => implode(', ', $validator->errors()->all()),
            ]);
        }
    
        if ($request->status === 'Completed') {
            if (!$order) {
                return response()->json(['error' => 'Order not found.'], 400);
            }
    
            if (!$gateway || !isset($gateway->parameters->api_key)) {
                return response()->json(['error' => 'Payment gateway details missing.'], 400);
            }
    
            $old = Fund::where('payment_id', $request->trx_id)->first();
            if ($old) {
                return response()->json(['error' => 'Transaction ID already used.'], 400);
            }
    
            $response = Http::post('https://tzsmmpay.com/api/payment/verify', [
                'trx_id' => $request->trx_id,
                'api_key' => $gateway->parameters->api_key,
            ]);
    
            if ($response->failed()) {
                return response()->json([
                    'error' => 'Payment verification failed.',
                    'details' => $response->json()
                ], 400);
            }
    
            $responseData = $response->json();
            if (!is_array($responseData) || !isset($responseData['status']) || $responseData['status'] !== 'Completed') {
                return response()->json([
                    'error' => 'Invalid request or payment verification failed.',
                    'details' => $responseData
                ], 400);
            }
    
            $order->payment_id = $request->trx_id;
            $order->save();
            BasicService::preparePaymentUpgradation($order);
    
            return response()->json([
                'success' => true,
                'messages' => 'Payment Success',
            ]);
        } else {
            return response()->json([
                'success' => false,
                'messages' => 'Payment status is: ' . $request->status,
            ]);
        }
    }

}
