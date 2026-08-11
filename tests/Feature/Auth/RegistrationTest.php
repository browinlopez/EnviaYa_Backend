<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    // El registro web asigna rol 4 (FK a rol.rol_id); en la base de test
    // hay que crearlo porque los seeders no corren aquí.
    \App\Models\Rol::firstOrCreate(
        ['rol_id' => 4],
        ['name' => 'web', 'guard_name' => 'web'],
    );

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
