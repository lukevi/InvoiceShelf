<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('customer_id');
            $table->unsignedInteger('charge_category_id');
            $table->text('description');
            $table->decimal('duration_hours', 8, 2);
            $table->unsignedInteger('company_id')->nullable();
            $table->index(['company_id', 'customer_id']);
            $table->index('charge_category_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_logs');
    }
};
