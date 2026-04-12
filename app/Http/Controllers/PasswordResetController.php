<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Auth\Events\PasswordReset;
use App\Notifications\TemporaryPasswordNotification;
use App\Traits\PasswordGenerator;


class PasswordResetController extends Controller
{
    use PasswordGenerator;

    /**
     * @OA\Post(
     *     path="/api/v1/auth/forgot-password",
     *     tags={"Recuperação de Senha"},
     *     summary="Solicitar reset de senha",
     *     description="Gera senha temporária e envia por email",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@minutor.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Senha temporária enviada",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Senha temporária enviada para seu email")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erro ao enviar email",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Erro ao enviar senha temporária")
     *         )
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Muitas tentativas",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Muitas tentativas de recuperação. Tente novamente em 1 hora."),
     *             @OA\Property(property="retry_after", type="integer", example=3600)
     *         )
     *     )
     * )
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        \Log::info('🔐 [FORGOT PASSWORD] Iniciando processo de recuperação de senha');
        \Log::info('📧 [FORGOT PASSWORD] Dados recebidos:', $request->all());

        $email = strtolower(trim($request->input('email', '')));

        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'O email é obrigatório',
            'email.email' => 'Formato de email inválido',
            'email.exists' => 'Email não encontrado em nossa base de dados',
        ]);

        if ($validator->fails()) {
            \Log::warning('⚠️ [FORGOT PASSWORD] Validação falhou:', $validator->errors()->toArray());
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }
        \Log::info("📤 [FORGOT PASSWORD] Tentando enviar email para: {$email}");

        // Verificar configurações de email
        \Log::info('⚙️ [FORGOT PASSWORD] Configurações de email:', [
            'MAIL_MAILER' => config('mail.default'),
            'MAIL_HOST' => config('mail.mailers.smtp.host'),
            'MAIL_PORT' => config('mail.mailers.smtp.port'),
            'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
            'MAIL_FROM_ADDRESS' => config('mail.from.address'),
            'MAIL_FROM_NAME' => config('mail.from.name'),
        ]);

        try {
            // Buscar o usuário
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                \Log::warning("⚠️ [FORGOT PASSWORD] Usuário não encontrado: {$email}");
                return response()->json([
                    'message' => 'Dados inválidos',
                    'errors' => ['email' => ['Email não encontrado em nossa base de dados']]
                ], 422);
            }

            // Gerar senha temporária
            $temporaryPassword = $this->generateTemporaryPassword();
            \Log::info("🔑 [FORGOT PASSWORD] Senha temporária gerada para: {$email}");

            // Definir senha temporária (expira em 24 horas)
            $user->setTemporaryPassword($temporaryPassword, 24);

            // Enviar email com a senha temporária
            $user->notify(new TemporaryPasswordNotification($temporaryPassword, 24));

            \Log::info("✅ [FORGOT PASSWORD] Senha temporária enviada com sucesso para: {$email}");
            
            return response()->json([
                'message' => 'Senha temporária enviada para seu email'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('🚨 [FORGOT PASSWORD] Exceção capturada:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Erro interno ao processar solicitação de recuperação'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/reset-password",
     *     tags={"Recuperação de Senha"},
     *     summary="Resetar senha com token",
     *     description="Redefine a senha usando token recebido por email",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","token","password","password_confirmation"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@minutor.com"),
     *             @OA\Property(property="token", type="string", example="reset_token_from_email"),
     *             @OA\Property(property="password", type="string", format="password", example="nova_senha_123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="nova_senha_123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Senha redefinida com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Senha redefinida com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Token inválido ou expirado")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Muitas tentativas",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Muitas tentativas de reset. Tente novamente em 1 hora."),
     *             @OA\Property(property="retry_after", type="integer", example=3600)
     *         )
     *     )
     * )
     */
    public function resetPassword(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'token.required' => 'Token de recuperação é obrigatório',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'Formato de email inválido',
            'password.required' => 'A nova senha é obrigatória',
            'password.min' => 'A nova senha deve ter pelo menos 8 caracteres',
            'password.confirmed' => 'A confirmação da senha não confere',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                // Revoga todos os tokens existentes por segurança
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Senha redefinida com sucesso'
            ], 200);
        } else {
            return response()->json([
                'message' => 'Token inválido ou expirado'
            ], 400);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/verify-reset-token",
     *     tags={"Recuperação de Senha"},
     *     summary="Verificar se token é válido",
     *     description="Verifica se token de reset é válido (sem resetar a senha)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","token"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@minutor.com"),
     *             @OA\Property(property="token", type="string", example="reset_token_from_email")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token válido",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Token válido"),
     *             @OA\Property(property="valid", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Token inválido ou expirado"),
     *             @OA\Property(property="valid", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Email não encontrado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Email não encontrado"),
     *             @OA\Property(property="valid", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function verifyResetToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email não encontrado',
                'valid' => false
            ], 404);
        }

        // Verifica se o token é válido usando o broker de password do Laravel
        $tokenRepository = Password::getRepository();
        $token = $tokenRepository->exists($user, $request->token);

        return response()->json([
            'message' => $token ? 'Token válido' : 'Token inválido ou expirado',
            'valid' => $token
        ], $token ? 200 : 400);
    }

} 