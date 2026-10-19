<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El evento decide si muestra fotos en público; cada ficha puede excluirse.
        Schema::table('events', fn (Blueprint $table) => $table->boolean('show_photos_publicly')->default(false));
        Schema::table('competitors', fn (Blueprint $table) => $table->boolean('photo_public')->default(true));
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('show_photos_publicly'));
        Schema::table('competitors', fn (Blueprint $table) => $table->dropColumn('photo_public'));
    }
};
