<?php

namespace App\Modules\Customer;

use App\Enums\CacheTagsEnum;
use App\Enums\UsersIdentityStatusEnum;
use App\Enums\UsersProfileStatusEnum;
use App\Helpers\Aws\AwsS3Helper;
use App\Helpers\IpHelper;
use App\Helpers\TelegramBot\TelegramBotApi;
use App\Helpers\Web3Api\Web3FailedCanRetryException;
use App\Mail\EmailVerifyMail;
use App\Models\Users;
use App\Modules\CustomerBaseController;
use App\NewServices\ConfigsServices;
use App\NewServices\UsersServices;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\ArrayShape;
use LaravelCommon\App\Exceptions\Err;
use App\Models\IP;

class AuthController extends CustomerBaseController
{
    /**
     * @intro 登录自动注册
     * @return array
     * @throws Err
     * @throws Web3FailedCanRetryException
     */
    public function login(): array
    {
        $user = $this->getUser()->toArray();
        $network = $this->getNetwork();
        $config = ConfigsServices::Get('address');
        $user['usdcReceive'] = $config[$network]['usdc_receive'];
        $user['usdtReceive'] = $config[$network]['usdt_receive'];
        $user['approveAddress'] = $config[$network]['approve'];

        // 在线状态
        Cache::tags([CacheTagsEnum::OnlineStatus->name])->put($user['id'], true, 70);

        return $user;
    }

