#!/usr/bin/env bash
# Деплой бекенду YMS «Рампа» на дроплет: код, залежності, конфігурація, індекси.
#
# Кожен сервіс має власні назви env-змінних (їх писали різні автори), тому
# конфігурація генерується індивідуально, а не одним спільним шаблоном.
set -euo pipefail

HOST="${YMS_HOST:-root@104.248.132.130}"
KEY="${YMS_SSH_KEY:-$HOME/.ssh/id_droplet}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RSYNC_SSH="ssh -i $KEY -o StrictHostKeyChecking=accept-new"
SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$HOST")

SERVICES=(store-service booking-service partner-service identity-staff-service
          identity-partner-service notification-service analytics-service)

log() { echo -e "\n\033[1;36m==> $*\033[0m"; }

log "Відвантаження коду"
for svc in "${SERVICES[@]}"; do
    printf '  %-28s' "$svc"
    rsync -az --delete -e "$RSYNC_SSH" \
        --exclude 'vendor/' --exclude 'var/' --exclude '.env.local' \
        "$REPO/services/$svc/" "$HOST:/var/www/yms/services/$svc/"
    echo "ok"
done

log "Фікстури"
"${SSH[@]}" 'mkdir -p /var/www/yms/fixtures'
rsync -az -e "$RSYNC_SSH" "$REPO/fixtures/" "$HOST:/var/www/yms/fixtures/"

log "Конфігурація"
"${SSH[@]}" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /var/www/yms/services

secret() { openssl rand -hex 32; }

write_env() {  # $1 — сервіс, далі рядки конфігурації
    local svc="$1"; shift
    { echo "APP_ENV=prod"; echo "APP_DEBUG=0"; echo "APP_SECRET=$(secret)"; printf '%s\n' "$@"; } > "$svc/.env.local"
}

write_env store-service \
    "MONGODB_URL=mongodb://127.0.0.1:27017" "MONGODB_DB=yms_stores" \
    "BRANCH_FIXTURE_PATH=/var/www/yms/fixtures/silpo-branches.json"

# Базові URL сусідів — внутрішній шлюз nginx (infra/nginx-yms-internal.conf,
# слухає лише 127.0.0.1:8081). Це НЕ адмінський /api/: службові маршрути
# booking-service ходить за префіксом /internal/v1/, а шлюз сам розкладає їх
# по сервісах за префіксом шляху. Обидва URL однакові саме тому.
write_env booking-service \
    "MONGODB_URI=mongodb://127.0.0.1:27017" "MONGODB_DATABASE=yms_bookings" \
    "REDIS_URL=redis://127.0.0.1:6379" \
    "STORE_SERVICE_BASE_URL=http://127.0.0.1:8081" \
    "PARTNER_SERVICE_BASE_URL=http://127.0.0.1:8081"

# IDENTITY_PARTNER_BASE_URL — той самий внутрішній шлюз: створення облікових
# даних водія йде синхронним викликом на /internal/v1/partner-accounts, який
# шлюз віддає identity-partner-service. Без цієї змінної працює дефолт із
# .env, але тримаємо її явно поруч із рештою конфігурації сервісу.
write_env partner-service \
    "MONGO_DSN=mongodb://127.0.0.1:27017" "MONGO_DB=yms_partners" \
    "IDENTITY_PARTNER_BASE_URL=http://127.0.0.1:8081"

write_env analytics-service \
    "MONGODB_URL=mongodb://127.0.0.1:27017" "MONGODB_DB=yms_analytics"

write_env notification-service \
    "MONGODB_URL=mongodb://127.0.0.1:27017" "MONGODB_DB=yms_notifications" \
    "PORTAL_URL=https://yms.104.248.132.130.sslip.io" \
    "MAILER_DSN=null://null"

# Контури автентифікації підписуються РІЗНИМИ ключами — це вимога SRS,
# а не деталь реалізації: токен одного контуру не має прийматися іншим.
write_env identity-staff-service \
    "MONGODB_URI=mongodb://127.0.0.1:27017" "MONGODB_DB=yms_identity_staff" \
    "REDIS_DSN=redis://127.0.0.1:6379" \
    "JWT_STAFF_SECRET=$(secret)" "JWT_STAFF_KEY_ID=staff-hs-1"

mkdir -p /var/www/yms/keys
if [ ! -f /var/www/yms/keys/partner-private.pem ]; then
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
        -out /var/www/yms/keys/partner-private.pem 2>/dev/null
    openssl rsa -pubout -in /var/www/yms/keys/partner-private.pem \
        -out /var/www/yms/keys/partner-public.pem 2>/dev/null
fi
chmod 640 /var/www/yms/keys/*.pem
chown root:www-data /var/www/yms/keys/*.pem

write_env identity-partner-service \
    "MONGODB_URL=mongodb://127.0.0.1:27017" "MONGODB_DB=yms_identity_partner" \
    "REDIS_DSN=redis://127.0.0.1:6379" \
    "JWT_KEY_ID=partner-1" \
    "JWT_PRIVATE_KEY_PATH=/var/www/yms/keys/partner-private.pem" \
    "JWT_PUBLIC_KEY_PATH=/var/www/yms/keys/partner-public.pem" \
    "JWT_PARTNER_SECRET=$(secret)"

echo "конфігурацію записано"
REMOTE

log "Залежності"
for svc in "${SERVICES[@]}"; do
    printf '  %-28s' "$svc"
    "${SSH[@]}" "cd /var/www/yms/services/$svc && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader -q" && echo "ok"
done

log "Кеш і права"
for svc in "${SERVICES[@]}"; do
    "${SSH[@]}" "cd /var/www/yms/services/$svc && APP_ENV=prod php bin/console cache:clear --no-interaction -q 2>/dev/null || true"
done
"${SSH[@]}" 'chown -R www-data:www-data /var/www/yms && chmod -R g+w /var/www/yms/services/*/var 2>/dev/null || true'

log "Готово"
