<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webappstack_workspaces', function (Blueprint $table) {
            $table->char('scope_key', 64)->primary();
            $table->string('organization_key', 160)->unique();
            $table->string('company_uuid', 191)->unique();
            $table->timestamps();
            $table->foreign('company_uuid')->references('uuid')->on('companies');
        });
        Schema::create('webappstack_identities', function (Blueprint $table) {
            $table->char('identity_key', 64)->primary();
            $table->char('scope_key', 64);
            $table->string('principal_kind', 20);
            $table->char('subject_id', 36);
            $table->string('user_uuid', 191)->unique();
            $table->string('access_role', 20);
            $table->timestamps();
            $table->foreign('scope_key')->references('scope_key')->on('webappstack_workspaces');
            $table->foreign('user_uuid')->references('uuid')->on('users');
            $table->unique(['scope_key', 'principal_kind', 'subject_id'], 'was_identity_subject');
        });
        Schema::create('webappstack_login_transactions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->char('cookie_hash', 64)->unique();
            $table->string('state', 64);
            $table->char('challenge', 43);
            $table->text('verifier_ciphertext');
            $table->dateTime('expires_at')->index();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('webappstack_sessions', function (Blueprint $table) {
            $table->char('cookie_hash', 64)->primary();
            $table->char('platform_session_id', 36)->index();
            $table->char('identity_key', 64);
            $table->string('user_uuid', 191);
            $table->string('status', 20);
            $table->text('token_ciphertext')->nullable();
            $table->unsignedBigInteger('personal_token_id')->nullable();
            $table->text('two_fa_ciphertext')->nullable();
            $table->dateTime('expires_at')->index();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('identity_key')->references('identity_key')->on('webappstack_identities');
            $table->foreign('user_uuid')->references('uuid')->on('users');
        });
        Schema::create('webappstack_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('scope_key', 64)->nullable()->index();
            $table->char('subject_id', 36)->nullable();
            $table->string('action', 60);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Identity and delivery history is retained. Roll forward using a reviewed recovery plan.');
    }
};
