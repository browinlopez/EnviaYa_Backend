<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class DomiciliarioPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permisos para domiciliarios
        $permissions = [
            'view_assigned_orders',   // Ver órdenes asignadas
            'update_order_status',    // Cambiar estado de orden (en camino, entregado)
            'view_delivery_routes',   // Ver rutas de entrega
            'chat_with_users',        // Chatear con usuarios/tenderos
            'view_profile_stats',     // Ver estadísticas personales
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Crear o asignar rol DOMICILIARIO (rol_id = 5 por ejemplo)
        $domiRole = Rol::firstOrCreate(
             ['rol_id' => 3],
            ['name' => 'domiciliario']
        );

        // Asignar permisos al rol
        foreach ($permissions as $perm) {
            $permission = Permission::where('name', $perm)->first();
            if ($permission) {
                $domiRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }
}
