<?php

namespace Zefy\LaravelSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use Zefy\LaravelSSO\LaravelSSOBroker;
use Illuminate\Support\Facades\Log;

class SSOAutoLoginMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $broker = new LaravelSSOBroker();
        $response = $broker->getUserInfo();

        Log::debug('broker response', $response);

        // If client is logged out in SSO server but still logged in broker.
        if (!isset($response['data']) && !auth()->guest()) {
            Log::debug('client log out');
            return $this->logout($request);
        }

        // If there is a problem with data in SSO server, we will re-attach client session.
        if (isset($response['error']) && strpos($response['error'], 'There is no saved session data associated with the broker session id') !== false) {
            Log::debug('reset cookie');
            return $this->clearSSOCookie($request);
        }

        $username = config('laravel-sso.username');
        $remoteUserName = config('laravel-sso.remoteUserName');
        // If client is logged in SSO server and didn't logged in broker...
        if (isset($response['data']) && (auth()->guest() || auth()->user()->$username != $response['data'][$remoteUserName])) {
            // ... we will authenticate our client.
            $user = config('laravel-sso.usersModel')::where($username, $response['data'][$remoteUserName])->first();

            Log::debug('user authentication', [
                'user' => $user
            ]);

            if(empty($user)){
                Log::debug('user logout');
                return $this->logout($request);
            }
            auth()->loginUsingId($user->id);
        }

        return $next($request);
    }

    /**
     * Clearing SSO cookie so broker will re-attach SSO server session.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function clearSSOCookie(Request $request)
    {
        return redirect($request->fullUrl())->cookie(cookie('sso_token_' . config('laravel-sso.brokerName')));
    }

    /**
     * Logging out authenticated user.
     * Need to make a page refresh because current page may be accessible only for authenticated users.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function logout(Request $request)
    {
        if (!auth()->guest()) {
            auth()->logout();
        }

        $cookie = cookie()->forget('sso_token_' . config('laravel-sso.brokerName'));

        return redirect(config('laravel-sso.logoutUrl'))->withCookie($cookie);
    }
}
