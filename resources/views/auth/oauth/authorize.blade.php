{{--
    Pantalla de consentimiento OAuth2 (Passport::authorizationView()).

    Passport entrega: $client, $user, $scopes, $request, $authToken.
    El POST debe ir a passport.authorizations.approve con el `auth_token` de la
    sesión; el rechazo es un DELETE a passport.authorizations.deny.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autorizar aplicación · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f5f7; color: #111827; }
        .card { width: 100%; max-width: 460px; margin: 24px; padding: 32px; background: #fff; border-radius: 16px;
                box-shadow: 0 10px 30px rgba(17, 24, 39, .08); }
        .brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; }
        .brand span { display: inline-block; width: 26px; height: 26px; border-radius: 8px; background: #f59e0b; }
        h1 { margin: 18px 0 6px; font-size: 21px; line-height: 1.3; }
        p { color: #4b5563; font-size: 14px; line-height: 1.55; }
        .client { font-weight: 700; color: #111827; }
        ul { list-style: none; margin: 18px 0 0; padding: 0; display: grid; gap: 10px; }
        li { display: flex; gap: 10px; padding: 12px 14px; background: #f9fafb; border: 1px solid #e5e7eb;
             border-radius: 12px; font-size: 14px; }
        li b { display: block; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #b45309; }
        .actions { display: flex; gap: 12px; margin-top: 26px; }
        button { flex: 1; padding: 12px; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
        .approve { background: #f59e0b; color: #111827; font-weight: 700; }
        .approve:hover { background: #d97706; }
        .deny { background: #fff; border-color: #d1d5db; color: #374151; }
        .deny:hover { background: #f9fafb; }
        .session { margin-top: 22px; font-size: 12px; color: #9ca3af; display: flex; justify-content: space-between; gap: 12px; }
        form { margin: 0; }
    </style>
</head>
<body>
<main class="card">
    <div class="brand"><span></span> Punto Plus</div>

    <h1>¿Autorizas a <span class="client">{{ $client->name }}</span> a usar tu cuenta?</h1>
    <p>
        La aplicación podrá actuar en tu nombre con los siguientes permisos.
        Podrás revocar el acceso en cualquier momento cerrando sesión.
    </p>

    <ul>
        @forelse ($scopes as $scope)
            <li>
                <div>
                    <b>{{ data_get($scope, 'id', 'scope') }}</b>
                    {{ data_get($scope, 'description', 'Permiso solicitado') }}
                </div>
            </li>
        @empty
            <li><div><b>acceso básico</b> Tokens de acceso según tu rol.</div></li>
        @endforelse
    </ul>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="approve">Autorizar</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="deny">Cancelar</button>
        </form>
    </div>

    <div class="session">
        <span>Sesión: {{ $user->email }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" style="all:unset; cursor:pointer; text-decoration:underline; color:#6b7280;">
                No soy yo
            </button>
        </form>
    </div>
</main>
</body>
</html>
