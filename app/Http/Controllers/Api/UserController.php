<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class UserController extends Controller
{
    /**
     * GET /api/user — perfil del usuario autenticado.
     */
    public function show(Request $request): UserResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return new UserResource(
            $user->load('business')->loadCount('customerCards')
        );
    }

    /**
     * PUT /api/user
     */
    public function update(UpdateUserRequest $request): UserResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $user->fill($request->validated())->save();

        return new UserResource($user->load('business')->loadCount('customerCards'));
    }

    /**
     * PUT /api/user/password — cambia la contraseña y cierra el resto de sesiones.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $currentTokenId = $this->currentTokenId($request);

        DB::transaction(function () use ($request, $user, $currentTokenId): void {
            $user->forceFill(['password' => $request->validated('password')])->save();

            // Se revocan todos los tokens menos el de esta sesión.
            $tokenIds = Passport::token()
                ->newQuery()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $currentTokenId)
                ->pluck('id');

            Passport::refreshToken()
                ->newQuery()
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);

            Passport::token()
                ->newQuery()
                ->whereIn('id', $tokenIds)
                ->update(['revoked' => true]);
        });

        return response()->json([
            'message' => 'Contraseña actualizada. Se cerraron las demás sesiones.',
        ]);
    }
}
