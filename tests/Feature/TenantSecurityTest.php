<?php

namespace Tests\Feature;

use App\Models\CentralRoleMapping;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use Tests\TestCase;

class TenantSecurityTest extends TestCase
{
    public function test_switching_and_altered_ids_never_leak_between_businesses(): void
    {
        $owner = $this->makeUser('u_owner');
        $other = $this->makeUser('u_other');
        $a = $this->makeBusiness($owner, 'Alpha');
        $b = $this->makeBusiness($owner, 'Beta');
        $c = $this->makeBusiness($other, 'Gamma');
        $clientA = $this->makeClient($a, 'Alpha Client');
        $clientC = $this->makeClient($c, 'Gamma Client');
        $this->actingSystem($owner);
        $invA = app(InvoiceService::class)->saveDraft($a, null, ['client_id' => $clientA->id, 'lines' => [['name' => 'x', 'quantity' => '1', 'unit_price' => '10']]]);

        // Owner sees A's invoice in A, but not through B's URL.
        $this->signIn($owner)->get("/b/{$a->ulid}/invoices/{$invA->id}")->assertOk()->assertSee('Alpha Client');
        $this->signIn($owner)->get("/b/{$b->ulid}/invoices/{$invA->id}")->assertNotFound();
        $this->signIn($owner)->get("/b/{$b->ulid}/clients/{$clientA->id}")->assertNotFound();
        $this->signIn($owner)->get("/b/{$b->ulid}/clients")->assertOk()->assertDontSee('Alpha Client');
        // Non-member gets a neutral 404 for the whole business.
        $this->signIn($other)->get("/b/{$a->ulid}/invoices")->assertNotFound();
        // Cross-tenant client reference in a draft is rejected.
        $this->signIn($owner)->post("/b/{$a->ulid}/invoices", [
            'client_id' => $clientC->id, 'discount_type' => 'none', 'lines' => [['name' => 'x', 'quantity' => '1', 'unit_price' => '1']],
        ])->assertSessionHasErrors('client_id');
        $this->assertSame(1, Invoice::count());
    }

