<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('name')->nullable();
            $table->string('email', 191)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};
