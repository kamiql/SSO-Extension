<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->string('first_name', 191)->nullable();
            $table->string('last_name', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};