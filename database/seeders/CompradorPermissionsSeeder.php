<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class CompradorPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permisos para compradores
        $permissions = [
            'view_products',         // Ver productos
            'add_to_cart',           // Agregar al carrito
            'place_orders',          // Realizar órdenes
            'view_own_orders',       // Ver sus propias órdenes
            'rate_products',         // Calificar productos
            'chat_with_tendero',     // Hablar con tendero o soporte
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Crear o asignar rol COMPRADOR (rol_id = 1 por ejemplo)
        $compradorRole = Rol::firstOrCreate(
             ['rol_id' => 1],
            ['name' => 'comprador', 'guard_name' => 'web']
        );

        // Asignar permisos al rol
        foreach ($permissions as $perm) {
            $permission = Permission::where('name', $perm)->first();
            if ($permission) {
                $compradorRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }
}
