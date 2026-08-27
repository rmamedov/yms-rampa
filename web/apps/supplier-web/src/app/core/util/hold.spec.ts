import {
  canExtendHold,
  extendedExpiry,
  holdRemainingSeconds,
  isHoldExpired,
} from './hold';

const base = new Date('2026-03-12T09:00:00Z');

describe('життєвий цикл холду (HOLD-01, HOLD-02, SUP-BOOK-01)', () => {
  it('рахує залишок TTL у секундах і не йде в мінус', () => {
    const hold = { expiresAt: '2026-03-12T09:05:00Z' };
    expect(holdRemainingSeconds(hold, base)).toBe(300);
    expect(
      holdRemainingSeconds(hold, new Date('2026-03-12T09:06:00Z')),
    ).toBe(0);
  });

  it('вважає hold протухлою після expiresAt', () => {
    const hold = { expiresAt: '2026-03-12T09:00:00Z' };
    expect(isHoldExpired(hold, base)).toBe(true);
    expect(isHoldExpired({ expiresAt: '2026-03-12T09:00:30Z' }, base)).toBe(
      false,
    );
  });

  it('продовжує TTL на 5 хв, але не далі за holdMaxMinutes', () => {
    const maxUntil = new Date('2026-03-12T09:15:00Z');
    // Звичайне продовження: now + 5 хв
    expect(extendedExpiry(base, maxUntil).toISOString()).toBe(
      '2026-03-12T09:05:00.000Z',
    );
    // Біля межі 15 хв продовження обрізається до maxUntil
    expect(
      extendedExpiry(new Date('2026-03-12T09:13:00Z'), maxUntil).toISOString(),
    ).toBe('2026-03-12T09:15:00.000Z');
  });

  it('припиняє heartbeat, коли досягнуто межі життя холду', () => {
    const atLimit = {
      expiresAt: '2026-03-12T09:15:00Z',
      maxUntil: '2026-03-12T09:15:00Z',
    };
    expect(canExtendHold(atLimit, base)).toBe(false);
    expect(
      canExtendHold(
        { expiresAt: '2026-03-12T09:05:00Z', maxUntil: '2026-03-12T09:15:00Z' },
        base,
      ),
    ).toBe(true);
  });
});
