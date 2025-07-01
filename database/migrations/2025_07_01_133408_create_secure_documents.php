<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSecureDocuments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('secure_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_id', 64)->unique();
            $table->longText('encrypted_document'); // Documento criptografado
            $table->text('encrypted_signature'); // Assinatura criptografada
            $table->text('encrypted_public_key'); // Chave pública criptografada
            $table->string('user_hash', 64)->index(); // Hash para indexação (sem dados pessoais)
            $table->string('document_hash', 64); // Hash do documento original
            $table->timestamp('created_at');
            $table->timestamp('expires_at');

            $table->index(['document_id', 'expires_at']);
            $table->index(['user_hash', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('secure_documents');
    }
}
