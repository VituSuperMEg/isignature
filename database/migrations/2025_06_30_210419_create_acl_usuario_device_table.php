<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAclUsuarioDeviceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('acl_usuario_device', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique()->index();
            $table->string('user_matricula')->index();
            $table->text('device_fingerprint');
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');
            $table->decimal('trust_level', 3, 2)->default(1.0);
            $table->text('registration_context')->nullable();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $table->timestamps();

            // Índices compostos para performance
            $table->index(['user_matricula', 'device_fingerprint']);
            $table->index(['user_matricula', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('acl_usuario_device');
    }
}
