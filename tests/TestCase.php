<?php

namespace Tests;

use App\Models\Business;
use App\Models\Client;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\BusinessProvisioner;
use App\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Crypt;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\Notifications\LogNotificationGateway::$sent = [];
    }

    protected function makeUser(string $id = 'u_owner', ?string $email = null, array $roles = ['user']): User
    {
        return User::create([
            'central_principal_type' => 'user',
            'central_principal_id' => $id,
            'email' => $email ?? $id.'@example.test',
            'name' => ucfirst($id),
            'central_roles' => $roles,
        ]);
    }

    protected function makeBusiness(User $owner, string $name = 'Acme Consulting'): Business
    {
        app(Context::class)->user = $owner;

        return app(BusinessProvisioner::class)->create($owner, [
            'legal_name' => $name.' LLC', 'display_name' => $name, 'timezone' => 'America/New_York',
            'zelle_recipient_name' => $name, 'zelle_email' => 'pay@example.test',
            'check_payee' => $name.' LLC', 'check_address' => "1 Main St\nBoston, MA 02110",
        ]);
    }

    protected function addMember(Business $b, User $u, string $roleKey): Membership
    {
        $m = Membership::create(['business_id' => $b->id, 'user_id' => $u->id, 'status' => 'active', 'joined_at' => now()]);
        $m->roles()->attach(Role::where('business_id', $b->id)->where('key', $roleKey)->value('id'), ['business_id' => $b->id]);

        return $m;
    }

    protected function makeClient(Business $b, string $name = 'Client Co', bool $exempt = false): Client
    {
        $c = new Client(['display_name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.test', 'tax_exempt' => $exempt]);
        $c->business_id = $b->id;
        $c->save();

        return $c;
    }

    /** Simulate a verified central session (recently validated, so no network revalidation). */
    protected function signIn(User $u): static
    {
        app(Context::class)->user = $u;

        return $this->withSession(['central_auth' => [
            'user_id' => $u->id,
            'principal_id' => $u->central_principal_id,
            'token' => Crypt::encryptString('session-token-'.$u->id),
            'exp' => time() + 3600,
            'validated_at' => time(),
        ]]);
    }

    protected function actingSystem(User $u): void
    {
        app(Context::class)->user = $u;
    }
}
