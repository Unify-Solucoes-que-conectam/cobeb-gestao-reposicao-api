<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarMensagemWhatsAppJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        EnviarMensagemWhatsAppJob::dispatch($phone, 'text', $request->text);

        return response()->json([
            'success' => true,
            'message' => 'Mensagem enfileirada para envio.',
        ], 202);
    }
}
