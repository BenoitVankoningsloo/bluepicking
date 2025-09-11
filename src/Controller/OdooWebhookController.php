<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\OdooSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class OdooWebhookController extends AbstractController
{
    public function __construct(private readonly OdooSyncService $sync) {}

    #[Route('/odoo/webhook', name: 'odoo_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Auth simple par token partagé
        $provided = (string) ($request->headers->get('X-Odoo-Webhook-Token') ?? $request->query->get('token') ?? '');
        $expected = (string) ($_ENV['ODOO_WEBHOOK_TOKEN'] ?? '');
        if ($expected !== '' && !\hash_equals($expected, $provided)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        // Payload JSON
        $raw = (string) $request->getContent();
        $json = \json_decode($raw, true);
        if (!\is_array($json)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        // Extraction robuste d’IDs ou de noms de sale.order
        $targets = $this->extractSaleOrderTargets($json);
        if (!$targets) {
            return new JsonResponse(['ok' => false, 'error' => 'no_sale_order_targets'], 400);
        }

        $done = 0;
        $errors = 0;
        $last = null;
        foreach ($targets as $t) {
            try {
                $this->sync->syncOne($t);
                $done++;
                $last = $t;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return new JsonResponse([
            'ok'      => $errors === 0,
            'imported'=> $done,
            'errors'  => $errors,
            'last'    => $last,
        ], 200);
    }

    /**
     * Accepte diverses formes:
     * - {"model":"sale.order","id":123}
     * - {"model":"sale.order","ids":[123,124]}
     * - {"resource":"sale.order","payload":{"id":123}}
     * - {"model":"sale.order","name":"SO0001"}
     * - {"model":"sale.order","records":[{"id":123},{"id":124}]}
     * - {"data":{"model":"sale.order","ids":[...]}}
     */
    private function extractSaleOrderTargets(array $json): array
    {
        $candidates = [];

        $isSaleOrder = static function ($m): bool {
            return \is_string($m) && \in_array($m, ['sale.order', 'sale.order,write', 'sale.order,create'], true);
        };

        // Racine
        if (isset($json['model']) && $isSaleOrder($json['model'])) {
            if (isset($json['id']) && (\is_int($json['id']) || \is_string($json['id']))) {
                $candidates[] = $json['id'];
            }
            if (isset($json['ids']) && \is_array($json['ids'])) {
                foreach ($json['ids'] as $v) {
                    if (\is_int($v) || (\is_string($v) && $v !== '')) { $candidates[] = $v; }
                }
            }
            if (isset($json['name']) && \is_string($json['name']) && $json['name'] !== '') {
                $candidates[] = $json['name'];
            }
            if (isset($json['records']) && \is_array($json['records'])) {
                foreach ($json['records'] as $r) {
                    if (\is_array($r) && isset($r['id']) && (\is_int($r['id']) || \is_string($r['id']))) {
                        $candidates[] = $r['id'];
                    }
                    if (\is_array($r) && isset($r['name']) && \is_string($r['name']) && $r['name'] !== '') {
                        $candidates[] = $r['name'];
                    }
                }
            }
        }

        // resource/payload
        if (isset($json['resource']) && $isSaleOrder($json['resource'])) {
            $p = $json['payload'] ?? $json['data'] ?? null;
            if (\is_array($p)) {
                if (isset($p['id']) && (\is_int($p['id']) || \is_string($p['id']))) {
                    $candidates[] = $p['id'];
                }
                if (isset($p['ids']) && \is_array($p['ids'])) {
                    foreach ($p['ids'] as $v) {
                        if (\is_int($v) || (\is_string($v) && $v !== '')) { $candidates[] = $v; }
                    }
                }
                if (isset($p['name']) && \is_string($p['name']) && $p['name'] !== '') {
                    $candidates[] = $p['name'];
                }
            }
        }

        // data imbriqué
        if (isset($json['data']) && \is_array($json['data']) && isset($json['data']['model']) && $isSaleOrder($json['data']['model'])) {
            $d = $json['data'];
            if (isset($d['id']) && (\is_int($d['id']) || \is_string($d['id']))) { $candidates[] = $d['id']; }
            if (isset($d['ids']) && \is_array($d['ids'])) {
                foreach ($d['ids'] as $v) {
                    if (\is_int($v) || (\is_string($v) && $v !== '')) { $candidates[] = $v; }
                }
            }
            if (isset($d['name']) && \is_string($d['name']) && $d['name'] !== '') { $candidates[] = $d['name']; }
        }

        // Nettoyage
        $out = [];
        foreach ($candidates as $c) {
            if (\is_int($c)) { $out[] = $c; }
            elseif (\is_string($c) && $c !== '') { $out[] = $c; }
        }
        // Unicité
        $uniq = [];
        foreach ($out as $v) {
            $key = \is_int($v) ? 'i:' . $v : 's:' . $v;
            $uniq[$key] = $v;
        }

        return \array_values($uniq);
    }
}
