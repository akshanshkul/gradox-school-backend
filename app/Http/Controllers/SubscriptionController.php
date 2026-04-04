<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Store a local record of the payment order
     */
    public function storeOrder(Request $request)
    {
        $request->validate([
            'order_id' => 'required',
            'client_id' => 'required',
            'transaction_id' => 'required',
            'amount' => 'required',
            'applicantData' => 'required|array'
        ]);

        try {
            $payment = SubscriptionPayment::create([
                'school_id' => $request->user()->school_id,
                'order_id' => $request->order_id,
                'client_id' => $request->client_id,
                'transaction_id' => $request->transaction_id,
                'amount' => $request->amount,
                'status' => 'pending',
                'payment_metadata' => $request->applicantData
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Order stored locally',
                'payment_id' => $payment->id
            ]);
        } catch (\Exception $e) {
            Log::error('Payment Order Store Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to store order locally'
            ], 500);
        }
    }
}
