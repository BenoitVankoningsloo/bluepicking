<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BpostCallbacksController extends AbstractController
{
    #[Route('/admin/bpost/confirm', name: 'admin_bpost_confirm', methods: ['GET'])]
    public function ok(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return new Response('<h3>Expédition bpost - confirmation reçue.</h3>', 200);
    }

    #[Route('/admin/bpost/error', name: 'admin_bpost_error', methods: ['GET'])]
    public function error(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return new Response('<h3>Expédition bpost - erreur.</h3>', 200);
    }

    #[Route('/admin/bpost/cancel', name: 'admin_bpost_cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return new Response('<h3>Expédition bpost - annulée.</h3>', 200);
    }
}

