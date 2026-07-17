<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvariaRequest extends FormRequest
{

    public function rules()
    {
        return [
            'cliente_id' => 'required|uuid|exists:clientes,id',
            'status' => 'required|string|in:pendente, em_analise, concluido',

            // Validação das Notas Fiscais relacionadas
            'notas_fiscais' => 'nullable|array',
            'notas_fiscais.*' => 'uuid|exists:notas_fiscais,id',

            // Validação dos Produtos relacionados
            'produtos' => 'required|array',
            'produtos.*.produto_id' => 'required|uuid|exists:produtos,id',
            'produtos.*.tipo_avaria_id' => 'required|uuid|exists:tipos_avaria,id',
            'produtos.*.quantidade' => 'required|integer|min:1',

            // Validação dos Anexos (Assumindo que os caminhos/arquivos já foram gerados ou serão enviados)
            'anexos' => 'nullable|array',
            'anexos.*' => 'string',
        ];
    }
}
