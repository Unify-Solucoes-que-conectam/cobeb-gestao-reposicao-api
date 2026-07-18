<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\WhatsAppService;

class NotificarClienteController extends Controller
{
    public function notify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:11'],
            'text' => ['required', 'string'],
        ], [
            'phone.required' => 'O telefone é obrigatório.',
            'phone.min' => 'O telefone deve ter pelo menos 10 dígitos.',
            'phone.max' => 'O telefone deve ter no máximo 11 dígitos.',
            'text.required' => 'O texto é obrigatório.',
        ]);

        $phone = preg_replace('/\D/', '', $request->phone);

        // Enviar via WhatsApp
        $whatsapp = new WhatsAppService();
        $sent = $whatsapp->sendMessage($phone, $request->text);

        if (!$sent) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar mensagem. Tente novamente.'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mensagem enviada.',
        ]);
    }
}
