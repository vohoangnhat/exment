<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\JoinedMultiUserFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Model\CustomValueModelScope;

/**
 * Role, user , organization helper
 */
class AuthUserOrgHelper
{
    /**
     * get organiztions who has roles.
     * this function is called from custom value role
     */
    // getRoleUserOrgQuery
    public static function getRoleOrganizationQueryTable($target_table, $tablePermission = null, $builder = null)
    {
        if (!System::organization_available()) {
            return [];
        }

        if (is_null($target_table)) {
            return [];
        }

        $target_table = CustomTable::getEloquent($target_table);
        if (is_null($target_table)) {
            return [];
        }

        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_ORGS, $target_table->id);
        return static::_getRoleUserOrOrgQueryTable(SystemTableName::ORGANIZATION, $key, $target_table, $tablePermission, $builder);
    }
    

    /**
     * get users who has roles for target table.
     * this function is called from custom value display's role
     */
    // getRoleUserOrgQuery
    public static function getRoleUserQueryTable($target_table, $tablePermission = null, $builder = null)
    {
        $target_table = CustomTable::getEloquent($target_table);
        if (is_null($target_table)) {
            return [];
        }

        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_USERS, $target_table->id);
        return static::_getRoleUserOrOrgQueryTable(SystemTableName::USER, $key, $target_table, $tablePermission, $builder);
    }


    /**
     * get users who has roles for target table.
     * and get users joined parent or children organizations
     * this function is called from custom value display's role
     */
    // getRoleUserOrgQuery
    public static function getRoleUserAndOrgBelongsUserQueryTable($target_table, $tablePermission = null, $builder = null)
    {
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);
        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_USERS_ORGS, $target_table->id);
        
        return static::_getRoleUserOrOrgQueryTable(SystemTableName::USER, $key, $target_table, $tablePermission, $builder, function($target_ids, $target_table){
            // joined organization belongs user ----------------------------------------------------
            if (!System::organization_available()) {
                return $target_ids;
            }

            // and get authoritiable organization
            $organizations = static::getRoleOrganizationQueryTable($target_table)
                ->get() ?? [];
            foreach ($organizations as $organization) {
                // get JoinedOrgFilterType. this method is for org_joined_type_role_group. get users for has role groups.
                $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ONLY_JOIN);
                $relatedOrgs = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel()->with('users')->find($organization->getOrganizationIds($enum));

                foreach ($relatedOrgs as $related_organization) {
                    foreach ($related_organization->users as $user) {
                        $target_ids[] = $user->getUserId();
                    }
                }
            }

            return $target_ids;
        });
    }


    protected static function _getRoleUserOrOrgQueryTable($table_name, $key, $target_table, $tablePermission = null, $builder = null, ?\Closure $target_ids_callback = null){
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);
        
        // get custom_value's users
        $target_ids = [];
        $all = false;
        
        if ($target_table->allUserAccessable()) {
            $all = true;
        } else {
            // if set $tablePermission, always call
            if (isset($tablePermission) || is_null($target_ids = System::requestSession($key))) {
                // get user ids
                $target_ids = static::getRoleUserOrgId($target_table ?? [], $table_name, $tablePermission);

                if($target_ids_callback){
                    $target_ids = $target_ids_callback($target_ids, $target_table);
                }

                if (!isset($tablePermission)) {
                    System::requestSession($key, $target_ids);
                }
            }
        }
    
        $target_ids = array_unique($target_ids);
        // return target values
        if (!isset($builder)) {
            $builder = getModelName($table_name)::query();
        }
        if (!$all) {
            $builder->whereIn('id', $target_ids);
        }

        return $builder;
    }


    /**
     * get all users and organizations who can access custom_value.
     *
     * @param CustomValue $custom_value
     * @param string|null|array $tablePermission
     * @return array
     */
    public static function getRoleUserAndOrganizations($custom_value, $tablePermission = null)
    {
        $results = [
            SystemTableName::USER => [],
            SystemTableName::ORGANIZATION => [],
        ];
        $ids = [
            SystemTableName::USER => [],
            SystemTableName::ORGANIZATION => [],
        ];
        
        // check request session
        $key = sprintf(Define::SYSTEM_KEY_SESSION_VALUE_ACCRSSIBLE_USERS, $custom_value->custom_table->id, $custom_value->id);
        // if set $tablePermission, always call
        if (isset($tablePermission) || is_null($results = System::requestSession($key))) {
            $ids[SystemTableName::USER] = $custom_value->value_authoritable_users()->pluck('authoritable_target_id')->toArray();

            // get custom_value's organizations
            if (System::organization_available()) {
                $ids[SystemTableName::ORGANIZATION]= $custom_value->value_authoritable_organizations()->pluck('authoritable_target_id')->toArray();
            }

            foreach($ids as $idkey => $idvalue){
                // get custom table's user ids(contains all table and permission role group)
                $func = $idkey == SystemTableName::USER ? 'getRoleUserQueryTable' : 'getRoleOrganizationQueryTable';
                $queryTable = static::{$func}($custom_value->custom_table, $tablePermission);
                $queryTable->withoutGlobalScope(CustomValueModelScope::class);

                $results[$idkey] = $queryTable->findMany($idvalue);
            }

            if (!isset($tablePermission)) {
                System::requestSession($key, $results);
            }
        }
        
        return $results;
    }

    /**
     * get users or organizaitons who can access table.
     *
     * @param CustomTable $target_table access table.
     * @param array $related_types "user" or "organization"
     */
    protected static function getRoleUserOrgId($target_table, $related_type, $tablePermission = null)
    {
        $target_table = CustomTable::getEloquent($target_table);
        
        // Get role group contains target_table's
        $roleGroups = RoleGroup::whereHas('role_group_permissions', function ($query) use ($target_table) {
            $query->where(function ($query) use ($target_table) {
                $query->orWhere(function ($query) {
                    $query->where('role_group_permission_type', RoleType::SYSTEM);
                });
                $query->orWhere(function ($query) use ($target_table) {
                    $query->where('role_group_permission_type', RoleType::TABLE)
                        ->where('role_group_target_id', $target_table->id);
                });
            });
        })->with(['role_group_user_organizations', 'role_group_permissions'])->get();

        $target_ids = collect();
        foreach ($roleGroups as $roleGroup) {
            // check permission
            if (!$roleGroup->role_group_permissions->contains(function ($role_group_permission) use ($target_table, $tablePermission) {
                // check as system
                if ($role_group_permission->role_group_permission_type == RoleType::SYSTEM) {
                    $tablePermission = [Permission::SYSTEM, Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_EDIT_ALL];
                }
                // check as table
                else {
                    // not match table, return false
                    if ($target_table->id != $role_group_permission->role_group_target_id) {
                        return false;
                    }
                }

                // check as table
                if (!isset($tablePermission)) {
                    $tablePermission = Permission::AVAILABLE_ACCESS_CUSTOM_VALUE;
                }
                
                // check contains $tablePermission in $role_group_permission
                return collect($tablePermission)->contains(function ($p) use ($role_group_permission) {
                    return in_array($p, $role_group_permission->permissions);
                });
            })) {
                continue;
            }

            foreach ($roleGroup->role_group_user_organizations as $role_group_user_organization) {
                // merge users from $role_group_user_organization
                if ($role_group_user_organization->role_group_user_org_type != $related_type) {
                    continue;
                }
                $target_ids = $target_ids->merge($role_group_user_organization->role_group_target_id);
            }
        }

        // set system user if $related_type is USER
        if ($related_type == SystemTableName::USER) {
            $target_ids = $target_ids->merge(System::system_admin_users() ?? []);
        }

        return $target_ids->filter()->unique()->toArray();
    }

    
    /**
     * get organization ids
     * @return mixed
     */
    public static function getOrganizationIds($filterType = JoinedOrgFilterType::ALL, $targetUserId = null)
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        
        // get organization and ids
        $orgsArray = static::getOrganizationTreeArray();
                
        if (!isset($targetUserId)) {
            $targetUserId = \Exment::getUserId();
        }

        $results = [];
        foreach ($orgsArray as $org) {
            static::setJoinedOrganization($results, $org, $filterType, $targetUserId);
        }

        return collect($results)->pluck('id')->toArray();
    }

    /**
     * Get all organization tree array
     *
     * @return array
     */
    protected static function getOrganizationTreeArray() : array
    {
        return System::requestSession(Define::SYSTEM_KEY_SESSION_ORGANIZATION_TREE, function () {
            $modelname = getModelName(SystemTableName::ORGANIZATION);
            $indexName = $modelname::getParentOrgIndexName();

            // get query
            $orgs = $modelname::with([
                'users' => function ($query) {
                    // pass aborting
                    return $query->withoutGlobalScope(CustomValueModelScope::class);
                }
                ])
                // pass aborting
                ->withoutGlobalScopes([CustomValueModelScope::class])
                ->get(['id', $indexName])->toArray();

            $baseOrgs = $orgs;

            if (is_nullorempty($orgs)) {
                return [];
            }

            foreach ($orgs as &$org) {
                static::parents($org, $baseOrgs, $org, $indexName);
                static::children($org, $orgs, $org, $indexName);
            }

            return $orgs;
        });
    }

    protected static function parents(&$org, $orgs, $target, $indexName)
    {
        if (!isset($target[$indexName])) {
            return;
        }

        // if same id, return
        if ($org['id'] == $target[$indexName]) {
            return;
        }

        $newTarget = collect($orgs)->first(function ($o) use ($target, $indexName) {
            return $target[$indexName] == $o['id'];
        });
        if (!isset($newTarget)) {
            return;
        }

        // set parent
        $org['parents'][] = $newTarget;
        static::parents($org, $orgs, $newTarget, $indexName);
    }

    protected static function children(&$org, $orgs, $target, $indexName)
    {
        $children = collect($orgs)->filter(function ($o) use ($target, $indexName) {
            if (!isset($o[$indexName])) {
                return;
            }

            return $o[$indexName] == $target['id'];
        });

        foreach ($children as $child) {
            if ($org['id'] == $child['id']) {
                continue;
            }
            // set children
            $org['children'][] = $child;
            static::children($org, $orgs, $child, $indexName);
        }
    }

    protected static function setJoinedOrganization(&$results, $org, $filterType, $targetUserId)
    {
        // set $org id only $targetUserId
        if (!array_has($org, 'users') || !collect($org['users'])->contains(function ($user) use ($targetUserId) {
            return $user['id'] == $targetUserId;
        })) {
            return;
        }

        $results[] = $org;
        if (JoinedOrgFilterType::isGetDowner($filterType) && array_has($org, 'parents')) {
            foreach ($org['parents'] as $parent) {
                $results[] = $parent;
            }
        }

        if (JoinedOrgFilterType::isGetUpper($filterType) && array_has($org, 'children')) {
            foreach ($org['children'] as $child) {
                $results[] = $child;
            }
        }
    }

    /**
     * Filtering user. Only join. set by filter_multi_user.
     *
     * @param [type] $builder
     * @param [type] $user
     * @param [type] $db_table_name
     * @return void
     */
    public static function filterUserOnlyJoin($builder, $user, $db_table_name)
    {
        $setting = System::filter_multi_user();
        if ($setting == JoinedMultiUserFilterType::NOT_FILTER) {
            return;
        }

        // if login user have FILTER_MULTIUSER_ALL, no filter
        if (\Exment::user()->hasPermission(Permission::FILTER_MULTIUSER_ALL)) {
            return;
        }

        $joinedOrgFilterType = JoinedOrgFilterType::getEnum($setting);

        // First, get users org joined
        $db_table_name_pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        $target_users = \DB::table($db_table_name_pivot)->whereIn('parent_id', $user->getOrganizationIds($joinedOrgFilterType))
            ->get(['child_id'])->pluck('child_id');

        $target_users = $target_users->merge($user->getUserId())->unique();
        
        // get only login user's organization user
        $builder->whereIn("$db_table_name.id", $target_users->toArray());
    }
    
    /**
     * Filtering user. Only join. set by filter_multi_user.
     *
     * @param [type] $builder
     * @param [type] $user
     * @param [type] $db_table_name
     * @return void
     */
    public static function filterOrganizationOnlyJoin($builder, $user, $db_table_name)
    {
        $setting = System::filter_multi_user();
        if ($setting == JoinedMultiUserFilterType::NOT_FILTER) {
            return;
        }

        // if login user have FILTER_MULTIUSER_ALL, no filter
        if (\Exment::user()->hasPermission(Permission::FILTER_MULTIUSER_ALL)) {
            return;
        }

        $joinedOrgFilterType = JoinedOrgFilterType::getEnum($setting);

        // get only login user's organization
        $builder->whereIn("$db_table_name.id", $user->getOrganizationIds($joinedOrgFilterType));
    }

    /**
     * Get User, org, role group form
     *
     * @return ModalForm
     */
    public static function getUserOrgModalForm($custom_table = null, $value = [], $options = [])
    {
        $options = array_merge([
            'prependCallback' => null
        ], $options);
        
        $form = new ModalForm();

        if (isset($options['prependCallback'])) {
            $options['prependCallback']($form);
        }

        list($users, $ajax) = CustomTable::getEloquent(SystemTableName::USER)->getSelectOptionsAndAjaxUrl([
            'display_table' => $custom_table,
            'selected_value' => array_get($value, SystemTableName::USER),
        ]);

        // select target users
        $form->multipleSelect('modal_' . SystemTableName::USER, exmtrans('menu.system_definitions.user'))
            ->options($users)
            ->ajax($ajax)
            ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
            ->default(array_get($value, SystemTableName::USER));

        if (System::organization_available()) {
            list($organizations, $ajax) = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getSelectOptionsAndAjaxUrl([
                'display_table' => $custom_table,
                'selected_value' => array_get($value, SystemTableName::ORGANIZATION),
            ]);
                
            $form->multipleSelect('modal_' . SystemTableName::ORGANIZATION, exmtrans('menu.system_definitions.organization'))
                ->options($organizations)
                ->ajax($ajax)
                ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
                ->default(array_get($value, SystemTableName::ORGANIZATION));
        }

        return $form;
    }
}
