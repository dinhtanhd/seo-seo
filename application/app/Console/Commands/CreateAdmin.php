<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'tram:admin-create {--email=} {--name=} {--global : Quyền owner toàn hệ thống} {--password-stdin : Đọc mật khẩu qua stdin, không qua argument}';

    protected $description = 'Tạo owner an toàn, không có mật khẩu mặc định và không đổi tài khoản cũ.';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email owner');
        $name = $this->option('name') ?: $this->ask('Tên hiển thị', 'Chủ hệ thống');
        $password = $this->option('password-stdin') ? rtrim(stream_get_contents(STDIN), "\r\n") : $this->secret('Mật khẩu mới (ít nhất 12 ký tự)');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password ?? '') < 12) {
            $this->error('Email không hợp lệ hoặc mật khẩu dưới 12 ký tự.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('Email đã tồn tại; lệnh không ghi đè tài khoản.');

            return self::FAILURE;
        }
        $data = ['name' => $name, 'email' => $email, 'password' => Hash::make($password)];
        foreach (['role' => 'owner', 'is_active' => true, 'active' => true, 'email_verified_at' => now()] as $column => $value) {
            if (Schema::hasColumn('users', $column)) {
                $data[$column] = $value;
            }
        }
        if (Schema::hasColumn('users', 'is_global_admin')) {
            $data['is_global_admin'] = (bool) $this->option('global');
        }
        DB::transaction(function () use ($data): void {
            $user = new User;
            $user->forceFill($data)->save();
            if (Schema::hasTable('audit_events')) {
                DB::table('audit_events')->insert([
                    'action' => 'identity.admin_created',
                    'after_json' => json_encode(['user_id' => $user->id, 'email' => $user->email, 'global' => $data['is_global_admin'] ?? false], JSON_UNESCAPED_UNICODE),
                    'correlation_id' => (string) Str::uuid(),
                ]);
            }
        });
        unset($password);
        $this->info('Owner đã tạo: '.$email.'. Mật khẩu không được ghi vào log.');

        return self::SUCCESS;
    }
}
