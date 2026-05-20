<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Models\Rol;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Crear permisos
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

        // Crear rol ADMIN
        $adminRole = Rol::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web']
        );

        // Asignar permisos
        $adminRole->syncPermissions(Permission::all());
    }
}