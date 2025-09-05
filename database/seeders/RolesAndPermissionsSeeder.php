<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Models\Rol;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Crear permisos base (los que quieras en tu app)
        $permissions = [
            'create business',
            'edit business',
            'delete business',
            'view business',
            'create product',
            'edit product',
            'delete product',
            'view product',
            'create order',
            'edit order',
            'delete order',
            'view order',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Crear o actualizar el rol ADMIN (rol_id = 1)
        $adminRole = Rol::updateOrCreate(
            ['rol_id' => 1],  // 👈 forzamos que tenga rol_id 1
            ['name' => 'admin']
        );

        // Asignar TODOS los permisos al rol admin
        $adminRole->syncPermissions(Permission::all());
    }
}
