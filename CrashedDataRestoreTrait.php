<?php

namespace App\Traits;

use App\Models\ActivityLog;
use \Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CrashedDataRestoreTrait
{
    // Predefined
    private $countCreated = 0;
    private $countUpdated = 0;
    private $crashed_restored_ids_map_array = [];
    // Defined in the trait methods
    private $table_name;
    private $modelNamespace;
    private $primary_key;
    private $primary_key_increments;
    private $find_by;
    private $additionals_find_by;
    private $found_columns_exceptions;
    private $init_qry_params;
    private $crashed_restored_ids_map_file_create;
    private $foreign_keys_map;
    private $crashedRowId;
    // Edge cases needed:
    private $lastGeneratedComponentSerialNumber;
    private $shippedOrdersDuringCrash;

    /**
     * Restore the data from a crashed database
     * @param array $attributes
     * @return void
     */
    public function restoreCrashed(array $attributes)
    {
        $this->table_name = $attributes['table_name'];
        $this->modelNamespace = $attributes['modelNamespace'];
        $this->primary_key = $attributes['primary_key'];
        $this->primary_key_increments = $attributes['primary_key_increments'] ?? true;
        $this->find_by = $attributes['find_by'];
        $this->additionals_find_by = $attributes['additionals_find_by'];
        $this->found_columns_exceptions = $attributes['found_columns_exceptions'];
        $this->init_qry_params = $attributes['init_qry_params'];
        $this->crashed_restored_ids_map_file_create = $attributes['crashed_restored_ids_map_file_create'];
        $this->foreign_keys_map = $attributes['foreign_keys_map'];

        DB::beginTransaction();
        try {
            if ($this->modelNamespace === 'App\\Models\\OrderComponent') {
                $this->handleOrderComponentEdgeCase();
                print_r($this->printResult());
                $this->logResult();
                DB::commit();
                return;
            }

            if ($this->modelNamespace === 'App\\Models\\Upload') {
                $this->handleUploadsEdgeCase();
                print_r($this->printResult());
                $this->logResult();
                DB::commit();
                return;
            }

            $crashedRows = DB::connection('mysql-crashed')->table($this->table_name)->where($this->init_qry_params)->get();

            foreach ($crashedRows as $crashedRow) {
                $crashedRow = (array) $crashedRow;
                $this->crashedRowId = $crashedRow[$this->primary_key];
                if (empty($row = DB::connection()->table($this->table_name)->where($this->setQryParams($crashedRow))->first())) {
                    $model = $this->createNewRow($crashedRow);
                    $this->countCreated+=1;
                    $this->setCrashedRestoredIdsMap($model);
                } else {
                    $row = (array) $row;
                    if ($row['updated_at'] < $crashedRow['updated_at']) {
                        unset($crashedRow[$this->primary_key]);
                        DB::connection()->table($this->table_name)->where($this->setQryParams($row))->update($crashedRow);
                        $this->countUpdated+=1;
                    } else {
                        if ($this->modelNamespace === 'App\\Models\\Component') {
                            $row = $this->handleComponentEdgeCase($crashedRow);
                        }
                        $this->setCrashedRestoredIdsMap($row);
                    }
                }
            }
            if (!is_null($this->crashed_restored_ids_map_file_create) && !empty($this->crashed_restored_ids_map_array)) {
                Storage::disk('local')->put($this->crashed_restored_ids_map_file_create, json_encode($this->crashed_restored_ids_map_array));
            }
            print_r($this->printResult());
            $this->logResult();
            DB::commit();
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
            DB::rollBack();
        }
    }

    /**
     * Create Database Query params
     * @param array $row
     * @return array
     */
    private function setQryParams(array $row)
    {
        $qry_params = [];
        foreach($this->find_by as $column => $column_comp_operator) {
            $qry_params[] = [$column, $column_comp_operator, $row[$column]];
            if (count($this->additionals_find_by) > 0 && in_array($row[$column], $this->found_columns_exceptions[$column])) {
                foreach ($this->additionals_find_by as $additional_column => $additional_column_comp_operator) {
                    $qry_params[] = [$additional_column, $additional_column_comp_operator, $row[$additional_column]];
                }
            }
        }
        return $qry_params;
    }

    /**
     * Create new row in Database using Laravel model instance
     * @param array $crashedRow
     * @return array
     */
    private function createNewRow(array $crashedRow)
    {
        $model = new $this->modelNamespace;
        $model->timestamps = false;
        if ($this->primary_key_increments) {
            unset($crashedRow[$this->primary_key]);
        }
        $model->setFillables(array_keys($crashedRow));
        if (isset($model->ignoreGenerateUiids)) {
            $model->ignoreGenerateUiids = true;
        }
        if (isset($model->ingoreSetAttributes)) {
            $model->ingoreSetAttributes = true;
        }
        if (!empty($this->foreign_keys_map)) {
            $this->getNewForeignKeysIds($crashedRow);
        }
        $model->fill($crashedRow);
        $model->save();
        return $model->toArray();
    }

    /**
     * Get the correct new foreign keys from an array containing pairs of crashed and newly created IDs
     * @param array $crashedRow
     * @return void
     */
    private function getNewForeignKeysIds(array &$crashedRow)
    {
        foreach ($this->foreign_keys_map as $foreign_key_column => $ids_map_file) {
            $ids_map_array = json_decode(Storage::disk('local')->get($ids_map_file), true);
            if (!empty($ids_map_array[$crashedRow[$foreign_key_column]])) {
                $crashedRow[$foreign_key_column] = $ids_map_array[$crashedRow[$foreign_key_column]];
            }
        }
    }

    /**
     * Creates a map of crashed rows IDs and the IDs of the newly created rows
     * @param array $newCreatedModel
     * @return void
     */
    private function setCrashedRestoredIdsMap(array $newCreatedModel)
    {
        if (!is_null($this->crashed_restored_ids_map_file_create)) {
            $this->crashed_restored_ids_map_array[$this->crashedRowId] = $newCreatedModel[$this->primary_key];
        }
    }

    /**
     * Specific logic needed to restore components table
     * @param array $crashedRow
     * @return array
     */
    private function handleComponentEdgeCase(array $crashedRow)
    {
        if ($this->modelNamespace === 'App\\Models\\Component') {
            if (!in_array($crashedRow['type'], ['SIM Card', 'Connected Drive Recorders']) && !empty($crashedRow['order_id'])) {
                $serialNumber = null;
                if (!empty($this->lastGeneratedComponentSerialNumber)) {
                    $serialNumber = $this->lastGeneratedComponentSerialNumber;
                } else {
                    if (!empty($row = DB::connection()->table($this->table_name)->where('type', $crashedRow['type'])->orderBy('created_at', 'desc')->first())) {
                        $serialNumber = $row->serialNumber;
                    }
                }
                if (!empty($serialNumber)) {
                    $serialNumArr = explode('.', $serialNumber);
                    $serialNextNum = (int) last($serialNumArr) + 1;
                    $serialNumArr[count($serialNumArr) - 1] = $serialNextNum;
                    $serialNew = implode(".", $serialNumArr);
                    $crashedRow['serialNumber'] = $serialNew;
                    if (!empty($component = $this->createNewRow($crashedRow))) {
                        $this->lastGeneratedComponentSerialNumber = $serialNew;
                        $this->countCreated+=1;
                    }
                    return $component;
                }
            }
        }
    }

    /**
     * Specific logic needed to restore order_components table
     * @return void
     */
    private function handleOrderComponentEdgeCase()
    {
        if ($this->modelNamespace === 'App\\Models\\OrderComponent') {
            $this->shippedOrdersDuringCrash = DB::connection('mysql-crashed')->table('orders')->where([
                ['updated_at', '>', '2020-01-09 23:36:25'],
                ['status', '=', 'shipped']
            ])->get()->pluck('id')->toArray();

            $shippedComponents = DB::connection('mysql-crashed')->table($this->table_name)->whereIn('order_id', $this->shippedOrdersDuringCrash)->get();

            foreach ($shippedComponents as $shippedComponent) {
                $shippedComponent = (array) $shippedComponent;
                if (empty($row = DB::connection()->table($this->table_name)->where($this->setQryParams($shippedComponent))->first())) {
                    $model = $this->createNewRow($shippedComponent);
                    $this->countCreated+=1;
                }
            }
        }
    }

    /**
     * Specific logic needed to restore uploads and galleries tables
     * @return void
     */
    private function handleUploadsEdgeCase()
    {
        if ($this->modelNamespace === 'App\\Models\\Upload') {
            $gallery_foreign_keys_map = [
                'App\Models\Product' =>
                    ['gallery_id' => 'crashed_restored_products_ids_map.json'],
                'App\User' =>
                    ['gallery_id' => 'crashed_restored_users_ids_map.json'],
            ];

            $crashedUploads = DB::connection('mysql-crashed')->table($this->table_name)->where($this->init_qry_params)->get();

            foreach ($crashedUploads as $crashedUpload) {
                $crashedUpload = (array) $crashedUpload;
                if (empty($uploadsRow = DB::connection()->table($this->table_name)->where($this->setQryParams($crashedUpload))->first())) {
                    $this->foreign_keys_map = [];
                    $model = $this->createNewRow($crashedUpload);
                    if (!empty($galleryRow = DB::connection('mysql-crashed')->table('galleries')->where('upload_id', $model['id'])->first())) {
                        $galleryRow = (array) $galleryRow;
                        if (empty($row = DB::connection()->table('galleries')->where('upload_id', $model['id'])->first())) {
                            if (in_array($galleryRow['gallery_type'], ['App\Models\Product', 'App\User'])) {
                                $this->foreign_keys_map = $gallery_foreign_keys_map[$galleryRow['gallery_type']];
                                $this->getNewForeignKeysIds($galleryRow);
                            }
                            DB::connection()->table('galleries')->insert($galleryRow);
                            $this->countCreated+=1;
                        }
                    }
                    $this->countCreated+=1;
                }
            }
        }
    }

    /**
     * Return the restore process summary information
     * @return string
     */
    private function printResult()
    {
        return
            'Total restored: ' . ($this->countCreated + $this->countUpdated) . "\r\n" .
            'Created: ' . $this->countCreated . "\r\n" .
            'Updated: ' . $this->countUpdated . "\r\n";
    }

    /**
     * Log the restore process summary information to DB
     * @return void
     */
    private function logResult()
    {
        ActivityLog::add(
            'restoreCrashedDbData',
            $this->modelNamespace,
            $this->printResult()
        );
    }
}
