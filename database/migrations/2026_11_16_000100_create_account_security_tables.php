<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->string('pending_email_token', 64)->nullable()->unique();   // sha256 of the emailed token
            $table->timestamp('pending_email_expires_at')->nullable();
        });

        // Browsers a customer has signed in from (for "new sign-in" alerts).
        // Only a hash of a random cookie is stored: no IP, no fingerprint.
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_hash', 64);
            $table->string('label', 120)->nullable();   // "Chrome on Android"
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pending_email_token']);
            $table->dropColumn(['failed_logins', 'locked_until', 'password_changed_at', 'pending_email', 'pending_email_token', 'pending_email_expires_at']);
        });
    }
};
