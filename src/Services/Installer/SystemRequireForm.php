<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\InitializeStatus;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Enums\SystemRequireCalledType;

/**
 *
 */
class SystemRequireForm
{
    use EnvTrait;

    public function index()
    {
        $checkResult = SystemRequireList::make(SystemRequireCalledType::INSTALL_WEB);
        return view('exment::install.system_require', ['checkResult' => $checkResult, 'login_box_classname' => 'login-box-wide']);
    }

    public function post()
    {
        if (boolval(request()->get('refresh'))) {
            return redirect(admin_url('install'));
        }

        try {
            $inputs = InstallService::getInputParams();
            if (is_null(array_get($inputs, 'APP_URL')) && !is_null($url = asset(''))) {
                $inputs['APP_URL'] = rtrim($url, '/');
            }
            $this->setEnv($inputs);
            InstallService::forgetInputParams();
        } catch (\Exception $ex) {
            return back()->withInput()->withErrors([
                'install_error' => exmtrans('install.error.cannot_write_env'),
            ]);
        }

        InstallService::setInitializeStatus(InitializeStatus::SYSTEM_REQUIRE);

        return redirect(admin_url('install'));
    }
}
