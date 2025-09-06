<?php declare(strict_types=1);
namespace App\Controller;

use App\Entity\ContactMessage;
use App\Form\ContactFormType;
use App\Security\RecaptchaVerifier;
use App\Service\ContactEmailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, ContactEmailer $emailer, RecaptchaVerifier $recaptcha): Response
    {
        $message = new ContactMessage();

        if (!\method_exists($message, 'getCreatedAt') || $message->getCreatedAt() === null) {
            if (\method_exists($message, 'setCreatedAt')) {
                $message->setCreatedAt(new \DateTimeImmutable());
            }
        }

        $form = $this->createForm(ContactFormType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // --- Vérification reCAPTCHA (serveur) ---
            // On ne bloque que si une clé site est configurée (comme login/register)
            $siteKey = (string) ($this->getParameter('app.recaptcha_site_key') ?? '');
            if ($siteKey !== '') {
                $token = (string) $request->request->get('g-recaptcha-response', '');
                if ($token === '') {
                    $form->addError(new FormError('Veuillez valider le reCAPTCHA.'));
                } elseif (!$recaptcha->verify($token, $request->getClientIp())) {
                    $form->addError(new FormError('Le reCAPTCHA n’a pas pu être validé. Merci de réessayer.'));
                }
            }

            if ($form->isValid()) {
                $this->em->persist($message);
                $this->em->flush();

                $okAdmin = $emailer->sendAdminNotification($message);
                $okUser  = $emailer->sendAutoReply($message);

                if (!$okAdmin || !$okUser) {
                    $this->addFlash('warning', 'Votre message a été enregistré, mais l’e‑mail de notification n’a pas pu être envoyé.');
                } else {
                    $this->addFlash('success', 'Merci, votre message a bien été envoyé.');
                }

                return $this->redirectToRoute('app_contact');
            }

            $this->addFlash('danger', 'Le formulaire contient des erreurs.');
        }

        // Passe la clé au template (en plus de la globale Twig, ceinture et bretelles)
        $siteKey = (string) ($this->getParameter('app.recaptcha_site_key') ?? '');

        return $this->render('contact/contact.html.twig', [
            'form' => $form->createView(),
            'recaptcha_site_key' => $siteKey,
        ]);
    }
}
