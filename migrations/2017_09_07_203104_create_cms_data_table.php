<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddRootToCatalogCategories extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('catalog_categories')->insert(['id' => 1, 'name' => '__ROOT__']);
        DB::table('catalog_categories_closure')->insert(['ancestor_id' => 1, 'descendant_id' => 1, 'length' => 0]);
    }
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('catalog_categories_closure')
            ->where([
                'ancestor_id' => 0,
                'descendant_id' => 0,
                'length' => 1,
            ])
            ->delete();

        DB::table('catalog_categories')->where('id', 0)->delete();
    }
}
