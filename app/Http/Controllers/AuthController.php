<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Auth\AuthGateway;
use App\Services\Auth\AuthRejected;
use App\Services\Auth\AuthUnavailable;
use App\Services\Auth\CentralSession;
use App\Services\Auth\DevAuthGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(private readonly AuthGateway $gateway, private readonly CentralSession $session) {}

    public function login(Request $request)
    {
        if ($this->session->user()) {
            return redirect('/');
        }
        if (! $this->gateway instanceof DevAuthGateway && (! config('finance.auth.app_id') || ! config('finance.auth.app_secret'))) {
            return response()->view('errors.auth-unconfigured', [], 503);
        }

        return view('auth.login', ['loginUrl' => $this->gateway->loginUrl(url('/auth/callback'))]);
    }

    public function callback(Request $request)
    {
        try {
            $identity = $this->gateway->verifyCallback($request->query());
        } catch (AuthRejected $e) {
            Log::notice('auth.callback.rejected', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);
            if ($denied = $this->gateway->accessDeniedUrl($e->getMessage(), url('/'))) {
                return redirect()->away($denied);
            }

            return response()->view('errors.access-denied', ['reason' => 'Sign-in could not be verified. Please try again.'], 403);
        } catch (AuthUnavailable) {
            return response()->view('errors.auth-unavailable', [], 503);
        }
        $this->session->establish($identity);
        $intended = session()->pull('url.intended', '/');
        // Redirect immediately so payload/sig never stay in the address bar or history.
        if (! is_string($intended) || ! str_starts_with($intended, url('/'))) {
            $intended = '/';
        }

        return redirect($intended);
    }

    public function logout(Request $request)
    {
        $user = $this->session->user();
        $token = $this->session->sessionToken();
        if ($user) {
            app(\App\Support\Context::class)->user = $user;
            Audit::log(null, 'auth.logout', $user);
        }
        $this->session->forget();

        return redirect()->away($this->gateway->logoutUrl(url('/'), $token));
    }

    public function devForm()
    {
        abort_unless($this->gateway instanceof DevAuthGateway && ! app()->environment('production'), 404);

        return view('auth.dev-login');
    }

    public function devLogin(Request $request)
    {
        abort_unless($this->gateway instanceof DevAuthGateway && ! app()->environment('production'), 404);
        $data = $request->validate([
            'uid' => ['required', 'regex:/^[A-Za-z0-9_-]{3,40}$/'],
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email'],
        ]);
        $q = DevAuthGateway::sign([
            'iss' => 'dev', 'iat' => time(), 'exp' => time() + 3600,
            'principal' => ['type' => 'user', 'id' => $data['uid']],
            'identity' => ['uid' => $data['uid'], 'email' => $data['email'], 'name' => $data['name'], 'username' => $data['uid']],
            'roles' => ['user'],
        ]);

        return redirect('/auth/callback?'.http_build_query($q));
    }
}
