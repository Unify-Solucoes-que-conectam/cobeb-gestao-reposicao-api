<?php

namespace App\Http\Controllers;

use App\Models\Motorista;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\MotoristaResource;

class MotoristasController extends Controller
{
    // Listar todos os motoristas
    public function index(Request $request)
    {

        // consultar dados dos motoristas e filtrar por nome ou cpf se os parâmetros forem fornecidos
        $query = Motorista::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($query) use ($search) {
                $query->where('nome', 'like', '%' . $search . '%')
                    ->orWhere('cpf', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $motoristas = $query->with([
            'mapaAtual',
            'usuario',
            'filial',
            'cluster'
        ])->get();

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de motoristas realizada com sucesso.',
                'data' => MotoristaResource::collection($motoristas)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar motoristas:' . $e->getMessage()
            ], 500);
        }
    }

    // função para criar um novo motorista
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), Motorista::createRules(), Motorista::messages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ]);
        }

        // lógica para criar um novo motorista
        try {

            $usuario = Usuario::create([
                'nome' => $request->input('nome'),
                'cpf' => $request->input('cpf'),
                'senha' => $request->input('cpf'),
                'role' => 'motorista',
                'primeiro_acesso' => true,
            ]);

            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao cadastrar usuário para o motorista.'
                ]);
            }

            $motorista = Motorista::create([
                'codigo' => $request->input('codigo'),
                'filial_id' => $request->input('filial_id'),
                'cluster_id' => $request->input('cluster_id'),
                'usuario_id' => $usuario->id,
                'status' => $request->input('status') ?? 'ativo',
                'data_admissao' => $request->input('data_admissao'),
                'data_inativacao' => $request->input('data_inativacao'),
            ]);

            if (!$motorista) {

                // roolback do usuário criado caso o motorista não seja criado
                $usuario->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao cadastrar motorista.'
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Motorista cadastrado com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar motorista: ' . $e->getMessage()
            ]);
        }
    }

    // função para atualizar os dados do motorista
    public function update(Request $request, $id)
    {
        // 1. Encontrar o motorista primeiro (com a relação de usuário)
        $motorista = Motorista::with('usuario')->find($id);

        if (!$motorista) {
            return response()->json([
                'success' => false,
                'message' => 'Motorista não encontrado.'
            ], 404);
        }

        // 2. Pegar o ID do usuário correto vinculado a este motorista
        $usuarioId = $motorista->usuario_id ?? $motorista->usuario?->id;

        // 3. Validar passando os IDs corretos para ignorar no Rule::unique
        $validator = Validator::make(
            $request->all(),
            Motorista::updateRules($id, $usuarioId),
            Motorista::messages()
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // 4. Obter APENAS os dados validados que foram enviados na requisição
            $dadosValidados = $validator->validated();

            // 5. Atualizar os dados do Usuário (se foram enviados nome/cpf)
            if ($motorista->usuario) {
                $dadosUsuario = array_intersect_key(
                    $dadosValidados,
                    array_flip(['nome', 'cpf'])
                );

                if (!empty($dadosUsuario)) {
                    $motorista->usuario->update($dadosUsuario);
                }
            }

            // 6. Filtrar dados do Motorista (remove nome e cpf para não dar erro de coluna inexistente)
            $dadosMotorista = array_diff_key(
                $dadosValidados,
                array_flip(['nome', 'cpf'])
            );

            // Atualiza o motorista apenas com as colunas dele
            if (!empty($dadosMotorista)) {
                $motorista->update($dadosMotorista);
            }

            return response()->json([
                'success' => true,
                'message' => 'Dados do motorista atualizados com sucesso.',
                'data' => new MotoristaResource($motorista->fresh(['usuario', 'filial', 'cluster']))
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar os dados do motorista: ' . $e->getMessage()
            ], 500);
        }
    }

    // Exibir um motorista específico
    public function show(string $id)
    {
        $motorista = Motorista::find($id);

        try {
            if (!$motorista) {
                return response()->json([
                    'success' => false,
                    'message' => 'Motorista não encontrado.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Motorista encontrado com sucesso.',
                'data' => MotoristaResource::make($motorista->load(['mapaAtual', 'usuario', 'filial', 'cluster']))
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar motorista.',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    // Deletar um motorista
    public function destroy(string $id)
    {
        $motorista = Motorista::find($id);
        try {
            if (!$motorista) {
                return response()->json([
                    'success' => false,
                    'message' => 'Motorista não encontrado para exclusão.',
                ], 404);
            }
            $motorista->delete();
            return response()->json([
                'success' => true,
                'message' => 'Motorista deletado com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao deletar motorista.',
                'data' => $e->getMessage()
            ], 400);
        }
    }
}
