<?php

namespace App\Traits;

trait PasswordGenerator
{
    /**
     * Gera uma senha temporária segura
     */
    public function generateTemporaryPassword(): string
    {
        // Gera uma senha com 12 caracteres incluindo letras, números e símbolos
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $symbols = '@#$%&*!';

        $password = '';
        
        // Garantir pelo menos um de cada tipo
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        // Completar com caracteres aleatórios
        $allChars = $lowercase . $uppercase . $numbers . $symbols;
        for ($i = 4; $i < 12; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Embaralhar a string
        return str_shuffle($password);
    }
}
