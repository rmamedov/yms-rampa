import {
  validateAddressOverride,
  validateDisplayName,
  validateEdrpou,
  validateEmail,
  validateHorizon,
  validateLeadTime,
  validateMaxWeight,
  validatePhone,
  validateReason,
} from './validators.util';

describe('STC-02 — валідація полів магазину', () => {
  it('телефон у форматі +380XXXXXXXXX', () => {
    expect(validatePhone('+380671234567')).toBeNull();
    expect(validatePhone('0671234567')).toBe('store.error.phone');
    expect(validatePhone('+38067123456')).toBe('store.error.phone');
    expect(validatePhone('+3806712345678')).toBe('store.error.phone');
  });

  it('порожній телефон дозволений (у MCP телефонів немає)', () => {
    expect(validatePhone(null)).toBeNull();
    expect(validatePhone('   ')).toBeNull();
  });

  it('назва для відображення — 1..120 символів', () => {
    expect(validateDisplayName('Сільпо 1998')).toBeNull();
    expect(validateDisplayName('   ')).toBe('store.error.displayName');
    expect(validateDisplayName('x'.repeat(121))).toBe('store.error.displayName');
    expect(validateDisplayName('x'.repeat(120))).toBeNull();
  });

  it('STC-07: addressOverride nullable, до 200 символів', () => {
    expect(validateAddressOverride(null)).toBeNull();
    expect(validateAddressOverride('вул. Хрещатик, 1')).toBeNull();
    expect(validateAddressOverride('x'.repeat(201))).toBe(
      'store.error.addressOverride',
    );
  });

  it('причина обовʼязкова і до 200 символів', () => {
    expect(validateReason('Інвентаризація', 'blocks.error.reason')).toBeNull();
    expect(validateReason('', 'blocks.error.reason')).toBe('blocks.error.reason');
    expect(validateReason('x'.repeat(201), 'blocks.error.reason')).toBe(
      'blocks.error.reason',
    );
  });
});

describe('STC-30 — maxVehicleWeightTons 1.0–40.0 з кроком 0.5', () => {
  it('приймає значення на кроці 0.5', () => {
    expect(validateMaxWeight(1)).toBeNull();
    expect(validateMaxWeight(20.5)).toBeNull();
    expect(validateMaxWeight(40)).toBeNull();
  });

  it('відхиляє значення поза діапазоном', () => {
    expect(validateMaxWeight(0.5)).toBe('limits.error.maxWeight');
    expect(validateMaxWeight(40.5)).toBe('limits.error.maxWeight');
    expect(validateMaxWeight(null)).toBe('limits.error.maxWeight');
  });

  it('відхиляє значення поза кроком 0.5', () => {
    expect(validateMaxWeight(12.3)).toBe('limits.error.maxWeight');
    expect(validateMaxWeight(7.25)).toBe('limits.error.maxWeight');
  });
});

describe('SUP-01 — реквізити постачальника', () => {
  it('ЄДРПОУ — 8 або 10 цифр', () => {
    expect(validateEdrpou('32145678')).toBeNull();
    expect(validateEdrpou('4312567890')).toBeNull();
    expect(validateEdrpou('123456789')).toBe('suppliers.error.edrpou');
    expect(validateEdrpou('3214567a')).toBe('suppliers.error.edrpou');
  });

  it('перевіряє формат e-mail', () => {
    expect(validateEmail('user@silpo.ua')).toBeNull();
    expect(validateEmail('user@silpo')).toBe('suppliers.error.email');
    expect(validateEmail('not-an-email')).toBe('suppliers.error.email');
  });
});

describe('Ліміти бронювання', () => {
  it('leadTimeMinutes — ціле 0..10080 хв', () => {
    expect(validateLeadTime(0)).toBeNull();
    expect(validateLeadTime(10_080)).toBeNull();
    expect(validateLeadTime(10_081)).toBe('limits.error.leadTime');
    expect(validateLeadTime(2.5)).toBe('limits.error.leadTime');
  });

  it('горизонт бронювання — ціле 1..90', () => {
    expect(validateHorizon(21)).toBeNull();
    expect(validateHorizon(0)).toBe('limits.error.horizon');
    expect(validateHorizon(91)).toBe('limits.error.horizon');
  });
});
