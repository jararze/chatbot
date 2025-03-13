<?php

namespace Database\Seeders;

use App\Models\Truck;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TruckSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Primero, aseguremos que tenemos algunos camiones específicos
        $predefinedTrucks = [
            [
                'license_plate' => '123ABC',
                'driver_name' => 'Juan Pérez',
                'model' => 'Scania R500',
                'year' => 2022,
                'last_maintenance' => now()->subDays(30),
                'status' => 'active',
                'additional_info' => 'Camión principal para rutas largas',
            ],
            [
                'license_plate' => '789XYZ',
                'driver_name' => 'María García',
                'model' => 'Volvo FH16',
                'year' => 2021,
                'last_maintenance' => now()->subDays(15),
                'status' => 'maintenance',
                'additional_info' => 'Programado para mantenimiento el próximo mes',
            ],
            [
                'license_plate' => '456DEF',
                'driver_name' => 'Carlos Rodríguez',
                'model' => 'Mercedes-Benz Actros',
                'year' => 2023,
                'last_maintenance' => now()->subDays(60),
                'status' => 'active',
                'additional_info' => 'Camión para distribución urbana',
            ],
            [
                'license_plate' => '923XIP',
                'driver_name' => 'Jorge Arze',
                'model' => 'Mercedes-Benz Actros',
                'year' => 2023,
                'last_maintenance' => now()->subDays(60),
                'status' => 'active',
                'additional_info' => 'Camión para distribución urbana',
            ],
        ];

        // Crear los camiones predefinidos
        foreach ($predefinedTrucks as $truckData) {
            Truck::create($truckData);
        }

        // Además, podemos crear camiones aleatorios usando la factory
        Truck::factory()->count(100)->create();

        // También podemos crear algunos camiones con estados específicos
        Truck::factory()->count(3)->active()->create();
        Truck::factory()->count(2)->inMaintenance()->create();
    }
}
