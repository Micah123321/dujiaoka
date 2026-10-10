<?php

namespace App\Admin\Repositories;

use Dcat\Admin\Form;
use Dcat\Admin\Repositories\EloquentRepository;
use InvalidArgumentException;
use RuntimeException;

abstract class TransactionalRepository extends EloquentRepository
{
    private $deletionConnection;

    public function deleting(Form $form)
    {
        $this->deletionConnection = $this->newQuery()->getQuery()->getConnection();
        return parent::deleting($form);
    }

    public static function deletionKeys($key): array
    {
        if ((! is_string($key) && ! is_int($key))
            || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', (string) $key)) {
            throw new InvalidArgumentException('Delete IDs must be positive integers separated by commas.');
        }
        $keys = explode(',', (string) $key);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException('Delete IDs must be unique.');
        }
        return $keys;
    }

    // withTrashed is an Eloquent macro, which the candidate parent's method_exists misses.
    protected function applyWithTrashed($query): void
    {
        $query->withTrashed();
    }

    /**
     * File cleanup follows a successful database commit. Cleanup exceptions propagate to
     * Form::destroy, but committed rows stay deleted and remaining files need cleanup.
     * Permanent deletion inside an outer transaction is rejected: Laravel 6 has no
     * reliable afterCommit hook. Soft deletion can participate in an outer transaction.
     */
    public function delete(Form $form, array $originalData)
    {
        $keys = self::deletionKeys($form->getKey());
        $connection = $this->deletionConnection;
        if (! $connection || $this->model()->getConnection() !== $connection) {
            throw new RuntimeException('Deletion database connection changed or preload is missing.');
        }
        $outerTransaction = $connection->transactionLevel() > 0;
        $preloaded = [];
        if ($this->isSoftDeletes) {
            $keyColumn = $this->getKeyName();
            $deletedColumn = $this->model()->getDeletedAtColumn();
            foreach ($originalData as $data) {
                if (isset($data[$keyColumn]) && array_key_exists($deletedColumn, $data)) {
                    $preloaded[(string) $data[$keyColumn]] = $data[$deletedColumn] !== null;
                }
            }
        }

        $files = $connection->transaction(function () use ($keys, $outerTransaction, $preloaded, $connection) {
            $query = $this->newQuery();
            // Builders retain their original SQL connection even when their model is switched.
            if ($query->getQuery()->getConnection() !== $connection
                || $this->model()->getConnection() !== $connection) {
                throw new RuntimeException('Deletion query connection changed.');
            }
            if ($this->isSoftDeletes) {
                $query->withTrashed();
            }
            // Reset inherited ordering and bindings so every batch locks in primary-key order.
            $query->getQuery()->orders = [];
            $query->getQuery()->setBindings([], 'order');
            // Re-read the entire batch under locks; never delete stale pre-hook models.
            $models = $query->with($this->getRelations())
                ->orderBy($this->getKeyName())->lockForUpdate()
                ->findOrFail($keys, $this->getFormColumns());

            $files = [];
            foreach ($models as $model) {
                if ($model->getConnection() !== $connection) {
                    throw new RuntimeException('Deletion model connection changed.');
                }
                // A concurrent soft-delete must never promote a stale request to permanent deletion.
                if ($this->isSoftDeletes && (!array_key_exists((string) $model->getKey(), $preloaded)
                    || $preloaded[(string) $model->getKey()] !== $model->trashed())) {
                    throw new RuntimeException('Deletion state changed for ID ' . $model->getKey() . '.');
                }
                if (! $this->isSoftDeletes || $model->trashed()) {
                    if ($outerTransaction) {
                        throw new RuntimeException('Permanent deletion requires its own database transaction.');
                    }
                    $files[] = $model->toArray();
                }
            }
            foreach ($models as $model) {
                $deleted = $this->isSoftDeletes && $model->trashed()
                    ? $model->forceDelete() : $model->delete();
                if ($deleted === false) {
                    throw new RuntimeException('Model deletion was cancelled for ID ' . $model->getKey() . '.');
                }
            }
            return $files;
        });

        foreach ($files as $data) {
            $form->deleteFiles($data, true);
        }
        return true;
    }
}
