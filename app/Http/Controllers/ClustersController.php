<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClustersController extends Controller
{
    // Listar todos os clusters
    public function index(Request $request)
    {

        // consultar dados dos clusters e filtrar por descricao ou codigo se os parâmetros forem fornecidos
        $query = Cluster::query();

        if ($request->has('search')) {
            $query->where('descricao', 'like', '%' . $request->input('search') . '%')
                ->orWhere('codigo', 'like', '%' . $request->input('search') . '%');
        }

        $clusters = $query->get();

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de clusters realizada com sucesso.',
                'data' => $clusters
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar clusters.'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), Cluster::createRules(), Cluster::messages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ]);
        }

        // lógica para criar um novo cluster
        try {
            Cluster::create([
                'descricao' => $request->descricao,
                'codigo' => $request->codigo,
                'usuario_responsavel_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cluster cadastrado com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar cluster: ' . $e->getMessage()
            ]);
        }
    }

    // função para atualizar os dados do cluster
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

        // lógica para atualizar os dados do cluster com o ID fornecido
        try {
            // encontrar cluster pelo ID
            $cluster = Cluster::find($id);

            if (!$cluster) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cluster não encontrado.'
                ]);
            }

            // atualizar dados do cluster
            $cluster->descricao = $request->descricao;
            $cluster->codigo = $request->codigo;
            $cluster->save();

            return response()->json([
                'success' => true,
                'message' => 'Dados do cluster atualizados com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar os dados do cluster: ' . $e->getMessage()
            ]);
        }
    }

    // Exibir um cluster específico
    public function show($id)
    {
        $cluster = Cluster::find($id);

        try {
            if (!$cluster) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cluster não encontrado.',
                ], 404);
            }
            // dados do cluster formatados
            $clusterArray = $cluster->toArray();
            $clusterArray['usuario_responsavel_id'] = $cluster->usuario_responsavel_id;
            return response()->json([
                'success' => true,
                'message' => 'Cluster encontrado com sucesso.',
                'data' => $clusterArray
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar cluster.',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    // Deletar um cluster
    public function destroy($id)
    {
        $cluster = Cluster::find($id);
        try {
            if (!$cluster) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cluster não encontrado para exclusão.',
                ], 404);
            }
            $cluster->delete();
            return response()->json([
                'success' => true,
                'message' => 'Cluster deletado com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao deletar cluster.',
                'data' => $e->getMessage()
            ], 400);
        }
    }
}
