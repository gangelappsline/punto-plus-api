<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleToken\ValidateSaleTokenRequest;
use App\Http\Resources\StampResource;
use App\Services\SaleTokenService;
use App\Support\CustomerCardSummary;
use Illuminate\Http\JsonResponse;

/**
 * Validación de tokens de venta por parte del cliente.
 *
 * Es el flujo inverso a `POST /api/business/stamps/scan`: el negocio muestra el
 * QR y el cliente lo escanea (o escribe el código). La compra se registra en su
 * tarjeta y el token se elimina.
 */
class CustomerSaleTokenController extends Controller
{
    public function __construct(private readonly SaleTokenService $tokens)
    {
    }

    /**
     * POST /api/customer/validate-token
     *
     * Body: { code, purchase_amount? }
     */
    public function validateToken(ValidateSaleTokenRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $outcome = $this->tokens->redeem(
            user: $user,
            rawCode: $request->validated('code'),
            data: $request->validated(),
        );

        $stamp = $outcome['stamp'];
        $card = $outcome['card'];

        return (new StampResource($stamp->load(['customerCard.loyaltyCard', 'business', 'registeredBy'])))
            ->additional([
                'message' => $card->isSpend()
                    ? sprintf('Venta registrada: %d puntos.', (int) $stamp->points_awarded)
                    : 'Venta registrada: sello añadido a tu tarjeta.',
                'customer_card' => CustomerCardSummary::for($card),
                'sale_token' => [
                    'code' => $outcome['token']->code,
                    'used' => true,
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }
}
