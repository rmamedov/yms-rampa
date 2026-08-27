/** Український словник store-web. Єдине джерело текстів інтерфейсу. */
export const UK_DICTIONARY: Record<string, string> = {
  // --- Загальне ---
  'app.title': 'Рампа — магазин',
  'app.shortTitle': 'Рампа',
  'common.save': 'Зберегти',
  'common.cancel': 'Скасувати',
  'common.confirm': 'Підтвердити',
  'common.close': 'Закрити',
  'common.apply': 'Застосувати',
  'common.clear': 'Очистити',
  'common.refresh': 'Оновити',
  'common.loading': 'Завантаження…',
  'common.notSpecified': 'не вказано',
  'common.yes': 'Так',
  'common.no': 'Ні',
  'common.required': 'Обовʼязкове поле',
  'common.back': 'Назад',
  'common.actions': 'Дії',
  'common.reason': 'Причина',
  'common.comment': 'Коментар',
  'common.optional': 'необовʼязково',
  'common.of': 'з',
  'common.minutesShort': 'хв',
  'common.tonsShort': 'т',
  'common.palletsShort': 'пал.',
  'common.empty': 'Немає даних',

  // --- Логін ---
  'login.heading': 'Вхід для персоналу магазину',
  'login.subheading': 'Використайте службовий обліковий запис',
  'login.email': 'E-mail',
  'login.password': 'Пароль',
  'login.submit': 'Увійти',
  'login.inProgress': 'Входимо…',
  'login.emailRequired': 'Вкажіть e-mail',
  'login.passwordRequired': 'Вкажіть пароль',
  'login.hint': 'Демо-режим: будь-який пароль. Оператор — operator@silpo.ua, менеджер — manager@silpo.ua.',
  'login.failed': 'Невірний e-mail або пароль',

  // --- Доступ ---
  'access.denied.title': 'Доступ заборонено',
  'access.denied.text':
    'Ваша роль не має доступу до застосунку магазину. Зверніться до адміністратора.',
  'access.denied.logout': 'Вийти',
  'access.forbiddenStore': 'Немає доступу до цього магазину',

  // --- Шапка ---
  'header.store': 'Магазин',
  'header.selectStore': 'Оберіть магазин',
  'header.logout': 'Вийти',
  'header.today': 'Сьогодні',
  'header.week': 'Розклад тижня',
  'header.walkIn': 'Позапланове прибуття',
  // Підписи ролей — як у `Role::label()` identity-staff-service.
  'header.role.super_admin': 'Суперадміністратор',
  'header.role.network_manager': 'Менеджер мережі',
  'header.role.store_manager': 'Керівник магазину',
  'header.role.store_operator': 'Приймальник магазину',
  'header.role.analyst': 'Аналітик',
  'header.role.supplier_admin': 'Адміністратор постачальника',
  'header.role.supplier_operator': 'Оператор постачальника',
  'header.role.driver': 'Водій',

  // --- Дошка ---
  'board.title': 'Прибуття',
  'board.mode.board': 'Дошка за рампами',
  'board.mode.timeline': 'Таймлайн',
  'board.today': 'Сьогодні',
  'board.yesterday': 'Вчора',
  'board.tomorrow': 'Завтра',
  'board.pickDate': 'Дата',
  'board.readOnly': 'Минула дата — доступне лише дозакриття розвантаження',
  'board.emptyRamp': 'Немає прибуттів',
  'board.emptyDay': 'На цю дату немає бронювань',
  'board.ramp': 'Рампа {name}',
  'board.atRiskRamp': 'Рампа під ризиком: розвантаження триває довше за слот',
  'board.atRisk': 'Під ризиком',
  'board.overrun': 'Перевищення часу слоту',
  'board.overrunBy': 'Перевищення на {minutes} хв',

  // --- Картка ---
  'card.supplier': 'Постачальник',
  'card.plate': 'Номер авто',
  'card.weight': 'Тоннаж',
  'card.orderId': 'Замовлення',
  'card.pallets': 'Палети',
  'card.driver': 'Водій',
  'card.driverId': 'Водій {id}',
  'card.noDriver': 'Водія не призначено',
  'card.walkIn': 'Позапланове',
  'card.arrivedAt': 'Прибув о {time}',
  'card.unloadingSince': 'Розвантаження з {time}',
  'card.completedAt': 'Завершено о {time}',
  'card.delayedEta': 'Затримка до {time}',
  'card.waitMinutes': 'Очікування {minutes} хв',
  'card.unloadDuration': 'Тривалість {minutes} хв',
  'card.unloadedPallets': 'Розвантажено {done} з {planned} палет',
  'card.partial': 'Часткове розвантаження',
  'card.rejected': 'Відмовлено: {reason}',
  'card.externalSupplier': 'поза системою',
  'card.openLog': 'Журнал дій',
  'card.moreActions': 'Ще дії',

  // --- Статуси ---
  'status.booked': 'Заплановано',
  'status.arrived': 'Очікує на території',
  'status.unloading': 'Розвантаження',
  'status.completed': 'Розвантажено',
  'status.no_show': 'Не приїхав',
  'status.rejected': 'Відмовлено в прийомі',
  'status.cancelled': 'Скасовано',
  'status.delayed': 'Затримка',

  // --- Дії ---
  'action.arrived': 'На місці',
  'action.startUnloading': 'Розвантаження почалось',
  'action.complete': 'Розвантажено',
  'action.noShow': 'Не приїхав',
  'action.reject': 'Відмовити в прийомі',
  'action.delay': 'Повідомити про затримку',
  'action.reassign': 'Перевести на іншу рампу',
  'action.log': 'Журнал дій',
  'action.walkIn': 'Позапланове прибуття',
  'action.blockSlot': 'Заблокувати слот',

  // Причини недоступності дій (тултіпи)
  'action.disabled.wrongStatus': 'Дія доступна лише зі статусу «{status}»',
  'action.disabled.noShowTooEarly':
    'Позначити «Не приїхав» можна лише після закінчення слоту',
  'action.disabled.pastDate': 'Минула дата — дія недоступна',
  'action.disabled.noFreeRamp':
    'На жодній іншій рампі немає вільного слота в цей час',
  'action.disabled.terminal': 'Бронювання в термінальному статусі',
  'action.disabled.role': 'Недостатньо прав для цієї дії',

  // --- Модалка «Розвантажено» ---
  'complete.title': 'Підтвердження розвантаження',
  'complete.plannedPallets': 'Заявлено палет: {count}',
  'complete.unloadedPallets': 'Фактично розвантажено палет',
  'complete.partial': 'Часткове розвантаження',
  'complete.partialReason': 'Причина часткового розвантаження',
  'complete.partialReasonRequired': 'Оберіть причину часткового розвантаження',
  'complete.commentRequired': 'Для причини «інше» коментар обовʼязковий',
  'complete.invalidCount': 'Кількість палет має бути від 0 до заявленої',

  // --- Модалка «Не приїхав» ---
  'noShow.title': 'Позначити «Не приїхав»?',
  'noShow.text':
    'Бронювання {supplier} ({plate}) на {slot} буде переведено у статус «Не приїхав». Дію неможливо скасувати.',

  // --- Модалка відмови ---
  'reject.title': 'Відмова в прийомі',
  'reject.reason': 'Причина відмови',
  'reject.reasonRequired': 'Вкажіть причину відмови з довідника',
  'reject.commentRequired': 'Для причини «інше» коментар обовʼязковий',

  // --- Модалка затримки ---
  'delay.title': 'Повідомлення про затримку',
  'delay.reason': 'Причина затримки',
  'delay.comment': 'Коментар (до 500 символів)',
  'delay.eta': 'Новий орієнтовний час',
  'delay.etaRequired': 'Вкажіть новий орієнтовний час',
  'delay.etaBeforeSlot':
    'Новий очікуваний час має бути пізнішим за початок слоту',
  'delay.etaOutOfDay': 'Новий час має бути в межах поточної доби',
  'delay.reasonRequired': 'Оберіть причину затримки',
  'delay.commentRequired': 'Для причини «інше» коментар обовʼязковий',
  'delay.commentTooLong': 'Коментар задовгий (максимум 500 символів)',

  // --- Переведення на рампу ---
  'reassign.title': 'Переведення на іншу рампу',
  'reassign.current': 'Поточна рампа: {name}',
  'reassign.pick': 'Оберіть вільну рампу на той самий слот',
  'reassign.none': 'На обраній рампі немає вільного слота в цей час',
  'reassign.done': 'Бронювання переведено на рампу {name}',

  // --- Walk-in ---
  'walkIn.title': 'Реєстрація позапланового прибуття',
  'walkIn.supplierMode': 'Постачальник',
  'walkIn.fromList': 'Зі списку',
  'walkIn.external': 'Поза системою',
  'walkIn.supplier': 'Постачальник зі списку',
  'walkIn.externalName': 'Назва постачальника',
  'walkIn.plate': 'Номер авто',
  'walkIn.weight': 'Тоннаж авто, т',
  'walkIn.pallets': 'Кількість палет',
  'walkIn.orderId': 'Номер замовлення (необовʼязково)',
  'walkIn.slot': 'Вільний слот',
  'walkIn.noFreeSlots': 'Немає вільних слотів на поточний момент',
  'walkIn.submit': 'Зареєструвати прибуття',
  'walkIn.supplierRequired': 'Оберіть постачальника або вкажіть назву',
  'walkIn.plateRequired': 'Вкажіть номер авто',
  'walkIn.weightRequired': 'Вкажіть тоннаж авто',
  'walkIn.palletsRange': 'Кількість палет — від 1 до 33',
  'walkIn.slotRequired': 'Оберіть вільний слот',
  'walkIn.created': 'Позапланове прибуття зареєстровано',
  'walkIn.maxWeight': 'Ця філія приймає авто до {max} т',

  // --- Фільтри ---
  'filters.title': 'Фільтри',
  'filters.ramp': 'Рампа',
  'filters.supplier': 'Постачальник',
  'filters.supplierPlaceholder': 'Пошук за назвою',
  'filters.status': 'Статус',
  'filters.delayed': 'Із затримкою',
  'filters.walkIn': 'Позапланові',
  'filters.clear': 'Очистити',
  'filters.open': 'Фільтри',
  'filters.applied': 'Активних фільтрів: {count}',

  // --- Статистика ---
  'stats.total': 'Всього',
  'stats.arrived': 'Приїхало',
  'stats.completed': 'Розвантажено',
  'stats.noShow': 'Не приїхали',
  'stats.rejected': 'Відмовлено',
  'stats.walkIn': 'Позапланових',
  'stats.avgWait': 'Середнє очікування',
  'stats.avgWaitValue': '{minutes} хв',
  'stats.noValue': '—',

  // --- Realtime ---
  'realtime.updatedAt': 'Оновлено о {time}',
  'realtime.stale': 'Дані можуть бути неактуальні, останнє оновлення о {time}',
  'realtime.refresh': 'Оновити зараз',
  'realtime.live': 'Онлайн',

  // --- Тиждень ---
  'week.title': 'Розклад тижня',
  'week.readOnly': 'Тільки перегляд',
  'week.prev': 'Попередній тиждень',
  'week.next': 'Наступний тиждень',
  'week.density': 'Заповненість {percent}%',
  'week.openDay': 'Відкрити день',
  'slotState.available': 'Вільно',
  'slotState.held': 'Оформлюється',
  'slotState.booked': 'Заброньовано',
  'slotState.reserved': 'Резерв',
  'slotState.blocked': 'Заблоковано',
  'slotState.past': 'Минуле',

  // --- Журнал ---
  'log.title': 'Журнал дій',
  'log.empty': 'Записів немає',
  'log.actor': 'Хто (ID користувача)',
  'log.time': 'Коли',
  'log.change': 'Зміна',
  'log.sourceNote':
    'Журнал побудовано з історії статусів бронювання. Окремого журналу дій із ПІБ виконавця бекенд поки не надає.',

  // --- Помилки: коди рівно ті, які повертає бекенд у problem+json ---
  'error.generic': 'Сталася помилка. Спробуйте ще раз.',
  'error.network': 'Немає звʼязку із сервером',
  'error.INVALID_STATUS_TRANSITION':
    'Статус уже змінено іншим користувачем — картку оновлено',
  'error.TRANSITION_NOT_ALLOWED': 'Ваша роль не має права на цю дію',
  'error.BOOKING_NOT_FOUND': 'Бронювання не знайдено',
  'error.ACCESS_DENIED': 'Немає доступу до цього магазину',
  'error.VALIDATION_FAILED': 'Перевірте заповнені дані',
  'error.SLOT_ALREADY_BOOKED':
    'Слот уже зайнятий — оберіть інший вільний слот або рампу',
  'error.SLOT_NOT_AVAILABLE': 'Слот недоступний для бронювання',
  'error.SLOT_RESERVED': 'Слот зарезервовано за іншим постачальником',
  'error.SLOT_HELD':
    'Слот зараз оформлює інший користувач. Спробуйте за кілька хвилин',
  'error.VEHICLE_TOO_HEAVY': 'Тоннаж авто перевищує допустимий для цього магазину',
  'error.VEHICLE_TIME_CONFLICT': 'Це авто вже зайняте в перетинному слоті',
  'error.PALLETS_OUT_OF_RANGE': 'Кількість палет — від 1 до 33',
  'error.INVALID_PLATE_NUMBER': 'Некоректний номер авто',
  'error.SUPPLIER_NOT_ALLOWED': 'Постачальнику недоступна ця філія',
  'error.STORE_NOT_FOUND': 'Магазин не знайдено або не налаштовано',
  'error.EDIT_DEADLINE_PASSED': 'Час на редагування бронювання вичерпано',
  'error.DATE_OUT_OF_HORIZON':
    'Бронювання доступне не далі ніж на {days} днів вперед',
  'error.BOOKING_LIMIT_EXCEEDED': 'Перевищено ліміт бронювань',
  'error.AUTH_INVALID_CREDENTIALS': 'Невірний e-mail або пароль',
  'error.AUTH_TOKEN_INVALID': 'Сесія завершилась, увійдіть повторно',
  'error.AUTH_TOKEN_EXPIRED': 'Сесія завершилась, увійдіть повторно',
  'error.AUTH_REFRESH_REUSED':
    'З міркувань безпеки всі сесії завершено. Увійдіть повторно',
  'error.AUTH_ACCOUNT_DISABLED':
    'Обліковий запис деактивовано. Зверніться до адміністратора',
  'error.AUTH_ACCOUNT_LOCKED':
    'Обліковий запис тимчасово заблоковано після кількох невдалих спроб',
  'error.ROUTE_NOT_FOUND': 'Невідомий маршрут API',
  'error.STORE_READ_NOT_IMPLEMENTED':
    'Бекенд ще не надає читання даних магазину — розділ тимчасово недоступний',
};
