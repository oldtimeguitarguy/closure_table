<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCmsClosureTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cms_closure', function (Blueprint $table) {
            $table->unsignedInteger('ancestor_id');
            $table->unsignedInteger('descendant_id');
            $table->unsignedInteger('length');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->foreign('ancestor_id')->references('id')->on('cms_data');
            $table->foreign('descendant_id')->references('id')->on('cms_data');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cms_closure');
    }
}
