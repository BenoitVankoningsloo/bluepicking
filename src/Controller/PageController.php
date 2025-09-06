<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    #[Route('/page1', name: 'page1', methods: ['GET'])]
    public function page1(): Response
    {
        return $this->render('home/page1.html.twig');
    }

    #[Route('/page2', name: 'page2', methods: ['GET'])]
    public function page2(): Response
    {
        return $this->render('home/page2.html.twig');
    }

    #[Route('/page3', name: 'page3', methods: ['GET'])]
    public function page3(): Response
    {
        return $this->render('home/page3.html.twig');
    }
}

