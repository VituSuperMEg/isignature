<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAclZeroKnowledge extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('acl_zero_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('token', 128)->unique();
            $table->string('user_matricula', 50)->index(); // Único dado não criptografado
            $table->string('data_hash', 64); // Hash SHA-256 dos dados
            $table->text('merkle_proof'); // Prova Merkle Tree
            $table->string('commitment', 64); // Commitment scheme
            $table->timestamp('created_at');
            $table->timestamp('expires_at');

            $table->index(['token', 'expires_at']);
            $table->index(['user_matricula', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('acl_zero_knowledge');
    }
}
