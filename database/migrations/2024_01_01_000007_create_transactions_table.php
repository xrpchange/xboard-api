<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id()->comment('Уникальный идентификатор транзакции');
            $table->string('tx_hash', 64)->unique()->comment('Хэш транзакции');
            $table->enum('tx_type', ['deposit', 'withdrawal', 'fee', 'refund', 'compensation', 'sale'])->comment('Тип транзакции');
            $table->string('from_address', 50)->comment('Адрес отправителя');
            $table->string('to_address', 50)->comment('Адрес получателя');
            $table->decimal('amount', 20, 8)->comment('Сумма транзакции');
            $table->string('token', 500)->nullable()->comment('Залоговый токен');
            $table->foreignId('exchange_lot_id')->nullable()->constrained('exchange_lots')->comment('Ссылка на лот обмена');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed')->comment('Статус транзакции');
            $table->boolean('is_test')->default(false)->comment('Тестовая транзакция для песочницы');
            $table->string('test_group', 50)->nullable()->comment('Группа тестового режима');
            $table->timestamps();
            
            $table->index('from_address', 'idx_transactions_from_address');
            $table->index('to_address', 'idx_transactions_to_address');
            $table->index('token', 'idx_transactions_token');
            $table->index('exchange_lot_id', 'idx_transactions_exchange_lot_id');
            $table->index(['is_test', 'test_group'], 'idx_transactions_test');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
}; 