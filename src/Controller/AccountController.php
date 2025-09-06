<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\AccountProfileType;
use App\Form\PasswordChangeFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Route('/account')]
final class AccountController extends AbstractController
{
    #[Route('', name: 'app_account', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('account/index.html.twig');
    }

    #[Route('/update', name: 'app_account_update', methods: ['GET', 'POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(AccountProfileType::class, $user);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Profil mis à jour.');
            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/update.html.twig', ['form' => $form->createView()]);
    }

    #[Route('/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(PasswordChangeFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $current = (string)$form->get('currentPassword')->getData();
            $new = (string)$form->get('newPassword')->getData();

            if (!$hasher->isPasswordValid($user, $current)) {
                $this->addFlash('danger', 'Mot de passe actuel invalide.');
                return $this->redirectToRoute('app_account_password');
            }

            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();
            $this->addFlash('success', 'Mot de passe mis à jour.');
            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/password.html.twig', ['form' => $form->createView()]);
    }
}

