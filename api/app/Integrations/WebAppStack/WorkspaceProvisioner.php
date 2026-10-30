<?php

namespace App\Integrations\WebAppStack;

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceProvisioner
{
    public function provision(array $identity): User
    {
        $scopeKey = hash('sha256', $identity['organizationKey']);
        $identityKey = hash('sha256', $scopeKey . ':' . $identity['principalKind'] . ':' . $identity['subjectId']);
        $lock = 'was:' . substr($scopeKey, 0, 48);
        abort_unless((int) DB::selectOne('SELECT GET_LOCK(?,10) acquired', [$lock])->acquired === 1, 503);
        try {
            return DB::transaction(function () use ($identity, $scopeKey, $identityKey) {
                $workspace = DB::table('webappstack_workspaces')->where('scope_key', $scopeKey)->first();
                if (!$workspace) {
                    $company = Company::create([
                        'name' => config('webappstack.workspace_name'), 'currency' => 'ZAR',
                        'country' => 'ZA', 'timezone' => 'Africa/Johannesburg', 'status' => 'active',
                    ]);
                    DB::table('webappstack_workspaces')->insert([
                        'scope_key' => $scopeKey, 'organization_key' => $identity['organizationKey'],
                        'company_uuid' => $company->uuid, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $workspace = DB::table('webappstack_workspaces')->where('scope_key', $scopeKey)->first();
                }
                $mapping = DB::table('webappstack_identities')->where('identity_key', $identityKey)->first();
                if (!$mapping) {
                    $user = new User([
                        'company_uuid' => $workspace->company_uuid,
                        'name' => Str::limit($identity['displayName'] ?? 'Delivery user', 180, ''),
                        'email' => 'was.' . $identityKey . '@identity.invalid',
                        'status' => 'active', 'timezone' => 'Africa/Johannesburg',
                    ]);
                    $user->type = 'user';
                    $user->save();
                    DB::table('webappstack_identities')->insert([
                        'identity_key' => $identityKey, 'scope_key' => $scopeKey,
                        'principal_kind' => $identity['principalKind'], 'subject_id' => $identity['subjectId'],
                        'user_uuid' => $user->uuid, 'access_role' => $identity['role'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $user = User::findOrFail($mapping->user_uuid);
                    abort_unless($user->company_uuid === $workspace->company_uuid && !$user->isAdmin(), 403);
                    $user->name = Str::limit($identity['displayName'] ?? 'Delivery user', 180, '');
                    $user->save();
                    DB::table('webappstack_identities')->where('identity_key', $identityKey)->update([
                        'access_role' => $identity['role'], 'updated_at' => now(),
                    ]);
                }
                $membership = CompanyUser::firstOrCreate([
                    'company_uuid' => $workspace->company_uuid, 'user_uuid' => $user->uuid,
                ], ['status' => 'active']);
                if ($identity['role'] === 'OWNER') {
                    $membership->assignSingleRole('Administrator');
                    Company::where('uuid', $workspace->company_uuid)->update(['owner_uuid' => $user->uuid]);
                } else {
                    $this->assignDeliveryViewer($membership, $workspace->company_uuid);
                    Company::where('uuid', $workspace->company_uuid)->where('owner_uuid', $user->uuid)->update(['owner_uuid' => null]);
                }
                DB::table('webappstack_audit')->insert([
                    'scope_key' => $scopeKey, 'subject_id' => $identity['subjectId'], 'action' => 'IDENTITY_RECONCILED',
                ]);
                return $user;
            });
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) released', [$lock]);
        }
    }

    private function assignDeliveryViewer(CompanyUser $membership, string $companyUuid): void
    {
        $names = ['fleet-ops see extension', 'iam view company', 'iam view user', 'iam list company', 'iam list user'];
        foreach (['order', 'driver', 'vehicle', 'fleet', 'entity', 'place', 'route'] as $resource) {
            foreach (['list', 'view'] as $action) {
                $names[] = 'fleet-ops ' . $action . ' ' . $resource;
            }
        }
        $permissions = Permission::where('guard_name', 'sanctum')->whereIn('name', $names)->get();
        abort_unless($permissions->contains('name', 'fleet-ops see extension'), 403, 'Delivery staff role setup is required.');
        $role = Role::firstOrCreate(['name' => 'WebAppStack Delivery Viewer', 'company_uuid' => $companyUuid, 'guard_name' => 'sanctum']);
        $role->syncPermissions($permissions);
        $membership->assignSingleRole($role);
    }
}
