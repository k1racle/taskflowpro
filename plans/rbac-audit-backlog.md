# RBAC audit backlog (one role per user, permission codes)

Context decisions (fixed):

- One role per user: the source is `users.role`.
- Permissions are defined as a list of permission codes (table `permissions.code`).
- `root` is break-glass (always allow).
- `administrator` is NOT break-glass; it is a normal role that should get full access via `admin.full`.

## P0 (must fix)

### P0-RBAC-001 — Remove legacy break-glass for `administrator` in API permission checks

**Problem**

`api/roles.php` currently treats both `root` and `administrator` as unconditional allow.
This violates the agreed model where only `root` is break-glass and `administrator` must be governed by `admin.full`.

**Where**

- `hasPermission()`: `api/roles.php` around lines 405-413 (currently: `root || administrator => true`).
- `getUserPermissions()`: same file around lines 458-469 (returns `admin.full` for administrator as break-glass).
- `hasAdminAccess()`: `api/roles.php` lines 18-32 (currently: administrator => true).

**Change**

- Keep unconditional allow only for `root`.
- For `administrator`, require `admin.full` through the same RBAC path as any other role.

**Acceptance**

- If role name is `administrator` but it has no `admin.full` in `role_permissions`, privileged endpoints return 403.
- If `admin.full` is assigned to `administrator`, privileged endpoints work.

---

### P0-RBAC-002 — Replace role-name checks (`administrator/root`) with `admin.full` (plus root) in access logic

**Problem**

Some modules grant extra visibility/behavior based on role name rather than permission codes.
After removing administrator break-glass, these become incorrect.

**Where (confirmed)**

- `api/tasks.php`: visibility restriction uses `in_array($currentUser['role'], ['administrator','root'])` (lines ~67-79).
- `api/helpdesk.php`: widget notification recipients are selected by roles `['administrator','root']` (lines ~1211-1221).

**Change**

- Introduce a single predicate in each file: `isRoot || hasPermission('admin.full')`.
- Replace role list checks with this predicate.

**Acceptance**

- Any role with `admin.full` behaves like admin regardless of its name.
- Role name `administrator` without `admin.full` does not get admin behavior.

---

### P0-RBAC-003 — Gate `/api/auth/register` by permission code (`users.create`) instead of role name

**Problem**

`api/auth.php` allows registration only when `currentUser.role === 'administrator'`.
We want permission-code gating and allow delegating user creation to non-admin roles.

**Where**

- `api/auth.php` around lines 345-353.

**Change**

- Allow when `currentUser.role === 'root'` OR `hasPermission(currentUser, 'users.create')`.

**Acceptance**

- User without `users.create` gets 403.
- User with `users.create` can register new users, regardless of role name.

---

### P0-RBAC-004 — Fix role creation permissions mapping to RBAC ids

**Problem**

In `api/roles.php` (POST /roles) there is code that attempts to insert role permissions into `role_permissions`, but it uses permission codes where `permission_id` (INT) is required.

**Where**

- `api/roles.php` in `POST /api/roles` block (around lines 201-211).

**Change**

- Convert permission codes -> `permissions.id` before inserting.
- Prefer a single endpoint for permissions update: `PUT /roles/:id/permissions` that takes `permission_codes: string[]`.

**Acceptance**

- After creating a role with `permission_codes`, `role_permissions` contains correct numeric ids.
- `hasPermission()` starts returning true for assigned codes.

---

### P0-RBAC-005 — Frontend `can()` must stop treating `administrator` as unconditional allow

**Problem**

In the bundled app state (`assets/js/app_combined.js`) the method `can(permissionCode)` returns true for `administrator` unconditionally.
This will diverge from the backend model after P0-RBAC-001.

**Where**

- `assets/js/app_combined.js` around lines 188-192.

**Change**

- Keep unconditional allow only for `root`.
- `administrator` must be governed by `userPermissions` (which should include `admin.full` when granted).

**Acceptance**

- UI hides admin-only controls when `administrator` lacks `admin.full`.

## P1 (should fix)

### P1-RBAC-006 — Define a single "source of truth" for role permissions (permission codes), deprecate JSON permissions in roles

**Problem**

The admin UI currently edits role permissions as a nested JSON object (sections/actions).
Backend checks permission codes via the `permissions` table.
We need the UI to manage permission codes directly.

**Where**

- `assets/js/modules/admin/index.js`: `rolePermissions` model + `saveRolePermissions()` currently sends nested JSON.

**Change**

- Fetch all permissions from `GET /api/permissions`.
- Store selected permission codes (Set/list).
- Save via `PUT /api/roles/:id/permissions` (or compatible endpoint).

**Acceptance**

- Changing permissions in UI affects backend authorization immediately.

---

### P1-RBAC-007 — Helpdesk widget recipients should be resolved by `admin.full` (not role names)

**Where**

- `api/helpdesk.php` widget path (lines ~1211-1221).

**Change**

- Add helper to resolve user ids by permission code `admin.full` + always include root.

## P2 (nice to have / cleanup)

### P2-RBAC-008 — Deprecate/remove `user_roles` usage for authorization

**Problem**

Project contains `user_roles` + sync logic, but we decided on one role per user.
Keeping both increases the risk of drift.

**Change**

- Stop reading `user_roles` in `hasPermission()`.
- Stop writing `user_roles` in users CRUD.
- Remove `user_roles` from fresh installs (installer).
- Keep table only for migration/backward compatibility in existing DBs, or remove later.

**Notes**

- A helper cleanup script exists: `tools/rbac_remove_user_roles.php` (backs up then drops the table).

### P2-UI-009 — Normalize role labels

**Where**

- `assets/js/utils.js` `translateRole()` only knows 4 roles.

**Change**

- Either display role name as-is for custom roles, or fetch role descriptions from API.
