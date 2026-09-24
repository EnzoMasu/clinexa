<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'Nueva$Clave1',
            'password_confirmation' => 'Nueva$Clave1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertTrue(Hash::check('Nueva$Clave1', $user->refresh()->password));
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'Nueva$Clave1',
            'password_confirmation' => 'Nueva$Clave1',
        ]);

    $response
        ->assertSessionHasErrorsIn('updatePassword', 'current_password')
        ->assertRedirect('/profile');
});
