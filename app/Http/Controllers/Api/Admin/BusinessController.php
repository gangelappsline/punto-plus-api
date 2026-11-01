<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Business;
use App\Http\Resources\BusinessResource;

class BusinessController extends Controller
{
    public function index()
    {
        $businesses = Business::with('owner', 'loyaltyCards', 'categories', 'promotions')->get();
        $stats = BusinessResource::collection($businesses);
        return response()->json(['stats' => $stats, 'business'=> $businesses]);
    }
}
