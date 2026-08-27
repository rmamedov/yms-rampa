import {
  isStandardPlate,
  normalizePhone,
  normalizePlate,
  validateOrderId,
  validatePallets,
  validatePhone,
  validatePlate,
  validateVehicleAgainstStore,
  validateWeightTons,
} from './validation';

describe('нормалізація та валідація держномера (SUP-BOOK-03)', () => {
  it('приводить номер до верхнього регістру і прибирає пробіли та дефіси', () => {
    expect(normalizePlate(' аа 1234-вс ')).toBe('АА1234ВС');
    expect(normalizePlate('ax 9087 em')).toBe('AX9087EM');
  });

  it('вимагає довжину 4–12 символів', () => {
    expect(validatePlate('АА1')).toBe('validation.plateLength');
    expect(validatePlate('АА1234ВС9012345')).toBe('validation.plateLength');
    expect(validatePlate('АА1234ВС')).toBeNull();
    expect(validatePlate('AB12')).toBeNull();
  });

  it('відхиляє порожній номер і сторонні символи', () => {
    expect(validatePlate('')).toBe('validation.plateRequired');
    expect(validatePlate('АА12#4ВС')).toBe('validation.plateChars');
  });

  it('розпізнає стандартний український формат 2-4-2', () => {
    expect(isStandardPlate('аа1234вс')).toBe(true);
    expect(isStandardPlate('AX9087EM')).toBe(true);
    expect(isStandardPlate('TRUCK7')).toBe(false);
  });
});

describe('вантажопідйомність і правило тоннажу (SUP-BOOK-04 / BOOK-01)', () => {
  it('вимагає число більше нуля', () => {
    expect(validateWeightTons(null)).toBe('validation.weightRequired');
    expect(validateWeightTons(0)).toBe('validation.weightPositive');
    expect(validateWeightTons(3.5)).toBeNull();
  });

  it('блокує авто, важче за maxVehicleWeightTons філії', () => {
    expect(validateVehicleAgainstStore(20, 10)).toBe(
      'validation.weightTooHeavy',
    );
    expect(validateVehicleAgainstStore(10, 10)).toBeNull();
    expect(validateVehicleAgainstStore(3.5, 10)).toBeNull();
  });
});

describe('палети та orderId (SUP-BOOK-05, SUP-BOOK-06)', () => {
  it('приймає лише цілі 1..33', () => {
    expect(validatePallets(0)).toBe('validation.palletsRange');
    expect(validatePallets(34)).toBe('validation.palletsRange');
    expect(validatePallets(12.5)).toBe('validation.palletsRange');
    expect(validatePallets(null)).toBe('validation.palletsRequired');
    expect(validatePallets(1)).toBeNull();
    expect(validatePallets(33)).toBeNull();
  });

  it('orderId необовʼязковий, але не довший за 64 символи', () => {
    expect(validateOrderId('')).toBeNull();
    expect(validateOrderId(null)).toBeNull();
    expect(validateOrderId('X'.repeat(64))).toBeNull();
    expect(validateOrderId('X'.repeat(65))).toBe('validation.orderIdLength');
  });
});

describe('телефон водія (SUP-DRV-02)', () => {
  it('нормалізує до формату +380XXXXXXXXX', () => {
    expect(normalizePhone('067 111 22 33')).toBe('+380671112233');
    expect(normalizePhone('380671112233')).toBe('+380671112233');
    expect(normalizePhone('+38 (067) 111-22-33')).toBe('+380671112233');
  });

  it('відхиляє некоректний формат', () => {
    expect(validatePhone('')).toBe('validation.phoneRequired');
    expect(validatePhone('+38067111223')).toBe('validation.phoneFormat');
    expect(validatePhone('0671112233')).toBeNull();
  });
});
