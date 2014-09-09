<?php

namespace Gedmo\SoftDeleteable;

use Doctrine\Common\Persistence\ObjectManager,
    Doctrine\Common\Persistence\Mapping\ClassMetadata,
    Doctrine\ORM\Mapping\ClassMetadataInfo,
    Doctrine\Common\EventArgs,

Gedmo\Mapping\MappedEventSubscriber,
    Gedmo\Mapping\Event\AdapterInterface,
    Gedmo\Loggable\Mapping\Event\LoggableAdapter;

/**
 * SoftDeleteable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
    /**
     * Pre soft-delete event
     *
     * @var string
     */
    const PRE_SOFT_DELETE = "preSoftDelete";

    /**
     * Post soft-delete event
     *
     * @var string
     */
    const POST_SOFT_DELETE = "postSoftDelete";


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
     * @var bool
     */
    protected $onDelete = false;

    protected $visited = array();

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            'loadClassMetadata',
            'onFlush',
        );
    }

    /**
     * Maps additional metadata
     *
     * @param  EventArgs $eventArgs
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * @param  EventArgs $args
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $this->visited = array();

        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            if ($this->isSoftDeleteable($om, $om->getClassMetadata(get_class($object)))) {
                $this->scheduleSoftDelete($ea,$object);
            }
        }
    }

    /**
     * If it's a SoftDeleteable object, update the "deletedAt" field
     * and skip the removal of the object
     *
     * @param AdapterInterface $ea
     * @param $object
     */
    protected function scheduleSoftDelete(AdapterInterface $ea,$object)
    {
        $object_hash = spl_object_hash($object);
        if (in_array($object_hash, $this->visited)) {
            return;
        }

        $this->visited[] = $object_hash;

        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();

        $meta = $om->getClassMetadata(get_class($object));

        $config = $this->getConfiguration($om, $meta->name);

        $reflProp = $meta->getReflectionProperty($config['fieldName']);
        $oldValue = $reflProp->getValue($object);
        if ($oldValue instanceof \Datetime) {
            return; // want to hard delete
        }

        $evm->dispatchEvent(
            self::PRE_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );

        $date = new \DateTime();
        $reflProp->setValue($object, $date);

        $om->persist($object);

        $uow->setOriginalEntityProperty(spl_object_hash($object), $config['fieldName'], $date);
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);

        $uow->scheduleExtraUpdate($object, array(
                $config['fieldName'] => array($oldValue, $date)
            ));

        $this->scheduleCascadeDeletes($ea, $object);

        $evm->dispatchEvent(
            self::POST_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );

    }

    /**
     * Find entries configured for DB cascade delete and delete them softly
     *
     * @param AdapterInterface $ea
     * @param $object
     */
    protected function scheduleCascadeDeletes(AdapterInterface $ea, $object)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        $this->loadCascadesConfig($ea);

        $meta = $om->getClassMetadata(get_class($object));

        if (!isset($this->onDelete[$meta->getName()])) {
            return;
        }

        $id = $identifier = $meta->getIdentifierValues($object);

        if (count($identifier)===1) {
            $id = reset($identifier);
        }

        foreach ($this->onDelete[$meta->getName()] as $targetClass => $onDelete) {

            $targetClassMetadata = $om->getClassMetadata($targetClass);

            $isSoftdeleteable = $this->isSoftDeleteable($om, $targetClassMetadata);

            /** @var \Doctrine\ORM\QueryBuilder $qb */
            $qb = $om->getRepository($targetClass)->createQueryBuilder('e');

            $orx = $qb->expr()->orX();

            $associations = call_user_func_array('array_merge', array_values($onDelete));

            foreach ($associations as $field) {
                $orx->add($qb->expr()->eq(sprintf('e.%s', $field), ':id'));
                $qb->addSelect(sprintf('IDENTITY(e.%s) as %s', $field, $field));
            }

            $qb
                ->where($orx)
                ->setParameter('id', $id);

            $query = $qb->getQuery();

//            foreach ($onDelete[self::ON_DELETE_CASCADE] as $field) {
//                $query->setFetchMode($targetClass, $field, ClassMetadataInfo::FETCH_EAGER);
//            }


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

            foreach ($onDelete[self::ON_DELETE_SET_NULL] as $association) {

                foreach ($grouped[$association] as $o) {
                    $oldValue = $targetClassMetadata->getFieldValue($o, $association);

                    $targetClassMetadata->setFieldValue($o, $association, null);

                    $uow->setOriginalEntityProperty(spl_object_hash($o), $association, null);
                    $uow->propertyChanged($o, $association, $oldValue, null);

                    $uow->scheduleExtraUpdate(
                        $o,
                        array(
                            $association => array($oldValue, null)
                        )
                    );
                }
            }

            foreach ($onDelete[self::ON_DELETE_CASCADE] as $association) {

                foreach ($grouped[$association] as $o) {
                    if ($isSoftdeleteable) {
                        $this->scheduleSoftDelete($ea, $o);
                    }
                    /*else{
                        $uow->scheduleForDelete($o);
                    }*/
                }
            }
        }
    }

    /**
     * @todo: metadata cache
     *
     * @param AdapterInterface $ea
     */
    protected function loadCascadesConfig(AdapterInterface $ea)
    {
        if ($this->onDelete !== false) {
            return;
        }

        $this->onDelete = array();

        $om = $ea->getObjectManager();

        $metadataFactory=$om->getMetadataFactory();

        /** @var \Doctrine\ORM\Mapping\ClassMetadata $meta  */
        foreach ($metadataFactory->getAllMetadata() as $meta) {

            if ($meta->isMappedSuperclass) {
                continue;
            }

            foreach ($meta->associationMappings as $association => $mapping) {

                $targetEntityMetadata = $om->getClassMetadata($mapping['targetEntity']);

                if (!$this->isSoftDeleteable($om, $targetEntityMetadata)) {
                    continue;
                }

                $sourceEntity = $mapping['sourceEntity'];

                if ($sourceEntity !== $meta->name) {
                    continue;
                }

                $onDelete = array(
                    self::ON_DELETE_CASCADE => array(),
                    self::ON_DELETE_SET_NULL => array()
                );

                $hasCascades = false;

                if (($mapping['type'] & ClassMetadataInfo::TO_ONE) > 0 && $mapping['isOwningSide']) {

                    $joinColumnMapping=$mapping['joinColumns'][0];

                    if (!isset($joinColumnMapping['onDelete'])) {
                        continue;
                    }

                    switch (strtoupper($joinColumnMapping['onDelete'])) {
                        case 'CASCADE':
                            $onDelete[self::ON_DELETE_CASCADE][] = $association;
                            $hasCascades = true;
                            break;
                        case 'SET NULL':
                            $onDelete[self::ON_DELETE_SET_NULL][] = $association;
                            $hasCascades = true;
                            break;
                    }
                }


                if (!$hasCascades) {
                    continue;
                }

                $targetEntities = array_merge(array($targetEntityMetadata->name), $targetEntityMetadata->subClasses);

                foreach ($targetEntities as $targetEntity) {

                    $this->onDelete = array_merge_recursive(
                        $this->onDelete,
                        array(
                            $targetEntity => array(
                                $sourceEntity => $onDelete
                            )
                        )
                    );
                }
            }

        }
    }

    private function isSoftDeleteable(ObjectManager $om, ClassMetadata $meta)
    {

        $config = $this->getConfiguration($om, $meta->name);

        if (isset($config['softDeleteable']) && $config['softDeleteable']) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
