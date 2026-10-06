<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f5f7; color: #111827; }
        .card { width: 100%; max-width: 420px; margin: 24px; padding: 32px; background: #fff; border-radius: 16px;
                box-shadow: 0 10px 30px rgba(17, 24, 39, .08); }
        h1 { margin: 0 0 4px; font-size: 22px; }
        p.lead { margin: 0 0 24px; color: #6b7280; font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        input[type=email], input[type=password] { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db;
                border-radius: 10px; font-size: 15px; }
        input:focus { outline: 2px solid #f59e0b; border-color: #f59e0b; }
        .row { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 14px; color: #374151; }
        button { width: 100%; margin-top: 22px; padding: 12px; border: 0; border-radius: 10px; background: #f59e0b;
                 color: #111827; font-weight: 700; font-size: 15px; cursor: pointer; }
        button:hover { background: #d97706; }
        .error { margin-top: 14px; padding: 10px 12px; border-radius: 10px; background: #fef2f2; color: #b91c1c;
                 font-size: 14px; }
        .brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; letter-spacing: -.2px; }
        .brand span { display: inline-block; width: 26px; height: 26px; border-radius: 8px; background: #f59e0b; }
        footer { margin-top: 24px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
<main class="card">
    <div class="brand"><span></span> Punto Plus</div>
    <h1>Inicia sesión para continuar</h1>
    <p class="lead">Necesitamos verificar tu identidad antes de autorizar a la aplicación.</p>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <div class="row">
            <input id="remember" name="remember" type="checkbox" value="1">
            <label for="remember" style="margin:0; font-weight:500;">Mantener la sesión abierta</label>
        </div>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <button type="submit">Entrar</button>
    </form>

    <footer>Si has llegado aquí desde la app de un negocio, vuelve a iniciar sesión allí tras autorizar.</footer>
</main>
</body>
</html>
