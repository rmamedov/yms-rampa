import { formatPhone, isValidPhone, normalizePhone } from './phone.util';

describe('normalizePhone (DRV-06, AUTH-23)', () => {
  it('нормалізує 0XX-формат до E.164', () => {
    expect(normalizePhone('0671234567')).toBe('+380671234567');
  });

  it('нормалізує запис із пробілами, дужками і дефісами', () => {
    expect(normalizePhone('067 123-45-67')).toBe('+380671234567');
    expect(normalizePhone('(067) 123 45 67')).toBe('+380671234567');
    expect(normalizePhone(' +380 67 123 45 67 ')).toBe('+380671234567');
  });

  it('нормалізує формат 380... та +380...', () => {
    expect(normalizePhone('380671234567')).toBe('+380671234567');
    expect(normalizePhone('+380671234567')).toBe('+380671234567');
  });

  it('приймає національний номер без нуля і старий формат через вісімку', () => {
    expect(normalizePhone('671234567')).toBe('+380671234567');
    expect(normalizePhone('8 067 123 45 67')).toBe('+380671234567');
  });

  it('відхиляє некоректні номери', () => {
    expect(normalizePhone('')).toBeNull();
    expect(normalizePhone(null)).toBeNull();
    expect(normalizePhone('06712345')).toBeNull(); // закоротко
    expect(normalizePhone('06712345678')).toBeNull(); // задовго
    expect(normalizePhone('+1 202 555 0143')).toBeNull(); // не Україна
    expect(normalizePhone('abc')).toBeNull();
  });

  it('isValidPhone узгоджений з normalizePhone', () => {
    expect(isValidPhone('067 123 45 67')).toBe(true);
    expect(isValidPhone('123')).toBe(false);
  });

  it('formatPhone показує номер у читабельному вигляді', () => {
    expect(formatPhone('+380671234567')).toBe('+380 67 123 45 67');
  });
});
