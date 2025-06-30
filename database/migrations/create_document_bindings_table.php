<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('document_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('binding_id')->unique();
            $table->longText('encrypted_binding');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->index(['binding_id', 'expires_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_bindings');
    }
};