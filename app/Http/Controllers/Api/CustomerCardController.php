<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerCard\JoinLoyaltyCardRequest;
use App\Http\Resources\CustomerCardResource;
use App\Http\Resources\RewardResource;
use App\Http\Resources\StampResource;
use App\Models\CustomerCard;
use App\Models\Reward;
use App\Models\Stamp;
use App\Services\CustomerCardService;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Passport\Attributes\AuthorizeToken;

/**
 * Tarjetas del cliente: alta por QR, progreso, historial de sellos y QR propio.
 */
#[AuthorizeToken(['cliente', 'negocio', 'admin'], anyScope: true)]
class CustomerCardController extends Controller
{
    public function __construct(
        private readonly CustomerCardService $cards,
        private readonly QrCodeService $qrCodes,
    ) {
    }

    /**
     * GET /api/customer/cards
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:active,completed,expired,blocked'],
            'business_id' => ['nullable', 'uuid'],
            'with_stamps' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $cards = CustomerCard::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['business_id'] ?? null), fn ($query) => $query->where('business_id', $filters['business_id']))
            ->with(['loyaltyCard.business', 'business'])
            ->withCount('stamps')
            ->when($request->boolean('with_stamps'), fn ($query) => $query->with(['stamps' => fn ($q) => $q->limit(5)]))
            ->orderByDesc('last_stamp_at')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CustomerCardResource::collection($cards);
    }

    /**
     * POST /api/customer/cards/join — alta escaneando el QR del negocio.
     */
    public function join(JoinLoyaltyCardRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $card = $this->cards->join(
            user: $user,
            rawCode: $request->validated('code'),
            loyaltyCardId: $request->validated('loyalty_card_id'),
        );

        $created = $card->wasRecentlyCreated;

        return (new CustomerCardResource(
            $card->load('loyaltyCard.rewards', 'business', 'loyaltyCard.business')->loadCount('stamps')
        ))
            ->additional([
                'message' => $created
                    ? 'Te has unido al programa de fidelidad.'
                    : 'Ya tenías una tarjeta de este programa.',
                'already_joined' => ! $created,
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * GET /api/customer/cards/{customerCard}
     */
    public function show(CustomerCard $customerCard): CustomerCardResource
    {
        $this->authorize('view', $customerCard);

        return new CustomerCardResource(
            $customerCard->load([
                'loyaltyCard.rewards',
                'loyaltyCard.business',
                'business',
                'stamps' => fn ($query) => $query->limit(20),
            ])->loadCount('stamps')
        );
    }

    /**
     * DELETE /api/customer/cards/{customerCard} — abandonar el programa.
     */
    public function destroy(CustomerCard $customerCard): JsonResponse
    {
        $this->authorize('delete', $customerCard);

        $customerCard->delete();

        return response()->json([
            'message' => 'Has salido del programa de fidelidad. Puedes volver a unirte escaneando el QR.',
        ]);
    }

    /**
     * GET /api/customer/cards/{customerCard}/stamps — historial de sellos.
     */
    public function stamps(Request $request, CustomerCard $customerCard): AnonymousResourceCollection
    {
        $this->authorize('viewStamps', $customerCard);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $stamps = Stamp::query()
            ->where('customer_card_id', $customerCard->getKey())
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->with('registeredBy')
            ->orderByDesc('stamped_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return StampResource::collection($stamps);
    }

    /**
     * GET /api/customer/cards/{customerCard}/rewards — recompensas y si ya se pueden canjear.
     */
    public function rewards(CustomerCard $customerCard): JsonResponse
    {
        $this->authorize('view', $customerCard);

        $rewards = $customerCard->loyaltyCard->rewards()
            ->where('is_active', true)
            ->orderBy('required_stamps')
            ->get();

        return response()->json([
            'data' => $rewards->map(function (Reward $reward) use ($customerCard): array {
                /** @var array<string, mixed> $data */
                $data = (new RewardResource($reward))->resolve();

                return $data + [
                    'can_redeem' => $customerCard->meetsRequirementFor($reward)
                        && $customerCard->isUsable()
                        && $reward->hasStock(),
                    'missing_stamps' => max(0, (int) $reward->required_stamps - (int) $customerCard->stamps_count),
                ];
            })->values(),
            'progress' => $customerCard->progress,
        ]);
    }

    /**
     * GET /api/customer/cards/{customerCard}/qr — QR que el negocio escanea.
     */
    public function qr(CustomerCard $customerCard): JsonResponse
    {
        $this->authorize('view', $customerCard);

        return response()->json($this->qrCodes->forCustomerCard($customerCard));
    }

    /*
    |--------------------------------------------------------------------------
    | Vista del negocio
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/business/customers — clientes (tarjetas emitidas) del negocio.
     */
    public function indexForBusiness(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'loyalty_card_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'in:active,completed,expired,blocked'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $cards = CustomerCard::query()
            ->where('business_id', $business->getKey())
            ->when(filled($filters['loyalty_card_id'] ?? null), fn ($query) => $query->where('loyalty_card_id', $filters['loyalty_card_id']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['search'].'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('code', 'like', $term)
                        ->orWhereHas('user', fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
                });
            })
            ->with(['user', 'loyaltyCard'])
            ->withCount('stamps')
            ->orderByDesc('last_stamp_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CustomerCardResource::collection($cards);
    }

    /**
     * GET /api/business/customers/{customerCard} — ficha completa del cliente.
     */
    public function showForBusiness(CustomerCard $customerCard): CustomerCardResource
    {
        $this->authorize('viewAsBusiness', $customerCard);

        return new CustomerCardResource(
            $customerCard->load([
                'user',
                'loyaltyCard.rewards',
                'stamps' => fn ($query) => $query->limit(20),
                'redemptions.reward',
            ])->loadCount('stamps')
        );
    }
}
