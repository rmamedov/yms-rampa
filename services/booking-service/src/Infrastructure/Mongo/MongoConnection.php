<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Session;
use Throwable;

/**
 * Тонка обгортка над драйвером MongoDB: зберігає Manager, назву БД
 * і вміє виконувати блок роботи в транзакції replica set.
 *
 * DATA-16 вимагає, щоб бізнес-документ і запис outbox лягали в одній
 * транзакції. На standalone-інсталяції (dev) транзакції недоступні —
 * тоді операції виконуються послідовно, про що сигналізує
 * transactionsSupported().
 */
final class MongoConnection
{
    private ?bool $transactionsSupported = null;

    public function __construct(
        private readonly Manager $manager,
        private readonly string $database = 'bookings',
    ) {
    }

    public function manager(): Manager
    {
        return $this->manager;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function namespace(string $collection): string
    {
        return $this->database.'.'.$collection;
    }

    /**
     * @template T
     *
     * @param callable(?Session): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $session = $this->openSession();

        if (null === $session) {
            return $work(null);
        }

        try {
            $result = $work($session);
            $session->commitTransaction();

            return $result;
        } catch (Throwable $error) {
            try {
                $session->abortTransaction();
            } catch (Throwable) {
                // Абортувати вже нічого — залишаємо оригінальну помилку.
            }

            throw $error;
        }
    }

    public function transactionsSupported(): bool
    {
        return $this->transactionsSupported ?? true;
    }

    private function openSession(): ?Session
    {
        if (false === $this->transactionsSupported) {
            return null;
        }

        try {
            $session = $this->manager->startSession();
            $session->startTransaction();
            $this->transactionsSupported = true;

            return $session;
        } catch (RuntimeException) {
            $this->transactionsSupported = false;

            return null;
        }
    }
}
