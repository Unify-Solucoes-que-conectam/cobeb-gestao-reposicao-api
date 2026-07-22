<?php

namespace App\Http\Controllers;

use App\Models\Filial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FiliaisController extends Controller
{
    // Listar todas as filiais
    public function index(Request $request)
    {

        // consultar dados das filiais e filtrar por descricao ou codigo se os parâmetros forem fornecidos
        $query = Filial::query();

        if ($request->has('search')) {
            $query->where('descricao', 'like', '%' . $request->input('search') . '%')
                ->orWhere('codigo', 'like', '%' . $request->input('search') . '%');
        }

        $filiais = $query->get();

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de filiais realizada com sucesso.',
                'data' => $filiais
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar filiais.'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), Filial::createRules(), Filial::messages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ]);
        }

        // lógica para criar uma nova filial
        try {
            Filial::create([
                'descricao' => $request->descricao,
                'codigo' => $request->codigo,
                'usuario_responsavel_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Filial cadastrada com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar filial: ' . $e->getMessage()
            ]);
        }
    }

    // função para atualizar os dados da filial
    public function update(Request $request, $id)
    {
        // configurar regras de validação
        $rules = [
            'descricao' => ['required'],
            'codigo' => ['nullable'],
        ];

        // validação dos dados recebidos
        $validator = Validator::make($request->all(), $rules, [
            'codigo.required' => 'O código é obrigatório.',
            'descricao.required' => 'A descrição é obrigatória.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ]);
        }

        // lógica para atualizar os dados da filial com o ID fornecido
        try {
            // encontrar filial pelo ID
            $filial = Filial::find($id);

            if (!$filial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Filial não encontrada.'
                ]);
            }

            // atualizar dados da filial
            $filial->descricao = $request->descricao;
            $filial->codigo = $request->codigo;
            $filial->save();

            return response()->json([
                'success' => true,
                'message' => 'Dados da filial atualizados com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar os dados da filial: ' . $e->getMessage()
            ]);
        }
    }

    // Exibir uma filial específica
    public function show($id)
    {
        $filial = Filial::find($id);

        try {
            if (!$filial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Filial não encontrada.',
                ], 404);
            }
            // dados da filial formatados
            $filialArray = $filial->toArray();
            $filialArray['usuario_responsavel_id'] = $filial->usuario_responsavel_id;
            return response()->json([
                'success' => true,
                'message' => 'Filial encontrada com sucesso.',
                'data' => $filialArray
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar filial.',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    // Deletar uma filial
    public function destroy($id)
    {
        $filial = Filial::find($id);
        try {
            if (!$filial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Filial não encontrada para exclusão.',
                ], 404);
            }
            $filial->delete();
            return response()->json([
                'success' => true,
                'message' => 'Filial deletada com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao deletar filial.',
                'data' => $e->getMessage()
            ], 400);
        }
    }
}
