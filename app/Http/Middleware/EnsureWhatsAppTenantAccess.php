<?php

namespace App\Http\Middleware;

use App\Models\Filial;
use App\Models\WhatsAppConfiguration;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuração de WhatsApp por tenant:
 * - Geral: pode configurar qualquer filial e a instância global.
 * - Filial: só a filial conectada, e sem mexer na instância global
 *   (ela é usada nos envios de todas as filiais).
 */
class EnsureWhatsAppTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::atual();
        $param = $request->route('filial');

        if ($tenant->geral || $param === null) {
            return $next($request);
        }

        $filialId = $param instanceof Filial ? $param->id : (string) $param;

        if ($tenant->filial?->id !== $filialId) {
            return $this->negar('Para configurar o WhatsApp de outras filiais, conecte-se no tenant Geral.');
        }

        // envio de teste não altera a configuração
        if (!$request->isMethodSafe() && !$request->routeIs('whatsapp.test')) {
            // configuração efetiva da filial: a global (se existir) prevalece sobre a própria
            $usaGlobal = WhatsAppConfiguration::query()->where('is_global', true)->exists();

            if ($usaGlobal || $request->routeIs('whatsapp.global') || $request->boolean('is_global')) {
                return $this->negar('A instância geral é usada por todas as filiais e só pode ser alterada no tenant Geral.');
            }
        }

        return $next($request);
    }

    private function negar(string $mensagem): Response
    {
        return response()->json(['success' => false, 'code' => 'WHATSAPP_TENANT_FORBIDDEN', 'message' => $mensagem], 403);
    }
}
