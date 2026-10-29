<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('central_principal_type', 20);
            $table->string('central_principal_id', 120);
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->json('central_roles')->nullable();
            $table->unsignedBigInteger('last_business_id')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['central_principal_type', 'central_principal_id'], 'users_principal_unique');
            $table->index('email');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('auth_callback_nonces', function (Blueprint $table) {
            $table->id();
            $table->char('sig_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
        });

        Schema::create('private_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->ulid('ulid')->unique();
            $table->string('kind', 40);
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('entity_type', 40)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 60)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('US');
            $table->string('phone', 40)->nullable();
            $table->string('public_email')->nullable();
            $table->string('website')->nullable();
            $table->foreignId('logo_document_id')->nullable()->constrained('private_documents');
            $table->string('timezone', 64)->default('America/New_York');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('reporting_basis', 10)->default('accrual');
            $table->char('currency', 3)->default('USD');
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->string('contract_prefix', 20)->default('AGR-');
            $table->string('credit_note_prefix', 20)->default('CN-');
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->text('tax_id_encrypted')->nullable();
            $table->boolean('show_tax_id')->default(false);
            $table->unsignedSmallInteger('default_terms_days')->default(30);
            $table->string('zelle_recipient_name')->nullable();
            $table->string('zelle_email')->nullable();
            $table->string('zelle_phone', 40)->nullable();
            $table->foreignId('zelle_qr_document_id')->nullable()->constrained('private_documents');
            $table->string('check_payee')->nullable();
            $table->text('check_address')->nullable();
            $table->json('payment_methods')->nullable();
            $table->timestamp('payment_instructions_changed_at')->nullable();
            $table->json('branding')->nullable();
            $table->json('reminder_settings')->nullable();
            $table->text('default_customer_note')->nullable();
            $table->text('default_terms_text')->nullable();
            $table->unsignedSmallInteger('invoice_link_ttl_days')->default(90);
            $table->unsignedSmallInteger('contract_link_ttl_days')->default(30);
            $table->unsignedSmallInteger('claim_review_days')->default(5);
            $table->string('check_receipt_policy', 20)->default('received');
            $table->date('books_closed_through')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::table('private_documents', function (Blueprint $table) {
            $table->foreign('business_id')->references('id')->on('businesses');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('group', 40);
            $table->string('description');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('key', 40);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'key']);
            $table->unique(['business_id', 'id']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'user_id']);
            $table->unique(['business_id', 'id']);
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['membership_id', 'role_id']);
            $table->foreign(['business_id', 'membership_id'])->references(['business_id', 'id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['business_id', 'role_id'])->references(['business_id', 'id'])->on('roles');
        });

        Schema::create('central_role_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('central_role', 80);
            $table->string('capability', 80);
            $table->foreignId('business_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['central_role', 'capability', 'business_id'], 'crm_role_cap_business_unique');
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('email');
            $table->unsignedBigInteger('role_id');
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign(['business_id', 'role_id'])->references(['business_id', 'id'])->on('roles');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 80);
            $table->string('object_type', 60)->nullable();
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'object_type', 'object_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('scope', 60);
            $table->string('key', 100);
            $table->string('result_ref')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['business_id', 'scope', 'key']);
        });

        Schema::create('job_heartbeats', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('name');
            $table->decimal('rate', 9, 4);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['tax_rates', 'job_heartbeats', 'idempotency_keys', 'audit_events', 'invitations', 'central_role_mappings',
            'membership_roles', 'memberships', 'role_permissions', 'roles', 'permissions'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('private_documents', fn (Blueprint $t) => $t->dropForeign(['business_id']));
        Schema::dropIfExists('businesses');
        Schema::dropIfExists('private_documents');
        Schema::dropIfExists('auth_callback_nonces');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
