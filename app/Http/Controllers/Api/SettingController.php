<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Controllers\Traits\FrontendResponse;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    use ApiResponse, FrontendResponse;

    /** Panel settings (GET manageapi/systemset/get) */
    public function panelShow()
    {
        return $this->frontendOk($this->panelPayload());
    }

    /** Panel settings (POST manageapi/systemset/edit) */
    public function panelUpdate(Request $request)
    {
        $data = $this->getParams($request);

        $map = [];
        if (array_key_exists('loginlogo', $data)) {
            $map['login_logo'] = $data['loginlogo'];
        }
        if (array_key_exists('systemlogo', $data)) {
            $map['system_logo'] = $data['systemlogo'];
        }
        if (array_key_exists('rechargeAudit', $data)) {
            $map['recharge_audit'] = filter_var($data['recharge_audit'] ?? $data['rechargeAudit'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        foreach ($data as $key => $value) {
            if (in_array($key, ['loginlogo', 'systemlogo', 'rechargeAudit'], true)) {
                continue;
            }
            if (is_scalar($value) || is_null($value)) {
                $map[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
            }
        }

        Setting::setMany($map, 'general');

        ActivityLog::record('settings.update', 'Updated system settings', ['payload' => $map]);

        return $this->frontendOk($this->panelPayload(), 'Settings saved');
    }

    /** GET api/v1/settings */
    public function index()
    {
        $groups = Setting::query()->orderBy('group')->orderBy('key')->get(['key', 'value', 'group', 'type', 'label']);

        return $this->ok($groups->groupBy('group')->map(fn ($rows) => $rows->map(fn ($row) => [
            'key' => $row->key,
            'value' => $row->value,
            'type' => $row->type,
            'label' => $row->label,
        ]))->toArray());
    }

    /** PUT api/v1/settings */
    public function update(Request $request)
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ])['settings'];

        Setting::setMany($data, $request->input('group', 'general'));

        ActivityLog::record('settings.update', 'Updated system settings', ['payload' => $data]);

        return $this->ok(Setting::allMap(), 'Settings saved');
    }

    protected function panelPayload(): array
    {
        $map = Setting::allMap();

        return [
            'loginLogo' => $map['login_logo'] ?? '',
            'systemLogo' => $map['system_logo'] ?? '',
            'rechargeAudit' => ($map['recharge_audit'] ?? '0') === '1',
            'systemName' => $map['system_name'] ?? config('app.name'),
            'supportEmail' => $map['support_email'] ?? '',
            'supportPhone' => $map['support_phone'] ?? '',
            'defaultCurrency' => $map['default_currency'] ?? 'USD',
            'defaultVolumetricFactor' => (int) ($map['volumetric_factor'] ?? 5000),
        ];
    }
}
