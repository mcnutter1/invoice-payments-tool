<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('name');
            $table->longText('body');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'id']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->ulid('ulid')->unique();
            $table->string('number', 40);
            $table->unsignedBigInteger('client_id');
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('term_end_date')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->bigInteger('committed_value_cents')->default(0);
            $table->bigInteger('value_cap_cents')->nullable();
            $table->string('cap_behavior', 10)->default('warn');
            $table->string('signing_order', 12)->default('parallel');
            $table->boolean('externally_signed')->default(false);
            $table->text('external_provenance')->nullable();
            $table->text('billing_override_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'status']);
            $table->foreign(['business_id', 'client_id'])->references(['business_id', 'id'])->on('clients');
        });

        Schema::create('contract_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->unsignedBigInteger('amends_version_id')->nullable();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('status', 20)->default('draft'); // draft, prepared, sent, partially_signed, completed, declined, expired, voided
            $table->foreignId('source_document_id')->nullable()->constrained('private_documents');
            $table->foreignId('rendered_document_id')->nullable()->constrained('private_documents');
            $table->char('sha256', 64)->nullable();
            $table->foreignId('signed_document_id')->nullable()->constrained('private_documents');
            $table->char('signed_sha256', 64)->nullable();
            $table->foreignId('certificate_document_id')->nullable()->constrained('private_documents');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['contract_id', 'version']);
            $table->unique(['business_id', 'id']);
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'contract_id'])->references(['business_id', 'id'])->on('contracts');
        });

        Schema::create('contract_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('contract_version_id');
            $table->string('role', 20); // client_signer | countersigner | cc
            $table->string('name');
            $table->string('email');
            $table->string('title')->nullable();
            $table->unsignedSmallInteger('sign_order')->default(1);
            $table->string('status', 20)->default('pending');
            $table->string('otp_hash', 255)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->unsignedTinyInteger('otp_sends')->default(0);
            $table->timestamp('otp_last_sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_email')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('typed_name')->nullable();
            $table->string('typed_title')->nullable();
            $table->string('signature_kind', 10)->nullable();
            $table->foreignId('signature_document_id')->nullable()->constrained('private_documents');
            $table->char('signature_hash', 64)->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedBigInteger('countersigner_user_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign('business_id')->references('id')->on('businesses');
            $table->foreign(['business_id', 'contract_version_id'])->references(['business_id', 'id'])->on('contract_versions');
        });

        Schema::create('electronic_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('contract_recipient_id');
            $table->string('consent_version', 20);
            $table->text('consent_text');
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreign(['business_id', 'contract_recipient_id'])->references(['business_id', 'id'])->on('contract_recipients');
        });

        Schema::create('signature_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('contract_version_id');
            $table->unsignedBigInteger('contract_recipient_id')->nullable();
            $table->unsignedInteger('seq');
            $table->string('event', 40);
            $table->json('details')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['contract_version_id', 'seq']);
            $table->foreign(['business_id', 'contract_version_id'])->references(['business_id', 'id'])->on('contract_versions');
        });

        Schema::create('contract_billing_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('contract_id');
            $table->string('name');
            $table->bigInteger('amount_cents');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'contract_id'])->references(['business_id', 'id'])->on('contracts');
        });
    }

    public function down(): void
    {
        foreach (['contract_billing_milestones', 'signature_events', 'electronic_consents', 'contract_recipients',
            'contract_versions', 'contracts', 'contract_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
