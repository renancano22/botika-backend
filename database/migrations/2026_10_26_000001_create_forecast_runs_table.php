<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demand Forecasting → "Generate Forecast": one row each time a forecast is generated and saved to the forecast
 * table (D6), so the page can show when, by whom and with which settings the saved forecast was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->id('forecast_run_id');
            $table->foreignId('generated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('method', 40);
            $table->unsignedTinyInteger('history_months');
            $table->unsignedTinyInteger('horizon');
            $table->date('period_start'); // first forecast month
            $table->date('period_end');   // last forecast month
            $table->unsignedInteger('medicines')->default(0); // medicines with saved forecasts
            $table->timestamp('generated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_runs');
    }
};
