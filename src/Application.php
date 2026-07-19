<?php

declare(strict_types=1);

namespace Nimbus;

use Nimbus\Admin\AdminController;
use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Request;
use Nimbus\Http\Router;
use Nimbus\Support\Config;
use Nimbus\Support\Env;
use Nimbus\View\View;

/**
 * The HTTP kernel. Boots config + database, then routes the request across the
 * admin, API and public-site areas. Handlers return HTML strings (echoed) or
 * redirect/exit themselves.
 */
final class Application
{
    private Connection $db;
    private Auth $auth;

    public function __construct()
    {
        Env::load(Config::basePath() . '/.env');
        $this->db   = new Connection(Config::db());
        $this->auth = new Auth($this->db);
    }

    public function run(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $request = Request::fromGlobals();

        try {
            if (!$this->db->isReady()) {
                $this->send($this->notice('Database unavailable', 'NimbusCMS can’t reach the database. Check your <code>.env</code> or Docker stack.'), 503);
                return;
            }
            if (!$this->db->tableExists('nb_users')) {
                $this->send($this->notice('Not installed yet', 'Run <code>php bin/nimbus install</code> to conjure the schema and your first user.'), 503);
                return;
            }

            $router = new Router();
            (new AdminController($this->db, $this->auth))->routes($router);
            $router->get('/', fn (): string => $this->home());

            $hit = $router->dispatch($request->method, $request->path);
            if ($hit === null) {
                $this->send($this->notice('Not found', 'Nothing lives at <code>' . View::e($request->path) . '</code>.'), 404);
                return;
            }
            if (is_string($hit['result'])) {
                echo $hit['result'];
            }
        } catch (\Throwable $e) {
            $message = Config::debug() ? $e->getMessage() : 'An unexpected error occurred.';
            $this->send($this->notice('Something went wrong', View::e($message)), 500);
        }
    }

    private function home(): string
    {
        return $this->notice(
            Config::appName(),
            'Your public site will render here soon. Head to <a href="/admin">/admin</a> to manage content.'
        );
    }

    private function notice(string $title, string $html): string
    {
        $t = View::e($title);
        return "<!doctype html><meta charset=\"utf-8\"><title>{$t}</title>"
            . '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:14vh auto;padding:0 24px;color:#1e2330">'
            . "<h1 style=\"letter-spacing:-.02em\">{$t}</h1><p style=\"color:#6b7280;line-height:1.6\">{$html}</p></div>";
    }

    private function send(string $html, int $status): void
    {
        http_response_code($status);
        echo $html;
    }
}
