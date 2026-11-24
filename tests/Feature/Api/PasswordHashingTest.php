<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHashingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_passwords_are_hashed_with_argon2id(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Hash Tester',
            'email' => 'hash@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);

        $this->assertStringStartsWith('$argon2id$', User::first()->password);
    }

    public function test_pre_switch_bcrypt_passwords_log_in_and_are_upgraded(): void
    {
        // A raw insert mirrors a row written before the argon2id switch; the
        // model's `hashed` cast (rightly) refuses to store a bcrypt hash now.
        DB::table('users')->insert([
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => Hash::driver('bcrypt')->make('Password123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::first();
        $this->assertStringStartsWith('$2y$', $user->password);

        $this->postJson('/api/v1/login', [
            'email' => 'legacy@example.com',
            'password' => 'Password123',
        ])->assertOk();

        // The hash was upgraded in place, and the password still works.
        $this->assertStringStartsWith('$argon2id$', $user->fresh()->password);

        $this->postJson('/api/v1/login', [
            'email' => 'legacy@example.com',
            'password' => 'Password123',
        ])->assertOk();
    }
}
