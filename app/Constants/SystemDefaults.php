<?php

namespace App\Constants;

/**
 * Constantes para dados padrões do sistema que não podem ser excluídos.
 * 
 * Estes registros são criados pelos seeders (CoreSeeder) e são essenciais
 * para o funcionamento do sistema.
 */
class SystemDefaults
{
    /**
     * Códigos de tipos de serviço que não podem ser excluídos.
     */
    public const PROTECTED_SERVICE_TYPE_CODES = [
        'projeto',
        'sustentacao',
    ];

    /**
     * Códigos de tipos de contrato que não podem ser excluídos.
     */
    public const PROTECTED_CONTRACT_TYPE_CODES = [
        'closed',
        'fixed_hours',
        'monthly_hours',
        'on_demand',
    ];

    /**
     * Códigos de categorias de despesas que não podem ser excluídas.
     */
    public const PROTECTED_EXPENSE_CATEGORY_CODES = [
        // Categorias principais
        'transport',
        'food',
        'accommodation',
        'representation',
        'personal',
        'material_equipment',
        'administrative',
        'education',
        
        // Subcategorias de Transporte
        'taxi_app',
        'fuel_parking',
        'tickets',
        'car_rental',
        
        // Subcategorias de Alimentação
        'meals_travel',
        'snacks',
        'client_meal',
        
        // Subcategorias de Hospedagem e Viagem
        'hotel_tourism',
        'laundry_insurance',
        
        // Subcategorias de Representação
        'gifts_corporate',
        'business_lunch',
        
        // Subcategorias de Pessoais
        'advance_payment',
        'emergency_medicine',
        
        // Subcategorias de Material e Equipamento
        'office_tech_it',
        
        // Subcategorias de Administrativas
        'fees_postal_notary',
        
        // Subcategorias de Educação/Treinamento
        'courses_certs_events',
    ];

    /**
     * Verifica se um código de tipo de serviço é protegido.
     */
    public static function isProtectedServiceType(string $code): bool
    {
        return in_array($code, self::PROTECTED_SERVICE_TYPE_CODES);
    }

    /**
     * Verifica se um código de tipo de contrato é protegido.
     */
    public static function isProtectedContractType(string $code): bool
    {
        return in_array($code, self::PROTECTED_CONTRACT_TYPE_CODES);
    }

    /**
     * Verifica se um código de categoria de despesa é protegido.
     */
    public static function isProtectedExpenseCategory(string $code): bool
    {
        return in_array($code, self::PROTECTED_EXPENSE_CATEGORY_CODES);
    }
}
