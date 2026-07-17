<?php

namespace App\Http\Controllers;

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
     * Monta array de dados do usuário para retorno na autenticação.
     *
     * @param  Usuario  $user  Usuário autenticado
     * @return array Dados do usuário
     */
    private function userDataArray(Usuario $user): array
    {
        // 1. Monta os dados base
        $userData = [
            'id' => $user->id,
            'nome' => $user->nome,
            'cpf' => $user->cpf,
            'role' => $user->role,
            'primeiro_acesso' => $user->primeiro_acesso,
        ];

        // 2. Adiciona a lógica do mapa caso seja motorista
        if ($user->role === 'motorista') {

            // Busca o perfil de motorista usando o CPF do usuário
            $motorista = \App\Models\Motorista::where('cpf', $user->cpf)->first();

            if ($motorista) {
                // Se achou o motorista, busca o mapa ativo
                $mapaAtivo = \App\Models\Mapa::with('motorista')
                    ->where('motorista_id', $motorista->id)
                    ->where('status', 'ativo')
                    ->first();

                $userData['mapa'] = $mapaAtivo;
            } else {
                $userData['mapa'] = null;
            }
        }

        // 3. Retorna o array completo
        return $userData;
    }

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

        // gerar uuid para a notificação
        $notificationId = (string) Str::uuid();

        // notificação
        $notification = [
            'id' => $notificationId,
            'titulo' => 'Bem-vindo ao nosso sistema de gestão, ' . ucwords(strtolower($user->nome)) . '!',
            'mensagem' => 'Estamos felizes em tê-lo(a) conosco. Explore nossos recursos e aproveite ao máximo sua experiência.',
            'tipo' => 'info',
            'usuario_id' => $user->id,
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

        $menus = Menu::buildMenuTree(Menu::query()->get()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Login realizado com sucesso.',
            'data' => [
                'token' => $token,
                'usuario' => $this->userDataArray($user),
                'menus' => $menus,
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
     * Retorna os dados do usuário logado (Check Me)
     */
    /**
     * Retorna os dados do usuário logado (Check Me)
     */
    public function me(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $user = $request->user();
            $menus = \App\Models\Menu::buildMenuTree(\App\Models\Menu::query()->get()->toArray());

            // Pega os dados formatados do usuário gerados pelo seu método
            $userData = $this->userDataArray($user);

            // Verifica se a role do usuário é motorista
            if ($user->role === 'motorista') {

                // 1. Busca o perfil de motorista usando o CPF do usuário logado
                $motorista = \App\Models\Motorista::where('cpf', $user->cpf)->first();

                if ($motorista) {
                    // 2. Se achou o motorista, busca o mapa ativo atrelado ao ID dele
                    $mapaAtivo = \App\Models\Mapa::with('motorista')
                        ->where('motorista_id', $motorista->id)
                        ->where('status', 'ativo')
                        ->first();

                    $userData['mapa'] = $mapaAtivo;
                } else {
                    // Se por acaso não achar o motorista associado ao CPF, retorna nulo
                    $userData['mapa'] = null;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Dados do usuário autenticado',
                'data' => [
                    'usuario' => $userData,
                    'menus' => $menus,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter os dados do usuário autenticado',
                'error' => $e->getMessage()
            ], 500);
        }
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
