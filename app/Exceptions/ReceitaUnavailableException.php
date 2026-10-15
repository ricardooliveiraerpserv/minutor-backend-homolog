<?php

namespace App\Exceptions;

/**
 * Lançada quando NENHUM provedor de CNPJ conseguiu responder (5xx, timeout,
 * rate limit) — ou seja, não dá pra afirmar que o CNPJ é inválido; é só
 * indisponibilidade temporária. Permite distinguir "tente de novo" de
 * "CNPJ não encontrado".
 */
class ReceitaUnavailableException extends \RuntimeException {}
