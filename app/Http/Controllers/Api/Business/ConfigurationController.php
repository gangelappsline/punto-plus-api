<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Business;
use App\Http\Resources\BusinessResource;

class ConfigurationController extends Controller
{

public function index(Request $request)
    {
        $business = Business::where('user_id', $request->user()->id)->firstOrFail();
        $business->load('products', 'owner', 'loyaltyCards', 'categories', 'promotions');

        return response()->json(new BusinessResource($business));
    }
    public function updateQrCode(Request $request)
    {
        $business = $request->user()->business;
        $business->qr_settings = array_merge($business->qr_settings ?? [], $request->all() ?? []);
        $business->save();

        return response()->json(['message' => 'QR code updated successfully.']);
    }
}
