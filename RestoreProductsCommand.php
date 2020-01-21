<?php

namespace App\Console\Commands\CrashedData;

use App\Traits\CrashedDataRestoreTrait;
use Illuminate\Console\Command;

/**
 * Example restore command
 */
class RestoreProductsCommand extends Command
{
    use CrashedDataRestoreTrait;

    private $attributes = [
        'table_name' => 'products',
        'modelNamespace' => 'App\\Models\\Product',
        'primary_key' => 'id',
        'find_by' => ['request_id' => '='],
        'additionals_find_by' => [],
        'found_columns_exceptions' => [],
        'init_qry_params' => [['updated_at', '>', '2020-01-10 02:46:07']],
        'crashed_restored_ids_map_file_create' => 'crashed_restored_products_ids_map.json',
        'foreign_keys_map' => ['user_id' => 'crashed_restored_users_ids_map.json'],
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crashed_data:restore_products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore products table missing data from crashed DB';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->restoreCrashed($this->attributes);
    }
}