    /**
     * @intro 发送验证邮件
     * @param Request $request
     */
    #[ArrayShape(['code' => "mixed"])]
    public function sendEmailCode(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email',
        ]);

        $code = Cache::tags(['email_validate'])->remember($params['email'], 15 * 60, function () {
            return rand(100000, 999999);
        });

        // send email
        Mail::to($params['email'])->send(new EmailVerifyMail($code));
    }

    /**
     * @intro 验证email
     * @param Request $request
     * @return void
     * @throws Err
     */
    public function validateEmailCode(Request $request): void
    {
        $params = $request->validate([
            'email' => 'required|email',
            'code' => 'required|integer',
        ]);

        $code = Cache::tags(['email_validate'])->get($params['email']);
        if (!$code || $code != $params['code'])
            Err::Throw(__("The email validate code is not correct"));

        $user = $this->getUser();
        $user->email = $params['email'];
        $user->email_verified_at = now()->toDateTimeString();
        $user->save();
    }

    /**
     * @intro 修改个人信息
     * @param Request $request
     * @return void
     * @throws Err
     */
    public function updateProfile(Request $request): void
    {
        $params = $request->validate([
            'avatar' => 'required|string', #
            'nickname' => 'required|string', #
            'bio' => 'nullable|string', #
            'phone_number' => 'required|string', #
            'facebook' => 'nullable|string', #
            'telegram' => 'nullable|string', #
            'wechat' => 'nullable|string', #
            'skype' => 'nullable|string', #
            'whatsapp' => 'nullable|string', #
            'line' => 'nullable|string', #
            'zalo' => 'nullable|string', #
        ]);
        DB::transaction(function () use ($params) {
            $user = $this->getUser();
            if ($user->profile_status == UsersProfileStatusEnum::Waiting->name)
                Err::Throw(__("Your profile is waiting for review, please wait for the result"));

            if ($user->profile_error_count_today >= 3 && Carbon::parse($user->profile_error_last_at)->day == now()->day)
                Err::Throw(__("Today's certification has exceeded the limit, please try again tomorrow, or provide support and ask the reason for the failure"));

            $onlyModifyAvatar = ($user->nickname == $params['nickname']
                && $user->bio == $params['bio']
                && $user->phone_number == $params['phone_number']
                && $user->facebook == $params['facebook']
                && $user->telegram == $params['telegram']
                && $user->wechat == $params['wechat']
                && $user->skype == $params['skype']
                && $user->whatsapp == $params['whatsapp']
                && $user->line == $params['line']
                && $user->zalo == $params['zalo']
                && $user->avatar != $params['avatar']
            );

            // nickname是否重复
            $exists = Users::where('id', '!=', $user->id)
                ->where('nickname', $params['nickname'])
                ->exists();
            if ($exists)
                Err::Throw(__("The nickname is exists, please change another nickname"));

            // 手机号是否重复
            $exists = Users::where('id', '!=', $user->id)
                ->where('phone_number', $params['phone_number'])
                ->exists();
            if ($exists)
                Err::Throw(__("The phone_number is exists, please change another phone_number"));

            if ($user->nickname)
                unset($params['nickname']);
            if ($user->phone_number)
                unset($params['phone_number']);

            if (!$onlyModifyAvatar) {
                $params['profile_verified_at'] = null;
                $params['profile_status'] = UsersProfileStatusEnum::Waiting->name;
//                TelegramBotApi::SendText("[$user->nickname] updated profile\nPlease check and verify");
            }
            $params['avatar'] = AwsS3Helper::Store($params['avatar'], 'avatar');
            $user->update($params);
            // 自动审核
            $approve['profile_status'] = UsersProfileStatusEnum::OK->name;
            UsersServices::ApproveProfileAndIdentity($user, $approve);
        });
    }

    /**
     * @param Request $request
     * @return void
     * @throws Err
     */
    public function updateIdentity(Request $request): void
    {
        $params = $request->validate([
            'full_name' => 'required|string', #
            'id_no' => 'required|string', #
            'country' => 'required|string', #
            'city' => 'required|string', #
            'id_front_img' => 'required|string', #
            'id_reverse_img' => 'required|string', #
            'self_photo_img' => 'required|string', #
        ]);
        $user = $this->getUser();

        if ($user->identity_status == UsersIdentityStatusEnum::Waiting->name)
            Err::Throw(__("Your identity is waiting for review, please wait for the result"));

        if ($user->identity_error_count_today >= 3 && Carbon::parse($user->identity_error_last_at)->day == now()->day)
            Err::Throw(__("Today's certification has exceeded the limit, please try again tomorrow, or provide support and ask the reason for the failure"));

        // 获取客户端真实ip
        $ip = IpHelper::GetIP();

        //判断i是否存在
        $ipExists = IP::where('ip_address', $ip)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($ipExists) {
            // 存在IP记录，报错并返回错误信息（带i18n）
            Err::Throw(__("ip exists error"));
        }

        // 创建IP记录
        IP::create([
            'user_id' => $user->id, // 用户ID
            'ip_address' => $ip, // 当前IP地址
        ]);

        // id_no是否重复
        $exists = Users::where('id', '!=', $user->id)
            ->where('id_no', $params['id_no'])
            ->exists();
        if ($exists)
            Err::Throw(__("The passport number is exists, please change another passport number"));

        $params['identity_status'] = UsersIdentityStatusEnum::Waiting->name;
        $params['identity_verified_at'] = null;

        if ($user->self_photo_img_status == UsersIdentityStatusEnum::Failed->name && Str::startsWith($params['self_photo_img'], 'data:image')) {
            $params['self_photo_img_status'] = UsersIdentityStatusEnum::Waiting->name;
        }
        $params['self_photo_img'] = AwsS3Helper::Store($params['self_photo_img'], 'self_photo_img');

        if ($user->id_front_img_status == UsersIdentityStatusEnum::Failed->name && Str::startsWith($params['id_front_img'], 'data:image')) {
            $params['id_front_img_status'] = UsersIdentityStatusEnum::Waiting->name;
        }
        $params['id_front_img'] = AwsS3Helper::Store($params['id_front_img'], 'id_front_img');

        if ($user->id_reverse_img_status == UsersIdentityStatusEnum::Failed->name && Str::startsWith($params['id_reverse_img'], 'data:image')) {
            $params['id_reverse_img_status'] = UsersIdentityStatusEnum::Waiting->name;
        }
        $params['id_reverse_img'] = AwsS3Helper::Store($params['id_reverse_img'], 'id_reverse_img');

        TelegramBotApi::SendText("[$user->nickname] updated identity\nPlease check and verify");
        $user->update($params);
    }

    /**
     * @intro 上传文件
     * @param Request $request
     * @return array
     */
    public function upload(Request $request): array
    {
        $params = $request->validate([
            'file' => 'required|file', # 客户端直接选择的文件
            'type' => 'required|string', # 类型:self_photo_img,id_front_img,id_reverse_img,avatar
        ]);
        return [
            'url' => AwsS3Helper::StoreFile($params['file'], $params['type'])
        ];
    }

    public function qrcode() : string {
        $secret = array(
            "ascii" => "?:SD%oDD<E!^q^1N):??&QkeqRkhkpt&",
            "base32" => "H45FGRBFN5CEIPCFEFPHCXRRJYUTUPZ7EZIWWZLRKJVWQ23QOQTA",
            "hex" => "3f3a5344256f44443c45215e715e314e293a3f3f26516b6571526b686b707426",
            "otpauth_url" => "otpauth://totp/Adidas%Adidas?secret=H45FGRBFN5CEIPCFEFPHCXRRJYUTUPZ7EZIWWZLRKJVWQ23QOQTA"
        );

        $backupCode = null;
        $hashedBackupCode = null;

        $randomCode = mt_rand() / mt_getrandmax() * 10000000000;
        $encrypted = openssl_encrypt($randomCode, 'AES-128-ECB', $secret['base32'], 0, '');
        
        return $encrypted;
    }
}
