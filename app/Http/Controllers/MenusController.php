<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use Illuminate\Http\Request;
use App\Http\Resources\MenuResource;

class MenusController extends Controller
{
    // Listar todos os menus
    public function read()
    {
        $menus = Menu::with('subMenus')->get();

        try {
            return response()->json([
                'success' => true,
                'message' => 'Consulta de menus realizada com sucesso.',
                'data' => MenuResource::collection($menus)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar menus:' . $e->getMessage()
            ], 500);
        }
    }

    // Criar novo menu
    public function create(Request $request)
    {
        $request->validate([
            'titulo' => 'required|string',
            'icone' => 'nullable|string',
            'rota' => 'nullable|string',
            'ordem' => 'nullable|integer',
            'menu_pai_id' => 'nullable|integer|exists:menus,id',
            'usuario_responsavel_id' => 'required|integer|exists:usuarios,id',
        ]);

        // validação de cadastro completo
        try {
            // Verifica se já existe menu com o mesmo título
            $existe = Menu::where('titulo', $request->titulo)->first();
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu já cadastrado.'
                ], 400);
            }
            Menu::create([
                'titulo' => $request->titulo,
                'icone' => $request->icone,
                'rota' => $request->rota,
                'ordem' => $request->ordem,
                'menu_pai_id' => $request->menu_pai_id,
                'usuario_responsavel_id' => $request->usuario_responsavel_id,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Menu cadastrado com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar menu.'
            ], 500);
        }
    }

    // Exibir um menu específico
    public function show($id)
    {
        $menu = Menu::find($id);

        try {
            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu não encontrado.',
                ], 404);
            }
            // dados do menu formatados
            $menuArray = $menu->toArray();
            $menuArray['usuario_responsavel_id'] = $menu->usuario_responsavel_id;
            return response()->json([
                'success' => true,
                'message' => 'Menu encontrado com sucesso.',
                'data' => $menuArray
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar menu.',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    // Atualizar um menu
    public function update(Request $request, $id)
    {
        $request->validate([
            'titulo' => 'required|string',
            'icone' => 'nullable|string',
            'rota' => 'nullable|string',
            'ordem' => 'nullable|integer',
            'menu_pai_id' => 'nullable|integer|exists:menus,id',
            'usuario_responsavel_id' => 'required|integer|exists:usuarios,id',
        ]);

        $menu = Menu::find($id);
        try {
            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu não encontrado para atualização.',
                ], 404);
            }
            $menu->update([
                'titulo' => $request->titulo,
                'icone' => $request->icone,
                'rota' => $request->rota,
                'ordem' => $request->ordem,
                'menu_pai_id' => $request->menu_pai_id,
                'usuario_responsavel_id' => $request->usuario_responsavel_id,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Menu atualizado com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar menu.',
                'data' => $e->getMessage()
            ], 400);
        }
    }

    // Deletar um menu
    public function delete($id)
    {
        $menu = Menu::find($id);
        try {
            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu não encontrado para exclusão.',
                ], 404);
            }
            $menu->delete();
            return response()->json([
                'success' => true,
                'message' => 'Menu deletado com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao deletar menu.',
                'data' => $e->getMessage()
            ], 400);
        }
    }
}
