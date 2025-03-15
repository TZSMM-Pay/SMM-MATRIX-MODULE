<?php

namespace App\Services\Gateway\tzsmmpay;

use App\Models\Fund;
use Facades\App\Services\BasicService;
use Illuminate\Support\Facades\Http;


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
        try {
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
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => implode(', ', $validator->errors()->all()),
                ];
            }
    
            if ($request->status !== 'Completed') {
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => 'Payment status is: ' . $request->status,
                ];
            }
    
            if (!$order) {
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => 'Order not found.',
                ];
            }
    
            if (!$gateway || empty($gateway->parameters->api_key)) {
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => 'Payment gateway details missing.',
                ];
            }
    
            $old = Fund::where('payment_id', $request->trx_id)->first();
            if ($old) {
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => 'Transaction ID already used.',
                ];
            }
    
            // Payment verification request
            $response = Http::post('https://tzsmmpay.com/api/payment/verify', [
                'trx_id' => $request->trx_id,
                'api_key' => $gateway->parameters->api_key,
            ]);
    
            // Handle HTTP response errors
            if ($response->failed()) {
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => 'Payment verification request failed.',
                    'status_code' => $response->status(),
                    'details' => $response->body(),
                ];
            }
    
            // Get response data
            $responseData = $response->json();
            if (!is_array($responseData) || !isset($responseData['status']) || $responseData['status'] !== 'Completed') {
                return [
                    'success' => false,
                    'status' => 'error',
                    'msg' => 'Invalid request or payment verification failed.',
                    'details' => $responseData
                ];
            }
    
            // Save transaction details
            $order->payment_id = $request->trx_id;
            $order->save();
    
            // Process payment success
            BasicService::preparePaymentUpgradation($order);
    
            return [
                'success' => true,
                'status' => 'success',
                'msg' => 'Payment Success',
                'redirect' => route('user.fund-history'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'msg' => 'An error occurred.',
                'error_message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ];
        }
    }



}