    public function test_client_contacts_can_be_edited_and_removed_within_tenant_only(): void
    {
        $owner = $this->makeUser('u_owner');
        $a = $this->makeBusiness($owner, 'Alpha');
        $b = $this->makeBusiness($owner, 'Beta');
        $client = $this->makeClient($a, 'Alpha Client');
        $base = "/b/{$a->ulid}/clients/{$client->id}/contacts";
        $this->signIn($owner)->post($base, ['name' => 'Pat', 'email' => 'pat@example.test', 'is_primary_billing' => 1])->assertRedirect();
        $this->signIn($owner)->post($base, ['name' => 'Sam', 'email' => 'sam@example.test'])->assertRedirect();
        [$pat, $sam] = \App\Models\Contact::where('client_id', $client->id)->orderBy('id')->get()->all();

        $this->signIn($owner)->post("$base/{$sam->id}", ['name' => 'Samantha', 'email' => 'samantha@example.test', 'phone' => '555-0100', 'title' => 'AP', 'is_primary_billing' => 1, 'is_signer' => 1])->assertRedirect();
        $sam->refresh();
        $this->assertSame(['Samantha', 'samantha@example.test', '555-0100', 'AP'], [$sam->name, $sam->email, $sam->phone, $sam->title]);
        $this->assertTrue($sam->is_primary_billing && $sam->is_signer);
        $this->assertFalse((bool) $pat->fresh()->is_primary_billing);
        $this->signIn($owner)->get("/b/{$a->ulid}/clients/{$client->id}")->assertOk()->assertSee('Edit or remove')->assertSee('Samantha');

        // Same ids through another business are not found.
        $this->signIn($owner)->post("/b/{$b->ulid}/clients/{$client->id}/contacts/{$pat->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->signIn($owner)->post("/b/{$b->ulid}/clients/{$client->id}/contacts/{$pat->id}/delete")->assertNotFound();
        $this->assertSame('Pat', $pat->fresh()->name);

        $this->signIn($owner)->post("$base/{$pat->id}/delete")->assertRedirect();
        $this->assertNull($pat->fresh());
    }

    public function test_roles_enforced_server_side(): void
    {
        $owner = $this->makeUser('u_owner');
        $viewer = $this->makeUser('u_viewer');
        $billing = $this->makeUser('u_billing');
        $biz = $this->makeBusiness($owner);
        $this->addMember($biz, $viewer, 'viewer');
        $this->addMember($biz, $billing, 'billing');
        $client = $this->makeClient($biz);
        $this->actingSystem($owner);
        $inv = app(InvoiceService::class)->saveDraft($biz, null, ['client_id' => $client->id, 'lines' => [['name' => 'x', 'quantity' => '1', 'unit_price' => '10']]]);

        $this->signIn($viewer)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk();
        $this->signIn($viewer)->post("/b/{$biz->ulid}/invoices/{$inv->id}/issue")->assertForbidden();
        $this->signIn($viewer)->get("/b/{$biz->ulid}/reports/profit-and-loss")->assertForbidden();
        $this->signIn($viewer)->get("/b/{$biz->ulid}/expenses")->assertForbidden();
        $this->signIn($billing)->post("/b/{$biz->ulid}/invoices/{$inv->id}/issue")->assertRedirect();
        $this->assertSame('issued', $inv->fresh()->status);
        $this->signIn($billing)->get("/b/{$biz->ulid}/reports/ar-aging")->assertOk();
        $this->signIn($billing)->get("/b/{$biz->ulid}/reports/profit-and-loss")->assertForbidden();
        $this->signIn($billing)->post("/b/{$biz->ulid}/payments", [])->assertForbidden();
    }

    public function test_last_owner_cannot_be_removed_and_admin_cannot_escalate(): void
    {
        $owner = $this->makeUser('u_owner');
        $admin = $this->makeUser('u_admin');
        $biz = $this->makeBusiness($owner);
        $adminM = $this->addMember($biz, $admin, 'admin');
        $ownerM = $biz->memberships()->where('user_id', $owner->id)->first();

        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/members/{$ownerM->id}/remove")->assertSessionHas('error');
        $this->assertSame('active', $ownerM->fresh()->status);
        // Admin cannot grant itself the owner role.
        $ownerRole = $biz->roles()->where('key', 'owner')->first();
        $this->signIn($admin)->post("/b/{$biz->ulid}/settings/members/{$adminM->id}/roles", ['roles' => [$ownerRole->id]])->assertSessionHas('error');
        $this->assertFalse($adminM->fresh()->roles->contains('key', 'owner'));
        // Removing the admin ends access immediately.
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/members/{$adminM->id}/remove")->assertSessionHas('status');
        $this->signIn($admin)->get("/b/{$biz->ulid}")->assertNotFound();
    }

    public function test_central_admin_role_does_not_grant_tenant_books(): void
    {
        $owner = $this->makeUser('u_owner');
        $globalAdmin = $this->makeUser('u_root', null, ['admin', 'user']);
        CentralRoleMapping::create(['central_role' => 'admin', 'capability' => 'platform.operations']);
        $biz = $this->makeBusiness($owner);
        $this->signIn($globalAdmin)->get("/b/{$biz->ulid}")->assertNotFound();
        $this->signIn($globalAdmin)->get("/b/{$biz->ulid}/reports/profit-and-loss")->assertNotFound();
        $this->signIn($globalAdmin)->get('/platform')->assertOk();
    }

    public function test_unauthenticated_requests_redirect_to_login(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $this->get("/b/{$biz->ulid}/invoices")->assertRedirect('/auth/login');
        $this->get('/health')->assertOk()->assertJsonPath('checks.database', 'ok')->assertJsonMissingPath('checks.secret');
    }
}
