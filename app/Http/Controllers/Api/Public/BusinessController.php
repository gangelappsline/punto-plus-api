<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use Illuminate\Http\Request;
use App\Models\Business;

class BusinessController extends Controller
{
    public function show($slug)
    {
       $business = Business::whereSlug($slug)->firstOrFail();
       return response()->json(new BusinessResource($business));
    }

    public function card(Request $request)
    {
        $business = Business::where('user_id', $request->user()->id)->firstOrFail();
        $business->load('products','owner','loyaltyCards','categories','promotions');
        return response()->json(new BusinessResource($business));
    }
}
