import { StaffRole } from '../models';
import {
  ADMIN_WEB_ROLES,
  assignableRoles,
  grantFor,
  ROLE_ASSIGNMENT_TREE,
  ROLES_REQUIRING_STORES,
  SECTION_ORDER,
  SECTION_PERMISSION,
  STAFF_ROLES,
} from './permissions';

describe('RBAC матриця (розділ 4.4)', () => {
  it('ADM-01: до admin-web допускаються лише 4 staff-ролі', () => {
    expect([...ADMIN_WEB_ROLES].sort()).toEqual(
      ['analyst', 'network_manager', 'store_manager', 'super_admin'].sort(),
    );
    expect(ADMIN_WEB_ROLES).not.toContain('store_operator');
  });

  it('RBAC-07: super_admin має всі права staff-контуру', () => {
    const permissions = Object.keys(SECTION_PERMISSION).map(
      (section) => SECTION_PERMISSION[section as keyof typeof SECTION_PERMISSION],
    );
    for (const permission of permissions) {
      expect(grantFor('super_admin', permission)).toBe('full');
    }
  });

  it('ADM-05: конфігурацію магазину редагують лише super_admin і network_manager', () => {
    expect(grantFor('super_admin', 'store.configure')).toBe('full');
    expect(grantFor('network_manager', 'store.configure')).toBe('full');
    expect(grantFor('store_manager', 'store.configure')).toBe('denied');
    expect(grantFor('analyst', 'store.configure')).toBe('denied');
    expect(grantFor('store_operator', 'store.configure')).toBe('denied');
  });

  it('store_manager блокує слоти лише в межах скоупа, а резервів не налаштовує', () => {
    expect(grantFor('store_manager', 'slot.block')).toBe('scoped');
    expect(grantFor('store_manager', 'slot.reserve')).toBe('denied');
    expect(grantFor('store_manager', 'store.sync.manage')).toBe('denied');
    expect(grantFor('store_manager', 'users.manage.staff')).toBe('denied');
  });

  it('analyst має лише читання та аналітику', () => {
    expect(grantFor('analyst', 'analytics.view')).toBe('full');
    expect(grantFor('analyst', 'store.read')).toBe('full');
    expect(grantFor('analyst', 'slot.block')).toBe('denied');
    expect(grantFor('analyst', 'supplier.manage')).toBe('denied');
    expect(grantFor('analyst', 'audit.read')).toBe('denied');
  });

  it('RBAC-02: deny by default для невідомої ролі або права', () => {
    expect(grantFor('unknown_role' as StaffRole, 'store.read')).toBe('denied');
  });

  it('RBAC-22: дерево призначення ролей обмежує network_manager', () => {
    expect(ROLE_ASSIGNMENT_TREE['network_manager']).toEqual([
      'store_manager',
      'store_operator',
    ]);
    expect(ROLE_ASSIGNMENT_TREE['network_manager']).not.toContain('super_admin');
    expect(ROLE_ASSIGNMENT_TREE['store_manager']).toBeUndefined();
  });

  it('канонічний перелік staff-ролей — рівно 5', () => {
    expect(STAFF_ROLES).toHaveLength(5);
  });

  it('розділ «Користувачі» відкриває право users.manage.staff', () => {
    expect(SECTION_ORDER).toContain('users');
    expect(SECTION_PERMISSION['users']).toBe('users.manage.staff');

    // Матриця 4.4: розділ доступний лише super_admin (✓) і network_manager (S*)
    expect(grantFor('super_admin', 'users.manage.staff')).toBe('full');
    expect(grantFor('network_manager', 'users.manage.staff')).toBe('scoped');
    expect(grantFor('store_manager', 'users.manage.staff')).toBe('denied');
    expect(grantFor('store_operator', 'users.manage.staff')).toBe('denied');
    expect(grantFor('analyst', 'users.manage.staff')).toBe('denied');
  });

  it('RBAC-31: розділ «Журнал аудиту» відкриває право audit.read', () => {
    expect(SECTION_ORDER).toContain('audit');
    expect(SECTION_PERMISSION['audit']).toBe('audit.read');

    // Матриця 4.4: журнал бачать лише мережеві ролі.
    expect(grantFor('super_admin', 'audit.read')).toBe('full');
    expect(grantFor('network_manager', 'audit.read')).toBe('full');
    expect(grantFor('store_manager', 'audit.read')).toBe('denied');
    expect(grantFor('store_operator', 'audit.read')).toBe('denied');
    expect(grantFor('analyst', 'audit.read')).toBe('denied');
  });

  it('RBAC-23: у селекті ролі пропонується лише дерево призначення актора', () => {
    expect(assignableRoles('super_admin')).toContain('super_admin');
    expect(assignableRoles('network_manager')).toEqual([
      'store_manager',
      'store_operator',
    ]);
    expect(assignableRoles('network_manager')).not.toContain('super_admin');
    expect(assignableRoles('store_manager')).toEqual([]);
    expect(assignableRoles('analyst')).toEqual([]);
    expect(assignableRoles(null)).toEqual([]);
  });

  it('USR-02: привʼязка магазинів потрібна лише магазинним ролям', () => {
    expect([...ROLES_REQUIRING_STORES].sort()).toEqual([
      'store_manager',
      'store_operator',
    ]);
  });
});
