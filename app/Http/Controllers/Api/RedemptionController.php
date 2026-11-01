<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Redemption\CompleteRedemptionRequest;
use App\Http\Requests\Redemption\RejectRedemptionRequest;
use App\Http\Requests\Redemption\StoreRedemptionRequest;
use App\Http\Resources\RedemptionResource;
use App\Models\CustomerCard;
use App\Models\Redemption;
use App\Models\Reward;
use App\Services\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Canjes: el cliente solicita la recompensa y el negocio valida el código.
 */
class RedemptionController extends Controller
{
    public function __construct(private readonly RedemptionService $redemptions)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Cliente
    |--------------------------------------------------------------------------
    */

    /**
     * POST /api/customer/cards/{customerCard}/redeem
     */
    public function store(StoreRedemptionRequest $request, CustomerCard $customerCard): JsonResponse
    {
        /** @var \App\Models\User $customer */
        $customer = $request->user();

        $reward = Reward::findOrFail($request->validated('reward_id'));

        $redemption = $this->redemptions->request(
            card: $customerCard,
            reward: $reward,
            customer: $customer,
            notes: $request->validated('notes'),
        );

        return (new RedemptionResource($redemption->load(['reward', 'customerCard.loyaltyCard', 'business'])))
            ->additional([
                'message' => 'Canje solicitado. Muestra el código al negocio para recibir tu recompensa.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/customer/redemptions
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,completed,rejected,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $redemptions = Redemption::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->with(['reward', 'business', 'customerCard.loyaltyCard'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return RedemptionResource::collection($redemptions);
    }

    /**
     * GET /api/customer/redemptions/{redemption}
     */
    public function show(Redemption $redemption): RedemptionResource
    {
        $this->authorize('view', $redemption);

        return new RedemptionResource($redemption->load(['reward', 'business', 'customerCard.loyaltyCard']));
    }

    /**
     * POST /api/customer/redemptions/{redemption}/cancel
     */
    public function cancel(Redemption $redemption): JsonResponse
    {
        $this->authorize('cancel', $redemption);

        $redemption = $this->redemptions->cancel($redemption, request()->user());

        return response()->json([
            'message' => 'Canje cancelado. Tus sellos se han devuelto.',
            'data' => new RedemptionResource($redemption),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Negocio
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/business/redemptions
     */
    public function businessIndex(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,completed,rejected,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $redemptions = Redemption::query()
            ->forBusiness($business)
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->where('created_at', '<=', $filters['to']))
            ->with(['reward', 'user', 'customerCard.loyaltyCard'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return RedemptionResource::collection($redemptions);
    }

    /**
     * POST /api/business/redemptions/{redemption}/approve
     */
    public function approve(Redemption $redemption): JsonResponse
    {
        $this->authorize('approve', $redemption);

        $redemption = $this->redemptions->approve($redemption, request()->user());

        return response()->json([
            'message' => 'Canje validado. Entrega la recompensa al cliente.',
            'data' => new RedemptionResource($redemption),
        ]);
    }

    /**
     * POST /api/business/redemptions/{redemption}/reject
     */
    public function reject(RejectRedemptionRequest $request, Redemption $redemption): JsonResponse
    {
        $this->authorize('reject', $redemption);

        $redemption = $this->redemptions->reject(
            $redemption,
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json([
            'message' => 'Canje rechazado. Los sellos vuelven a la tarjeta del cliente.',
            'data' => new RedemptionResource($redemption),
        ]);
    }

    /**
     * POST /api/business/redemptions/complete — validar por código.
     */
    public function completeByCode(CompleteRedemptionRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);

        $redemption = $this->redemptions->completeByCode(
            $business,
            $request->validated('code'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Canje validado. Entrega la recompensa al cliente.',
            'data' => new RedemptionResource($redemption),
        ]);
    }
}
