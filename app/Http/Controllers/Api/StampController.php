<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stamp\ScanStampRequest;
use App\Http\Resources\StampResource;
use App\Models\Stamp;
use App\Services\CustomerCardService;
use App\Services\StampService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Passport\Attributes\AuthorizeToken;

/**
 * Sellos: el negocio escanea el QR del cliente para registrar una compra.
 */
#[AuthorizeToken(['negocio', 'admin'], anyScope: true)]
class StampController extends Controller
{
    public function __construct(
        private readonly StampService $stamps,
        private readonly CustomerCardService $cards,
    ) {
    }

    /**
     * POST /api/business/stamps/scan
     *
     * Body: { code, purchase_amount?, notes?, stamped_at?, source?, idempotency_key? }
     */
    public function scan(ScanStampRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);

        /** @var \App\Models\User $staff */
        $staff = $request->user();

        $card = $this->cards->findByCardCode($business, $request->validated('code'));

        $stamp = $this->stamps->register(
            business: $business,
            card: $card,
            staff: $staff,
            data: $request->validated(),
        );

        $card->refresh();

        return (new StampResource($stamp->load(['customerCard.user', 'registeredBy'])))
            ->additional([
                'message' => 'Sello registrado.',
                'customer_card' => [
                    'id' => $card->getKey(),
                    'code' => $card->code,
                    'stamps_count' => (int) $card->stamps_count,
                    'required_stamps' => (int) $card->required_stamps,
                    'progress_percentage' => $card->progress_percentage,
                    'status' => $card->status->value,
                    'reward_available' => $card->isCompleted(),
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/business/stamps/recent — últimos escaneos del negocio.
     */
    public function recent(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $stamps = Stamp::query()
            ->forBusiness($business)
            ->with(['customerCard.user', 'customerCard.loyaltyCard', 'registeredBy'])
            ->recent()
            ->limit(min((int) $request->integer('limit', 20), 100))
            ->get();

        return StampResource::collection($stamps);
    }

    /**
     * GET /api/business/stamps — historial paginado con filtros.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $filters = $request->validate([
            'loyalty_card_id' => ['nullable', 'uuid'],
            'customer_card_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'in:scan,manual,import,adjustment'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $stamps = Stamp::query()
            ->forBusiness($business)
            ->when(filled($filters['loyalty_card_id'] ?? null), fn ($query) => $query->where('loyalty_card_id', $filters['loyalty_card_id']))
            ->when(filled($filters['customer_card_id'] ?? null), fn ($query) => $query->where('customer_card_id', $filters['customer_card_id']))
            ->when(filled($filters['source'] ?? null), fn ($query) => $query->where('source', $filters['source']))
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->with(['customerCard.user', 'loyaltyCard', 'registeredBy'])
            ->orderByDesc('stamped_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return StampResource::collection($stamps);
    }

    /**
     * DELETE /api/business/stamps/{stamp} — anula un sello erróneo o fraudulento.
     */
    public function destroy(Stamp $stamp): JsonResponse
    {
        $this->authorize('delete', $stamp);

        /** @var \App\Models\User $staff */
        $staff = request()->user();

        $cardId = $stamp->customer_card_id;
        $stampId = $stamp->getKey();

        $this->stamps->void($stamp, $staff);

        $card = \App\Models\CustomerCard::find($cardId);

        return response()->json([
            'message' => 'Sello anulado y progreso revertido.',
            'stamp_id' => $stampId,
            'customer_card' => $card === null ? null : [
                'id' => $card->getKey(),
                'code' => $card->code,
                'stamps_count' => (int) $card->stamps_count,
                'required_stamps' => (int) $card->required_stamps,
                'status' => $card->status->value,
            ],
        ]);
    }
}
