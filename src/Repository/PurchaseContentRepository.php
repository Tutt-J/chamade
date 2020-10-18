<?php

namespace App\Repository;

use App\Entity\PurchaseContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method PurchaseContent|null find($id, $lockMode = null, $lockVersion = null)
 * @method PurchaseContent|null findOneBy(array $criteria, array $orderBy = null)
 * @method PurchaseContent[]    findAll()
 * @method PurchaseContent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PurchaseContentRepository extends ServiceEntityRepository
{
    /**
     * PurchaseContentRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseContent::class);
    }
}
