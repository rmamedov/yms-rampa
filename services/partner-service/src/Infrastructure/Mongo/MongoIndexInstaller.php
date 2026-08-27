<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

/**
 * Створення індексів БД `partners` (розділ 10.4, DATA-28: індекси — лише
 * через міграції/команди, ніколи «руками» в проді).
 *
 * Найважливіша пара індексів усього сервісу:
 *  - DATA-18 `vehicles {supplierId:1, plateNumber:1, archivedAt:1}` — unique
 *    у межах постачальника, БЕЗ глобальної унікальності номера;
 *  - DATA-17 `partner_users {phone:1}` unique partial для активних водіїв —
 *    навпаки, глобальна унікальність телефону-логіна.
 */
final readonly class MongoIndexInstaller
{
    public function __construct(private MongoConnection $connection)
    {
    }

    /**
     * @return list<string> перелік створених/підтверджених індексів
     */
    public function install(): array
    {
        $created = [];

        foreach ($this->definitions() as $collection => $indexes) {
            $command = new \MongoDB\Driver\Command([
                'createIndexes' => $collection,
                'indexes' => $indexes,
            ]);

            $this->connection->manager()->executeCommand($this->connection->database(), $command);

            foreach ($indexes as $index) {
                $created[] = $collection.'.'.$index['name'];
            }
        }

        return $created;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function definitions(): array
    {
        return [
            MongoSupplierRepository::COLLECTION => [
                [
                    'key' => ['nameLower' => 1],
                    'name' => 'uniq_name_active',
                    'unique' => true,
                    // SUP-01: назва унікальна серед неархівованих постачальників.
                    'partialFilterExpression' => ['archivedAt' => ['$type' => 'null']],
                ],
                [
                    'key' => ['edrpou' => 1],
                    'name' => 'uniq_edrpou',
                    'unique' => true,
                    // Унікальність лише там, де ЄДРПОУ заповнено.
                    'partialFilterExpression' => ['edrpou' => ['$type' => 'string']],
                ],
                [
                    'key' => ['name' => 1],
                    'name' => 'name_search',
                ],
            ],
            MongoPartnerUserRepository::COLLECTION => [
                [
                    'key' => ['phone' => 1],
                    'name' => 'uniq_driver_phone',
                    'unique' => true,
                    // DATA-17: телефон = логін водія, унікальний глобально
                    // серед активних (неархівованих) профілів водіїв.
                    'partialFilterExpression' => [
                        'type' => 'driver',
                        'archivedAt' => ['$type' => 'null'],
                    ],
                ],
                [
                    'key' => ['accountId' => 1],
                    'name' => 'uniq_account',
                    'unique' => true,
                ],
                [
                    'key' => ['supplierId' => 1, 'type' => 1],
                    'name' => 'supplier_type',
                ],
            ],
            MongoVehicleRepository::COLLECTION => [
                [
                    'key' => ['supplierId' => 1, 'plateNumber' => 1, 'archivedAt' => 1],
                    'name' => 'uniq_supplier_plate',
                    'unique' => true,
                    // DATA-18: саме compound-ключ; глобального unique на
                    // plateNumber немає навмисно (SUP-VEH-02).
                ],
                [
                    'key' => ['supplierId' => 1, 'lastUsedAt' => -1],
                    'name' => 'supplier_last_used',
                ],
            ],
        ];
    }
}
