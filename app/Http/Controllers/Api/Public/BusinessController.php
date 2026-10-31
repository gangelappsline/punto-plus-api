<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use Illuminate\Http\Request;
use App\Models\Business;
use Illuminate\Support\Str;

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
        $business->load('products', 'owner', 'loyaltyCards', 'categories', 'promotions');
        return response()->json(new BusinessResource($business));
    }

    public function cardUpdate(Request $request)
    {
        $business = Business::where('user_id', $request->user()->id)->firstOrFail();

        switch ($request->step) {
            case 'information':
                $business->update($request->all());
                if ($business->slug == null || $business->name != $request->name) {
                    $business->slug = Str::slug($business->name);
                    $business->save();
                }

                $iconBase64 = $request['logo_url'] ?? null;
                unset($request['logo_url']);

                if ($iconBase64 !== null) {
                    $business->logo_url = $this->assets->storeCategoryIconBase64($iconBase64, (string) $business->getKey());
                    $business->save();
                }
                break;
            case 'categories':
                # code...
                break;
            default:
                # code...
                break;
        }

        $business->load('products', 'owner', 'loyaltyCards', 'categories', 'promotions');
        return response()->json(new BusinessResource($business));
    }
}
