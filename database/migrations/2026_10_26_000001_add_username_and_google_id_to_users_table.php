<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('name');
            $table->string('google_id', 64)->nullable()->unique()->after('email');
        });

        // akun lama: username dari bagian depan email (unik)
        $taken = [];
        foreach (DB::table('users')->whereNull('username')->orderBy('id')->get() as $u) {
            $base = Str::of(Str::before($u->email, '@'))->lower()->replaceMatches('/[^a-z0-9_.]/', '')->substr(0, 24)->toString() ?: 'user';
            $name = $base;
            $i = 1;
            while (in_array($name, $taken, true) || DB::table('users')->where('username', $name)->exists()) {
                $name = $base . $i++;
            }
            $taken[] = $name;
            DB::table('users')->where('id', $u->id)->update(['username' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['google_id']);
            $table->dropColumn(['username', 'google_id']);
        });
    }
};
