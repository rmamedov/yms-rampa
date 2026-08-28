import {
  AnyRole,
  Permission,
  PermissionGrant,
  StaffRole,
} from '../models';

/** Ролі staff-контуру (RBAC-06). */
export const STAFF_ROLES: readonly StaffRole[] = [
  'super_admin',
  'network_manager',
  'store_manager',
  'store_operator',
  'analyst',
];

/** Ролі, яким взагалі відкрито admin-web (ADM-01). */
export const ADMIN_WEB_ROLES: readonly StaffRole[] = [
  'super_admin',
  'network_manager',
  'store_manager',
  'analyst',
];

const F: PermissionGrant = 'full';
const S: PermissionGrant = 'scoped';
const D: PermissionGrant = 'denied';

/**
 * Матриця «роль × право» з розділу 4.4, дослівно (staff-контур).
 * Deny by default: відсутній запис = 'denied' (RBAC-02).
 */
export const PERMISSION_MATRIX: Readonly<
  Record<StaffRole, Readonly<Record<Permission, PermissionGrant>>>
> = {
  super_admin: {
    'store.read': F,
    'store.configure': F,
    'store.sync.manage': F,
    'slot.read': F,
    'slot.block': F,
    'slot.reserve': F,
    'booking.read.all': F,
    'booking.cancel.any': F,
    'supplier.read': F,
    'supplier.manage': F,
    'analytics.view': F,
    'users.manage.staff': F,
    'users.manage.supplier': F,
    'roles.assign': F,
    'audit.read': F,
  },
  network_manager: {
    'store.read': F,
    'store.configure': F,
    'store.sync.manage': F,
    'slot.read': F,
    'slot.block': F,
    'slot.reserve': F,
    'booking.read.all': F,
    'booking.cancel.any': F,
    'supplier.read': F,
    'supplier.manage': F,
    'analytics.view': F,
    // S* — обмеження деревом призначення (лише store_manager / store_operator)
    'users.manage.staff': S,
    'users.manage.supplier': F,
    'roles.assign': S,
    'audit.read': F,
  },
  store_manager: {
    'store.read': S,
    'store.configure': D,
    'store.sync.manage': D,
    'slot.read': S,
    'slot.block': S,
    'slot.reserve': D,
    'booking.read.all': S,
    'booking.cancel.any': S,
    'supplier.read': D,
    'supplier.manage': D,
    'analytics.view': S,
    'users.manage.staff': D,
    'users.manage.supplier': D,
    'roles.assign': D,
    'audit.read': D,
  },
  store_operator: {
    'store.read': S,
    'store.configure': D,
    'store.sync.manage': D,
    'slot.read': S,
    'slot.block': D,
    'slot.reserve': D,
    'booking.read.all': S,
    'booking.cancel.any': D,
    'supplier.read': D,
    'supplier.manage': D,
    'analytics.view': D,
    'users.manage.staff': D,
    'users.manage.supplier': D,
    'roles.assign': D,
    'audit.read': D,
  },
  analyst: {
    'store.read': F,
    'store.configure': D,
    'store.sync.manage': D,
    'slot.read': F,
    'slot.block': D,
    'slot.reserve': D,
    'booking.read.all': F,
    'booking.cancel.any': D,
    'supplier.read': F,
    'supplier.manage': D,
    'analytics.view': F,
    'users.manage.staff': D,
    'users.manage.supplier': D,
    'roles.assign': D,
    'audit.read': D,
  },
};

/** Дерево призначення ролей (RBAC-22). */
export const ROLE_ASSIGNMENT_TREE: Readonly<
  Partial<Record<StaffRole, readonly StaffRole[]>>
> = {
  super_admin: [
    'super_admin',
    'network_manager',
    'store_manager',
    'store_operator',
    'analyst',
  ],
  network_manager: ['store_manager', 'store_operator'],
};

/** Ролі, для яких привʼязка ≥1 магазину обовʼязкова (USR-02). */
export const ROLES_REQUIRING_STORES: readonly StaffRole[] = [
  'store_manager',
  'store_operator',
];

/**
 * Розділи admin-web, які реально має бекенд контуру /api/admin/v1.
 *
 * Розділ «Користувачі» зʼявився разом із /api/admin/v1/users, а «Журнал
 * аудиту» — разом із /api/admin/v1/audit (identity-staff-service, RBAC-31):
 * записи `role_audit` велися від початку, але назовні не публікувалися.
 *
 * ОБСЯГ АУДИТУ: журнал покриває зміни облікових записів і ролей. Дії над
 * магазинами, постачальниками й бронюваннями ведуть інші сервіси у власних
 * журналах, спільного маршруту для них немає.
 */
export type SectionId =
  | 'stores'
  | 'suppliers'
  | 'users'
  | 'sync'
  | 'analytics'
  | 'audit';

/** Розділ admin-web → право, що відкриває його (ADM-02). */
export const SECTION_PERMISSION: Readonly<Record<SectionId, Permission>> = {
  stores: 'store.read',
  suppliers: 'supplier.read',
  users: 'users.manage.staff',
  sync: 'store.sync.manage',
  analytics: 'analytics.view',
  audit: 'audit.read',
};

export const SECTION_ORDER: readonly SectionId[] = [
  'stores',
  'suppliers',
  'users',
  'sync',
  'analytics',
  'audit',
];

/**
 * RBAC-23: кого показувати у фільтрі ролей і в селекті ролі — рівно те,
 * що актор має право призначати за деревом 4.7. Бекенд відповідає 403
 * RBAC_ROLE_ASSIGNMENT_FORBIDDEN на решту, тож пропонувати їх не можна.
 */
export function assignableRoles(role: StaffRole | null): readonly StaffRole[] {
  return role ? (ROLE_ASSIGNMENT_TREE[role] ?? []) : [];
}

export function isStaffRole(role: AnyRole): role is StaffRole {
  return (STAFF_ROLES as readonly string[]).includes(role);
}

export function grantFor(role: StaffRole, permission: Permission): PermissionGrant {
  const row = PERMISSION_MATRIX[role];
  if (!row) {
    return 'denied';
  }
  return row[permission] ?? 'denied';
}
