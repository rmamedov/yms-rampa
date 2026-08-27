# Локальне оточення

Потрібні три зовнішні сервіси: **MongoDB** (дані), **Redis** (холди слотів і кеш конфігурацій), **RabbitMQ** (доменні події).

## Варіант 1: Docker

```bash
cd infra && docker compose up -d
```

Реплікасет MongoDB піднімається автоматично контейнером `mongo-init` — він потрібен для транзакцій: запис бронювання і події в `outbox` мають бути атомарними.

## Варіант 2: Homebrew (машина без Docker)

```bash
brew services start redis
brew services start mongodb-community
```

MongoDB через Homebrew ставиться з тапа `mongodb/brew`, який Homebrew за замовчуванням вважає недовіреним. Команду довіри виконує розробник власноруч:

```bash
brew trust --formula mongodb/brew/mongodb-community && brew install mongodb-community
```

Для транзакцій одновузловий MongoDB теж треба перевести в реплікасет — додайте в `/opt/homebrew/etc/mongod.conf`:

```yaml
replication:
  replSetName: rs0
```

і після перезапуску виконайте один раз:

```bash
mongosh --eval 'rs.initiate()'
```

RabbitMQ у варіанті без Docker не обовʼязковий: у dev-режимі Symfony Messenger працює через синхронний транспорт, події обробляються в тому ж процесі.

## PHP-розширення

```bash
pecl install mongodb redis
```

Якщо `pecl` падає з `failed to mkdir .../pecl/20240924`, спочатку створіть каталог, на який вказує символьне посилання:

```bash
mkdir -p /opt/homebrew/lib/php/pecl/20240924
```

Після встановлення переконайтеся, що розширення підключені:

```bash
php -m | grep -E '^(mongodb|redis)$'
```

Якщо їх немає у виводі, додайте drop-in файл `/opt/homebrew/etc/php/8.4/conf.d/99-yms.ini`:

```ini
extension=mongodb.so
extension=redis.so
```

## Перевірка

```bash
mongosh --quiet --eval 'db.adminCommand("ping")'
redis-cli ping
```

Юніт-тести сервісів навмисно не потребують нічого з цього — вони працюють на InMemory-реалізаціях репозиторіїв. Зовнішні сервіси потрібні лише для інтеграційних тестів (`--group integration`) і для реального запуску API.
