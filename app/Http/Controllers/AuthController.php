<?php

namespace App\Http\Controllers;

use App\Http\Resources\MenuResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Menu;
use App\Models\Usuario;
use App\Notifications\UserNotification;
// use App\Services\GoogleAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Illuminate\Validation\Rules\Password as RulesPassword;

class AuthController extends Controller
{

    /**
     * Gera e retorna um token único para o usuário, removendo tokens antigos.
     *
     * @param  Usuario  $user  Usuário para o qual gerar o token
     * @param  string  $tokenName  Nome do token (padrão: 'web_auth')
     * @return string Token gerado
     */
    private function generateToken(Usuario $user, $tokenName = 'web_auth'): string
    {
        $user->tokens()->where('name', $tokenName)->delete();

        return $user->createToken($tokenName)->plainTextToken;
    }

    /**
     * Autenticação do usuário básica.
     *
     * @param  Request  $request  Dados da requisição
     * @return JsonResponse $JsonResponse      Token de autenticação e dados do usuário
     */
    public function login(Request $request)
    {
        // Valida os dados enviados pelo usuário
        $payload = $request->validate([
            'cpf' => ['required', 'string'],
            'senha' => ['required', 'string'],
        ]);

        // Busca o usuário pelo campo 'cpf'
        $user = Usuario::query()
            ->where('cpf', $payload['cpf'])
            ->first();

        // Verifica se o usuário existe e está ativo
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso não autorizado. Tente novamente ou contate o administrador.',
                'debug_error' => 'Usuário não encontrado ou senha inválida.',
            ], 401);
        }

        // Validação de senha segura
        if (!Hash::check($payload['senha'], $user->senha)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciais inválidas.',
            ], 401);
        }

        // Define o nome do token conforme o ambiente e o gera
        $tokenName = (config('app.env') !== 'production' && str_starts_with($request->userAgent(), 'PostmanRuntime/')) ? 'postman_auth' : 'web_auth';
        $token = $this->generateToken($user, $tokenName);

        // notificação apenas para usuários do monitoramento
        if ($user->role === 'monitoramento') {

            // gerar uuid para a notificação
            $notificationId = (string) Str::uuid();

            $notification = [
                'id' => $notificationId,
                'titulo' => 'Bem-vindo ao sistema de gestão de reposição, ' . ucwords(strtolower($user->nome)) . '!',
                'mensagem' => 'Você está logado no sistema de monitoramento.',
                'tipo' => 'info',
            ];

            // notificação de boas vindas, só aparece se a notificação ainda não foi enviada
            if (!$user->notificacoes()
                ->where('titulo', $notification['titulo'])
                ->where('mensagem', $notification['mensagem'])
                ->where('tipo', $notification['tipo'])
                ->exists()) {
                // enviar notificação
                $user->notify(new UserNotification($notification));
            }
        }

        $menus = Menu::with('subMenus')->where('menu_pai_id', null)->get();

        return response()->json([
            'success' => true,
            'message' => 'Login realizado com sucesso.',
            'data' => [
                'token' => $token,
                'usuario' => UsuarioResource::make($user->load('motorista', 'motorista.mapaAtual', 'motorista.filial')),
                'menus' => MenuResource::collection($menus),
            ],
        ]);
    }

    /**
     * Registrar novo usuário
     *
     * Cria um usuário e retorna o token JWT
     */
    public function register(Request $request)
    {
        // Valida os dados relativos ao usuário na requisição
        $validator = Validator::make($request->all(), Usuario::createRules(), Usuario::messages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro ao validar os dados do usuario.',
                'debug_errors' => $validator->errors(),
            ], 422);
        }

        Usuario::create([
            'nome' => $request->nome,
            'cpf' => $request->cpf,
            'senha' => $request->senha,
            'role' => $request->role,
            'primeiro_acesso' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Usuário registrado com sucesso.',
        ]);
    }

    /**
     * Logout
     *
     * Invalida o token de acesso do usuário
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso.',
        ]);
    }

    // função para redefinir a senha do usuário
    public function resetPassword(Request $request, $id)
    {

        // configurar regras de validação
        $rules = [
            'senha_antiga' => ['required'],
            'senha_nova' => ['required', 'confirmed:confirmar_senha_nova', RulesPassword::default()],
            'confirmar_senha_nova' => ['required'],
        ];

        // validação dos dados recebidos
        $validator = Validator::make($request->all(), $rules, [
            'senha_antiga.required' => 'A senha antiga é obrigatória.',
            'senha_antiga.password.mixed' => 'A senha deve conter letras maiúsculas, minúsculas, números e símbolos.',

            'senha_nova.min' => 'A senha deve ter no mínimo :min caracteres.',
            'senha_nova.password.mixed' => 'A senha deve conter letras maiúsculas, minúsculas, números e símbolos.',
            'senha_nova.confirmed' => 'As senhas não coincidem.',

            'confirmar_senha_nova.required' => 'A confirmação da senha é obrigatória.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ]);
        }

        // lógica para alterar a senha do usuário com o ID fornecido
        try {
            // encontrar usuário pelo ID
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado.'
                ]);
            }

            // validar se a senha antiga está correta
            if (!Hash::check($request->senha_antiga, $usuario->senha)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A senha antiga está incorreta.'
                ]);
            }

            // alterar primeiro_acesso para false se for true
            if ($usuario->primeiro_acesso) {
                $usuario->primeiro_acesso = false;
            }

            // atualizar senha do usuário
            $usuario->senha = $request->senha_nova;
            $usuario->save();

            return response()->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao alterar a senha: ' . $e->getMessage()
            ]);
        }
    }
}
