<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="Minutor API",
 *      description="Sistema de apontamento de horas e despesas",
 *      @OA\Contact(
 *          email="admin@minutor.com"
 *      ),
 *      @OA\License(
 *          name="MIT",
 *          url="https://opensource.org/licenses/MIT"
 *      )
 * )
 * 
 * @OA\Server(
 *      url=L5_SWAGGER_CONST_HOST,
 *      description="Servidor de Desenvolvimento"
 * )
 * 
 * @OA\SecurityScheme(
 *      securityScheme="bearerAuth",
 *      type="http",
 *      scheme="bearer",
 *      bearerFormat="JWT",
 *      description="Token de autenticação Bearer obtido através do endpoint de login"
 * )
 * 
 * @OA\Tag(
 *     name="Autenticação",
 *     description="Endpoints para login, logout e verificação de tokens"
 * )
 * 
 * @OA\Tag(
 *     name="Usuário",
 *     description="Endpoints para gerenciamento de dados do usuário"
 * )
 * 
 * @OA\Tag(
 *     name="Recuperação de Senha",
 *     description="Endpoints para reset e recuperação de senhas"
 * )
 * 
 * @OA\Tag(
 *     name="Sistema",
 *     description="Endpoints de sistema e health check"
 * )
 */
abstract class Controller
{
    //
}
