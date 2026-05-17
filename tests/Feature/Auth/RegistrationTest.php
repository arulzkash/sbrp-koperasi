<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'student_name' => 'Budi',
            'school_level' => 'SD',
            'class_room' => '4',
            'class_room_note' => 'B',
            'service_type' => 'full',
            'session_in' => '06:30',
            'latitude' => '-6.82',
            'longitude' => '107.63',
            'distance' => '1.5',
            'price_estimasi' => '250000',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
