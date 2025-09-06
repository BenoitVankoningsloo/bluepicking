<?php /** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

namespace App\Service;

use Random\RandomException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OdooClient
{
    private string $url;
    private string $db;
    private string $login;
    private string $passOrKey;
    private int $uid = 0;

    public function __construct(private readonly HttpClientInterface $http)
    {
        $this->url       = rtrim($_ENV['ODOO_URL'] ?? '', '/') . '/jsonrpc';
        $this->db        = (string)($_ENV['ODOO_DB'] ?? '');
        $this->login     = (string)($_ENV['ODOO_LOGIN'] ?? '');
        $this->passOrKey = (string)($_ENV['ODOO_API_KEY'] ?? '');
        if ($this->url === '/jsonrpc' || !$this->db || !$this->login || !$this->passOrKey) {
            throw new RuntimeException('OdooClient: variables ODOO_URL/DB/LOGIN/API_KEY manquantes.');
        }
    }

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    private function jsonRpc(string $service, string $method, array $args): mixed
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => ['service' => $service, 'method' => $method, 'args' => $args],
            'id'      => random_int(1, PHP_INT_MAX),
        ];
        $resp = $this->http->request('POST', $this->url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $payload,
            'timeout' => 30,
        ]);
        $data = $resp->toArray(false);
        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? 'Odoo JSON-RPC error';
            $det = $data['error']['data']['message'] ?? '';
            throw new RuntimeException(sprintf('Odoo RPC: %s %s', $msg, $det));
        }
        return $data['result'] ?? null;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    private function ensureLogin(): void
    {
        if ($this->uid) return;
        /** @noinspection PhpUnhandledExceptionInspection */
        $uid = $this->jsonRpc('common', 'login', [$this->db, $this->login, $this->passOrKey]);
        if (!is_int($uid) || $uid <= 0) {
            throw new RuntimeException('Odoo login failed (uid invalide).');
        }
        $this->uid = $uid;
    }

    /** execute_kw (avec kwargs)
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function callKW(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $this->ensureLogin();
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->jsonRpc('object', 'execute_kw', [
            $this->db, $this->uid, $this->passOrKey, $model, $method, $args, $kwargs
        ]);
    }

    /** execute (sans kwargs)
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function call(string $model, string $method, mixed ...$args): mixed
    {
        $this->ensureLogin();
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->jsonRpc('object', 'execute', [
            $this->db, $this->uid, $this->passOrKey, $model, $method, ...$args
        ]);
    }

    // Helpers usuels
    public function searchRead(string $model, array $domain, array $fields = [], int $limit = 80, int $offset = 0, ?string $order = null): array
    {
        $kwargs = ['fields' => $fields, 'limit' => $limit, 'offset' => $offset];
        if ($order) { $kwargs['order'] = $order; }
        return $this->callKW($model, 'search_read', [$domain], $kwargs);
    }

    public function read(string $model, array $ids, array $fields = []): array
    {
        $kwargs = $fields ? ['fields' => $fields] : [];
        return $this->callKW($model, 'read', [$ids], $kwargs);
    }
}

