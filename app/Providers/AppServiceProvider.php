<?php

namespace App\Providers;

use App\Broadcasting\DatabaseChannel;
use App\Models\PersonalAccessToken;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn($user) => $user->role === 'administrador' ? true : null);
        Gate::define('manage-whatsapp', fn($user) => $user->role === 'administrador');
        Gate::define('manage-users', fn($user) => $user->role === 'administrador');

        /**
         * Define a regra padrão para novas senhas:
         *
         * Mínimo de 8 caracteres
         * Pelo menos uma letra maiúscula e uma minúscula
         * Pelo menos um número
         * Pelo menos um caractere especial
         */
        Password::defaults(function () {
            return Password::min(8)
                ->mixedCase()
                ->numbers()
                ->symbols()
            ;
        });

        // Altera a tabela padrão de tokens de acesso do Laravel Sanctum
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Registra um novo canal de transmissão para o banco de dados
        $this->app->make(ChannelManager::class)->extend('database', fn() => new DatabaseChannel());

        if ($this->app->environment('production')) {
            URL::forceRootUrl(config('app.url'));
        }

        // Nota: O Laravel 11 faz auto-discovery de listeners automaticamente.
        // Não é necessário registrar manualmente eventos que seguem a convenção:
        // app/Listeners/{EventName}*.php para app/Events/{EventName}.php
    }
}
