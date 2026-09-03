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
            'filial_id' => ['required', 'uuid', 'exists:filiais,id'],
            'phone' => ['required', 'string', 'min:10', 'max:11'],
            'text' => ['required', 'string'],
            'event' => ['nullable', 'in:manual_notification'],
        ], [
            'phone.required' => 'O telefone é obrigatório.',
            'phone.min' => 'O telefone deve ter pelo menos 10 dígitos.',
            'phone.max' => 'O telefone deve ter no máximo 11 dígitos.',
            'text.required' => 'O texto é obrigatório.',
        ]);

        $phone = preg_replace('/\D/', '', $request->phone);

        EnviarMensagemWhatsAppJob::dispatch(
            $request->filial_id,
            $phone,
            'text',
            $request->text,
            null,
            null,
            $request->event ?? 'manual_notification',
            [$request->text],
        );

        return response()->json([
            'success' => true,
            'message' => 'Mensagem enfileirada para envio.',
        ], 202);
    }
}
