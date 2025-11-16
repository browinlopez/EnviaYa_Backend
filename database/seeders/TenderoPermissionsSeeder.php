<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TenderoPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
   public function run(): void
{
    // Crear permisos específicos para el rol tendero
    $permissions = [
        'view_own_products',   // Listar productos del negocio
        'create_products',     // Crear productos
        'view_orders',         // Listar órdenes
        'view_reviews',        // Listar reviews que escriban
        'view_top_products',   // Listar productos mejor calificados
        'chat_with_users',     // Enviar mensajes a usuarios
    ];

    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    // Crear o actualizar el rol TENDERO (rol_id = 2)
    $tenderoRole = Rol::firstOrCreate(
        ['rol_id' => 2],
        ['name' => 'tendero']
    );

    // Asignar los permisos al rol tendero
    foreach ($permissions as $perm) {
        $permission = Permission::where('name', $perm)->first();
        if ($permission) {
            $tenderoRole->permissions()->attach($permission->id);
        }
    }
}
}
