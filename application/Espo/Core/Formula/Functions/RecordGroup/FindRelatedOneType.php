<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2020 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Formula\Functions\RecordGroup;

use Espo\Core\Exceptions\Error;

use Espo\Core\Di;

class FindRelatedOneType extends \Espo\Core\Formula\Functions\Base implements
    Di\EntityManagerAware,
    Di\SelectManagerFactoryAware,
    Di\MetadataAware
{
    use Di\EntityManagerSetter;
    use Di\SelectManagerFactorySetter;
    use Di\MetadataSetter;

    public function process(\StdClass $item)
    {
        if (!property_exists($item, 'value')) {
            throw new Error();
        }

        if (!is_array($item->value)) {
            throw new Error();
        }

        if (count($item->value) < 3) {
            throw new Error();
        }

        $entityManager = $this->entityManager;

        $entityType = $this->evaluate($item->value[0]);
        $id = $this->evaluate($item->value[1]);
        $link = $this->evaluate($item->value[2]);

        $orderBy = null;
        $order = null;

        if (count($item->value) > 3) {
            $orderBy = $this->evaluate($item->value[3]);
        }
        if (count($item->value) > 4) {
            $order = $this->evaluate($item->value[4]) ?? null;
        }

        if (!$entityType) throw new Error("record\\findRelatedOne: Empty entityType.");
        if (!$id) return null;
        if (!$link) throw new Error("record\\findRelatedOne: Empty link.");

        $entity = $entityManager->getEntity($entityType, $id);

        if (!$entity) return null;

        $metadata = $this->metadata;

        $relationType = $entity->getRelationParam($link, 'type');

        if (in_array($relationType, ['belongsTo', 'hasOne', 'belongsToParent'])) {
            $relatedEntity = $entityManager->getRepository($entityType)->findRelated($entity, $link, [
                'select' => ['id'],
            ]);
            if (!$relatedEntity) {
                return null;
            }
            return $relatedEntity->id;
        }

        if (!$orderBy) {
            $orderBy = $metadata->get(['entityDefs', $entityType, 'collection', 'orderBy']);
            if (is_null($order)) {
                $order = $metadata->get(['entityDefs', $entityType, 'collection', 'order']) ?? 'asc';
            }
        } else {
            $order = $order ?? 'asc';
        }

        $foreignEntityType = $entity->getRelationParam($link, 'entity');
        if (!$foreignEntityType) throw new Error("Formula record\\findRelatedOne: Bad or not supported link '{$link}'.");

        $foreignLink = $entity->getRelationParam($link, 'foreign');
        if (!$foreignLink) throw new Error("Formula record\\findRelatedOne: Not supported link '{$link}'.");

        $selectManager = $this->selectManagerFactory->create($foreignEntityType);
        $selectParams = $selectManager->getEmptySelectParams();

        if ($relationType === 'hasChildren') {
            $selectParams['whereClause'][] = [$foreignLink . 'Id' => $entity->id];
            $selectParams['whereClause'][] = [$foreignLink . 'Type' => $entity->getEntityType()];
        } else {
            $selectManager->addJoin($foreignLink, $selectParams);
            $selectParams['whereClause'][] = [$foreignLink . '.id' => $entity->id];
        }

        if (count($item->value) <= 6) {
            $filter = null;
            if (count($item->value) == 6) {
                $filter = $this->evaluate($item->value[5]);
            }
            if ($filter) {
                if (!is_string($filter)) throw new Error("Formula record\\findRelatedOne: Bad filter.");
                $selectManager->applyFilter($filter, $selectParams);
            }
        } else {
            $i = 5;
            while ($i < count($item->value) - 1) {
                $key = $this->evaluate($item->value[$i]);
                $value = $this->evaluate($item->value[$i + 1]);
                $selectParams['whereClause'][] = [$key => $value];
                $i = $i + 2;
            }
        }

        if ($orderBy) {
            $selectManager->applyOrder($orderBy, $order, $selectParams);
        }

        $e = $entityManager->getRepository($foreignEntityType)->select(['id'])->findOne($selectParams);

        if ($e) return $e->id;

        return null;
    }
}
