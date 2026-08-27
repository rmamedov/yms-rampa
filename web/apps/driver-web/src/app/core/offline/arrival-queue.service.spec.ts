import { TestBed } from '@angular/core/testing';
import { ArrivalQueueService } from './arrival-queue.service';
import { LocalStorageService, STORAGE_KEYS } from '../storage/local-storage';

/**
 * Черга переживає перезапуск застосунку: водій цілком може закрити вкладку
 * в дорозі й відкрити її вже на місці.
 */
describe('ArrivalQueueService', () => {
  let queue: ArrivalQueueService;
  let storage: LocalStorageService;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
    queue = TestBed.inject(ArrivalQueueService);
    storage = TestBed.inject(LocalStorageService);
  });

  it('зберігає відмітку разом із фактичним часом натискання', () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00Z');

    expect(queue.size()).toBe(1);
    expect(queue.has('bk-1')).toBe(true);
    expect(queue.occurredAt('bk-1')).toBe('2026-08-27T09:12:00Z');
    expect(storage.getRaw(STORAGE_KEYS.arrivalQueue)).toContain('bk-1');
  });

  it('повторне натискання не зсуває час прибуття вперед', () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00Z');
    queue.enqueue('bk-1', '2026-08-27T09:40:00Z');

    expect(queue.size()).toBe(1);
    expect(queue.occurredAt('bk-1')).toBe('2026-08-27T09:12:00Z');
  });

  it('переживає перезапуск застосунку', () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00Z');

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    const restored = TestBed.inject(ArrivalQueueService);

    expect(restored.occurredAt('bk-1')).toBe('2026-08-27T09:12:00Z');
  });

  it('порожня черга не лишає ключа у сховищі', () => {
    queue.enqueue('bk-1', '2026-08-27T09:12:00Z');

    queue.remove('bk-1');

    expect(queue.isEmpty()).toBe(true);
    expect(storage.getRaw(STORAGE_KEYS.arrivalQueue)).toBeNull();
  });

  it('побитий вміст ключа не ламає старт', () => {
    storage.setRaw(STORAGE_KEYS.arrivalQueue, '{"нежить":true}');

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});

    expect(TestBed.inject(ArrivalQueueService).items()).toEqual([]);
  });
});
