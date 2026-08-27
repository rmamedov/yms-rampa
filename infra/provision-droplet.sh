#!/usr/bin/env bash
# Провіженинг дроплета під YMS «Рампа».
#
# Ідемпотентний: повторний запуск нічого не ламає. Наявний сайт на цьому
# сервері (Сільпо PWA на 104.248.132.130.sslip.io) не зачіпається — YMS
# отримує власні піддомени і власні конфіги nginx.
#
# Свідоме рішення: RabbitMQ не ставимо. На дроплеті з 1 vCPU і 2 ГБ RAM
# Erlang зʼїв би ~200 МБ заради черг, які на цьому етапі обслуговує Redis
# (Symfony Messenger має транспорт redis://). Для проду з навантаженням
# RabbitMQ повертається — контракт подій від транспорту не залежить.
set -euo pipefail

log() { echo -e "\n\033[1;36m==> $*\033[0m"; }

log "Swap"
# 2 ГБ RAM без swap — MongoDB і composer install валяться на піку.
if ! swapon --show | grep -q /swapfile; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -qxF '/swapfile none swap sw 0 0' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo "swap 2G додано"
else
    echo "swap уже є"
fi

log "Базові пакети"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    php8.3-cli php8.3-fpm php8.3-mbstring php8.3-intl php8.3-curl php8.3-xml \
    php8.3-zip php8.3-dev php-pear \
    redis-server unzip curl gnupg ca-certificates rsync >/dev/null

log "Composer"
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
composer --version

log "MongoDB 7"
if ! command -v mongod >/dev/null; then
    curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc \
        | gpg -o /usr/share/keyrings/mongodb-server-7.0.gpg --dearmor --yes
    echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" \
        > /etc/apt/sources.list.d/mongodb-org-7.0.list
    apt-get update -qq
    apt-get install -y -qq mongodb-org >/dev/null
fi

log "MongoDB: реплікасет і ліміт памʼяті"
# Реплікасет з одного вузла потрібен для транзакцій: бронювання і подія
# в outbox мають писатися атомарно. Кеш обмежуємо, інакше WiredTiger
# забирає половину RAM і сервер починає свопитися.
if ! grep -q 'replSetName' /etc/mongod.conf; then
    cat >> /etc/mongod.conf <<'CONF'

replication:
  replSetName: rs0
CONF
fi
if ! grep -q 'cacheSizeGB' /etc/mongod.conf; then
    python3 - <<'PY'
import re
path = '/etc/mongod.conf'
conf = open(path).read()
if 'wiredTiger:' not in conf:
    conf = conf.replace(
        'storage:\n',
        'storage:\n  wiredTiger:\n    engineConfig:\n      cacheSizeGB: 0.25\n',
        1,
    )
open(path, 'w').write(conf)
PY
fi

systemctl enable --now mongod
sleep 3
mongosh --quiet --eval 'try { rs.status().ok } catch (e) { rs.initiate({_id:"rs0", members:[{_id:0, host:"127.0.0.1:27017"}]}) }' >/dev/null
echo "MongoDB: $(mongosh --quiet --eval 'db.version()')"

log "Redis"
systemctl enable --now redis-server
redis-cli ping

log "PHP-розширення mongodb і redis"
for ext in mongodb redis; do
    if ! php -m | grep -qx "$ext"; then
        pecl install "$ext" >/dev/null 2>&1 || true
        for ini in /etc/php/8.3/cli/conf.d /etc/php/8.3/fpm/conf.d; do
            echo "extension=${ext}.so" > "${ini}/30-${ext}.ini"
        done
    fi
done
php -m | grep -E '^(mongodb|redis)$' || echo "УВАГА: розширення не підхопилися"

log "PHP-FPM"
systemctl enable --now php8.3-fpm
systemctl reload php8.3-fpm

log "Каталоги застосунку"
mkdir -p /var/www/yms/{services,web}
mkdir -p /var/log/yms

log "Готово"
php -v | head -1
echo "MongoDB: $(systemctl is-active mongod), Redis: $(systemctl is-active redis-server), PHP-FPM: $(systemctl is-active php8.3-fpm)"
free -h | head -2
