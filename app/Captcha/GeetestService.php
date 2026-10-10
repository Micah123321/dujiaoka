<?php

namespace App\Captcha;

use Carbon\Carbon;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\Request;

class GeetestService
{
    private const STATE = 'geetest_state';
    private $http;
    private $response = [];

    public function __construct(ClientInterface $http)
    {
        $this->http = $http;
    }

    public function preProcess(array $context): int
    {
        $request = request();
        $request->session()->forget(self::STATE);
        $id = (string) config('geetest.id');
        $key = (string) config('geetest.key');
        if ($id === '' || $key === '') {
            $this->response = ['success' => 0, 'gt' => $id, 'challenge' => bin2hex(random_bytes(17)), 'new_captcha' => 1];
            return 0;
        }
        $this->response = $this->client()->register($context);
        $request->session()->put(self::STATE, [
            'challenge' => $this->response['challenge'], 'success' => $this->response['success'],
            'issued_at' => Carbon::now()->getTimestamp(), 'context' => $context,
            'credentials' => hash('sha256', $id . "\0" . $key),
        ]);
        return $this->response['success'];
    }

    public function getResponseStr(): string
    {
        return json_encode($this->response);
    }

    public function validate(Request $request, array $data): bool
    {
        if (!$request->hasSession()) { return false; }
        $state = $request->session()->pull(self::STATE);
        if (!is_array($state)) { return false; }
        $id = (string) config('geetest.id');
        $key = (string) config('geetest.key');
        if ($id === '' || $key === '' || !hash_equals($state['credentials'], hash('sha256', $id . "\0" . $key))) { return false; }
        $age = Carbon::now()->getTimestamp() - $state['issued_at'];
        if ($age < 0 || $age > 300) { return false; }
        foreach (['geetest_challenge', 'geetest_validate', 'geetest_seccode'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') { return false; }
        }
        $challenge = $data['geetest_challenge'];
        // V3 appends a suffix; bind its random prefix to this session.
        if (!preg_match('/\A[a-f0-9]{32}[a-zA-Z0-9]{0,32}\z/', $challenge)
            || !hash_equals(substr($state['challenge'], 0, 32), substr($challenge, 0, 32))) { return false; }
        if ($state['success'] === 1) {
            return $this->client()->validateOnline($challenge, $data['geetest_validate'], $data['geetest_seccode'], $state['context']);
        }
        return $this->client()->validateOffline($challenge, $data['geetest_validate'], $data['geetest_seccode']);
    }

    private function client(): GeetestClient
    {
        // Refresh shop credentials for every call, including long-running workers.
        return new GeetestClient($this->http, (string) config('geetest.id'), (string) config('geetest.key'));
    }
}
