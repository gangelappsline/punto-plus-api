<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login por sesión imprescindible para el flujo OAuth2 Authorization Code:
 * Passport redirige aquí a los usuarios no autenticados que abren
 * /oauth/authorize (route('login')).
 *
 * La app móvil no usa estas pantallas para autenticarse: sólo las abre el
 * navegador del usuario durante el consentimiento.
 */
class AuthorizationViewController extends Controller
{
    public function showLogin(Request $request): View
    {
        return view('auth.login', [
            'intended' => $request->session()->get('url.intended'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = mb_strtolower(trim($credentials['email']));

        $authenticated = Auth::guard('web')->attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password'], 'is_active' => true],
            $request->boolean('remember'),
        );

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas o la cuenta está desactivada.',
            ]);
        }

        $request->session()->regenerate();

        Auth::guard('web')->user()?->forceFill(['last_login_at' => now()])->save();

        // `redirect()->intended()` devuelve al usuario a /oauth/authorize con su
        // query string original (client_id, redirect_uri, state, PKCE...).
        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
