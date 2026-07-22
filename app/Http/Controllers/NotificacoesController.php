<?php

namespace App\Http\Controllers;
use Illuminate\Support\Str;
use App\Events\GlobalEvent;
use App\Http\Controllers\Controller;
use App\Models\Notificacao;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificacoesController extends Controller
{
    /**
     * Disparar notificação
     *
     * Recebe uma notificação, junto dos usuários/grupos que a receberão e dispara-a.
     */
    public function dispararNotificacao(Request $request)
    {
        try {
            $request->validate([
                'titulo' => ['nullable', 'string'],
                'mensagem' => ['required', 'string'],
                'tipo' => ['required', 'string', 'in:info,warning,error,success'],
                'menu_id' => ['nullable', 'exists:menus,id'],
                'link' => ['nullable', 'string'],
            ]);

            $data = $request->only(['titulo', 'mensagem', 'tipo', 'menu_id', 'link']);
            $requestOnly = array_merge($data, [
                'id' => (string) Str::uuid(),
                'data_envio' => now()
            ]);

            Notificacao::create($requestOnly);

            event(new GlobalEvent($data));

            return response()->json([
                'success' => true,
                'message' => 'Notificação disparada com sucesso.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao disparar notificação: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Buscar notificações
     *
     * Busca as notificações do usuário autenticado.
     */
    public function getAll(Request $request)
    {

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $notificacoes = Notificacao::query()
            ->with('menu')
            ->where('usuario_id', $user->id)
            ->orderBy('data_envio', 'asc')
            ->get()
            ->map(function (Notificacao $notificacao) {
                return [
                    'id' => $notificacao->id,
                    'titulo' => $notificacao->titulo,
                    'mensagem' => $notificacao->mensagem,
                    'tipo' => $notificacao->tipo,
                    'link' => $notificacao->menu ? $notificacao->menu->rota : null,
                    'data_envio' => $notificacao->data_envio,
                    'lida' => $notificacao->data_leitura !== null,
                ];
            })
        ;

        return response()->json([
            'success' => true,
            'message' => 'Notificações consultadas com sucesso.',
            'data' => $notificacoes,
        ]);
    }

    /**
     * Marcar como lida
     *
     * Recebe o ID de uma notificação e a marca como lida.
     */
    public function marcarComoLida(Request $request)
    {
        try {
            $request->validate([
                'id' => ['required', 'array'],
                'id.*' => ['required', 'string', 'exists:notificacoes,id'],
            ]);

            $notificacoes = Notificacao::query()
                ->whereIn('id', $request->input('id'))
                ->get()
            ;

            foreach ($notificacoes as $notificacao) {
                if ($notificacao->usuario_id === $request->user()->id) {
                    $notificacao->data_leitura = now();
                    $notificacao->save();
                }
            }

            return response()->json([
                'success' => true,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao marcar notificação como lida: ' . $e->getMessage(),
            ], 500);
        }
    }
}
