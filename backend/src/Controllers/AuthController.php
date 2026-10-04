<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(Request $request): void
    {
        $input = $request->json();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($username === '' || $password === '') {
            throw HttpException::validation('username', 'Kullanıcı adı ve şifre zorunludur.');
        }
        Response::data($this->auth->login($username, $password));
    }

    public function logout(Request $request): void
    {
        $this->auth->logout((string) $request->bearerToken());
        Response::noContent();
    }

    public function me(Request $request): void
    {
        Response::data($request->user);
    }
}
