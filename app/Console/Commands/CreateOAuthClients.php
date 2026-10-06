<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Crea los clientes OAuth2 que consume Punto Plus:
 *
 *   1. App móvil (Authorization Code + PKCE, cliente público) — flujo recomendado.
 *   2. Password grant (cliente confidencial) — usado por /api/auth/login y
 *      /api/auth/refresh para emitir tokens con refresh token de un solo paso.
 *   3. Personal access client — necesario para los tokens personales de Passport.
 *
 * Con `--write-env` deja las credenciales en el .env (no se pueden recuperar
 * después: el secreto se guarda hasheado).
 */
class CreateOAuthClients extends Command
{
    protected $signature = 'punto-plus:oauth-clients
                            {--name= : Nombre base de los clientes}
                            {--write-env : Escribe las credenciales en el archivo .env}
                            {--force : Regenera el secreto del cliente password grant}';

    protected $description = 'Crea/actualiza los clientes OAuth2 (PKCE, password grant y personal access) de Punto Plus';

    public function handle(ClientRepository $clients): int
    {
        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            $this->components->warn('No hay claves RSA de Passport: ejecuta primero `php artisan passport:install`.');
        }

        $baseName = (string) ($this->option('name') ?: config('app.name', 'Punto Plus'));
        $provider = config('auth.guards.api.provider', 'users');

        // 1) App móvil (PKCE, cliente público: sin secreto).
        $redirectUris = (array) config('punto_plus.oauth.mobile_redirect_uris');

        $pkce = $this->findExisting($clients, fn (Client $client): bool => $client->hasGrantType('authorization_code')
            && $client->secret === null);

        if ($pkce === null || $this->option('force')) {
            $pkce = $clients->createAuthorizationCodeGrantClient(
                name: $baseName.' — App móvil (PKCE)',
                redirectUris: $redirectUris,
                confidential: false,
            );

            $this->components->info('Cliente PKCE creado: '.$pkce->getKey());
        }

        // 2) Password grant (cliente confidencial, con secreto).
        $password = $this->findExisting($clients, fn (Client $client): bool => $client->hasGrantType('password'));

        if ($password === null) {
            $password = $clients->createPasswordGrantClient(
                name: $baseName.' — Password grant',
                provider: $provider,
                confidential: true,
            );

            $this->components->info('Cliente password grant creado: '.$password->getKey());
        } elseif ($this->option('force')) {
            $password->forceFill(['secret' => Str::random(40)])->save();

            $this->components->info('Secreto del cliente password grant regenerado.');
        }

        $plainSecret = $password->plainSecret;

        if ($plainSecret === null && ! $this->option('force')) {
            $this->components->warn(
                'El secreto del cliente password grant ya existe (hasheado) y no se puede recuperar. '.
                'Ejecuta de nuevo con --force para generar uno nuevo y volver a escribirlo en el .env.'
            );
        }

        // 3) Personal access client (para tokens personales).
        try {
            $clients->personalAccessClient($provider);
            $this->components->info('Cliente personal access ya existente.');
        } catch (\Throwable) {
            $personal = $clients->createPersonalAccessGrantClient($baseName.' — Personal access', $provider);
            $this->components->info('Cliente personal access creado: '.$personal->getKey());
        }

        $this->newLine();
        $this->components->twoColumnDetail('PKCE client_id', $pkce->getKey());
        $this->components->twoColumnDetail('PKCE redirect_uris', implode(', ', $redirectUris));
        $this->components->twoColumnDetail('Password client_id', $password->getKey());

        if ($plainSecret !== null) {
            $this->components->twoColumnDetail('Password client_secret', $plainSecret);

            if ($this->option('write-env')) {
                $this->writeEnv([
                    'PASSPORT_PASSWORD_CLIENT_ID' => $password->getKey(),
                    'PASSPORT_PASSWORD_CLIENT_SECRET' => $plainSecret,
                ]);
            } else {
                $this->components->warn('Guarda el client_secret en el .env (o ejecuta con --write-env).');
            }
        } elseif ($this->option('write-env')) {
            $this->writeEnv(['PASSPORT_PASSWORD_CLIENT_ID' => $password->getKey()]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable(Client): bool  $matcher
     */
    private function findExisting(ClientRepository $clients, callable $matcher): ?Client
    {
        return Client::query()
            ->where('revoked', false)
            ->get()
            ->first($matcher);
    }

    /**
     * Escribe (o reemplaza) variables en el archivo .env.
     *
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->components->warn('No existe .env: copia .env.example antes de continuar.');

            return;
        }

        $contents = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;

            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
                $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
            } else {
                $contents = rtrim($contents, "\n").PHP_EOL.$line.PHP_EOL;
            }
        }

        file_put_contents($path, $contents);

        $this->components->info('Credenciales escritas en .env (ejecuta `php artisan config:clear`).');
    }
}
