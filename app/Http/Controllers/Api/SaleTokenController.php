<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleToken\NewSaleTokenRequest;
use App\Http\Resources\SaleTokenResource;
use App\Models\SaleToken;
use App\Services\SaleTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tokens de venta del negocio.
 *
 * El panel pide un token (`GET /api/business/new-token`), dibuja el QR con
 * `qr.svg`/`qr_content` (o muestra `display_code`) y el cliente lo valida en su
 * app. El token dura pocos minutos y muere al usarse.
 */
class SaleTokenController extends Controller
{
    public function __construct(private readonly SaleTokenService $tokens)
    {
    }

    /**
     * GET /api/business/new-token
     *
     * Query opcional: `loyalty_card_id`, `purchase_amount`, `notes`.
     */
    public function store(NewSaleTokenRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);

        $this->authorize('create', SaleToken::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $token = $this->tokens->issue($business, $user, $request->validated());

        return (new SaleTokenResource($token))
            ->additional([
                'message' => sprintf(
                    'Token generado: compártelo con tu cliente antes de %d minutos.',
                    $token->ttlMinutes(),
                ),
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/business/tokens — tokens vivos del negocio.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $this->authorize('viewAny', SaleToken::class);

        return SaleTokenResource::collection($this->tokens->activeFor($business));
    }

    /**
     * DELETE /api/business/tokens/{saleToken} — cancela un token antes de que caduque.
     */
    public function destroy(SaleToken $saleToken): JsonResponse
    {
        $this->authorize('delete', $saleToken);

        $saleToken->delete();

        return response()->json(['message' => 'Token cancelado.']);
    }
}
