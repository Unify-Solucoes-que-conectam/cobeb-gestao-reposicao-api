<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant (filial) da requisição a partir do header X-Tenant,
 * validando se o usuário autenticado tem acesso a ele.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario) {
            $tenant = Tenant::resolver($usuario, $request->header('X-Tenant'));

            if ($tenant === null) {
                return response()->json([
                    'success' => false,
                    'code' => 'tenant_forbidden',
                    'message' => 'Você não tem acesso à filial selecionada.',
                ], 403);
            }

            app()->instance(Tenant::class, $tenant);
        }

        return $next($request);
    }
}
