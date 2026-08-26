<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Primary Organization: LP Ma'arif PCNU Cilacap
        $org = Organization::firstOrCreate(
            ['code' => 'LP-MAARIF-CLP'],
            [
                'name' => 'LP Ma\'arif NU PCNU Cilacap',
                'type' => 'lp_maarif',
                'address' => 'Jl. Kemerdekaan Barat No. 12, Cilacap',
                'phone' => '0282-534123',
                'email' => 'sekretariat@maarif-cilacap.org',
                'is_active' => true,
            ]
        );

        // 2. Default Users

        // Super Admin (Global, organization_id can be null or default)
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@maarif.org'],
            [
                'organization_id' => null,
                'name' => 'Super Administrator',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $superAdmin->assignRole(Role::SUPER_ADMIN);

        // Accounting Admin
        $accountingAdmin = User::firstOrCreate(
            ['email' => 'admin@maarif-cilacap.org'],
            [
                'organization_id' => $org->id,
                'name' => 'Admin Keuangan LP Ma\'arif',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $accountingAdmin->assignRole(Role::ACCOUNTING_ADMIN);

        // Accounting Operator
        $operator = User::firstOrCreate(
            ['email' => 'operator@maarif-cilacap.org'],
            [
                'organization_id' => $org->id,
                'name' => 'Operator Keuangan',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $operator->assignRole(Role::ACCOUNTING_OPERATOR);

        // Reconciler
        $reconciler = User::firstOrCreate(
            ['email' => 'reconciler@maarif-cilacap.org'],
            [
                'organization_id' => $org->id,
                'name' => 'Petugas Rekonsiliasi Bank',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $reconciler->assignRole(Role::RECONCILER);

        // Approver
        $approver = User::firstOrCreate(
            ['email' => 'approver@maarif-cilacap.org'],
            [
                'organization_id' => $org->id,
                'name' => 'Pejabat Penyetuju (Ketua / Bendahara)',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $approver->assignRole(Role::APPROVER);

        // Auditor
        $auditor = User::firstOrCreate(
            ['email' => 'auditor@maarif-cilacap.org'],
            [
                'organization_id' => $org->id,
                'name' => 'Auditor Internal PCNU',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $auditor->assignRole(Role::AUDITOR);
    }
}
