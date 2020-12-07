<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\PromoCode;
use App\Entity\Purchase;
use App\Entity\PurchaseContent;
use Doctrine\ORM\EntityManagerInterface;
use Konekt\PdfInvoice\InvoicePrinter;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;

class ProcessPurchase
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $em;
    /**
     * @var ContentsBasketChecker
     */
    private ContentsBasketChecker $contentsBasketChecker;
    /**
     * @var SessionInterface
     */
    private SessionInterface $session;
    /**
     * @var FlashBagInterface
     */
    private FlashBagInterface $flashbag;

    /**
     * @var Security
     */
    private Security $security;

    /**
     * @var UrlGeneratorInterface
     */
    protected UrlGeneratorInterface $router;

    private StripeClient $stripeClient;

    private StripeHelper $stripeHelper;
    /**
     * @var Purchase
     */
    private Purchase $purchase;
    /**
     * @var SendMail
     */
    private SendMail $sendMail;

    /**
     * BasketAdministrator constructor.
     * @param StripeHelper $stripeHelper
     * @param EntityManagerInterface $em
     * @param Security $security
     * @param SessionInterface $session
     * @param ContentsBasketChecker $contentsBasketChecker
     * @param FlashBagInterface $flashbag
     * @param UrlGeneratorInterface $router
     */
    public function __construct(SendMail $sendMail, StripeHelper $stripeHelper, EntityManagerInterface $em, Security $security, SessionInterface $session, ContentsBasketChecker $contentsBasketChecker, FlashBagInterface $flashbag, UrlGeneratorInterface $router)
    {
        $this->em = $em;
        $this->session = $session;
        $this->contentsBasketChecker = $contentsBasketChecker;
        $this->flashbag=$flashbag;
        $this->stripeClient=new StripeClient($_ENV['STRIPE_SECRET']);
        $this->security=$security;
        $this->router= $router;
        $this->stripeHelper = $stripeHelper;
        $this->sendMail = $sendMail;
    }

    public function processPurcharse(){
        $this->setPurchaseContent();
        $this->updatePromoCode();
        $this->em->flush();
        $this->sendMail->sendTemplated($this->getInvoice($this->session->get('basket'), $this->purchase));
        $this->sendAdminHtmlMail();
    }

    public function sendAdminHtmlMail(){
        $contents='<ul>';
        foreach ($this->session->get('basket') as $item) {
            $contents.= '<li>'.$item['Entity']->getTitle().'</li>';
        }
        $contents.='</ul>';
        $html='<p>Nom : '.$this->security->getUser()->getFirstName().' '.$this->security->getUser()->getLastName().'</p>
                    <p>Email : <a href="mailto:'.$this->security->getUser()->getEmail().'">'.$this->security->getUser()->getEmail().'</a></p>
                    <p>Contenus :</p>'.$contents;
        $this->sendMail->sendBasicEmail($html);
    }

    public function setPurchase(){

        $purchase=new Purchase();
        if($this->session->get('stripe')){
            $charge= $this->stripeHelper->retrievePurchase('Basket');
            $purchase->setStripeId($charge['payment_intent']);
        } else{
            $purchase->setStripeId("Pas d\'id stripe car offert avec la carte cadeau ". $this->session->get('promoCode')->getCode());
        }
        $purchase->setStatus("Paiement accepté");
        $purchase->setAmount($this->session->get('purchaseInfos')['totalAmount']);
        $purchase->setUser($this->security->getUser());

        return $purchase;
    }

    public function setPurchaseContent(){
        $this->purchase=$this->setPurchase();
        //SET ALL PURCHASE CONTENTS
        for ($i=0; $i < sizeof($this->session->get('basket'));$i++) {
            $content = $this->em
                ->getRepository(Content::class)
                ->findOneBy(
                    ['id' => $this->session->get('basket')[$i]['Entity']->getId()]
                );

            $purchaseContent=new PurchaseContent();
            $purchaseContent->setPurchase($this->purchase);
            $purchaseContent->setContent($content);
            $purchaseContent->setQuantity(1);
            if ($this->session->get('basket')[$i]['isFidelity']) {
                $purchaseContent->setPrice($content->getFidelityPrice());
            } else {
                $purchaseContent->setPrice($content->getPrice());
            }

            $this->purchase->addPurchaseContent($purchaseContent);
            $this->em->persist($purchaseContent);
        }
        $this->em->persist($this->purchase);
    }

    public function updatePromoCode(){
        $promoCode=$this->em
            ->getRepository(PromoCode::class)
            ->findOneBy(
                [
                    'code' =>  $this->session->get('promoCode')->getCode()
                ]
            );
        $promoCode->setRestAmount($promoCode->getRestAmount()-$this->session->get('applyPromo'));
        $this->em->persist($promoCode);
    }



    public function getInvoice($items, $purchase, $user=null, $refund = false)
    {
        if(is_null($user)){
            $user=$this->security->getUser();
        }
        $invoice = new InvoicePrinter("A4", "€", "fr");

        /* Header settings */
        $invoice->setLogo("build/images/logo_basique.png");   //logo image path
        $invoice->setColor("#f28066");      // pdf color scheme
        $invoice->setType("Facture");    // Invoice Type
        $invoice->setReference('WEB'.date('Y').'_'.$purchase->getId());   // Reference
        $invoice->setDate(date('d/m/Y', time()));   //Billing Date
        $invoice->setTime(date('H:i:s', time()));   //Billing Time
        $invoice->setFrom(array("CHAMADE","419 RUE DE BORINGES","74930 REIGNIER-ESERY"));
        $invoice->setTo(array(
            $this->stripAccents($user->getFirstName()).' '.$this->stripAccents($user->getLastName()),
            $this->stripAccents($user->getAddress()->getStreet()),
            $this->stripAccents($user->getAddress()->getPostalCode())." ".$this->stripAccents($user->getAddress()->getCity()),
            $this->stripAccents($user->getAddress()->getCountry())
        ));

        $total=0;
        foreach ($items as $item) {
            $invoice->addItem(
                $item['Entity']->getTitle(),
                null,
                1,
                false,
                $item['Entity']->getPrice(),
                false,
                $item['Entity']->getPrice()
            );
            if($item['isFidelity']){
                $total+=$item['Entity']->getFidelityPrice();
            } else{
                $total+=$item['Entity']->getPrice();
            }
        }
        if(null != $this->session->get('applyPromo')){
            $invoice->addTotal("Réductions", $this->session->get('applyPromo'));
            $total=$total-$this->session->get('applyPromo');
        }
        $invoice->addTotal("Total", $total);
        $invoice->flipflop();
        if($refund){
            $invoice->addBadge("Remboursé");
        } elseif($total == 0 ){
            $invoice->addBadge("Offert");
        } else{
            $invoice->addBadge("Payée");
        }

        $invoice->setFooternote("Chamade");

        if (!file_exists('invoices')) {
            mkdir('invoices', 0775, true);
        }

        $path='invoices/Facture_WEB'.date('Y').'_'.$purchase->getId().'.pdf';
        $invoice->render($path, 'F');

        return $path;
    }

    public function changeSession(){
        $this->session->set('purchaseSuccess', $this->session->get('basket'));
        $this->session->set('purchaseSuccessInfos', $this->session->get('purchaseInfos'));
        $this->session->remove('basket');
        $this->session->remove('purchaseInfos');
        $this->session->remove('stripe');
        $this->session->remove('promoCode');
        $this->session->remove('applyPromo');
        $this->session->remove('description');
    }

    function stripAccents($str) {
        return strtoupper(strtr(utf8_decode($str), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY'));
    }

}
