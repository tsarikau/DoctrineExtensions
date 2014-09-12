<?php
/**
 * Created by PhpStorm.
 * User: tsarikau
 * Date: 09/09/2014
 * Time: 20:24
 */

namespace Gedmo\SoftDeleteable;


use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;

class CascadeResolver
{

    /**
     * onDelete="CASCADE"
     */
    const ON_DELETE_CASCADE = 'DELETE';

    /**
     * onDelete="SET NULL"
     */
    const ON_DELETE_SET_NULL = 'SET_NULL';

    /**
     * Cascade operations mapping
     * @var array|bool
     */
    protected $config = false;

    /** @var EntityManager */
    protected $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    protected function load()
    {

        $this->config = array();

        $metadataFactory = $this->em->getMetadataFactory();

        /** @var ClassMetadata $classMetadata */
        foreach ($metadataFactory->getAllMetadata() as $classMetadata) {

            if ($classMetadata->isMappedSuperclass) {
                continue;
            }

            foreach ($classMetadata->associationMappings as $association => $mapping) {

                $targetEntity = $mapping['targetEntity'];
                $sourceEntity = $mapping['sourceEntity'];

                if ($sourceEntity !== $classMetadata->name) {
                    continue;
                }

                /** @var ClassMetadata $targetEntityMetadata */
                $targetEntityMetadata = $metadataFactory->getMetadataFor($targetEntity);

                $config = array();

                if (($mapping['type'] & ClassMetadata::TO_ONE) > 0 && $mapping['isOwningSide']) {

                    $joinColumnMapping = $mapping['joinColumns'][0];

                    if (!isset($joinColumnMapping['onDelete'])) {
                        continue;
                    }

                    switch (strtoupper($joinColumnMapping['onDelete'])) {
                        case 'CASCADE':
                            $config[self::ON_DELETE_CASCADE] = array($association);
                            break;
                        case 'SET NULL':
                            $config[self::ON_DELETE_SET_NULL] = array($association);
                            break;
                    }
                }


                if (!$config) {
                    continue;
                }

                $targetEntities = array_merge(array($targetEntityMetadata->name), $targetEntityMetadata->subClasses);

                foreach ($targetEntities as $targetEntity) {

                    $this->config = array_merge_recursive(
                        $this->config,
                        array(
                            $targetEntity => array(
                                $sourceEntity => $config
                            )
                        )
                    );
                }
            }
        }

    }

    public function getCascadeOperations($object, array $config)
    {

        $metadataFactory = $this->em->getMetadataFactory();

        $sourceMetadata = $metadataFactory->getMetadataFor(get_class($object));

        $id = $identifier = $sourceMetadata->getIdentifierValues($object);

        if (count($identifier) === 1) {
            $id = reset($identifier);
        }

        $cascadeObjects = array();

        foreach ($config as $targetClass => $cascade) {

            if (!$cascade) {
                continue;
            }

            /** @var \Doctrine\ORM\QueryBuilder $qb */
            $qb = $this->em->getRepository($targetClass)->createQueryBuilder('e');

            $orx = $qb->expr()->orX();

            $associations = call_user_func_array('array_merge', array_values($cascade));

            foreach ($associations as $field) {
                $orx->add($qb->expr()->eq(sprintf('e.%s', $field), ':id'));
                $qb->addSelect(sprintf('IDENTITY(e.%s) as %s', $field, $field));
            }

            $qb
                ->where($orx)
                ->setParameter('id', $id);

            $query = $qb->getQuery();


            $grouped = array_fill_keys($associations, array());


            if ($results = $query->getResult()) {
                foreach ($results as $result) {
                    $object = $result[0];
                    foreach ($grouped as $association => &$objects) {
                        if (isset($result[$association]) && $result[$association] == $id) {
                            $objects[] = $object;
                        }
                    }
                }
            }

            $cascadeObjects[$targetClass] = array();

            foreach ($cascade as $type => $associations) {
                $cascadeObjects[$targetClass][$type] = array();
                foreach ($associations as $association) {
                    if (isset($grouped[$association])) {

                        switch ($type) {
                            case self::ON_DELETE_SET_NULL:
                                $cascadeObjects[$targetClass][$type] = array_merge(
                                    $cascadeObjects[$targetClass][$type],
                                    array($association => $grouped[$association])
                                );
                                break;
                            default:
                                $cascadeObjects[$targetClass][$type] = array_merge(
                                    $cascadeObjects[$targetClass][$type],
                                    $grouped[$association]
                                );

                        }

                    }
                }
            }
        }

        return $cascadeObjects;
    }

    public function getObjectCascadeConfiguration($object)
    {

        $config = $this->getCascadeConfiguration();

        $sourceClass = get_class($object);

        if (isset($config[$sourceClass])) {
            return $config[$sourceClass];
        }

        return array();
    }

    public function getCascadeMapping()
    {
        if ($this->config === false) {
            $this->load();
        }

        return $this->config;
    }
}