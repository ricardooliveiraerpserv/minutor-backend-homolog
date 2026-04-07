<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ExpenseCategory;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Transporte',
                'code' => 'transport',
                'description' => 'Despesas relacionadas a transporte e locomoção',
                'sort_order' => 1,
                'subcategories' => [
                    ['name' => 'Táxi / Aplicativo (Uber, 99)', 'code' => 'taxi_app', 'sort_order' => 1],
                    ['name' => 'Combustível, Estacionamento, Pedágio', 'code' => 'fuel_parking', 'sort_order' => 2],
                    ['name' => 'Passagens (aérea, rodoviária)', 'code' => 'tickets', 'sort_order' => 3],
                    ['name' => 'Locação de veículo', 'code' => 'car_rental', 'sort_order' => 4],
                ]
            ],
            [
                'name' => 'Alimentação',
                'code' => 'food',
                'description' => 'Despesas com alimentação e refeições',
                'sort_order' => 2,
                'subcategories' => [
                    ['name' => 'Almoço/Jantar em viagem', 'code' => 'meals_travel', 'sort_order' => 1],
                    ['name' => 'Lanches', 'code' => 'snacks', 'sort_order' => 2],
                    ['name' => 'Refeição com cliente', 'code' => 'client_meal', 'sort_order' => 3],
                ]
            ],
            [
                'name' => 'Hospedagem e Viagem',
                'code' => 'accommodation',
                'description' => 'Despesas com hospedagem e viagem',
                'sort_order' => 3,
                'subcategories' => [
                    ['name' => 'Diária de hotel, Taxa de turismo', 'code' => 'hotel_tourism', 'sort_order' => 1],
                    ['name' => 'Lavanderia, Seguro viagem', 'code' => 'laundry_insurance', 'sort_order' => 2],
                ]
            ],
            [
                'name' => 'Representação',
                'code' => 'representation',
                'description' => 'Despesas de representação e relacionamento',
                'sort_order' => 4,
                'subcategories' => [
                    ['name' => 'Brindes, Presentes corporativos', 'code' => 'gifts_corporate', 'sort_order' => 1],
                    ['name' => 'Almoço de negócios', 'code' => 'business_lunch', 'sort_order' => 2],
                ]
            ],
            [
                'name' => 'Pessoais',
                'code' => 'personal',
                'description' => 'Despesas pessoais emergenciais',
                'sort_order' => 5,
                'subcategories' => [
                    ['name' => 'Adiantamento de verba', 'code' => 'advance_payment', 'sort_order' => 1],
                    ['name' => 'Compras emergenciais, Medicamentos', 'code' => 'emergency_medicine', 'sort_order' => 2],
                ]
            ],
            [
                'name' => 'Material e Equipamento',
                'code' => 'material_equipment',
                'description' => 'Despesas com materiais e equipamentos',
                'sort_order' => 6,
                'subcategories' => [
                    ['name' => 'Escritório, Técnicos, Informática', 'code' => 'office_tech_it', 'sort_order' => 1],
                ]
            ],
            [
                'name' => 'Administrativas',
                'code' => 'administrative',
                'description' => 'Despesas administrativas e burocráticas',
                'sort_order' => 7,
                'subcategories' => [
                    ['name' => 'Taxas bancárias, Correios, Cartório', 'code' => 'fees_postal_notary', 'sort_order' => 1],
                ]
            ],
            [
                'name' => 'Educação/Treinamento',
                'code' => 'education',
                'description' => 'Despesas com educação e treinamento',
                'sort_order' => 8,
                'subcategories' => [
                    ['name' => 'Cursos, Certificações, Inscrições em eventos', 'code' => 'courses_certs_events', 'sort_order' => 1],
                ]
            ],
        ];

        foreach ($categories as $categoryData) {
            $subcategories = $categoryData['subcategories'] ?? [];
            unset($categoryData['subcategories']);

            // Criar categoria principal (usando firstOrCreate para evitar duplicatas)
            $category = ExpenseCategory::firstOrCreate(
                ['code' => $categoryData['code']], // buscar por código
                $categoryData
            );

            // Criar subcategorias
            foreach ($subcategories as $subcategoryData) {
                $subcategoryData['parent_id'] = $category->id;
                $subcategoryData['description'] = $subcategoryData['name'];
                ExpenseCategory::firstOrCreate(
                    ['code' => $subcategoryData['code']], // buscar por código
                    $subcategoryData
                );
            }
        }

        $this->command->info('Categorias de despesas criadas com sucesso!');
    }
}
